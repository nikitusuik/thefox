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
    // - Игры с 0 игроков удаляем только если игра уже начиналась (все вышли) — иначе только что созданная комната удалится до join создателя
    // - Игры с 1 игроком удаляем если прошло более 30 минут и игра началась
    // - Игры (в т.ч. с полным составом) удаляем, если прошло 2+ часа с момента начала (turn_started_at)
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
            WHERE (COALESCE(f.foxpos, 0) >= 0 AND COALESCE(f.foxpos, 0) < 37)
              AND (
                  -- Вариант 1: 0 игроков — только если игра уже начиналась (turn_started_at был), иначе не трогаем (создатель ещё не за join'ился)
                  (COALESCE(pc.players_now, 0) = 0 AND g.turn_started_at IS NOT NULL)
                  OR
                  -- Вариант 2: 1 игрок, игра началась, прошло 30+ минут
                  (COALESCE(pc.players_now, 0) = 1 AND g.turn_started_at IS NOT NULL AND g.turn_started_at < NOW() - INTERVAL '30 minutes')
                  OR
                  -- Вариант 3: С момента начала прошло 2+ часа (даже при полном стаке)
                  (g.turn_started_at IS NOT NULL AND g.turn_started_at < NOW() - INTERVAL '2 hours')
              )
        )
        SELECT gameid FROM games_to_delete
    ");
    
    $gamesToDelete = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Логируем для отладки (можно убрать в продакшене)
    error_log("Games to delete: " . count($gamesToDelete) . " - IDs: " . implode(', ', $gamesToDelete));
    
    if (!empty($gamesToDelete)) {
        foreach ($gamesToDelete as $gameId) {
            delete_game_cascade($pdo, (int)$gameId);
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
