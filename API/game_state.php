<?php
/*
 game_state.php
 --------------
 GET  /API/game_state.php?game_id=5
 POST /API/game_state.php   JSON { "game_id": 5 }

 ЕДИНСТВЕННЫЙ ИСТОЧНИК ПРАВДЫ.

 Отдаём:
 - game: game_id, turntime, seatcount, players_now, started, current_seat, game_over, result
 - fox: foxpos
 - players: игроки + координаты
 - clues: подсказки + координаты + статус + fox_has_item (только если подсказка вскрыта)
 - suspects: подозреваемые + статус
 - pending_actions: по игрокам -> direction + result

ВАЖНО: foxes.id == game_id
*/

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

try {
    $pdo = db();

    $gameId = 0;
    if (isset($_GET['game_id'])) {
        $gameId = (int)$_GET['game_id'];
    } else {
        $data = json_input();
        $gameId = (int)($data['game_id'] ?? 0);
    }

    if ($gameId <= 0) {
        response_error(400, 'game_id is required');
    }

    // Удаляем игру, если с момента начала прошло 2+ часа (в т.ч. при полном стаке)
    $stmt = $pdo->prepare("
        SELECT 1 FROM games
        WHERE gameid = :gid
          AND turn_started_at IS NOT NULL
          AND turn_started_at < NOW() - INTERVAL '2 hours'
    ");
    $stmt->execute([':gid' => $gameId]);
    if ($stmt->fetchColumn()) {
        $pdo->beginTransaction();
        try {
            delete_game_cascade($pdo, $gameId);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        response_error(404, 'Game not found');
    }

    // Авто-скип хода, если таймер истёк (перед тем как отдавать состояние)
    auto_advance_turn_if_timeout($pdo, $gameId);

    // game (после возможного авто-скипа)
    $stmt = $pdo->prepare("
        SELECT
            gameid,
            turntime,
            seatcount,
            current_seat,
            turn_started_at,
            turntime
        FROM games
        WHERE gameid = :gid
    ");
    $stmt->execute([':gid' => $gameId]);
    $game = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$game) {
        response_error(404, 'Game not found');
    }

    // fox (foxes.id == game_id)
    $stmt = $pdo->prepare("SELECT foxpos, susid FROM foxes WHERE id = :gid LIMIT 1");
    $stmt->execute([':gid' => $gameId]);
    $foxRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$foxRow) {
        response_error(500, 'Fox row not found for this game');
    }

    $foxpos = (int)$foxRow['foxpos'];
    $foxSusId = ($foxRow['susid'] === null) ? 0 : (int)$foxRow['susid'];

    // game_over + result
    $gameOver = ($foxpos >= 37) || ($foxpos < 0);

    // null | 'win' | 'lose'
    $gameResult = null;
    if ($foxpos === -2) $gameResult = 'win';
    if ($foxpos === -1) $gameResult = 'lose';
    if ($foxpos >= 37) $gameResult = 'lose'; // Лис убежал - все проиграли

    // players
    $stmt = $pdo->prepare("
        SELECT
            p.playerid,
            p.login,
            p.seatnumber,
            p.color,
            c.y,
            c.x
        FROM players p
        JOIN cells c ON c.cellid = p.cellid
        WHERE c.gameid = :gid
        ORDER BY p.seatnumber
    ");
    $stmt->execute([':gid' => $gameId]);
    $players = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $playersNow = is_array($players) ? count($players) : 0;
    $seatCount = (int)$game['seatcount'];
    $started = ($playersNow >= $seatCount);

    // время
    $startedAtRaw = $game['turn_started_at'] ?? null;
    $startedAtTs = $startedAtRaw ? strtotime($startedAtRaw) : null;
    $timeLimitSec = isset($game['turntime']) ? (int)$game['turntime'] : 0;

    $timeRemainingSec = null;
    if ($startedAtTs && $timeLimitSec > 0) {
        $elapsed = time() - $startedAtTs;
        $timeRemainingSec = max(0, $timeLimitSec - $elapsed);
    }

    // clues (берём clueid, чтобы сравнить с clues_and_suspects)
    $stmt = $pdo->prepare("
        SELECT
            c.y,
            c.x,
            cl.clueid,
            cl.item_name,
            cig.status::text AS status
        FROM clues_in_game cig
        JOIN cells c ON c.cellid = cig.cellid
        JOIN clues cl ON cl.clueid = cig.clueid
        WHERE c.gameid = :gid
        ORDER BY c.y, c.x
    ");
    $stmt->execute([':gid' => $gameId]);
    $clueRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // получаем все clueid, которые есть у лиса (одним запросом)
    // Используем suspect_clues_in_game для конкретной игры, а не глобальную clues_and_suspects
    $foxClueSet = [];
    if ($foxSusId > 0) {
        $stmt = $pdo->prepare("
            SELECT clueid
            FROM suspect_clues_in_game
            WHERE gameid = :gid AND susid = :sid
        ");
        $stmt->execute([':gid' => $gameId, ':sid' => $foxSusId]);
        $foxClueIds = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

        foreach ($foxClueIds as $cid) {
            $foxClueSet[(int)$cid] = true;
        }
    }

    // Собираем clues + fox_has_item
    $clues = [];
    foreach ($clueRows as $r) {
        $cid = (int)$r['clueid'];
        $status = (string)$r['status'];

        $opened = ($status === 'вскрыт' || $status === 'opened');
        $foxHasItem = null;
        if ($opened) {
            $foxHasItem = isset($foxClueSet[$cid]); // true/false
        }

        $clues[] = [
            'y' => (int)$r['y'],
            'x' => (int)$r['x'],
            'item_name' => (string)$r['item_name'],
            'status' => $status,
            'fox_has_item' => $foxHasItem
        ];
    }

    // suspects (с подсказками для открытых подозреваемых)
    // Проверяем, есть ли поле image_path в таблице suspects
    $hasImagePath = false;
    try {
        $checkStmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'suspects' AND column_name = 'image_path'");
        $hasImagePath = $checkStmt->rowCount() > 0;
    } catch (Exception $e) {
        // Игнорируем ошибку, если таблицы нет или нет доступа
    }
    
    $selectFields = "s.susname, sig.status::text AS status, sig.susid";
    if ($hasImagePath) {
        $selectFields .= ", s.image_path";
    }
    
    $stmt = $pdo->prepare("
        SELECT
            $selectFields
        FROM suspects_in_game sig
        JOIN suspects s ON s.susid = sig.susid
        WHERE sig.gameid = :gid
        ORDER BY s.susid
    ");
    $stmt->execute([':gid' => $gameId]);
    $suspectRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Формируем массив suspects с подсказками только для открытых
    $suspects = [];
    foreach ($suspectRows as $row) {
        $status = (string)$row['status'];
        $opened = ($status === 'вскрыт' || $status === 'opened');
        $susId = (int)$row['susid'];
        
        $suspect = [
            'susname' => (string)$row['susname'],
            'status' => $status
        ];
        
        // Добавляем image_path, если есть
        if ($hasImagePath && isset($row['image_path']) && !empty($row['image_path'])) {
            $suspect['image_path'] = (string)$row['image_path'];
        }
        
        // Добавляем подсказки только если подозреваемый открыт
        if ($opened) {
            $stmtHints = $pdo->prepare("
                SELECT c.item_name
                FROM suspect_clues_in_game scig
                JOIN clues c ON c.clueid = scig.clueid
                WHERE scig.gameid = :gid AND scig.susid = :sid
                ORDER BY c.item_name
            ");
            $stmtHints->execute([':gid' => $gameId, ':sid' => $susId]);
            $hints = $stmtHints->fetchAll(PDO::FETCH_COLUMN, 0);
            
            if (!empty($hints)) {
                $suspect['hints'] = array_map('strval', $hints);
            }
        }
        
        $suspects[] = $suspect;
    }

    // pending_actions (moves) — включаем max_steps для режима движения
    $stmt = $pdo->prepare("
        SELECT
            p.login,
            p.seatnumber,
            m.direction::text AS direction,
            m.result,
            m.max_steps
        FROM players p
        JOIN cells c ON c.cellid = p.cellid
        LEFT JOIN moves m ON m.playerid = p.playerid
        WHERE c.gameid = :gid
        ORDER BY p.seatnumber
    ");
    $stmt->execute([':gid' => $gameId]);
    $pending = $stmt->fetchAll(PDO::FETCH_ASSOC);

    response_json([
        'ok' => true,
        'game' => [
            'game_id' => (int)$game['gameid'],
            'turntime' => (int)$game['turntime'],
            'seatcount' => (int)$game['seatcount'],
            'players_now' => $playersNow,
            'started' => $started,
            'current_seat' => (int)$game['current_seat'],
            'game_over' => $gameOver,
            'result' => $gameResult, // null | win | lose
            'timeRemainingSec' => $timeRemainingSec,
        ],
        'fox' => ['foxpos' => $foxpos],
        'players' => $players,
        'clues' => $clues,
        'suspects' => $suspects,
        'pending_actions' => $pending
    ]);

} catch (Throwable $e) {
    response_error(500, 'Server error: ' . $e->getMessage());
}
