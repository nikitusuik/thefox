<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
/*
 auth_login.php
 ---------------
 Этот файл отвечает за АВТОРИЗАЦИЮ пользователя.

 Пользователь отправляет логин и пароль,
 мы проверяем их в базе данных и,
 если всё верно, возвращаем токен.

 POST /api/auth_login.php
 JSON:
 {
   "login": "user1",
   "password": "123"
 }

 Ответ:
 {
   "ok": true,
   "token": "abcdef123456"
 }
*/

require_once __DIR__ . '/db.php';       // подключение к БД
require_once __DIR__ . '/helpers.php';  // функции для JSON и ошибок

// Читаем JSON из тела запроса
$data = json_input();

// Достаём логин и пароль
$login = trim($data['login'] ?? '');
$password = (string)($data['password'] ?? '');

// Проверяем, что данные вообще пришли
if ($login === '' || $password === '') {
    response_error(400, 'login and password are required');
}

try {
    // Получаем подключение к БД
    $pdo = db();

    // Ищем пользователя по логину без учёта регистра (чтобы один и тот же пользователь всегда матчился)
    $stmt = $pdo->prepare(
        'SELECT login, password FROM users WHERE LOWER(TRIM(login)) = LOWER(:login) LIMIT 1'
    );
    $stmt->execute(['login' => $login]);

    // Получаем строку пользователя
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Если пользователя нет или пароль не совпадает — ошибка
    $passwordHash = md5($password);
    if (!$user || $user['password'] !== $passwordHash) {
        response_error(401, 'Invalid login or password');
    }

    // Используем логин из БД, чтобы токен сохранялся в ту же строку
    $loginFromDb = trim((string)$user['login']);

    // Генерируем токен авторизации и сохраняем его в users.token
    $token = make_token($loginFromDb);
    
    // Убеждаемся, что токен не пустой
    if (empty($token)) {
        response_error(500, 'Failed to generate token');
    }

    $stmt = $pdo->prepare('UPDATE users SET token = :token WHERE login = :login');
    $result = $stmt->execute([':token' => $token, ':login' => $loginFromDb]);
    
    // Проверяем, что токен действительно сохранился
    if (!$result) {
        response_error(500, 'Failed to save token');
    }

    // Проверяем, есть ли пользователь уже в какой-то игре (игра не завершена: foxpos 0..32)
    $gameId = null;
    $stmt = $pdo->prepare("
        SELECT c.gameid
        FROM players p
        JOIN cells c ON c.cellid = p.cellid
        LEFT JOIN foxes f ON f.id = c.gameid
        WHERE p.login = :login
          AND (COALESCE(f.foxpos, 0) >= 0 AND COALESCE(f.foxpos, 0) < 37)
        LIMIT 1
    ");
    $stmt->execute([':login' => $loginFromDb]);
    $row = $stmt->fetch(PDO::FETCH_COLUMN, 0);
    if ($row !== false) {
        $gameId = (int) $row;
    }

    $response = [
        'ok' => true,
        'token' => $token
    ];
    if ($gameId !== null) {
        $response['game_id'] = $gameId;
    }
    response_json($response);

} catch (Throwable $e) {
    response_error(500, 'Server error: ' . $e->getMessage());
}


