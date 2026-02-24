<?php
/*
 cleanup_old_games.php — одноразовая очистка
 ------------------------------------------
 1) Удаляет все игры, где turn_started_at IS NULL (никогда не стартовали).
 2) Завершает все игры, начавшиеся не сегодня (foxpos = 37), чтобы не висели в лобби.

 Вызвать один раз: GET или POST /API/cleanup_old_games.php
*/

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = db();
    $pdo->beginTransaction();

    // 1) Игры, которые начались не сегодня — завершаем (лис убежал)
    $stmt = $pdo->query("
        SELECT g.gameid
        FROM games g
        JOIN foxes f ON f.id = g.gameid
        WHERE g.turn_started_at IS NOT NULL
          AND g.turn_started_at::date < CURRENT_DATE
          AND f.foxpos >= 0 AND f.foxpos < 37
    ");
    $toEnd = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $ended = 0;
    foreach ($toEnd as $gid) {
        $stmt = $pdo->prepare("UPDATE foxes SET foxpos = 37 WHERE id = :gid");
        $stmt->execute([':gid' => $gid]);
        $ended++;
    }

    // 2) Игры с turn_started_at IS NULL — удаляем навсегда
    $stmt = $pdo->query("
        SELECT gameid FROM games WHERE turn_started_at IS NULL
    ");
    $toDelete = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $deleted = 0;
    foreach ($toDelete as $gid) {
        delete_game_cascade($pdo, (int)$gid);
        $deleted++;
    }

    $pdo->commit();

    response_json([
        'ok' => true,
        'ended' => $ended,
        'deleted' => $deleted,
        'message' => "Завершено игр (начались не сегодня): $ended. Удалено игр (без старта): $deleted."
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    response_error(500, 'Server error: ' . $e->getMessage());
}
