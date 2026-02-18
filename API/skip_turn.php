<?php
/*
 skip_turn.php
 -------------
 Принудительно завершает ход текущего игрока (таймаут).
*/

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

/**
 * Игра начата, когда players_now == seatcount
 */
function ensure_game_started(PDO $pdo, int $gameId): void {
    // Лочим игру и берём seatcount
    $stmt = $pdo->prepare("
        SELECT seatcount
        FROM games
        WHERE gameid = :gid
        FOR UPDATE
    ");
    $stmt->execute([':gid' => $gameId]);
    $seatCount = (int)$stmt->fetchColumn();
    if ($seatCount <= 0) response_error(404, 'Game not found');

    // Считаем игроков БЕЗ COUNT + лочим строки игроков
    $stmt = $pdo->prepare("
        SELECT p.playerid
        FROM players p
        JOIN cells c ON c.cellid = p.cellid
        WHERE c.gameid = :gid
        FOR UPDATE
    ");
    $stmt->execute([':gid' => $gameId]);
    $playersNow = count($stmt->fetchAll(PDO::FETCH_COLUMN, 0));

    if ($playersNow < $seatCount) {
        response_error(400, "Game is not started yet. Players: {$playersNow}/{$seatCount}");
    }
}


try {
    $pdo = db();
    $data = json_input();

    $gameId = (int)($data['game_id'] ?? 0);
    $login  = trim($data['login'] ?? '');

    if ($gameId <= 0 || $login === '') {
        response_error(400, 'game_id, login are required');
    }

    // Проверяем токен игрока
    require_auth($pdo, $login);

    $pdo->beginTransaction();

    ensure_game_not_over($pdo, $gameId);
    ensure_game_started($pdo, $gameId); // NEW

    // игрок + seat + current_seat
    $stmt = $pdo->prepare("
        SELECT p.playerid, p.seatnumber, g.current_seat, g.seatcount
        FROM players p
        JOIN cells c ON c.cellid = p.cellid
        JOIN games g ON g.gameid = c.gameid
        WHERE p.login = :login AND c.gameid = :gid
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([':login' => $login, ':gid' => $gameId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

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

    // чистим pending action
    $stmt = $pdo->prepare("DELETE FROM moves WHERE playerid = :pid");
    $stmt->execute([':pid' => $playerId]);

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
            RETURNING foxpos
        ");
        $stmt->execute([':gid' => $gameId]);
        $foxpos = (int)$stmt->fetchColumn();
    }

    // переключаем очередь
    $nextSeat = ($currentSeat >= $seatCount) ? 1 : ($currentSeat + 1);
    $stmt = $pdo->prepare("
        UPDATE games
        SET current_seat = :ns,
            turn_started_at = NOW()
        WHERE gameid = :gid
    ");
    $stmt->execute([':ns' => $nextSeat, ':gid' => $gameId]);

    $pdo->commit();

    response_json([
        'ok' => true,
        'skipped' => true,
        'next_seat' => $nextSeat,
        'fox' => ['moved' => true, 'foxpos' => $foxpos]
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    response_error(500, 'Server error: ' . $e->getMessage());
}
