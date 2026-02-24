<template>
  <div class="lobby-container">
    <div class="lobby-header">
      <h2 class="lobby-title">Комнаты</h2>

      <button class="lobby-btn-create" :disabled="loadingCreate" @click="openCreateModal">
        {{ loadingCreate ? 'Создание...' : 'Создать игру' }}
      </button>

      <button class="lobby-btn-refresh" :disabled="loadingList" @click="refresh">
        {{ loadingList ? '...' : 'Refresh' }}
      </button>

      <span v-if="error" class="lobby-error">{{ error }}</span>
    </div>

    <div v-if="loadingList && rooms.length === 0" class="lobby-loading">Loading...</div>

    <div v-if="rooms.length === 0 && !loadingList" class="lobby-empty-message">
      Нет доступных комнат. Создай новую.
    </div>

    <div class="lobby-rooms-list">
      <div
        v-for="r in rooms"
        :key="r.game_id"
        class="lobby-room-card"
      >
        <div class="lobby-room-info">
          <strong class="lobby-room-title">Игра</strong>
          <div class="lobby-room-details">
            лис: {{ r.foxpos }}
            <span class="lobby-room-turn-time">ход: {{ r.turntime }}с</span>
          </div>
        </div>

        <div class="lobby-room-players">
          <div class="lobby-room-players-count">
            игроки: <strong>{{ r.players_now }}/{{ r.seatcount }}</strong>
            <span class="lobby-room-free-seats"> (свободно: {{ r.free_seats }})</span>
          </div>
          <div v-if="r.player_logins && r.player_logins.length > 0" class="lobby-room-players-list">
            <span class="lobby-room-players-label">В комнате:</span>
            <span
              v-for="(login, idx) in r.player_logins"
              :key="login"
              class="lobby-room-player-name"
            >
              {{ login }}<span v-if="idx < r.player_logins.length - 1">, </span>
            </span>
          </div>
        </div>

        <button
          :disabled="joiningId === String(r.game_id)"
          @click="onJoin(r.game_id)"
        >
          {{ joiningId === String(r.game_id) ? 'Joining...' : 'Join' }}
        </button>
      </div>
    </div>

    <div class="lobby-footer">
      Скрываем комнаты, если foxpos &lt; 0 (WIN/LOSE) или foxpos ≥ 33 (game over).
    </div>

    <!-- Modal: game settings -->
    <div
      v-if="showCreateModal"
      class="lobby-modal-overlay"
    >
      <div class="lobby-modal-content">
        <h3 class="lobby-modal-title">Новая игра</h3>

        <div class="lobby-modal-description">
          Выбери, сколько игроков и сколько секунд на ход.
        </div>

        <div class="lobby-modal-form">
          <div>
            <div class="lobby-modal-field-label">Количество игроков</div>
            <div class="lobby-modal-buttons-group">
              <button
                v-for="n in [2,3,4]"
                :key="n"
                type="button"
                :disabled="loadingCreate"
                @click="seatcount = n"
                :class="[
                  'lobby-modal-button',
                  { 'lobby-modal-button-selected-players': seatcount === n }
                ]"
              >
                {{ n }}
              </button>
            </div>
          </div>

          <div>
            <div class="lobby-modal-field-label">Время на ход (сек)</div>
            <div class="lobby-modal-buttons-group-wrap">
              <button
                v-for="t in [20,30,40,50,60]"
                :key="t"
                type="button"
                :disabled="loadingCreate"
                @click="turntime = t"
                :class="[
                  'lobby-modal-button',
                  { 'lobby-modal-button-selected-time': turntime === t }
                ]"
              >
                {{ t }}s
              </button>
            </div>
          </div>
        </div>

        <div class="lobby-modal-actions">
          <button
            type="button"
            @click="closeCreateModal"
            :disabled="loadingCreate"
            class="lobby-modal-button-cancel"
          >
            Cancel
          </button>
          <button
            type="button"
            @click="onCreate"
            :disabled="loadingCreate"
            class="lobby-modal-button-create"
          >
            {{ loadingCreate ? 'Creating...' : 'Create' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted, onBeforeUnmount, ref } from 'vue'
import { listGames, createGame, joinGame } from '../api'

const props = defineProps({
  login: { type: String, required: true },
})
const emit = defineEmits(['enter-game', 'logout'])

const rooms = ref([])
const loadingList = ref(false)
const loadingCreate = ref(false)
const joiningId = ref('')
const error = ref('')
const tick = ref(0)

const showCreateModal = ref(false)
const seatcount = ref(2)
const turntime = ref(30)

let alive = true
let timer = null

function isFinishedRoom(r) {
  const fox = Number(r?.foxpos ?? 0)
  // -2 => WIN, -1 => LOSE, любые отрицательные считаем завершением
  // >=33 тоже конец по твоему правилу
  return fox < 0 || fox >= 33
}

async function refresh() {
  tick.value++
  loadingList.value = true
  error.value = ''
  try {
    const data = await listGames()
    if (!data?.ok) throw new Error(data?.error || 'list_games failed')

    const raw = Array.isArray(data.games) ? data.games : []

    // показываем только доступные:
    // - не завершены (foxpos < 0 / >=33)
    // - есть свободные места
    rooms.value = raw.filter(r => {
      const free = Number(r?.free_seats ?? (Number(r?.seatcount ?? 0) - Number(r?.players_now ?? 0)))
      return !isFinishedRoom(r) && free > 0
    }).map(r => {
      // Парсим player_logins, если это строка JSON
      let playerLogins = r.player_logins
      if (typeof playerLogins === 'string') {
        try {
          playerLogins = JSON.parse(playerLogins)
        } catch {
          playerLogins = []
        }
      }
      if (!Array.isArray(playerLogins)) {
        playerLogins = []
      }
      return { ...r, player_logins: playerLogins }
    })
  } catch (e) {
    error.value = e?.message || 'Ошибка загрузки комнат'
  } finally {
    loadingList.value = false
  }
}

async function loop() {
  if (!alive) return
  await refresh()
  timer = setTimeout(loop, 2500)
}

function openCreateModal() {
  error.value = ''
  showCreateModal.value = true
}

function closeCreateModal() {
  if (loadingCreate.value) return
  showCreateModal.value = false
}

async function onCreate() {
  loadingCreate.value = true
  error.value = ''
  try {
    // Создаем игру
    const data = await createGame(turntime.value, seatcount.value)
    const gameId = data?.game_id ?? data?.id ?? data
    if (!gameId) throw new Error('create_game: нет game_id в ответе')
    
    // Сразу же подключаемся к созданной игре
    if (!props.login || props.login.trim() === '') {
      throw new Error('Логин не указан')
    }
    
    const joinResp = await joinGame(gameId, props.login)
    if (joinResp?.ok === false) {
      throw new Error(joinResp?.error || 'Ошибка подключения к созданной игре')
    }
    
    localStorage.setItem('game_id', String(gameId))
    showCreateModal.value = false
    emit('enter-game', gameId)
  } catch (e) {
    error.value = e?.message || 'Ошибка создания игры'
  } finally {
    loadingCreate.value = false
  }
}

async function onJoin(game_id) {
  joiningId.value = String(game_id)
  error.value = ''
  try {
    // Проверяем, что логин есть
    if (!props.login || props.login.trim() === '') {
      throw new Error('Логин не указан')
    }
    
    const resp = await joinGame(game_id, props.login)
    if (resp?.ok === false) {
      throw new Error(resp?.error || 'Ошибка подключения к игре')
    }

    localStorage.setItem('game_id', String(game_id))
    emit('enter-game', game_id)
  } catch (e) {
    // Более информативное сообщение об ошибке
    const errorMsg = e?.message || 'Ошибка подключения'
    error.value = errorMsg
    
    // Логируем для отладки
    console.error('Join game error:', {
      game_id,
      login: props.login,
      error: e?.message,
      stack: e?.stack
    })
  } finally {
    joiningId.value = ''
  }
}

onMounted(() => { alive = true; loop() })
onBeforeUnmount(() => { alive = false; if (timer) clearTimeout(timer) })
</script>

<style scoped>
@import '../styles/lobby-page.css';
</style>
