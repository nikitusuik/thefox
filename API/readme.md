## API документация

### Общие правила

- **Формат**
  - Все тела запросов и ответов — **JSON**.
  - Кодировка **UTF‑8**.
- **Базовый URL**
  - Далее для краткости используется `/API/...` (подставь свой хост и путь).
- **Заголовки**
  - Для всех запросов с JSON‑телом: `Content-Type: application/json`.
  - Для игровых действий (где есть поле `login`) обязателен заголовок:
    - `X-Auth-Token: <token>` — токен авторизации игрока.
- **Ошибки**
  - При любой ошибке API возвращает:
    ```json
    { "ok": false, "error": "Сообщение об ошибке" }
    ```

---

## Очередность запросов (основной сценарий)

1. **Регистрация / логин**
   - `POST /API/create_player.php` — создать аккаунт и получить `token`.
   - `POST /API/auth_login.php` — войти и получить (обновить) `token`.
2. **Создание или выбор игры**
   - `POST /API/create_game.php` — создать комнату с нужным `turntime` и `seatcount` (2/3/4 игрока).
   - `GET  /API/list_games.php` — посмотреть доступные игры (видно `seatcount`, `players_now`, `free_seats`).
3. **Вход в игру**
   - `POST /API/join_game.php` — зайти в выбранную игру (нужен `login` и `X-Auth-Token`).
   - Повторять для всех игроков, пока `players_now == seatcount` — тогда игра стартует.
4. **Игровой цикл**
   - Периодически вызывать `GET/POST /API/game_state.php`:
     - чтобы обновлять поле, позиции, таймер и очередь;
     - при истёкшем времени текущего хода сервер сам скипает ход при первом заходе.
   - Когда у текущего игрока ход:
     - `POST /API/choose_action.php` — выбрать действие (`clue`/`suspect`) и бросить кубики.
       - При `success + clue` сервер вернёт `max_steps` и создаст pending move в БД.
       - При `success + suspect` будет ждать `open_suspect`.
     - Если `direction = clue` и `success = true`:
       - `POST /API/move.php` — сделать ход по клеткам (сервер сам проверит `distance <= max_steps`).
     - Если `direction = suspect` и `success = true`:
       - `POST /API/open_suspect.php` — вскрыть подозреваемого.
   - В любой момент игрок может:
     - `POST /API/accuse.php` — сделать финальное обвинение (win/lose для всех).
     - `POST /API/skip_turn.php` — принудительно пропустить свой ход (альтернатива авто‑таймеру).

После каждого действия (choose/move/open/accuse/skip) стоит обновлять состояние через `game_state`.

---

## Аккаунты и авторизация

### `POST /API/create_player.php`

