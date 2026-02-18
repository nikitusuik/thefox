const API_BASE =
  import.meta.env.PROD
    ? 'https://se.ifmo.ru/~s368719/kovar/API'
    : '/API';

// sessionStorage = токен свой у каждой вкладки (два игрока в двух вкладках на localhost не затирают друг друга)
// Если нужен один токен на все вкладки — замените на localStorage
const tokenStorage = typeof sessionStorage !== 'undefined' ? sessionStorage : localStorage;

function getAuthHeaders() {
  const token = tokenStorage.getItem('token');
  if (!token || typeof token !== 'string' || token.trim() === '') {
    return {};
  }
  return { 'X-Auth-Token': token.trim() };
}

async function safeJson(res) {
  const text = await res.text();
  let data;
  try {
    data = text ? JSON.parse(text) : null;
  } catch {
    data = { raw: text };
  }

  if (!res.ok) {
    const msg = data?.error || data?.message || `HTTP ${res.status}`;
    throw new Error(msg);
  }
  return data;
}

function toFormBody(obj) {
  const params = new URLSearchParams();
  for (const [k, v] of Object.entries(obj || {})) {
    if (v === undefined || v === null) continue;
    params.append(k, String(v));
  }
  return params.toString();
}

async function postForm(path, data, withAuth = false) {
  try {
    const res = await fetch(`${API_BASE}/${path}`, {
      method: 'POST',
      mode: 'cors',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
        ...(withAuth ? getAuthHeaders() : {}),
      },
      body: toFormBody(data),
    });
    return safeJson(res);
  } catch (error) {
    // Обработка CORS и сетевых ошибок
    if (error.name === 'TypeError' && error.message.includes('fetch')) {
      throw new Error(`CORS ошибка: сервер не разрешает запросы с этого домена. Проверьте настройки CORS на сервере ${API_BASE}`);
    }
    throw error;
  }
}

// -------------------- GET --------------------

export async function listGames() {
  try {
    const res = await fetch(`${API_BASE}/list_games.php`, { 
      method: 'GET',
      mode: 'cors'
    });
    return safeJson(res);
  } catch (error) {
    if (error.name === 'TypeError' && error.message.includes('fetch')) {
      throw new Error(`CORS ошибка: сервер не разрешает запросы с этого домена. Проверьте настройки CORS на сервере ${API_BASE}`);
    }
    throw error;
  }
}

export async function getGameState(game_id) {
  try {
    const res = await fetch(
      `${API_BASE}/game_state.php?game_id=${encodeURIComponent(game_id)}`,
      { 
        method: 'GET',
        mode: 'cors'
      },
    );
    return safeJson(res);
  } catch (error) {
    if (error.name === 'TypeError' && error.message.includes('fetch')) {
      throw new Error(`CORS ошибка: сервер не разрешает запросы с этого домена. Проверьте настройки CORS на сервере ${API_BASE}`);
    }
    throw error;
  }
}

// -------------------- POST (form-urlencoded) --------------------

export function authLogin(login, password) {
  return postForm('auth_login.php', { login, password }, false);
}

export function createPlayer(login, password) {
  return postForm('create_player.php', { login, password }, false);
}

export function createGame(turntime, seatcount) {
  return postForm('create_game.php', { turntime, seatcount }, false);
}

export function joinGame(game_id, login) {
  return postForm('join_game.php', { game_id, login }, true);
}

export function chooseAction(game_id, login, direction) {
  return postForm('choose_action.php', { game_id, login, direction }, true);
}

export function movePlayer(game_id, login, max_steps, to_x, to_y) {
  return postForm('move.php', { game_id, login, max_steps, to_x, to_y }, true);
}

export function openSuspect(game_id, login, suspect_name) {
  return postForm('open_suspect.php', { game_id, login, suspect_name }, true);
}

export function accuse(game_id, login, suspect_name) {
  return postForm('accuse.php', { game_id, login, suspect_name }, true);
}

export async function skipTurn(gameId, login) {
  // оставил отдельной, но тоже через postForm — так безопаснее
  return postForm('skip_turn.php', { game_id: Number(gameId), login }, true);
}

export function leaveGame(game_id, login) {
  return postForm('leave_game.php', { game_id, login }, true);
}
