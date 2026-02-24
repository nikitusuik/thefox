<?php

// ===== Response helpers =====
function response_json(array $data, int $status = 200): void {
    http_response_code($status);
    
    // CORS headers для работы с GitHub Pages и другими доменами
    $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
    
    // Разрешаем запросы с GitHub Pages и локального хоста
    $allowedOrigins = [
        'https://se.ifmo.ru',
        'http://localhost',
        'http://localhost:5173',
        'http://127.0.0.1:5173',
    ];
    
    // Проверяем, является ли origin GitHub Pages (любой поддомен github.io)
    $isGitHubPages = preg_match('/^https:\/\/[a-zA-Z0-9-]+\.github\.io$/', $requestOrigin);
    
    // Если это разрешенный origin или GitHub Pages, используем его
    if (in_array($requestOrigin, $allowedOrigins) || $isGitHubPages) {
        header('Access-Control-Allow-Origin: ' . $requestOrigin);
        header('Access-Control-Allow-Credentials: true');
    } else if ($requestOrigin !== '') {
        // Если origin указан, но не в списке - все равно разрешаем (для гибкости)
        header('Access-Control-Allow-Origin: ' . $requestOrigin);
        header('Access-Control-Allow-Credentials: true');
    } else {
        // Если origin не указан (например, прямой запрос), разрешаем всем
        header('Access-Control-Allow-Origin: *');
    }
    
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Auth-Token');
    header('Access-Control-Max-Age: 86400'); // 24 часа
    
    // Обработка preflight запросов
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
    
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function response_error(int $status, string $message): void {
    response_json(['ok' => false, 'error' => $message], $status);
}

// ===== Auth token =====
function get_auth_token(): string {
    // Пробуем разные варианты имени заголовка (PHP может преобразовывать по-разному)
    $token = '';
    
    // Вариант 1: через getallheaders() если доступно (самый надежный способ)
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (isset($headers['X-Auth-Token'])) {
            $token = $headers['X-Auth-Token'];
        } elseif (isset($headers['x-auth-token'])) {
            $token = $headers['x-auth-token'];
        }
    }
    
    // Вариант 2: через $_SERVER (PHP преобразует X-Auth-Token в HTTP_X_AUTH_TOKEN)
    if ($token === '' && isset($_SERVER['HTTP_X_AUTH_TOKEN'])) {
        $token = $_SERVER['HTTP_X_AUTH_TOKEN'];
    }
    
    // Вариант 3: пробуем разные варианты регистра
    if ($token === '') {
        $variants = [
            'HTTP_X_AUTH_TOKEN',
            'HTTP_X-AUTH-TOKEN',
            'X-Auth-Token',
            'x-auth-token'
        ];
        foreach ($variants as $key) {
            if (isset($_SERVER[$key])) {
                $token = $_SERVER[$key];
                break;
            }
        }
    }
    
    return trim((string)$token);
}

function make_token(string $login): string {
    return substr(hash('sha256', $login . microtime(true)), 0, 16);
}

/**
 * Проверяет, что для указанного логина передан валидный X-Auth-Token.
 * Если токен отсутствует или неверный — сразу response_error(401, ...).
 */
