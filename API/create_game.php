<?php
/*
 create_game.php
 ----------------
 Создаёт новую игру (комнату) и полностью инициализирует всё для игры:

 1) games — создаём запись о новой игре (seatcount, turntime)
 2) foxes — создаём лиса для этой игры:
      - foxpos = 0
      - susid = случайный suspect из таблицы suspects (КТО ИМЕННО ЛИС В ЭТОЙ ИГРЕ)
 3) cells — создаём поле 18x18 (324 клетки)
 4) suspects_in_game — добавляем 16 подозреваемых в игру со статусом "скрыт"
 5) clues_in_game — кладём 12 подсказок на случайные клетки, но:
     - НЕ кладём в центр 2x2 (y=9..10, x=9..10)
     - НЕ кладём 2 подсказки на одну клетку

 Возвращает:
 { "ok": true, "game_id": 1 }
*/

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

try {
    $pdo = db();
    $pdo->beginTransaction();

    // 1) Параметры игры из запроса
    $data = json_input();

    // turntime ОБЯЗАТЕЛЕН: клиент выбирает 20..60 кратно 10
    if (!isset($data['turntime'])) {
        response_error(400, 'turntime is required (20..60, step 10)');
    }

    $turntime = (int)$data['turntime'];
    if ($turntime < 20 || $turntime > 60 || $turntime % 10 !== 0) {
        response_error(400, 'turntime must be between 20 and 60 and divisible by 10');
    }

    // seatcount: игра может быть на 2, 3 или 4 игроков
    if (!isset($data['seatcount'])) {
        response_error(400, 'seatcount is required (2, 3 or 4)');
    }

    $seatcount = (int)$data['seatcount'];
    if (!in_array($seatcount, [2, 3, 4], true)) {
        response_error(400, 'seatcount must be 2, 3 or 4');
    }

    $stmt = $pdo->prepare("
        INSERT INTO games (turntime, seatcount, current_seat)
        VALUES (:turntime, :seatcount, 0)
        RETURNING gameid
    ");

    $stmt->execute([
        ':turntime'  => $turntime,
        ':seatcount' => $seatcount
    ]);

    $gameId = (int)$stmt->fetchColumn();
    if ($gameId <= 0) {
        response_error(500, 'Failed to create game');
    }

    // 2) Создаём запись в foxes для этой игры:
    //    - foxpos = 0 (лиса ещё не двигалась)
    //    - susid  = случайный подозреваемый из suspects
    $stmt = $pdo->query("
        SELECT susid
        FROM suspects
        ORDER BY random()
        LIMIT 1
    ");
    $foxSusId = (int)$stmt->fetchColumn();

    if ($foxSusId <= 0) {
        response_error(500, 'No suspects found to create fox');
    }

    $stmt = $pdo->prepare("
        INSERT INTO foxes (id, foxpos, susid)
        VALUES (:gid, 0, :sid)
    ");
    $stmt->execute([
        ':gid' => $gameId,
        ':sid' => $foxSusId
    ]);

    // 2) Подготавливаем значения enum status_enum (нам нужен "скрыт")
    $enumRows = $pdo->query("SELECT unnest(enum_range(NULL::status_enum)) AS v")->fetchAll();

    $statusHidden = null;
    foreach ($enumRows as $r) {
        if ($r['v'] === 'скрыт' || $r['v'] === 'hidden') {
            $statusHidden = $r['v'];
            break;
        }
    }
    if ($statusHidden === null) {
        // если вдруг enum отличается, берём первое значение как "скрыт"
        $statusHidden = $enumRows[0]['v'];
    }

    // 3) Создаём поле 18x18 (324 клетки)
    $stmt = $pdo->prepare("
        INSERT INTO cells (gameid, y, x)
        SELECT :gid, y, x
        FROM generate_series(1,18) AS y
        CROSS JOIN generate_series(1,18) AS x
    ");
    $stmt->execute([':gid' => $gameId]);


    // 5) Добавляем ВСЕХ подозреваемых в игру со статусом "скрыт"
    $stmt = $pdo->prepare("
        INSERT INTO suspects_in_game (susid, gameid, status)
        SELECT susid, :gid, :st::status_enum
        FROM suspects
    ");
    $stmt->execute([
        ':gid' => $gameId,
        ':st'  => $statusHidden
    ]);

    // 5.5) Выбираем 12 случайных подсказок для этой игры
    // Эти подсказки будут и на поле, и связаны с подозреваемыми
    $stmt = $pdo->prepare("
        SELECT clueid
        FROM clues
        ORDER BY random()
        LIMIT 12
    ");
    $stmt->execute();
    $selectedClueIds = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    
    if (count($selectedClueIds) < 12) {
        $pdo->rollBack();
        response_error(500, 'Not enough clues in database (need at least 12)');
    }

    // 5.6) Назначаем каждому подозреваемому по 3 подсказки из выбранных 12
    // Каждая из 12 подсказок будет связана минимум с одним подозреваемым
    $stmt = $pdo->prepare("
        SELECT susid
        FROM suspects_in_game
        WHERE gameid = :gid
        ORDER BY susid
    ");
    $stmt->execute([':gid' => $gameId]);
    $suspects = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

    // Перемешиваем подсказки для случайного распределения
    shuffle($selectedClueIds);
    
    // Распределяем 12 подсказок между подозреваемыми
    // Сначала гарантируем, что каждая из 12 подсказок попадет хотя бы одному подозреваемому
    // Затем дополняем до 3 подсказок на каждого подозреваемого
    $clueIndex = 0;
    $usedClues = []; // Отслеживаем, какие подсказки уже использованы
    
    // Первый проход: каждая подсказка назначается хотя бы одному подозреваемому
    foreach ($selectedClueIds as $clueId) {
        // Назначаем подсказку первому доступному подозреваемому
        $susId = $suspects[$clueIndex % count($suspects)];
        $usedClues[$susId][] = $clueId;
        
        $stmt = $pdo->prepare("
            INSERT INTO suspect_clues_in_game (gameid, susid, clueid)
            VALUES (:gid, :sid, :cid)
            ON CONFLICT (gameid, susid, clueid) DO NOTHING
        ");
        $stmt->execute([
            ':gid' => $gameId,
            ':sid' => $susId,
            ':cid' => $clueId
        ]);
        $clueIndex++;
    }
    
    // Второй проход: дополняем до 3 подсказок на каждого подозреваемого
    foreach ($suspects as $susId) {
        $currentCount = isset($usedClues[$susId]) ? count($usedClues[$susId]) : 0;
        $needed = 3 - $currentCount;
        
        if ($needed > 0) {
            // Берем случайные подсказки из выбранных 12
            shuffle($selectedClueIds);
            $added = 0;
            foreach ($selectedClueIds as $clueId) {
                if ($added >= $needed) break;
                
                // Проверяем, нет ли уже этой подсказки у этого подозреваемого
                if (!isset($usedClues[$susId]) || !in_array($clueId, $usedClues[$susId])) {
                    $stmt = $pdo->prepare("
                        INSERT INTO suspect_clues_in_game (gameid, susid, clueid)
                        VALUES (:gid, :sid, :cid)
                        ON CONFLICT (gameid, susid, clueid) DO NOTHING
                    ");
                    $stmt->execute([
                        ':gid' => $gameId,
                        ':sid' => $susId,
                        ':cid' => $clueId
                    ]);
                    
                    if (!isset($usedClues[$susId])) {
                        $usedClues[$susId] = [];
                    }
                    $usedClues[$susId][] = $clueId;
                    $added++;
                }
            }
        }
    }

    // 6) Кладём эти же 12 подсказок на поле в случайные места (НЕ центр 2x2)
    // Получаем случайные клетки
    $stmt = $pdo->prepare("
        SELECT cellid
        FROM cells
        WHERE gameid = :gid
        AND NOT (y IN (9,10) AND x IN (9,10))
        ORDER BY random()
        LIMIT 12
    ");
    $stmt->execute([':gid' => $gameId]);
    $selectedCells = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    
    // Перемешиваем подсказки для случайного распределения по клеткам
    shuffle($selectedClueIds);
    
    // Вставляем подсказки на клетки
    for ($i = 0; $i < min(12, count($selectedCells), count($selectedClueIds)); $i++) {
        $stmt = $pdo->prepare("
            INSERT INTO clues_in_game (cellid, clueid, status)
            VALUES (:cellid, :clueid, :st::status_enum)
        ");
        $stmt->execute([
            ':cellid' => $selectedCells[$i],
            ':clueid' => $selectedClueIds[$i],
            ':st' => $statusHidden
        ]);
    }

    $pdo->commit();

    response_json([
        'ok' => true,
        'game_id' => $gameId
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    response_error(500, 'Server error: ' . $e->getMessage());
}