**URL**: [`https://se.ifmo.ru/~s368719/kovar/API/create_player.php`](https://se.ifmo.ru/~s368719/kovar/API/create_player.php)

**Назначение**: регистрация нового игрока (создание аккаунта).

- **Body**

```json
{
  "login": "user1",
  "password": "pass1234"
}
```

- **Правила**
  - **`login`**:
    - 3–30 символов;
    - только `[A-Za-z0-9_]`;
    - должен быть уникален в таблице `users`.
  - **`password`**:
    - 4–50 символов;
    - хранится как `md5(password)` (упрощение для учебного проекта).
  - При регистрации сразу генерируется `token` и сохраняется в `users.token`.

- **Успешный ответ**

```json
{
  "ok": true,
  "login": "user1",
  "token": "abcdef1234567890"
}
```

Полученный `token` нужно запомнить на клиенте и слать в `X-Auth-Token` для игровых запросов.

---

### `POST /API/auth_login.php`

**URL**: [`https://se.ifmo.ru/~s368719/kovar/API/auth_login.php`](https://se.ifmo.ru/~s368719/kovar/API/auth_login.php)

**Назначение**: вход игрока по логину и паролю.

- **Body**

```json
{
  "login": "user1",
  "password": "pass1234"
}
```

- **Поведение**
  - Находит пользователя по `login`.
  - Сравнивает `md5(password)` с хранимым значением.
  - При успехе генерирует новый `token`, записывает его в `users.token`.

- **Успешный ответ**

```json
{
  "ok": true,
  "token": "newtoken1234567890"
}
```

С этого момента действителен **последний выданный** токен.

---

## Игры / комнаты

### `POST /API/create_game.php`

**URL**: [`https://se.ifmo.ru/~s368719/kovar/API/create_game.php`](https://se.ifmo.ru/~s368719/kovar/API/create_game.php)

**Назначение**: создать новую игру (комнату).

- **Body**

```json
{
  "turntime": 30
}
```

- **Поля**
  - **`turntime`** (обязателен): время на один ход в секундах.
    - Допустимые значения: **20, 30, 40, 50, 60** (любое число от 20 до 60, кратное 10).

- **Успешный ответ**

```json
{
  "ok": true,
  "game_id": 24
}
```

---

### `GET /API/list_games.php`

**URL**: [`https://se.ifmo.ru/~s368719/kovar/API/list_games.php`](https://se.ifmo.ru/~s368719/kovar/API/list_games.php)

**Назначение**: список комнат, куда можно зайти.

- **Query**: без параметров.
- **Фильтры на стороне сервера**
  - Берём только игры, где:
    - лиса ещё не убежала и не финал (`0 <= foxpos < 33`);
    - есть свободные места (`players_now < seatcount`).

- **Успешный ответ**

```json
{
  "ok": true,
  "games": [
    {
      "game_id": 24,
      "turntime": 60,
      "seatcount": 4,
      "players_now": 2,
      "free_seats": 2,
      "foxpos": 0,
      "game_over": false
    }
  ]
}
```

---

### `POST /API/join_game.php`  *(нужен токен)*

**URL**: [`https://se.ifmo.ru/~s368719/kovar/API/join_game.php`](https://se.ifmo.ru/~s368719/kovar/API/join_game.php)

**Назначение**: присоединиться к игре, занять место и стартовую позицию.

- **Headers**
  - **`X-Auth-Token`**: токен игрока.

- **Body**

```json
{
  "game_id": 24,
  "login": "user1"
}
```

- **Правила**
  - `game_id` и `login` обязательны.
  - Токен в `X-Auth-Token` должен совпадать с `users.token` для этого `login`.
  - Если игрок уже в игре, эндпоинт вернёт существующую информацию.
  - При входе последнего игрока (когда `players_now == seatcount`):
    - игра считается **стартовавшей**;
    - `games.current_seat` становится `1`;
    - `turn_started_at` устанавливается в `NOW()` (запуск таймера первого хода).

- **Успешный ответ (пример)**

```json
{
  "ok": true,
  "player_id": 66,
  "seat": 1,
  "color": "красный",
  "start": { "y": 5, "x": 5 },
  "game": {
    "players_now": 4,
    "seatcount": 4,
    "current_seat": 1,
    "started": true
  }
}
```

---

## Состояние игры

### `GET /API/game_state.php?game_id=24`
### `POST /API/game_state.php`

**URL**: [`https://se.ifmo.ru/~s368719/kovar/API/game_state.php`](https://se.ifmo.ru/~s368719/kovar/API/game_state.php)

**Назначение**: единый источник правды по игре.

- **GET Query**
  - `game_id` — обязательный.

- **POST Body**

```json
{
  "game_id": 24
}
```

- **Важное поведение**
  - Перед сборкой состояния вызывается внутренняя функция авто‑таймера:
    - если с момента `turn_started_at` прошло больше, чем `turntime` секунд,
    - сервер **автоматически**:
      - завершает ход текущего игрока (чистит его `moves`);
      - переключает `current_seat` на следующего;
      - обновляет `turn_started_at = NOW()`.
  - То есть первый запрос к `game_state` после истечения таймера **автоматически скипает** ход на сервере.

- **Успешный ответ (структура)**

```json
{
  "ok": true,
  "game": {
    "game_id": 24,
    "turntime": 60,
    "seatcount": 4,
    "players_now": 4,
    "started": true,
    "current_seat": 1,
    "game_over": false,
    "result": null,              // null | "win" | "lose"
    "timeRemainingSec": 37       // секунды до конца текущего хода или null
  },
  "fox": {
    "foxpos": 0                  // -2 win, -1 lose, 0..32 обычная позиция
  },
  "players": [
    {
      "playerid": 66,
      "login": "user1",
      "seatnumber": 1,
      "color": "красный",
      "y": 5,
      "x": 5
    }
  ],
  "clues": [
    {
      "y": 3,
      "x": 5,
      "item_name": "umbrella",
      "status": "скрыт" | "вскрыт" | "opened",
      "fox_has_item": true
    }
  ],
  "suspects": [
    {
      "susname": "Daisy",
      "status": "скрыт" | "вскрыт" | ...
    }
  ],
  "pending_actions": [
    {
      "login": "user1",
      "seatnumber": 1,
      "direction": "clue" | "suspect" | null,
      "result": true | false | null
    }
  ]
}
```

- **Для клиента**
  - **Таймер**: использовать `game.timeRemainingSec` как источник правды.
  - **Позиции**: брать `players`, `clues`, `suspects`, `fox`.
  - **Ожидаемые действия**: смотреть по `pending_actions` и `current_seat`.

---

## Ход игрока: выбор действия и перемещение

### `POST /API/choose_action.php`  *(нужен токен)*

**URL**: [`https://se.ifmo.ru/~s368719/kovar/API/choose_action.php`](https://se.ifmo.ru/~s368719/kovar/API/choose_action.php)

**Назначение**: начало хода — выбор действия и бросок кубиков.

- **Headers**
  - `X-Auth-Token: <token игрока>`

- **Body**

```json
{
  "game_id": 24,
  "login": "user1",
  "direction": "clue"        // или "suspect", можно по‑русски: "подсказка" / "подозреваемый"
}
```

- **Правила**
  - Игра должна быть:
    - не окончена (`ensure_game_not_over`);
    - стартовавшей (`ensure_game_started`).
  - Сейчас должен ходить именно этот игрок (`seatnumber == games.current_seat`).
  - У игрока не должно быть незавершённого действия в `moves`.

- **Поведение**
  - Симулируются 3 кубика (3 попытки, переброс только нулей).
  - Результат: `success = true/false`.
  - В таблицу `moves` **всегда** пишется запись:
    - `playerid`, `direction`, `result`, `max_steps`.
  - Если **fail**:
    - `foxpos += 3` (лиса убегает вперёд);
    - запись в `moves` удаляется;
    - очередь сразу переключается на следующего игрока;
    - `turn_started_at` обновляется.
  - Если **success**:
    - для `direction = clue` сервер случайно выбирает **`max_steps` от 3 до 6**,
      записывает его в `moves.max_steps` и возвращает клиенту;
    - ход завершится через `move.php` / `open_suspect.php`.

- **Успешный ответ**

```json
{
  "ok": true,
  "direction": "clue",
  "success": true,
  "dice": [1, 1, 1],
  "fox": {
    "moved": false,
    "foxpos": 0
  },
  "max_steps": 4        // только при success + clue
  // "next_seat": 2     // только при fail (ход сразу передан)
}
```

Клиент должен запомнить `max_steps` для визуального ограничения, но **НЕ отправлять его обратно на сервер**.

---

### `POST /API/move.php`  *(нужен токен)*

**URL**: [`https://se.ifmo.ru/~s368719/kovar/API/move.php`](https://se.ifmo.ru/~s368719/kovar/API/move.php)

**Назначение**: перемещение игрока ПРИ успешном `choose_action(direction = clue)`.

- **Headers**
  - `X-Auth-Token: <token игрока>`

- **Body**

```json
{
  "game_id": 24,
  "login": "user1",
  "to_y": 5,
  "to_x": 7
}
```

- **Важно**
  - Клиент **НЕ отправляет** `max_steps`.
  - Сервер берёт `max_steps` из таблицы `moves` для этого `playerid`.

- **Проверки на сервере**
  - Игра не окончена, игра стартовала.
  - Сейчас ход этого игрока (`seatnumber == current_seat`).
  - В `moves` есть запись с `direction = clue`, `result = true`.
  - Из `moves.max_steps` берётся лимит шагов.
  - Считается манхэттен‑расстояние:
    - `distance = |to_y - from_y| + |to_x - from_x|`.
  - Если `distance > max_steps` → ошибка:
    ```json
    { "ok": false, "error": "Target cell is too far" }
    ```
  - Целевая клетка должна существовать в поле.

- **Поведение при успехе**
  - Игрок переносится в целевую клетку.
  - Если там есть подсказка, она вскрывается.
  - Запись в `moves` удаляется.
  - Ход передаётся следующему игроку (`current_seat`+1, по кругу).
  - `turn_started_at` обновляется (таймер для нового игрока).

- **Успешный ответ**

```json
{
  "ok": true,
  "from": { "y": 5, "x": 5 },
  "to": { "y": 5, "x": 7 },
  "distance": 2,
  "max_steps": 4,
  "opened_clue": "umbrella",     // или null, если подсказки не было
  "next_seat": 2
}
```

---

## Работа с подозреваемыми и финалом

### `POST /API/open_suspect.php`  *(нужен токен)*

**URL**: [`https://se.ifmo.ru/~s368719/kovar/API/open_suspect.php`](https://se.ifmo.ru/~s368719/kovar/API/open_suspect.php)

**Назначение**: вскрыть подозреваемого после успешного `choose_action(direction = suspect)`.

- **Headers**
  - `X-Auth-Token: <token игрока>`

- **Body**

```json
{
  "game_id": 24,
  "login": "user1",
  "suspect_name": "Daisy"
}
```

- **Правила**
  - В `moves` для этого игрока должна быть запись с `direction = suspect`.
  - Если результат `result = false`, действие отменяется, ход передаётся дальше.
  - Если результат `true`, статус подозреваемого в `suspects_in_game` меняется на `вскрыт/opened`.
  - Запись в `moves` удаляется, ход переходит к следующему, `turn_started_at` обновляется.

- **Успешный ответ**

```json
{
  "ok": true,
  "opened_suspect": "Daisy",
  "status": "вскрыт",
  "next_seat": 2
}
```

---

### `POST /API/accuse.php`  *(нужен токен)*

**URL**: [`https://se.ifmo.ru/~s368719/kovar/API/accuse.php`](https://se.ifmo.ru/~s368719/kovar/API/accuse.php)

**Назначение**: финальное обвинение — попытка угадать, кто лис.

- **Headers**
  - `X-Auth-Token: <token игрока>`

- **Body**

```json
{
  "game_id": 24,
  "login": "user1",
  "suspect_name": "Daisy"
}
```

- **Правила**
  - Если имя совпадает с настоящим лисом (`foxes.susid`), все выигрывают.
  - Иначе — все проигрывают.
  - Результат кодируется так:
    - `foxpos = -2` → **win**;
    - `foxpos = -1` → **lose**.
  - После этого игра считается завершённой, любые следующие ходы блокируются через `ensure_game_not_over`.

- **Успешный ответ**

```json
{
  "ok": true,
  "accused": "Daisy",
  "win": true,
  "lose": false,
  "fox": {
    "moved": false,
    "foxpos": -2
  }
}
```

---

## Принудительный пропуск хода

### `POST /API/skip_turn.php`  *(нужен токен)*

**URL**: [`https://se.ifmo.ru/~s368719/kovar/API/skip_turn.php`](https://se.ifmo.ru/~s368719/kovar/API/skip_turn.php)

**Назначение**: серверный скип текущего хода по запросу клиента (например, при таймауте, если клиент сам решил не ждать авто‑скипа).

- **Headers**
  - `X-Auth-Token: <token игрока>`

- **Body**

```json
{
  "game_id": 24,
  "login": "user1"
}
```

- **Правила**
  - Игрок должен быть тем, чей сейчас ход (`current_seat`).
  - Любой `moves` для этого игрока очищается.
  - `current_seat` переключается на следующего, `turn_started_at` обновляется.

- **Успешный ответ**

```json
{
  "ok": true,
  "skipped": true,
  "next_seat": 2
}
```

---

## Итог по безопасности и шагам

- **Шаги (`max_steps`)**
  - Генерируются **только сервером** в `choose_action.php` при успешном `clue`.
  - Хранятся в `moves.max_steps`.
  - Клиент **никогда не отправляет** количество шагов в `move.php`.
  - Проверка расстояния по клеткам происходит на сервере против `max_steps`.

- **Таймер хода**
  - Время хода задаётся в `create_game.php` через `turntime` (20–60, шаг 10).
  - Сервер ведёт `turn_started_at` и считает `timeRemainingSec` в `game_state.php`.
  - При истечении таймера первый же вызов `game_state` автоматически скипает ход на следующего игрока.

