<?php
/*
 db.php
 --------
 Этот файл отвечает ТОЛЬКО за подключение к базе данных PostgreSQL.
 Мы выносим подключение в отдельный файл, чтобы не копировать его
 в каждом API-эндпоинте.
*/

function db(): PDO {
    // static — чтобы подключение создавалось один раз
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    // ДАННЫЕ ДЛЯ ПОДКЛЮЧЕНИЯ К БД
    $host = 'helios.cs.ifmo.ru';      // где запущена БД
    $port = '5432';           // стандартный порт PostgreSQL
    $dbname = 'studs';     // имя базы данных
    $user = 's368719';        // твой логин
    $password = 'lNZG7hGdhDY2zlCH'; // пароль от БД

    // Строка подключения
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";

    // Создаём объект PDO — стандартный способ работы с БД в PHP
    $pdo = new PDO($dsn, $user, $password, [
        // Если ошибка — выбрасываем исключение
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        // Результаты SELECT будут массивами
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // ВАЖНО: указываем схему, в которой лежат наши таблицы
    $pdo->exec("SET search_path TO s368719");

    return $pdo;
}
