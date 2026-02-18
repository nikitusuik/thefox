<?php
/*
 accuse.php
 ----------
 FINAL ACTION for everyone.

 POST JSON:
 {
   "game_id": 4,
   "login": "test",
   "suspect_name": "Daisy"
 }

 RULES (твои, 100%):
 - если угадал лиса -> ВСЕ ВЫИГРАЛИ -> foxpos = -2
 - если НЕ угадал -> ВСЕ ПРОИГРАЛИ -> foxpos = -1
 - игра должна считаться завершённой для всех клиентов через game_state
*/

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

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

    // ===== BLOCK A: validate player in game =====
    $stmt = $pdo->prepare("
        SELECT p.playerid
        FROM players p
        JOIN cells c ON c.cellid = p.cellid
        WHERE p.login = :login AND c.gameid = :gid
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([':login' => $login, ':gid' => $gameId]);
    $playerId = (int)$stmt->fetchColumn();

    if ($playerId <= 0) {
        $pdo->rollBack();
        response_error(404, 'Player not found in this game');
    }

    // ===== BLOCK B: accused suspect id =====
    $stmt = $pdo->prepare("
        SELECT susid
        FROM suspects
        WHERE susname = :name
        LIMIT 1
    ");
    $stmt->execute([':name' => $susName]);
    $accusedSusId = (int)$stmt->fetchColumn();

    if ($accusedSusId <= 0) {
        $pdo->rollBack();
        response_error(404, 'Suspect not found');
    }

    // ===== BLOCK C: load real fox + lock fox row =====
    // ВАЖНО: у тебя foxes.id == game_id
    $stmt = $pdo->prepare("
        SELECT susid, foxpos
        FROM foxes
        WHERE id = :gid
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute([':gid' => $gameId]);
    $foxRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$foxRow) {
        $pdo->rollBack();
        response_error(400, 'Fox row not found for this game');
    }

    $realFoxSusId = (int)($foxRow['susid'] ?? 0);
    $foxpos = (int)($foxRow['foxpos'] ?? 0);

    if ($realFoxSusId <= 0) {
        $pdo->rollBack();
        response_error(400, 'Fox suspect is not set (foxes.susid is NULL)');
    }

    // ===== BLOCK D: if game already ended - do not change =====
    // (защита от двойных акьюзов после конца)
    if ($foxpos < 0) {
        $pdo->commit();
        response_json([
            'ok' => true,
            'accused' => $susName,
            'win' => ($foxpos === -2),
            'lose' => ($foxpos === -1),
            'already_ended' => true,
            'fox' => ['foxpos' => $foxpos]
        ]);
    }

    // ===== BLOCK E: FINAL DECISION (your strict rules) =====
    $win = ($accusedSusId === $realFoxSusId);

    // win => -2, else => -1
    $finalFoxPos = $win ? -2 : -1;

    $stmt = $pdo->prepare("
        UPDATE foxes
        SET foxpos = :fp
        WHERE id = :gid
        RETURNING foxpos
    ");
    $stmt->execute([':fp' => $finalFoxPos, ':gid' => $gameId]);
    $foxpos = (int)$stmt->fetchColumn();

    // ===== BLOCK F: optional cleanup (чтобы никто не завис в moves) =====
    // Удаляем pending moves у всех игроков этой игры
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

    $pdo->commit();

    response_json([
        'ok' => true,
        'accused' => $susName,
        'win' => $win,
        'lose' => !$win,
        'fox' => [
            'moved' => false,
            'foxpos' => $foxpos
        ]
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    response_error(500, 'Server error: ' . $e->getMessage());
}
