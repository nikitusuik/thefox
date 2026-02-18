<?php
/*
 list_games.php
 --------------
 Список комнат (игр), куда можно зайти.

 Правила:
 - игра может быть на 2, 3 или 4 игроков (games.seatcount)
 - game_over вычисляем по:
     foxpos >= 37  (лиса убежала)
     foxpos < 0    (финал через accuse: -2 win, -1 lose)
 - показываем только игры где game_over = false
 - показываем только игры где есть свободные места (players_now < seatcount)

 GET /API/list_games.php
*/

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

try {
    $pdo = db();

    // ===== BLOCK: Удаляем неактивные игры (<=1 участника более 30 минут) =====
    $pdo->beginTransaction();
    
    // Находим игры для удаления:
    // - Игры с 0 игроков удаляем сразу (независимо от того, начались они или нет)
    // - Игры с 1 игроком удаляем если прошло более 30 минут и игра началась
    $stmt = $pdo->query("
        WITH players_count AS (
            SELECT
                c.gameid AS game_id,
                COUNT(*)::int AS players_now
            FROM players p
            JOIN cells c ON c.cellid = p.cellid
            GROUP BY c.gameid
        ),
        games_to_delete AS (
            SELECT g.gameid
            FROM games g
            LEFT JOIN players_count pc ON pc.game_id = g.gameid
            LEFT JOIN foxes f ON f.id = g.gameid
            WHERE COALESCE(pc.players_now, 0) <= 1
              AND (COALESCE(f.foxpos, 0) >= 0 AND COALESCE(f.foxpos, 0) < 37)
              AND (
                  -- Вариант 1: Игра с 0 игроков - удаляем сразу
                  COALESCE(pc.players_now, 0) = 0
                  OR
                  -- Вариант 2: Игра началась (turn_started_at установлен) и прошло более 30 минут и остался 1 игрок
                  (g.turn_started_at IS NOT NULL AND g.turn_started_at < NOW() - INTERVAL '30 minutes' AND COALESCE(pc.players_now, 0) = 1)
              )
        )
        SELECT gameid FROM games_to_delete
    ");
    
    $gamesToDelete = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Логируем для отладки (можно убрать в продакшене)
    error_log("Games to delete: " . count($gamesToDelete) . " - IDs: " . implode(', ', $gamesToDelete));
    
    if (!empty($gamesToDelete)) {
        // Удаляем связанные данные в правильном порядке (из-за foreign keys)
        foreach ($gamesToDelete as $gameId) {
            // Удаляем moves
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
            
            // Удаляем players
            $stmt = $pdo->prepare("
                DELETE FROM players
                WHERE cellid IN (
                    SELECT cellid FROM cells WHERE gameid = :gid
                )
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
    }
    
    $pdo->commit();
    // ===== END BLOCK: Удаление неактивных игр =====

    // Получаем список активных игр
    $stmt = $pdo->query("
        WITH players_count AS (
            SELECT
                c.gameid AS game_id,
                COUNT(*)::int AS players_now
            FROM players p
            JOIN cells c ON c.cellid = p.cellid
            GROUP BY c.gameid
        ),
        players_list AS (
            SELECT
                c.gameid AS game_id,
                json_agg(p.login ORDER BY p.seatnumber) AS player_logins
            FROM players p
            JOIN cells c ON c.cellid = p.cellid
            GROUP BY c.gameid
        )
        SELECT
            g.gameid AS game_id,
            g.turntime,
            g.seatcount,
            COALESCE(pc.players_now, 0) AS players_now,
            (g.seatcount - COALESCE(pc.players_now, 0)) AS free_seats,
            COALESCE(f.foxpos, 0) AS foxpos,
            ((COALESCE(f.foxpos, 0) >= 37) OR (COALESCE(f.foxpos, 0) < 0)) AS game_over,
            COALESCE(pl.player_logins, '[]'::json) AS player_logins
        FROM games g
        LEFT JOIN players_count pc ON pc.game_id = g.gameid
        LEFT JOIN foxes f ON f.id = g.gameid
        LEFT JOIN players_list pl ON pl.game_id = g.gameid
        WHERE (COALESCE(f.foxpos, 0) >= 0 AND COALESCE(f.foxpos, 0) < 37)
          AND COALESCE(pc.players_now, 0) < g.seatcount
        ORDER BY g.gameid DESC
    ");

    $games = $stmt->fetchAll();

    response_json([
        'ok' => true,
        'games' => $games
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error in list_games.php: " . $e->getMessage());
    response_error(500, 'Server error: ' . $e->getMessage());
}
