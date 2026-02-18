<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

try {
    $pdo = db();
    $data = json_input();

    $gameId = (int)($data['game_id'] ?? 0);
    $login  = trim($data['login'] ?? '');

    if ($gameId <= 0 || $login === '') {
        response_error(400, 'game_id and login are required');
    }

    // Проверяем токен игрока (использует сравнение без учета регистра)
    require_auth($pdo, $login);

    // Получаем точный логин из БД (для дальнейшего использования)
    // Используем сравнение без учета регистра, как в require_auth
    $stmt = $pdo->prepare("SELECT login FROM users WHERE LOWER(TRIM(login)) = LOWER(:login) LIMIT 1");
    $stmt->execute([':login' => $login]);
    $dbLogin = $stmt->fetchColumn();
    
    if (!$dbLogin) {
        response_error(404, 'User not found');
    }
    
    // Используем логин из БД для всех дальнейших операций
    $login = trim((string)$dbLogin);

    $pdo->beginTransaction();

    // 1) Лочим игру 
    $stmt = $pdo->prepare("
        SELECT gameid, seatcount, current_seat
        FROM games
        WHERE gameid = :gid
        FOR UPDATE
    ");
    $stmt->execute([':gid' => $gameId]);
    $game = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$game) {
        $pdo->rollBack();
        response_error(404, 'Game not found');
    }

    $seatCount   = (int)$game['seatcount'];
    $currentSeat = (int)$game['current_seat'];

    // 2) Проверяем, не в игре ли уже (и лочим строку игрока, если есть)
    // Используем точное совпадение логина, так как login уже нормализован из БД
    $stmt = $pdo->prepare("
        SELECT p.playerid, p.seatnumber, p.color, c.y, c.x
        FROM players p
        JOIN cells c ON c.cellid = p.cellid
        WHERE c.gameid = :gid AND p.login = :login
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([':gid' => $gameId, ':login' => $login]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $pdo->commit();
        response_json([
            'ok' => true,
            'message' => 'Already in game',
            'player_id' => (int)$existing['playerid'],
            'seat' => (int)$existing['seatnumber'],
            'color' => (string)$existing['color'],
            'start' => ['y' => (int)$existing['y'], 'x' => (int)$existing['x']],
            'game' => [
                'seatcount' => $seatCount,
                'current_seat' => $currentSeat,
                'started' => ($currentSeat > 0),
            ]
        ]);
    }

    // 3) Считаем сколько игроков сейчас и проверяем уникальность логина в игре
    $stmt = $pdo->prepare("
        SELECT p.playerid, p.login
        FROM players p
        JOIN cells c ON c.cellid = p.cellid
        WHERE c.gameid = :gid
        FOR UPDATE
    ");
    $stmt->execute([':gid' => $gameId]);
    $existingPlayers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $playersNow = count($existingPlayers);
    
    // Проверяем, нет ли уже игрока с таким логином (на случай race condition)
    foreach ($existingPlayers as $player) {
        if (strcasecmp(trim($player['login']), $login) === 0) {
            $pdo->rollBack();
            response_error(409, 'Player with this login already in game');
        }
    }

    if ($playersNow >= $seatCount) {
        $pdo->rollBack();
        response_error(409, "No free seats. Current: {$playersNow}/{$seatCount}");
    }

    if ($currentSeat > 0) {
        $pdo->rollBack();
        response_error(409, 'Game already started');
    }

    // 4) Место/цвет/стартовая клетка
    $seatNumber = $playersNow + 1;

    $colors = [
        1 => 'красный',
        2 => 'желтый',
        3 => 'синий',
        4 => 'зеленый',
    ];
    $color = $colors[$seatNumber] ?? 'красный';

    // Стартовые координаты зависят от seatcount на поле 18x18.
    // Используем центр 2x2 (y,x ∈ {9,10}):
    // 2 игрока: (9,9) и (10,10)
    // 3 игрока: (9,9), (9,10), (10,10)
    // 4 игрока: (9,9), (9,10), (10,9), (10,10)
    if ($seatCount === 2) {
        $center = [
            1 => ['y' => 9, 'x' => 9],
            2 => ['y' => 10, 'x' => 10],
        ];
    } elseif ($seatCount === 3) {
        $center = [
            1 => ['y' => 9, 'x' => 9],
            2 => ['y' => 9, 'x' => 10],
            3 => ['y' => 10, 'x' => 10],
        ];
    } else {
        $center = [
            1 => ['y' => 9, 'x' => 9],
            2 => ['y' => 9, 'x' => 10],
            3 => ['y' => 10, 'x' => 9],
            4 => ['y' => 10, 'x' => 10],
        ];
    }

    $y = $center[$seatNumber]['y'];
    $x = $center[$seatNumber]['x'];

    $stmt = $pdo->prepare("
        SELECT cellid
        FROM cells
        WHERE gameid = :gid AND y = :y AND x = :x
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([':gid'=>$gameId, ':y'=>$y, ':x'=>$x]);
    $cellId = (int)$stmt->fetchColumn();

    if ($cellId <= 0) {
        $pdo->rollBack();
        response_error(500, 'Start cell not found');
    }

    // 5) Добавляем игрока
    $stmt = $pdo->prepare("
        INSERT INTO players (login, seatnumber, color, cellid)
        VALUES (:login, :seat, :color, :cellid)
        RETURNING playerid
    ");
    $stmt->execute([
        ':login' => $login,
        ':seat' => $seatNumber,
        ':color' => $color,
        ':cellid' => $cellId
    ]);
    $playerId = (int)$stmt->fetchColumn();

    // 6) Если это был последний игрок — стартуем игру: current_seat = 1
    $playersAfterJoin = $playersNow + 1;
    $started = false;

    if ($playersAfterJoin >= $seatCount) {
        // игра стартует: первый игрок ходит, фиксируем время начала хода
        $stmt = $pdo->prepare("
            UPDATE games
            SET current_seat = 1,
                turn_started_at = NOW()
            WHERE gameid = :gid
        ");
        $stmt->execute([':gid' => $gameId]);
        $currentSeat = 1;
        $started = true;
    } else {
        // игра ещё не стартовала: обнуляем current_seat и сбрасываем таймер
        $stmt = $pdo->prepare("
            UPDATE games
            SET current_seat = 0,
                turn_started_at = NULL
            WHERE gameid = :gid
        ");
        $stmt->execute([':gid' => $gameId]);
        $currentSeat = 0;
        $started = false;
    }

    $pdo->commit();

    response_json([
        'ok' => true,
        'player_id' => $playerId,
        'seat' => $seatNumber,
        'color' => $color,
        'start' => ['y' => $y, 'x' => $x],
        'game' => [
            'players_now' => $playersAfterJoin,
            'seatcount' => $seatCount,
            'current_seat' => $currentSeat,
            'started' => $started
        ]
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    
    // Более детальное сообщение об ошибке для отладки
    $errorMsg = 'Server error: ' . $e->getMessage();
    if ($e->getCode() === 23505) { // PostgreSQL unique violation
        $errorMsg = 'Player already exists in this game or duplicate entry';
    } elseif ($e->getCode() === 23503) { // Foreign key violation
        $errorMsg = 'Invalid game or user reference';
    }
    
    response_error(500, $errorMsg);
}
