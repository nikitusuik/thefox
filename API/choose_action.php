<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

/*
 choose_action.php
 -----------------
 POST JSON:
 {
   "game_id": 5,
   "login": "p1",
   "direction": "подсказка" | "подозреваемый" | "clue" | "suspect"
 }

 Логика:
 - игра не должна быть закончена
 - ИГРА ДОЛЖНА БЫТЬ "НАЧАТА": players_now == seatcount (иначе блокируем)
 - проверяем очередь: player.seatnumber == games.current_seat
 - запрещаем choose_action, если у игрока уже есть незавершённое действие (строка в moves)
 - симуляция кубиков: 3 попытки, переброс только нулей
 - пишем moves (playerid, direction, result) ВСЕГДА
 - если fail:
      * foxpos += 3
      * ход СРАЗУ заканчивается: удаляем moves
      * переключаем очередь (games.current_seat++)
 - если success:
      * для clue возвращаем max_steps (3..6)
      * moves остаётся (ход завершится в move.php / open_suspect.php)
*/

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

/**
 * На вход RU/EN, на выход — РОВНО значение, которое реально есть в enum direction_enum.
 */
function normalize_direction(PDO $pdo, string $raw): string {
    $raw = trim(mb_strtolower($raw, 'UTF-8'));

    if ($raw === 'подсказка') $raw = 'clue';
    if ($raw === 'подозреваемый') $raw = 'suspect';

    $rows = $pdo->query("SELECT unnest(enum_range(NULL::direction_enum)) AS v")->fetchAll();
    $vals = array_map(fn($r) => (string)$r['v'], $rows);

    $map = [
        'clue'    => ['clue', 'подсказка'],
        'suspect' => ['suspect', 'подозреваемый'],
    ];

    if (!isset($map[$raw])) {
        response_error(400, 'Invalid direction (use: clue/suspect or подсказка/подозреваемый)');
    }

    foreach ($map[$raw] as $candidate) {
        if (in_array($candidate, $vals, true)) return $candidate;
    }

    response_error(500, 'direction_enum does not contain required values for this direction');
}

/**
 * Игра считается начатой, когда заняты все места: players_now == seatcount.
 * Ничего в БД не добавляем, просто вычисляем.
 *
 * ВАЖНО: players связаны с game через cells.gameid.
 */



try {
    $pdo = db();
    $data = json_input();

    $gameId = (int)($data['game_id'] ?? 0);
    $login  = trim($data['login'] ?? '');
    $directionRaw = (string)($data['direction'] ?? '');

    if ($gameId <= 0 || $login === '' || trim($directionRaw) === '') {
        response_error(400, 'game_id, login and direction are required');
    }

    // Проверяем токен игрока
    require_auth($pdo, $login);

    $pdo->beginTransaction();

    // game over check inside transaction
    ensure_game_not_over($pdo, $gameId);

    // NEW: блокируем до полного набора игроков
    ensure_game_started($pdo, $gameId);

    $dirEnum = normalize_direction($pdo, $directionRaw);

    // Лочим games строку + находим игрока
    $stmt = $pdo->prepare("
        SELECT
            p.playerid,
            p.seatnumber,
            g.current_seat,
            g.seatcount
        FROM players p
        JOIN cells c ON c.cellid = p.cellid
        JOIN games g ON g.gameid = c.gameid
        WHERE p.login = :login AND c.gameid = :gid
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([':login' => $login, ':gid' => $gameId]);
    $row = $stmt->fetch();

    if (!$row) {
        $pdo->rollBack();
        response_error(404, 'Player not found in this game');
    }

    $playerId    = (int)$row['playerid'];
    $seatNumber  = (int)$row['seatnumber'];
    $currentSeat = (int)$row['current_seat'];
    $seatCount   = (int)$row['seatcount'];

    if ($seatNumber !== $currentSeat) {
        $pdo->rollBack();
        response_error(400, "Not your turn. Current seat: $currentSeat");
    }

    // Запрещаем если есть незавершённый ход (moves уже существует)
    $stmt = $pdo->prepare("SELECT 1 FROM moves WHERE playerid = :pid LIMIT 1 FOR UPDATE");
    $stmt->execute([':pid' => $playerId]);
    if ($stmt->fetchColumn()) {
        $pdo->rollBack();
        response_error(400, 'You already chose an action. Finish it (move/open_suspect) before choosing again');
    }

    // Кубики: 3 попытки, переброс только нулей
    $dice = [0, 0, 0];
    for ($round = 1; $round <= 3; $round++) {
        for ($i = 0; $i < 3; $i++) {
            if ($dice[$i] === 0) $dice[$i] = random_int(0, 1);
        }
        if ($dice[0] === 1 && $dice[1] === 1 && $dice[2] === 1) break;
    }
    $success = ($dice[0] === 1 && $dice[1] === 1 && $dice[2] === 1);

    // max_steps для успешного хода с подсказкой
    $dirText = mb_strtolower($dirEnum, 'UTF-8');
    $maxSteps = null;
    if ($success && ($dirText === 'clue' || $dirText === 'подсказка')) {
        $maxSteps = random_int(3, 6);
    }

    // Пишем moves ВСЕГДА
    $stmt = $pdo->prepare("
        INSERT INTO moves (playerid, direction, result, max_steps)
        VALUES (:pid, :dir::direction_enum, :res, :max_steps)
    ");
    $stmt->bindValue(':pid', $playerId, PDO::PARAM_INT);
    $stmt->bindValue(':dir', $dirEnum);
    $stmt->bindValue(':res', (bool)$success, PDO::PARAM_BOOL);
    $stmt->bindValue(':max_steps', $maxSteps, $maxSteps === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $stmt->execute();

    // Лис двигается только при fail
    $foxMoved = false;
    if (!$success) {
        $stmt = $pdo->prepare("
            UPDATE foxes
            SET foxpos = foxpos + 3
            WHERE id = :gid
            RETURNING foxpos
        ");
        $stmt->execute([':gid' => $gameId]);
        $foxpos = (int)$stmt->fetchColumn();
        $foxMoved = true;
    } else {
        $stmt = $pdo->prepare("SELECT foxpos FROM foxes WHERE id = :gid");
        $stmt->execute([':gid' => $gameId]);
        $foxpos = (int)$stmt->fetchColumn();
    }

    $nextSeat = null;

    // FAIL = ход заканчивается сразу: удаляем moves и переключаем очередь
    if (!$success) {
        $stmt = $pdo->prepare("DELETE FROM moves WHERE playerid = :pid");
        $stmt->execute([':pid' => $playerId]);

        $nextSeat = ($currentSeat >= $seatCount) ? 1 : ($currentSeat + 1);

        $stmt = $pdo->prepare("
            UPDATE games
            SET current_seat = :ns,
                turn_started_at = NOW()
            WHERE gameid = :gid
        ");
        $stmt->execute([':ns' => $nextSeat, ':gid' => $gameId]);
    }

    $pdo->commit();

    $resp = [
        'ok' => true,
        'direction' => $dirEnum,
        'success' => $success,
        'dice' => $dice,
        'fox' => ['moved' => $foxMoved, 'foxpos' => $foxpos],
    ];

    // max_steps только при success + clue (то же значение, что и в БД)
    if ($maxSteps !== null) {
        $resp['max_steps'] = $maxSteps;
    }

    if (!$success) {
        $resp['next_seat'] = $nextSeat;
    }

    response_json($resp);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    response_error(500, 'Server error: ' . $e->getMessage());
}
