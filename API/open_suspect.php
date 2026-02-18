<?php
/*
 open_suspect.php
 ----------------
 Вскрытие подозреваемого ПОСЛЕ успешного choose_action(direction=suspect).
*/

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

function get_enum_value(PDO $pdo, string $enumType, array $candidates): string {
    $rows = $pdo->query("SELECT unnest(enum_range(NULL::{$enumType})) AS v")->fetchAll();
    $vals = array_map(fn($r) => (string)$r['v'], $rows);
    foreach ($candidates as $c) {
        if (in_array($c, $vals, true)) return $c;
    }
    response_error(500, "Enum {$enumType} does not contain required values");
}

/**
 * Игра начата, когда players_now == seatcount.
 * Считаем через players+cells, без изменения БД.
 */



try {
    $pdo = db();
    $data = json_input();

    $gameId  = (int)($data['game_id'] ?? 0);
    $login   = trim($data['login'] ?? '');
    $susName = trim($data['suspect_name'] ?? '');

    if ($gameId <= 0 || $login === '' || $susName === '') {
        response_error(400, 'game_id, login, suspect_name are required');
    }

    // Проверяем токен игрока
    require_auth($pdo, $login);

    $pdo->beginTransaction();

    ensure_game_not_over($pdo, $gameId);
    ensure_game_started($pdo, $gameId); // NEW

    $suspectEnum  = get_enum_value($pdo, 'direction_enum', ['suspect', 'подозреваемый']);
    $openedStatus = get_enum_value($pdo, 'status_enum', ['вскрыт', 'opened']);
    $hiddenStatus = get_enum_value($pdo, 'status_enum', ['скрыт', 'hidden']);

    // 1) Игрок + очередь
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

    // 2) Проверяем moves
    $stmt = $pdo->prepare("
        SELECT direction::text AS dir_text, result
        FROM moves
        WHERE playerid = :pid
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([':pid' => $playerId]);
    $m = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$m) {
        $pdo->rollBack();
        response_error(400, 'No pending action. Call choose_action first');
    }

    if ((string)$m['dir_text'] !== (string)$suspectEnum) {
        $pdo->rollBack();
        response_error(400, 'Pending action is not suspect');
    }

    if (!$m['result']) {
        // fail -> закрываем действие и передаём ход
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

        $pdo->commit();
        response_error(400, 'Pending suspect action is failed (result=false)');
    }

    // 3) Находим suspect по имени (без регистра)
    $stmt = $pdo->prepare("
        SELECT susid, susname
        FROM suspects
        WHERE lower(susname) = lower(:name)
        LIMIT 1
    ");
    $stmt->execute([':name' => $susName]);
    $srow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$srow) {
        $pdo->rollBack();
        response_error(404, 'Suspect not found');
    }

    $susId = (int)$srow['susid'];

    // 4) Проверяем статус в suspects_in_game (FOR UPDATE)
    $stmt = $pdo->prepare("
        SELECT status::text AS st
        FROM suspects_in_game
        WHERE gameid = :gid AND susid = :sid
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([':gid' => $gameId, ':sid' => $susId]);
    $curStatus = $stmt->fetchColumn();

    if ($curStatus === false) {
        $pdo->rollBack();
        response_error(400, 'suspects_in_game row not found for this game/suspect');
    }

    if ((string)$curStatus === (string)$openedStatus) {
        $pdo->rollBack();
        response_error(409, 'Suspect already opened');
    }

    if ((string)$curStatus !== (string)$hiddenStatus) {
        $pdo->rollBack();
        response_error(400, 'Suspect has unexpected status: ' . $curStatus);
    }

    // 5) Вскрываем
    $stmt = $pdo->prepare("
        UPDATE suspects_in_game
        SET status = :st::status_enum
        WHERE gameid = :gid AND susid = :sid
    ");
    $stmt->execute([':st' => $openedStatus, ':gid' => $gameId, ':sid' => $susId]);

    // 6) Закрываем действие
    $stmt = $pdo->prepare("DELETE FROM moves WHERE playerid = :pid");
    $stmt->execute([':pid' => $playerId]);

    // 7) Переключаем очередь
    $nextSeat = ($currentSeat >= $seatCount) ? 1 : ($currentSeat + 1);
    $stmt = $pdo->prepare("
        UPDATE games
        SET current_seat = :ns,
            turn_started_at = NOW()
        WHERE gameid = :gid
    ");
    $stmt->execute([':ns' => $nextSeat, ':gid' => $gameId]);

    // 8) Получаем подсказки для открытого подозреваемого
    $stmt = $pdo->prepare("
        SELECT c.item_name
        FROM suspect_clues_in_game scig
        JOIN clues c ON c.clueid = scig.clueid
        WHERE scig.gameid = :gid AND scig.susid = :sid
        ORDER BY c.item_name
    ");
    $stmt->execute([':gid' => $gameId, ':sid' => $susId]);
    $hints = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

    $pdo->commit();

    $response = [
        'ok' => true,
        'opened_suspect' => (string)$srow['susname'],
        'status' => $openedStatus,
        'next_seat' => $nextSeat
    ];
    
    // Добавляем подсказки в ответ
    if (!empty($hints)) {
        $response['hints'] = array_map('strval', $hints);
    }

    response_json($response);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    response_error(500, 'Server error: ' . $e->getMessage());
}
