# API «Коварный лис»

Бэкенд для настольной игры «Коварный лис»: PHP + PostgreSQL. Все ответы в формате JSON. CORS настроен в `helpers.php`.

**Базовый URL:** на проде фронт ходит на `https://se.ifmo.ru/~s368719/kovar/API`, 

---

## Общие правила

- **Авторизация:** для эндпоинтов, помеченных «с токеном», в заголовок запроса нужно добавлять `X-Auth-Token: <token>`. Токен выдаётся при логине или регистрации.
- **Тело запроса:** поддерживаются и **JSON** (`Content-Type: application/json`), и **form-urlencoded** (`application/x-www-form-urlencoded`). В описании ниже параметры перечислены как для JSON.
- **Ответ при ошибке:** `{ "ok": false, "error": "текст ошибки" }` с соответствующим HTTP-кодом (400, 401, 404, 500).

---

## Список эндпоинтов

### Без авторизации

| Метод | Файл | Описание |
|-------|------|----------|
| GET | `list_games.php` | Список комнат (игр) со свободными местами |
| GET | `game_state.php` | Состояние игры (единственный источник правды) |
| POST | `auth_login.php` | Вход: логин + пароль → токен |
| POST | `create_player.php` | Регистрация: логин + пароль → токен |
| POST | `create_game.php` | Создать игру (комнату) |

### С токеном (заголовок `X-Auth-Token`)

| Метод | Файл | Описание |
|-------|------|----------|
| POST | `join_game.php` | Войти в игру по `game_id` |
| POST | `choose_action.php` | Выбор действия: подсказка / подозреваемый (бросок кубиков) |
| POST | `move.php` | Ход на клетку после успешной «подсказки» |
| POST | `open_suspect.php` | Вскрыть карточку подозреваемого после успешного «подозреваемый» |
| POST | `accuse.php` | Обвинить подозреваемого (финал игры) |
| POST | `skip_turn.php` | Пропуск хода (по таймауту и т.п.) |
| POST | `leave_game.php` | Выйти из игры |

### Служебные (не для клиента)

| Файл | Назначение |
|------|-------------|
| `db.php` | Подключение к PostgreSQL, схема `s368719` |
| `helpers.php` | CORS, JSON-ответы, `require_auth`, проверки игры, авто-скип хода |
| `test_db.php` | Проверка подключения к БД |
| `cleanup_old_games.php` | Очистка старых/неактивных игр (вызывается при `list_games.php`) |

---

## Детали по эндпоинтам

### GET `list_games.php`
Список игр, где игра не завершена (`foxpos` 0..36) и есть свободные места.

**Ответ:**  
`{ "ok": true, "games": [ { "game_id", "turntime", "seatcount", "players_now", "free_seats", "foxpos", "game_over", "player_logins" }, ... ] }`

---

### GET `game_state.php?game_id=<id>`
Состояние игры. Перед ответом выполняется авто-скип хода при истечении таймера.

**Ответ:**  
`{ "ok": true, "game": { "game_id", "turntime", "seatcount", "players_now", "started", "current_seat", "game_over", "result", "timeRemainingSec" }, "fox": { "foxpos" }, "players": [...], "clues": [...], "suspects": [...], "pending_actions": [...] }`

---

### POST `auth_login.php` (JSON)
**Тело:** `{ "login": "user", "password": "pass" }`  
**Ответ:** `{ "ok": true, "token": "..." }` или при входе из незавершённой игры: `{ "ok": true, "token": "...", "game_id": 5 }`

---

### POST `create_player.php` (JSON)
**Тело:** `{ "login": "user", "password": "pass" }`  
Логин: 3–30 символов, буквы/цифры/подчёркивание. Пароль: 4–50 символов.  
**Ответ:** `{ "ok": true, "login": "...", "token": "..." }`

---

### POST `create_game.php` (form или JSON)
**Тело:** `{ "turntime": 30, "seatcount": 2 }`  
`turntime`: 20–60, кратно 10 (секунды на ход). `seatcount`: 2, 3 или 4.  
**Ответ:** `{ "ok": true, "game_id": 1 }`

---

### POST `join_game.php` (с токеном)
**Тело:** `{ "game_id": 5, "login": "user" }`  
**Ответ:** `{ "ok": true }` или ошибка (нет мест, игра не найдена и т.д.)

---

### POST `choose_action.php` (с токеном)
**Тело:** `{ "game_id": 5, "login": "user", "direction": "clue" | "suspect" }`  
Можно передавать `"подсказка"` / `"подозреваемый"` — нормализуется в `clue` / `suspect`.  
**Ответ при успехе (подсказка):** `{ "ok": true, "success": true, "max_steps": 4 }`  
**Ответ при успехе (подозреваемый):** `{ "ok": true, "success": true }`  
**Ответ при неудаче:** `{ "ok": true, "success": false }` (ход переходит следующему, лис +3 клетки)

---

### POST `move.php` (с токеном)
**Тело:** `{ "game_id", "login", "max_steps", "to_x", "to_y" }`  
Ход на клетку после успешного `choose_action` с `direction: "clue"`.  
**Ответ:** `{ "ok": true }` или ошибка (неверная клетка, превышен лимит шагов и т.д.)

---

### POST `open_suspect.php` (с токеном)
**Тело:** `{ "game_id", "login", "suspect_name": "Daisy" }`  
Вскрытие карточки подозреваемого после успешного `choose_action` с `direction: "suspect"`.  
**Ответ:** `{ "ok": true }`

---

### POST `accuse.php` (с токеном)
**Тело:** `{ "game_id", "login", "suspect_name": "Daisy" }`  
Обвинение: угадал лиса → победа (`foxpos = -2`), не угадал → поражение (`foxpos = -1`).  
**Ответ:** `{ "ok": true }`

---

### POST `skip_turn.php` (с токеном)
**Тело:** `{ "game_id", "login" }`  
Принудительное завершение хода текущего игрока.  
**Ответ:** `{ "ok": true }`

---

### POST `leave_game.php` (с токеном)
**Тело:** `{ "game_id", "login" }`  
Выход игрока из игры. Игра остаётся, если в ней есть другие игроки.  
**Ответ:** `{ "ok": true }`

---

