<?php
/*
 create_player.php
 -----------------
 Регистрация нового игрока (аккаунта).

 POST /API/create_player.php
 JSON:
 {
   "login": "user1",
   "password": "123456"
 }

 Правила:
 - login и password обязательны
 - login 3..30 символов, только буквы/цифры/подчёркивание
 - password 4..50 символов
 - логин должен быть уникален
 - пароль храним в виде md5-хэша (в рамках учебного проекта)
 - сразу выдаём auth token и сохраняем его в users.token
*/

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

try {
    $pdo  = db();
    $data = json_input();

    $login    = trim($data['login'] ?? '');
    $password = (string)($data['password'] ?? '');

    if ($login === '' || $password === '') {
        response_error(400, 'login and password are required');
    }

    // Валидация login
    $loginLen = mb_strlen($login, 'UTF-8');
    if ($loginLen < 3 || $loginLen > 30) {
        response_error(400, 'login length must be between 3 and 30 characters');
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/u', $login)) {
        response_error(400, 'login may contain only letters, digits and underscore');
    }

    // Валидация password
    $passLen = mb_strlen($password, 'UTF-8');
    if ($passLen < 4 || $passLen > 50) {
        response_error(400, 'password length must be between 4 and 50 characters');
    }

    // Проверка уникальности логина
    $stmt = $pdo->prepare("SELECT 1 FROM users WHERE login = :login LIMIT 1");
    $stmt->execute([':login' => $login]);
    if ($stmt->fetchColumn()) {
        response_error(409, 'User with this login already exists');
    }

    // Хэш пароля (md5, чтобы влезть в VARCHAR(50) в учебной схеме)
    $passwordHash = md5($password);

    // Генерируем токен и сохраняем сразу
    $token = make_token($login);
    
    // Убеждаемся, что токен не пустой
    if (empty($token)) {
        response_error(500, 'Failed to generate token');
    }

    $stmt = $pdo->prepare("
        INSERT INTO users (login, password, token)
        VALUES (:login, :password, :token)
    ");
    $result = $stmt->execute([
        ':login'    => $login,
        ':password' => $passwordHash,
        ':token'    => $token
    ]);
    
    // Проверяем, что запись создана успешно
    if (!$result) {
        response_error(500, 'Failed to create user');
    }

    response_json([
        'ok'    => true,
        'login' => $login,
        'token' => $token
    ]);

} catch (Throwable $e) {
    response_error(500, 'Server error: ' . $e->getMessage());
}