function require_auth(PDO $pdo, string $login): void {
    $login = trim($login);
    if ($login === '') {
        response_error(401, 'login is required for auth');
    }

    $token = get_auth_token();
    if ($token === '') {
        response_error(401, 'X-Auth-Token header is required');
    }

    // Поиск по логину без учёта регистра, чтобы совпадал с auth_login
    $stmt = $pdo->prepare("
        SELECT token
        FROM users
        WHERE LOWER(TRIM(login)) = LOWER(:login)
        LIMIT 1
    ");
    $stmt->execute([':login' => $login]);
    $dbToken = $stmt->fetchColumn();

    // Проверяем, что токен есть в БД и не пустой
    if ($dbToken === false || $dbToken === null) {
        response_error(401, 'User not found or token not set');
    }
    
    $dbToken = trim((string)$dbToken);
    if ($dbToken === '') {
        response_error(401, 'Token not set for this user. Please login again.');
    }
    
    // Безопасное сравнение токенов
    if (!hash_equals($dbToken, $token)) {
        response_error(401, 'Invalid auth token');
    }
}

// ===== Game guards =====
// foxes.id == game_id
function ensure_game_not_over(PDO $pdo, int $gameId): void {
    $stmt = $pdo->prepare("SELECT foxpos FROM foxes WHERE id = :gid");
    $stmt->execute([':gid' => $gameId]);
    $foxpos = (int)$stmt->fetchColumn();

    // foxpos >= 37 -> лиса убежала
    // foxpos < 0   -> глобальный финал (accuse): -2 win / -1 lose
    if ($foxpos >= 37) {
        response_error(400, 'Game over: fox reached the den');
    }
    if ($foxpos < 0) {
        response_error(400, 'Game over: final result is already set');
    }
}

// Игра считается «начатой», когда заняты ВСЕ места (players_now == seatcount).
// Никаких новых полей в БД не добавляем — это вычисляемое правило.
function ensure_game_started(PDO $pdo, int $gameId): void {
    // seatcount
    $stmt = $pdo->prepare("SELECT seatcount FROM games WHERE gameid = :gid");
    $stmt->execute([':gid' => $gameId]);
    $seatCount = (int)$stmt->fetchColumn();
    if ($seatCount <= 0) {
        response_error(404, 'Game not found');
    }

    // players_now
    $stmt = $pdo->prepare("
        SELECT COUNT(*)::int
        FROM players p
        JOIN cells c ON c.cellid = p.cellid
        WHERE c.gameid = :gid
    ");
    $stmt->execute([':gid' => $gameId]);
    $playersNow = (int)$stmt->fetchColumn();

    if ($playersNow < $seatCount) {
        response_error(400, "Game is not started yet. Waiting players: {$playersNow}/{$seatCount}");
    }
}

/**
 * Авто-передача хода, если таймер хода истёк.
 * Никакого логина не нужно: смотрим на current_seat и turn_started_at.
 */
function auto_advance_turn_if_timeout(PDO $pdo, int $gameId): void {
    try {
        $pdo->beginTransaction();

        // Лочим игру и пересчитываем, точно ли таймер истёк
        $stmt = $pdo->prepare("
            SELECT gameid, turntime, seatcount, current_seat, turn_started_at
            FROM games
            WHERE gameid = :gid
            FOR UPDATE
        ");
        $stmt->execute([':gid' => $gameId]);
        $game = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$game) {
            $pdo->rollBack();
            return;
        }

        $seatCount   = (int)$game['seatcount'];
        $currentSeat = (int)$game['current_seat'];
        $turntime    = (int)$game['turntime'];
        $startedRaw  = $game['turn_started_at'] ?? null;
        $startedTs   = $startedRaw ? strtotime($startedRaw) : null;

        // Если игра ещё не стартовала или нет таймера — ничего не делаем
        if ($currentSeat <= 0 || $turntime <= 0 || !$startedTs) {
            $pdo->commit();
            return;
        }

        $elapsed = time() - $startedTs;
        if ($elapsed < $turntime) {
            // Время ещё не вышло
            $pdo->commit();
            return;
        }

        // Находим игрока, чей ход сейчас (по seatnumber)
        $stmt = $pdo->prepare("
            SELECT p.playerid
            FROM players p
            JOIN cells c ON c.cellid = p.cellid
            WHERE c.gameid = :gid AND p.seatnumber = :seat
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([':gid' => $gameId, ':seat' => $currentSeat]);
        $playerId = (int)$stmt->fetchColumn();

        // Чистим его pending move, если есть
        if ($playerId > 0) {
            $stmt = $pdo->prepare("DELETE FROM moves WHERE playerid = :pid");
            $stmt->execute([':pid' => $playerId]);
        }

        // Лис двигается вперед на 3 клетки при таймауте (только если игра не закончена)
        $stmt = $pdo->prepare("SELECT foxpos FROM foxes WHERE id = :gid FOR UPDATE");
        $stmt->execute([':gid' => $gameId]);
        $foxpos = (int)$stmt->fetchColumn();
        
        // Двигаем лиса только если игра не закончена (foxpos >= 0 && foxpos < 37)
        if ($foxpos >= 0 && $foxpos < 37) {
            $stmt = $pdo->prepare("
                UPDATE foxes
                SET foxpos = foxpos + 3
                WHERE id = :gid
            ");
            $stmt->execute([':gid' => $gameId]);
        }

        // Передаём ход следующему
        $nextSeat = ($currentSeat >= $seatCount) ? 1 : ($currentSeat + 1);

        $stmt = $pdo->prepare("
            UPDATE games
            SET current_seat = :ns,
                turn_started_at = NOW()
            WHERE gameid = :gid
        ");
        $stmt->execute([':ns' => $nextSeat, ':gid' => $gameId]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // game_state.php не должен падать из-за авто-скипа, поэтому молчим
    }
}

/**
 * Каскадное удаление игры и всех связанных данных (moves, players, clues_in_game, …).
 * Вызывать внутри уже открытой транзакции или без — функция сама не управляет транзакцией.
 */
function delete_game_cascade(PDO $pdo, int $gameId): void {
    $stmt = $pdo->prepare("
        DELETE FROM moves
        WHERE playerid IN (
            SELECT p.playerid FROM players p
            JOIN cells c ON c.cellid = p.cellid
            WHERE c.gameid = :gid
        )
    ");
    $stmt->execute([':gid' => $gameId]);

    $stmt = $pdo->prepare("
        DELETE FROM players
        WHERE cellid IN (SELECT cellid FROM cells WHERE gameid = :gid)
    ");
    $stmt->execute([':gid' => $gameId]);

    $stmt = $pdo->prepare("DELETE FROM clues_in_game WHERE cellid IN (SELECT cellid FROM cells WHERE gameid = :gid)");
    $stmt->execute([':gid' => $gameId]);

    $stmt = $pdo->prepare("DELETE FROM suspect_clues_in_game WHERE gameid = :gid");
    $stmt->execute([':gid' => $gameId]);

    $stmt = $pdo->prepare("DELETE FROM suspects_in_game WHERE gameid = :gid");
    $stmt->execute([':gid' => $gameId]);

    $stmt = $pdo->prepare("DELETE FROM cells WHERE gameid = :gid");
    $stmt->execute([':gid' => $gameId]);

    $stmt = $pdo->prepare("DELETE FROM foxes WHERE id = :gid");
    $stmt->execute([':gid' => $gameId]);

    $stmt = $pdo->prepare("DELETE FROM games WHERE gameid = :gid");
    $stmt->execute([':gid' => $gameId]);
}

function json_input(): array {
    // 1) пробуем JSON
    $raw = file_get_contents('php://input');
    if (is_string($raw) && trim($raw) !== '') {
        $data = json_decode($raw, true);
        if (is_array($data)) return $data;

        // 2) если это не JSON, попробуем как querystring (на всякий)
        parse_str($raw, $parsed);
        if (is_array($parsed) && !empty($parsed)) return $parsed;
    }

    // 3) fallback: обычная форма (x-www-form-urlencoded / form-data)
    if (!empty($_POST) && is_array($_POST)) return $_POST;

    return [];
}

