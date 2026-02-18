<?php
/*
 leave_game.php
 --------------
 Позволяет игроку выйти из игры.

 POST form-urlencoded:
 {
   "game_id": 4,
   "login": "test"
 }

 Правила:
 - Удаляет игрока из players
 - Удаляет связанные moves
 - Игра остается, если в ней есть другие игроки
*/

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

    // Проверяем токен игрока
    require_auth($pdo, $login);

    // Получаем точный логин из БД
    $stmt = $pdo->prepare("SELECT login FROM users WHERE LOWER(TRIM(login)) = LOWER(:login) LIMIT 1");
    $stmt->execute([':login' => $login]);
    $dbLogin = $stmt->fetchColumn();
    
    if (!$dbLogin) {
        response_error(404, 'User not found');
    }
    
    $login = trim((string)$dbLogin);

    $pdo->beginTransaction();

    // Находим игрока в игре
    $stmt = $pdo->prepare("
        SELECT p.playerid, p.seatnumber, c.gameid
        FROM players p
        JOIN cells c ON c.cellid = p.cellid
        WHERE p.login = :login AND c.gameid = :gid
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([':login' => $login, ':gid' => $gameId]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$player) {
        $pdo->rollBack();
        response_json([
            'ok' => true,
            'message' => 'Player not in game (already left or never joined)'
        ]);
        exit;
    }

    $playerId = (int)$player['playerid'];
    $seatNumber = (int)$player['seatnumber'];

    // Удаляем pending moves игрока
    $stmt = $pdo->prepare("DELETE FROM moves WHERE playerid = :pid");
    $stmt->execute([':pid' => $playerId]);

    // Удаляем игрока из players
    $stmt = $pdo->prepare("DELETE FROM players WHERE playerid = :pid");
    $stmt->execute([':pid' => $playerId]);

    // Проверяем, остались ли игроки в игре
    $stmt = $pdo->prepare("
        SELECT COUNT(*)::int
        FROM players p
        JOIN cells c ON c.cellid = p.cellid
        WHERE c.gameid = :gid
    ");
    $stmt->execute([':gid' => $gameId]);
    $remainingPlayers = (int)$stmt->fetchColumn();

    // Если игра не началась (turn_started_at IS NULL) и игроков не осталось, удаляем игру
    $stmt = $pdo->prepare("SELECT turn_started_at FROM games WHERE gameid = :gid FOR UPDATE");
    $stmt->execute([':gid' => $gameId]);
    $game = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($game && $game['turn_started_at'] === null && $remainingPlayers === 0) {
        // Удаляем всю игру, если она не началась и игроков не осталось
        // Удаляем moves (уже удалили выше, но на всякий случай)
        $stmt = $pdo->prepare("
            DELETE FROM moves
            WHERE playerid IN (
                SELECT p.playerid
                FROM players p
                JOIN cells c ON c.cellid = p.cellid
                WHERE c.gameid = :gid
            )
        ");
        $stmt->execute([':gid' => $gameId]);
        
        // Удаляем players (уже удалили выше)
        $stmt = $pdo->prepare("
            DELETE FROM players
            WHERE cellid IN (SELECT cellid FROM cells WHERE gameid = :gid)
        ");
        $stmt->execute([':gid' => $gameId]);
        
        // Удаляем clues_in_game
        $stmt = $pdo->prepare("DELETE FROM clues_in_game WHERE cellid IN (SELECT cellid FROM cells WHERE gameid = :gid)");
        $stmt->execute([':gid' => $gameId]);
        
        // Удаляем suspect_clues_in_game
        $stmt = $pdo->prepare("DELETE FROM suspect_clues_in_game WHERE gameid = :gid");
        $stmt->execute([':gid' => $gameId]);
        
        // Удаляем suspects_in_game
        $stmt = $pdo->prepare("DELETE FROM suspects_in_game WHERE gameid = :gid");
        $stmt->execute([':gid' => $gameId]);
        
        // Удаляем cells
        $stmt = $pdo->prepare("DELETE FROM cells WHERE gameid = :gid");
        $stmt->execute([':gid' => $gameId]);
        
        // Удаляем foxes
        $stmt = $pdo->prepare("DELETE FROM foxes WHERE id = :gid");
        $stmt->execute([':gid' => $gameId]);
        
        // Удаляем game
        $stmt = $pdo->prepare("DELETE FROM games WHERE gameid = :gid");
        $stmt->execute([':gid' => $gameId]);
    }

    $pdo->commit();

    response_json([
        'ok' => true,
        'message' => 'Left game successfully',
        'game_deleted' => ($game && $game['turn_started_at'] === null && $remainingPlayers === 0)
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error in leave_game.php: " . $e->getMessage());
    response_error(500, 'Server error: ' . $e->getMessage());
}
