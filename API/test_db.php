<?php
/*
 test_db.php
 ------------
 Проверка подключения PHP к PostgreSQL.
 Если этот файл работает — значит db.php настроен правильно.
*/

require_once __DIR__ . '/db.php';

try {
    $pdo = db();

    // Простейший запрос к БД
    $stmt = $pdo->query('SELECT 1 AS ok');
    $row = $stmt->fetch();

    echo 'DB CONNECTED. Result = ' . $row['ok'];
} catch (Throwable $e) {
    echo 'DB ERROR: ' . $e->getMessage();
}
