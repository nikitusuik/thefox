<?php
/*
 move.php
 --------
 Перемещение игрока ПОСЛЕ choose_action(direction=clue).
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
 * Игра начата, когда players_now == seatcount
 * (без изменения БД, считаем через players+cells).
 */



try {
    $pdo = db();
    $data = json_input();

    $gameId   = (int)($data['game_id'] ?? 0);
    $login    = trim($data['login'] ?? '');
    $toY      = (int)($data['to_y'] ?? 0);
    $toX      = (int)($data['to_x'] ?? 0);

    if ($gameId <= 0 || $login === '' || $toY <= 0 || $toX <= 0) {
        response_error(400, 'game_id, login, to_y, to_x are required');
    }

    // Проверяем токен игрока
    require_auth($pdo, $login);

    $pdo->beginTransaction();

    ensure_game_not_over($pdo, $gameId);
    ensure_game_started($pdo, $gameId); // NEW

    $clueEnum     = get_enum_value($pdo, 'direction_enum', ['clue', 'подсказка']);
    $openedStatus = get_enum_value($pdo, 'status_enum', ['вскрыт', 'opened']);

    // 1) Игрок + очередь + текущая клетка
    $stmt = $pdo->prepare("
        SELECT p.playerid,
               p.seatnumber,
               g.current_seat,
               g.seatcount,
               c.y AS from_y,
               c.x AS from_x
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
    $fromY       = (int)$row['from_y'];
    $fromX       = (int)$row['from_x'];

    if ($seatNumber !== $currentSeat) {
        $pdo->rollBack();
        response_error(400, "Not your turn. Current seat: $currentSeat");
    }

    // 2) Проверяем moves
    $stmt = $pdo->prepare("
        SELECT direction::text AS dir_text, result, max_steps
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

    if ((string)$m['dir_text'] !== (string)$clueEnum) {
        $pdo->rollBack();
        response_error(400, 'Pending action is not clue');
    }

    $maxSteps = isset($m['max_steps']) ? (int)$m['max_steps'] : 0;
    if ($maxSteps <= 0) {
        $pdo->rollBack();
        response_error(500, 'Server error: invalid max_steps for pending move');
    }

    // Если fail — закрываем ход и двигаем очередь
    if (!$m['result']) {
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
        response_error(400, 'Pending clue action is failed (result=false)');
    }

    // 3) Манхэттен
    $distance = abs($toY - $fromY) + abs($toX - $fromX);
    if ($distance > $maxSteps) {
        $pdo->rollBack();
        response_error(400, 'Target cell is too far');
    }

    // 4) Целевая клетка
    $stmt = $pdo->prepare("
        SELECT cellid
        FROM cells
        WHERE gameid = :gid AND y = :y AND x = :x
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([':gid' => $gameId, ':y' => $toY, ':x' => $toX]);
    $toCellId = (int)$stmt->fetchColumn();

    if ($toCellId <= 0) {
        $pdo->rollBack();
        response_error(400, 'Target cell does not exist');
    }

    // 5) Перемещаем игрока
    $stmt = $pdo->prepare("UPDATE players SET cellid = :cid WHERE playerid = :pid");
    $stmt->execute([':cid' => $toCellId, ':pid' => $playerId]);

    // 6) Вскрываем подсказку если есть
    $openedClue = null;
    $stmt = $pdo->prepare("
        SELECT cig.clueid, cl.item_name, cig.status::text AS st
        FROM clues_in_game cig
        JOIN clues cl ON cl.clueid = cig.clueid
        WHERE cig.cellid = :cellid
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([':cellid' => $toCellId]);
    $clue = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($clue) {
        if ((string)$clue['st'] !== (string)$openedStatus) {
            $stmt = $pdo->prepare("
                UPDATE clues_in_game
                SET status = :st::status_enum
                WHERE cellid = :cellid AND clueid = :clueid
            ");
            $stmt->execute([
                ':st' => $openedStatus,
                ':cellid' => $toCellId,
                ':clueid' => (int)$clue['clueid']
            ]);
        }
        $openedClue = (string)$clue['item_name'];
    }

    // 7) Закрываем действие
    $stmt = $pdo->prepare("DELETE FROM moves WHERE playerid = :pid");
    $stmt->execute([':pid' => $playerId]);

    // 8) Переключаем очередь
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
        'from' => ['y' => $fromY, 'x' => $fromX],
        'to' => ['y' => $toY, 'x' => $toX],
        'distance' => $distance,
        'max_steps' => $maxSteps,
        'opened_clue' => $openedClue,
        'next_seat' => $nextSeat
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    response_error(500, 'Server error: ' . $e->getMessage());
}
