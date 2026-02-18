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

export async function listGames() {
  const res = await fetch(`${API_BASE}/list_games.php`, { method: 'GET' });
  return safeJson(res);
}

export async function createGame(turntime, seatcount) {
  const res = await fetch(`${API_BASE}/create_game.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ turntime, seatcount }),
  });
  return safeJson(res);
}

export async function joinGame(game_id, login) {
  const res = await fetch(`${API_BASE}/join_game.php`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      ...getAuthHeaders(),
    },
    body: JSON.stringify({ game_id, login }),
  });
  return safeJson(res);
}

export async function getGameState(game_id) {
  const res = await fetch(
    `${API_BASE}/game_state.php?game_id=${encodeURIComponent(game_id)}`,
    { method: 'GET' },
  );
  return safeJson(res);
}

export async function authLogin(login, password) {
  const res = await fetch(`${API_BASE}/auth_login.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ login, password }),
  });
  return safeJson(res);
}

export async function createPlayer(login, password) {
  const res = await fetch(`${API_BASE}/create_player.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ login, password }),
  });
  return safeJson(res);
}

export async function chooseAction(game_id, login, direction) {
  const res = await fetch(`${API_BASE}/choose_action.php`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      ...getAuthHeaders(),
    },
    body: JSON.stringify({ game_id, login, direction }),
  });
  return safeJson(res);
}

export async function movePlayer(game_id, login, max_steps, to_x, to_y) {
  const res = await fetch(`${API_BASE}/move.php`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      ...getAuthHeaders(),
    },
    body: JSON.stringify({ game_id, login, max_steps, to_x, to_y }),
  });
  return safeJson(res);
}

export async function openSuspect(game_id, login, suspect_name) {
  const res = await fetch(`${API_BASE}/open_suspect.php`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      ...getAuthHeaders(),
    },
    body: JSON.stringify({ game_id, login, suspect_name }),
  });
  return safeJson(res);
}

export async function skipTurn(gameId, login) {
  const r = await fetch(`${API_BASE}/skip_turn.php`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      ...getAuthHeaders(),
    },
    body: JSON.stringify({ game_id: Number(gameId), login }),
  });
  const data = await r.json();
  if (!r.ok || data.ok === false) {
    throw new Error(data?.error || 'skip_turn error');
  }
  return data;
}

export async function accuse(game_id, login, suspect_name) {
  const res = await fetch(`${API_BASE}/accuse.php`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      ...getAuthHeaders(),
    },
    body: JSON.stringify({ game_id, login, suspect_name }),
  });
  return safeJson(res);
}
