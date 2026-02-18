<template>
  <div class="game-page">
    <div class="game-page-inner">
      <div class="game-layout">

      <!-- =========================
           BLOCK: LEFT COLUMN (BOARD ONLY)
           ========================= -->
      <div class="game-left">
        <!-- =========================
             BLOCK: GLOBAL ERROR / LOADING
             ========================= -->
        <div v-if="error" style="color:#b00020; margin-bottom: 10px;">
          {{ error }}
        </div>
        <div v-if="!state" style="opacity:.7;">Loading game_state...</div>

        <!-- =========================
             BLOCK: BOARD
             ========================= -->
        <div v-if="state" class="game-board-row">
          <div class="game-board-and-debug">
            <div class="board-with-cards" :style="{ '--field-image': `url(${fieldImage})` }">
              <div class="board-wrapper">
                <div class="board-grid">
                  <div
                    v-for="cell in cells"
                    :key="cell.key"
                    @click="onCellClick(cell.x, cell.y)"
                    :style="cellStyle(cell.x, cell.y)"
                  >
                    <span
                      v-if="playerAt(cell.x, cell.y)"
                      class="cell-player-token"
                      :class="{ 'cell-player-token--img': getPlayerTokenImage(playerAt(cell.x, cell.y)?.color) }"
                      :style="getPlayerTokenImage(playerAt(cell.x, cell.y)?.color) ? { backgroundImage: `url(${getPlayerTokenImage(playerAt(cell.x, cell.y).color)})` } : {}"
                      :title="playerAt(cell.x, cell.y).login"
                    />
                    <span
                      v-if="clueAt(cell.x, cell.y) && !isClueOpened(clueAt(cell.x, cell.y))"
                      class="cell-clue-paw cell-clue-paw--closed"
                      :style="{ backgroundImage: `url(${pawPrintsImage})` }"
                      :title="translateItem(clueAt(cell.x, cell.y)?.item_name) || 'Подсказка'"
                    />
                    <span
                      v-if="foxAt(cell.x, cell.y)"
                      class="cell-fox"
                      :style="{ backgroundImage: `url(${foxImage})` }"
                      title="Лис"
                    />
                  </div>
                </div>
              </div>

              <div
                v-for="(s, idx) in allSuspects"
                :key="s.susname"
                class="suspect-card"
                :class="[
                  cardPositionClass(idx),
                  isSuspectOpened(s) ? 'suspect-card--opened' : '',
                  (suspectMode && !isSuspectOpened(s)) || isSuspectOpened(s) ? 'suspect-card--clickable' : ''
                ]"
                @click="handleSuspectCardClick(s)"
                :title="isSuspectOpened(s) ? `Подсказки: ${s.susname}` : (suspectMode ? `Вскрыть: ${s.susname}` : s.susname)"
              >
                <div class="suspect-card-inner">
                  <div class="suspect-card-face suspect-card-back" />
                  <div
                    class="suspect-card-face suspect-card-front"
                    :style="getSuspectImage(s) ? { backgroundImage: `url(${getSuspectImage(s)})` } : {}"
                  >
                    <div class="suspect-card-name">{{ s.susname }}</div>
                  </div>
                </div>
              </div>
            </div>

          </div>

          <div style="margin-top:8px; font-size:12px; opacity:.85;">
            <div v-if="moveError" style="color:#b00020;">{{ moveError }}</div>
            <div v-if="moveMsg">{{ moveMsg }}</div>
          </div>
        </div>
      </div>

      <!-- =========================
           BLOCK: RIGHT COLUMN (ALL INFO BLOCKS)
           ========================= -->
      <div class="game-right">
        <!-- Status and Timer -->
        <div class="card card--right">
          <div class="game-right-title">
            <strong>{{ statusText }}</strong>
          </div>

          <div v-if="state && isMyTurn && !isGameOver" style="margin-bottom:6px; font-size:11px; flex-shrink:0;">
            ⏳ <strong>{{ turnLeft }}</strong>s
          </div>

          <div v-if="state" style="flex:1; overflow:hidden; display:flex; flex-direction:column;">
            <div class="players-title" style="font-size:11px;">Игроки</div>
            <div class="players-list">
              <div
                v-for="p in state.players"
                :key="p.login"
                class="player-card"
              >
                <div class="player-card-header">
                  <span
                    v-if="getPlayerTokenImage(p.color)"
                    class="player-card-token"
                    :style="{ backgroundImage: `url(${getPlayerTokenImage(p.color)})` }"
                  />
                  <div class="player-card-name">
                    <strong>{{ p.login }}</strong>
                  </div>
                  <div class="player-card-seat">
                    #{{ p.seatnumber }}
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="game-right-footer" style="font-size:9px; flex-shrink:0;">
            poll: {{ pollCount }}
          </div>
        </div>

        <!-- GAME STATUS -->
        <div v-if="state" class="card card--status">
          <div><strong>Seat:</strong> {{ state.game.current_seat }}/{{ me?.seatnumber ?? '—' }}</div>
          <div><strong>Turn:</strong> {{ isMyTurn ? 'YES' : 'NO' }}</div>
          <div><strong>Fox:</strong> {{ state.fox.foxpos }}</div>
          <div><strong>Over:</strong> {{ isGameOver ? 'YES' : 'NO' }}</div>
        </div>

        <!-- PENDING -->
        <div v-if="state" class="card card--pending">
          <div style="margin-bottom:4px;"><strong>Pending:</strong></div>
          <div style="opacity:.8; font-size:9px;">
            dir: {{ myPending?.direction ?? '—' }}<br />
            res: {{ myPending?.result ?? '—' }}<br />
            steps: {{ myMaxSteps ?? '—' }}
          </div>
        </div>

        <!-- ACTIONS -->
        <div v-if="state" class="card card--actions">
          <div style="margin-bottom:8px;"><strong>Действия</strong></div>

          <div style="display:flex; gap:6px; flex-wrap:wrap;">
            <button
              @click="openActionModal"
              :disabled="!canChooseAction || actionLoading"
              style="padding:6px 10px; font-size:10px;"
            >
              {{ actionLoading ? '...' : 'Выбрать действие' }}
            </button>

          </div>

          <div style="margin-top:6px; font-size:9px; opacity:.85;">
            <div v-if="moveMode" style="color:#4caf50;">✅ Режим движения (≤ {{ myMaxSteps }} шагов) - кликните на клетки</div>
            <div v-if="suspectMode" style="color:#4caf50;">✅ Режим подозреваемых - кликните на карточки на поле</div>
          </div>
        </div>


        <!-- OPENED CLUES - moved to right column -->
        <div v-if="state" class="card card--panel card--clues">
          <div style="margin-bottom:6px; font-size:11px;"><strong>Открытые подсказки</strong></div>

          <div v-if="openedClues.length === 0" style="opacity:.7; font-size:10px;">
            Нет подсказок
          </div>

          <div style="display:flex; flex-direction:column; gap:6px; max-height:200px; overflow-y:auto;">
            <div
              v-for="c in openedClues"
              :key="`${c.x}-${c.y}-${c.item_name}`"
              style="padding:8px 12px; border-radius:6px; border:1px solid #88c; background:#1f1f1f; color:#fff; display:flex; align-items:center; gap:8px;"
              :style="{
                borderColor: c.fox_has_item === true ? '#4caf50' : c.fox_has_item === false ? '#f44336' : '#88c'
              }"
            >
              <span v-if="c.fox_has_item === true" style="font-size:16px; flex-shrink:0;">🟢</span>
              <span v-else-if="c.fox_has_item === false" style="font-size:16px; flex-shrink:0;">🔴</span>
              <span v-else style="font-size:16px; flex-shrink:0;">❓</span>
              <span style="font-size:11px; flex:1;">{{ translateItem(c.item_name) }}</span>
              <span style="font-size:9px; opacity:0.6;">({{ c.x }},{{ c.y }})</span>
            </div>
          </div>

          <div style="margin-top:6px; font-size:9px; opacity:.7;">
            🟢 = есть у лиса, 🔴 = нет у лиса
          </div>
        </div>

        <!-- OPENED SUSPECTS - moved to right column -->
        <div v-if="state" class="card card--panel card--suspects-opened">
          <div style="margin-bottom:6px; font-size:11px;"><strong>Открытые подозреваемые</strong></div>

          <div v-if="openedSuspects.length === 0" style="opacity:.7; font-size:10px;">
            Нет открытых
          </div>

          <div style="display:flex; flex-direction:column; gap:6px; max-height:300px; overflow:hidden;">
            <div
              v-for="s in openedSuspects"
              :key="s.susname"
              style="padding:6px; border-radius:6px; border:1px solid #88c; background:#1f1f1f; color:#fff;"
            >
              <div style="margin-bottom:4px; font-weight:bold; font-size:11px;">
                🎩 <strong>{{ s.susname }}</strong>
              </div>
              <div v-if="s.hints && s.hints.length > 0" style="margin-top:6px;">
                <div style="font-size:10px; opacity:.85; margin-bottom:6px; font-weight:bold;">Подсказки ({{ s.hints.length }}):</div>
                <div style="display:flex; flex-direction:column; gap:4px;">
                  <div
                    v-for="(hint, idx) in s.hints"
                    :key="idx"
                    style="display:flex; align-items:center; gap:6px; padding:4px 6px; background:rgba(255,255,255,0.05); border-radius:4px; border:1px solid rgba(255,255,255,0.1);"
                  >
                    <div
                      :style="{ width: '20px', height: '20px', backgroundImage: `url(${pawPrintsImage})`, backgroundSize: 'contain', backgroundRepeat: 'no-repeat', backgroundPosition: 'center', opacity: 0.9, flexShrink: 0 }"
                      :title="translateItem(hint)"
                    />
                    <span style="font-size:10px; color:#e0e0e0;">{{ translateItem(hint) }}</span>
                  </div>
                </div>
              </div>
              <div v-else style="font-size:11px; opacity:.6; margin-top:6px;">
                Подсказки не найдены
              </div>
            </div>
          </div>
        </div>

        <!-- Debug panel moved to right column -->
        <details class="debug-panel card">
          <summary style="font-size:11px; cursor:pointer;">Raw game_state (debug)</summary>
          <pre style="font-size:9px; max-height:200px; overflow:hidden;">{{ json }}</pre>
        </details>
      </div>

      </div>

      <!-- =========================
         BLOCK: ACTION CHOICE MODAL (CLUE / SUSPECT)
         ========================= -->
      <div
        v-if="showActionModal && (canChooseAction || diceResult)"
        class="action-modal-overlay"
        @click.self="!diceRolling && !diceResult && (showActionModal = false)"
      >
        <div class="action-modal">
          <h3 style="margin:0 0 20px; text-align:center;">Ваш ход</h3>
          
          <div v-if="!diceRolling && !diceResult" class="action-modal-buttons">
            <button
              @click="startDiceRoll('clue')"
              class="action-modal-btn action-modal-btn--clue"
              :disabled="actionLoading"
            >
              Подсказка
            </button>
            <button
              @click="startDiceRoll('suspect')"
              class="action-modal-btn action-modal-btn--suspect"
              :disabled="actionLoading"
            >
              Подозреваемый
            </button>
          </div>

          <!-- Анимация кубиков -->
          <div v-if="diceRolling" class="dice-animation">
            <div class="dice-container">
              <div
                v-for="i in 3"
                :key="`rolling-${i}-${diceAnimationImages[i - 1]}`"
                class="dice dice--custom dice--rolling"
                :style="{ backgroundImage: `url(${diceAnimationImages[i - 1]})` }"
              />
            </div>
            <div style="text-align:center; margin-top:16px; opacity:0.7;">Бросаем кубики...</div>
          </div>

          <!-- Результат броска -->
          <div v-if="diceResult" class="dice-result">
            <div class="dice-result-final">
              <div
                v-for="i in 3"
                :key="i"
                class="dice dice--final dice--custom"
                :style="{ backgroundImage: `url(${getDiceImageForAnimation(i - 1, diceResult.success, diceResult.max_steps || diceResult.response?.max_steps || 0, diceResult.direction || pendingDirection)})` }"
              />
            </div>
            <div class="dice-result-message" :class="diceResult.success ? 'success' : 'fail'">
              {{ diceResult.success ? '✅ Успех!' : '❌ Неудача' }}
              <div v-if="diceResult.success && (diceResult.direction || pendingDirection) === 'clue'" style="font-size:14px; margin-top:8px; opacity:0.8;">
                Выберите клетку для движения (≤ {{ diceResult.response?.max_steps || '?' }} шагов)
              </div>
              <div v-if="diceResult.success && (diceResult.direction || pendingDirection) === 'suspect'" style="font-size:14px; margin-top:8px; opacity:0.8;">
                Кликните на карточку подозреваемого на поле
              </div>
            </div>
            <button
              @click="confirmDiceResult"
              class="action-modal-btn action-modal-btn--confirm"
            >
              {{ diceResult.success ? 'Продолжить' : 'Закрыть' }}
            </button>
          </div>
        </div>
      </div>

      <!-- =========================
         BLOCK: REVEAL SUSPECT MODAL (подсказки по карточке, закрыть → карточка рубашкой)
         ========================= -->
      <div
        v-if="revealModalOpen && revealModalSuspect"
        class="action-modal-overlay reveal-modal-overlay"
        @click.self="closeRevealModal"
      >
        <div class="action-modal reveal-modal">
          <div class="reveal-modal-body">
            <div
              class="reveal-modal-card"
              :style="revealModalCardImage ? { backgroundImage: `url(${revealModalCardImage})` } : {}"
            >
              <span class="reveal-modal-card-name">{{ revealModalSuspect.susname }}</span>
            </div>
            <div class="reveal-modal-hints-col">
              <div class="reveal-modal-hints-label">Подсказки</div>
              <div class="reveal-modal-hints">
                <template v-if="revealModalSuspect.hints && revealModalSuspect.hints.length">
                  <div
                    v-for="(hint, idx) in revealModalSuspect.hints"
                    :key="idx"
                    class="reveal-hint-item"
                  >
                    <div
                      class="reveal-hint-paw"
                      :style="{ backgroundImage: `url(${pawPrintsImage})` }"
                      :title="translateItem(hint)"
                    />
                    <span class="reveal-hint-text">{{ translateItem(hint) }}</span>
                  </div>
                </template>
                <span v-else class="reveal-no-hints">Нет подсказок</span>
              </div>
            </div>
          </div>
          <div v-if="accuseError" style="color:#b00020; margin-bottom:10px; font-size:12px; text-align:center;">
            {{ accuseError }}
          </div>
          <div style="display:flex; gap:10px; justify-content:center;">
            <button
              type="button"
              class="action-modal-btn action-modal-btn--confirm"
              @click="closeRevealModal"
            >
              Закрыть
            </button>
            <button
              type="button"
              class="action-modal-btn action-modal-btn--accuse"
              @click="handleAccuse(revealModalSuspect.susname)"
              :disabled="!canAccuse || accuseLoading"
            >
              {{ accuseLoading ? '...' : 'Обвинить' }}
            </button>
          </div>
        </div>
      </div>

      <!-- =========================
         BLOCK: REVEAL CLUE MODAL (подсказка открывается → переворот → название + цвет)
         ========================= -->
      <div
        v-if="revealClueModalOpen && revealClueModalClue"
        class="action-modal-overlay reveal-modal-overlay"
        @click.self="revealClueModalOpen = false"
      >
        <div class="action-modal reveal-modal reveal-clue-modal">
          <div class="reveal-clue-modal-body">
            <div class="reveal-clue-card-wrapper">
              <div 
                class="reveal-clue-card"
                :class="{
                  'reveal-clue-card--flipped': revealClueModalOpen,
                  'reveal-clue-card--has-item': revealClueModalClue.fox_has_item === true,
                  'reveal-clue-card--no-item': revealClueModalClue.fox_has_item === false
                }"
              >
                <div class="reveal-clue-card-back">
                  <div
                    class="reveal-clue-paw-back"
                    :style="{ backgroundImage: `url(${pawPrintsImage})` }"
                  />
                </div>
                <div class="reveal-clue-card-front">
                  <div class="reveal-clue-name">{{ translateItem(revealClueModalClue.item_name) }}</div>
                  <div 
                    class="reveal-clue-indicator"
                    :class="{
                      'reveal-clue-indicator--has-item': revealClueModalClue.fox_has_item === true,
                      'reveal-clue-indicator--no-item': revealClueModalClue.fox_has_item === false
                    }"
                  >
                    <span v-if="revealClueModalClue.fox_has_item === true">🟢 Есть у лиса</span>
                    <span v-else-if="revealClueModalClue.fox_has_item === false">🔴 Нет у лиса</span>
                    <span v-else>❓ Неизвестно</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <button
            type="button"
            class="action-modal-btn action-modal-btn--confirm"
            @click="revealClueModalOpen = false"
          >
            Закрыть
          </button>
        </div>
      </div>

      <!-- =========================
         BLOCK: END MODAL (WIN / LOSE) — SINGLE VERSION
         ========================= -->
      <div
        v-if="endModalOpen"
        style="
          position: fixed;
          inset: 0;
          background: rgba(0,0,0,.65);
          display:flex;
          align-items:center;
          justify-content:center;
          z-index: 9999;
        "
      >
        <div style="width: min(520px, 92vw); background:#111; color:#fff; border-radius: 14px; padding: 18px; border:2px solid #444;">
          <h2 style="margin:0 0 10px;">
            <span v-if="endModalType === 'win'">🎉 Вы победили</span>
            <span v-else>💀 Вы проиграли</span>
          </h2>

          <div style="opacity:.85; margin-bottom: 14px;">
            Игра завершена для всех игроков. Выход из комнаты.
          </div>

          <button
            @click="leaveRoom"
            style="padding:10px 14px; border-radius: 10px; border: 1px solid #666; background:#1f1f1f; color:#fff; cursor:pointer;"
          >
            Выйти в лобби
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, onBeforeUnmount, ref } from 'vue'
import { getGameState, chooseAction, movePlayer, openSuspect, accuse, skipTurn } from '../api'
import { translateItem } from '../utils/translations'
import fieldImage from '../assets/field.png'

// Функция для правильного формирования путей к статическим файлам из public
// В Vite файлы из public доступны по абсолютным путям от корня
function getPublicPath(path) {
  // Убираем ведущий слэш если есть
  const cleanPath = path.startsWith('/') ? path.slice(1) : path
  // BASE_URL уже содержит завершающий слэш (например, '/foxthegame/')
  const baseUrl = import.meta.env.BASE_URL || '/'
  // Убеждаемся что baseUrl заканчивается на /
  const base = baseUrl.endsWith('/') ? baseUrl : baseUrl + '/'
  // Объединяем base URL и путь
  return base + cleanPath
}

// Путь к изображению лапок
const pawPrintsImage = getPublicPath('paw-prints.png')
// Путь к изображению лиса
const foxImage = getPublicPath('fox.png')
// Пути к изображениям кубиков
const dice1PawImage = getPublicPath('1paw.png')
const dice2PawsImage = getPublicPath('2paws.png')
const diceEyeImage = getPublicPath('eye.png')

// Картинки подозреваемых и рубашки.
// Положи файлы в frontend/public/suspects/ с такими именами,
// либо поправь пути под свои.
const SUSPECT_BACK_IMG = getPublicPath('suspects/back.png')

// Фишки игроков (цвет из join_game / game_state.players.color)
const PLAYER_TOKEN_IMAGES = {
  'красный': getPublicPath('red.png'),
  red: getPublicPath('red.png'),
  'желтый': getPublicPath('yellow.png'),
  yellow: getPublicPath('yellow.png'),
  'синий': getPublicPath('blue.png'),
  blue: getPublicPath('blue.png'),
  'зеленый': getPublicPath('green.png'),
  green: getPublicPath('green.png')
}

function getPlayerTokenImage(color) {
  if (!color) return null
  const key = String(color).trim().toLowerCase()
  return PLAYER_TOKEN_IMAGES[key] || null
}

const SUSPECT_IMAGES = {
  Ted: getPublicPath('suspects/ted.png'),
  Alice: getPublicPath('suspects/alice.png'),
  Vera: getPublicPath('suspects/vera.png'),
  Oliver: getPublicPath('suspects/oliver.png'),
  Kevin: getPublicPath('suspects/kevin.png'),
  Ralph: getPublicPath('suspects/ralf.png'),
  Eva: getPublicPath('suspects/eva.png'),
  Lucy: getPublicPath('suspects/lucy.png'),
  Julia: getPublicPath('suspects/julia.png'),
  Mary: getPublicPath('suspects/mary.png'),
  Lily: getPublicPath('suspects/lily.png'),
  Patrick: getPublicPath('suspects/patrick.png'),
  Daisy: getPublicPath('suspects/daisy.png'),
  Neil: getPublicPath('suspects/neil.png'),
  Anna: getPublicPath('suspects/anna.png'),
  Claire: getPublicPath('suspects/claire.png'),
}

/* =========================================================
   BLOCK 0: CONFIG
   ========================================================= */
const LEAVE_ROUTE_NAME = 'LobbyPage' // <-- у тебя LobbyPage.vue: роут по name

/* =========================================================
   BLOCK 1: PROPS / ROUTER
   ========================================================= */
const props = defineProps({
  login: { type: String, required: true },
  gameId: { type: [String, Number], required: true },
})

/* =========================================================
   BLOCK 2: SERVER SNAPSHOT STATE (game_state)
   ========================================================= */
const state = ref(null)
const error = ref('')
const pollCount = ref(0)

/* =========================================================
   BLOCK 3: GLOBAL END MODAL (from server snapshot)
   - endModalType: 'win' | 'lose' | null
   - endModalOpen: boolean
   ========================================================= */
const endModalOpen = ref(false)
const endModalType = ref(null) // 'win' | 'lose' | null

/* =========================================================
   BLOCK 4: UI MESSAGES / LOADERS
   ========================================================= */
const actionLoading = ref(false)
const actionMsg = ref('')

const moveLoading = ref(false)
const moveMsg = ref('')
const moveError = ref('')

const suspectLoading = ref(false)
const suspectMsg = ref('')
const suspectError = ref('')

const accuseLoading = ref(false)
const accuseMsg = ref('')
const accuseError = ref('')

const skipLoading = ref(false)
const skipMsg = ref('')

/* =========================================================
   BLOCK 4.5: ACTION MODAL & DICE ANIMATION
   ========================================================= */
const showActionModal = ref(false)
const diceRolling = ref(false)
const diceValues = ref([1, 1, 1])
const diceResult = ref(null)
const pendingDirection = ref(null) // 'clue' | 'suspect' | null
const diceAnimationImages = ref([diceEyeImage, diceEyeImage, diceEyeImage]) // Изображения для анимации

/* =========================================================
   BLOCK 4.6: REVEAL SUSPECT MODAL (flip → modal with hints → close → flip back)
   ========================================================= */
const revealModalOpen = ref(false)
const revealModalSuspect = ref(null) // { susname, hints: string[] } | null
const revealedCardSusname = ref(null) // карточка перевёрнута, пока открыта модалка

/* =========================================================
   BLOCK 4.7: REVEAL CLUE MODAL (подсказка открывается → модалка с переворотом → цвет)
   ========================================================= */
const revealClueModalOpen = ref(false)
const revealClueModalClue = ref(null) // { item_name, fox_has_item } | null

/* =========================================================
   BLOCK 5: POLLING CONTROL
   ========================================================= */
let alive = true
let timer = null
let inFlight = false

function stopPolling() {
  alive = false
  if (timer) clearTimeout(timer)
  timer = null
}

/* =========================================================
   BLOCK 6: TURN TIMER CONTROL
   ========================================================= */
const turnLeft = ref(0)
let turnTimer = null
let turnKey = ''

function stopTurnTimer() {
  if (turnTimer) {
    clearInterval(turnTimer)
    turnTimer = null
  }
  turnLeft.value = 0
}

function startTurnTimer(seconds) {
  stopTurnTimer()
  turnLeft.value = seconds

  turnTimer = setInterval(async () => {
    if (!isMyTurn.value || isGameOver.value) {
      stopTurnTimer()
      return
    }

    turnLeft.value -= 1

    if (turnLeft.value <= 0) {
      stopTurnTimer()
      try {
        await skipTurn(props.gameId, props.login)
        await poll()
      } catch (e) {
        error.value = e?.message || 'timeout skip error'
      }
    }
  }, 1000)
}

/* =========================================================
   BLOCK 7: DERIVED COMPUTEDS (core flags)
   ========================================================= */
const me = computed(() => state.value?.players?.find(p => p.login === props.login) || null)

const isMyTurn = computed(() => {
  if (!me.value) return false
  return state.value?.game?.current_seat === me.value.seatnumber
})

const isGameOver = computed(() => {
  const foxpos = (state.value?.fox?.foxpos ?? 0)
  return (
    endModalOpen.value === true ||
    state.value?.game?.game_over === true ||
    foxpos < 0 || // win/lose encoded OR any global end
    foxpos >= 37
  )
})

const myPending = computed(() => {
  const pa = state.value?.pending_actions
  if (!Array.isArray(pa)) return null
  return pa.find(a => a.login === props.login) || null
})

const hasPending = computed(() => !!(myPending.value && myPending.value.direction !== null))

/* =========================================================
   BLOCK 8: ACTION PERMISSIONS (single source of truth)
   ========================================================= */
const canChooseAction = computed(() => {
  if (endModalOpen.value) return false
  if (!state.value) return false
  if (isGameOver.value) return false
  if (!isMyTurn.value) return false
  if (hasPending.value) return false
  return true
})

const canSkipTurn = computed(() => {
  if (endModalOpen.value) return false
  if (!state.value) return false
  if (isGameOver.value) return false
  if (!isMyTurn.value) return false
  if (hasPending.value) return false
  return true
})

const canAccuse = computed(() => {
  if (endModalOpen.value) return false
  if (!state.value) return false
  if (isGameOver.value) return false
  if (!isMyTurn.value) return false
  return true
})

/* =========================================================
   BLOCK 9: MOVE MODES (clue move / suspect open)
   ========================================================= */
const lastMaxSteps = ref(null)
const saved = localStorage.getItem(`max_steps_${props.gameId}_${props.login}`)
if (saved && !Number.isNaN(Number(saved))) {
  lastMaxSteps.value = Number(saved)
}
// max_steps: из модалки/локального кэша ИЛИ из state.pending_actions (после poll/refresh)
const myMaxSteps = computed(() => {
  const fromState = myPending.value?.direction === 'clue' && myPending.value?.result === true && myPending.value?.max_steps
  const num = fromState != null ? Number(myPending.value.max_steps) : lastMaxSteps.value
  return typeof num === 'number' && !Number.isNaN(num) ? num : null
})

const moveMode = computed(() => {
  return !!state.value &&
    !isGameOver.value &&
    isMyTurn.value &&
    myPending.value?.direction === 'clue' &&
    myPending.value?.result === true &&
    typeof myMaxSteps.value === 'number' &&
    myMaxSteps.value > 0
})

const suspectMode = computed(() => {
  return !!state.value &&
    !isGameOver.value &&
    isMyTurn.value &&
    myPending.value?.direction === 'suspect' &&
    myPending.value?.result === true
})

/* =========================================================
   BLOCK 10: SUSPECT LISTS (open/closed)
   ========================================================= */
const allSuspects = computed(() => {
  const arr = state.value?.suspects
  if (!Array.isArray(arr)) return []
  return arr.slice().sort((a, b) => (a.susname || '').localeCompare(b.susname || ''))
})

const closedSuspects = computed(() => {
  const arr = state.value?.suspects
  if (!Array.isArray(arr)) return []
  return arr.filter(s => (s.status !== 'вскрыт' && s.status !== 'opened'))
})

const openedSuspects = computed(() => {
  const arr = state.value?.suspects
  if (!Array.isArray(arr)) return []
  return arr
    .filter(s => (s.status === 'вскрыт' || s.status === 'opened'))
    .slice()
    .sort((a, b) => (a.susname || '').localeCompare(b.susname || ''))
})

const revealModalCardImage = computed(() => {
  const s = revealModalSuspect.value
  return s ? getSuspectImage({ susname: s.susname }) : null
})

/* =========================================================
   BLOCK 11: CLUES (opened list + styles)
   ========================================================= */
function isClueOpened(c) {
  if (!c) return false
  return c.status === 'вскрыт' || c.status === 'opened'
}

const openedClues = computed(() => {
  const arr = state.value?.clues
  if (!Array.isArray(arr)) return []
  return arr
    .filter(c => isClueOpened(c))
    .slice()
    .sort((a, b) => (a.item_name || '').localeCompare(b.item_name || ''))
})

function isSuspectOpened(s) {
  if (!s) return false
  return s.status === 'вскрыт' || s.status === 'opened'
}

function getSuspectImage(s) {
  if (!s) return null
  // Сначала пробуем использовать image_path из БД (если есть)
  if (s.image_path) {
    return s.image_path
  }
  // Иначе используем статический объект
  return SUSPECT_IMAGES[s.susname] || null
}

function clueBadgeStyle(clue) {
  const base = {
    display: 'inline-flex',
    alignItems: 'center',
    gap: '8px',
    padding: '8px 10px',
    borderRadius: '10px',
    border: '2px solid #666',
    background: '#1f1f1f',
    color: '#fff',
    fontSize: '13px',
    lineHeight: '1',
  }

  if (clue?.fox_has_item === true) base.border = '2px solid #4caf50'
  else if (clue?.fox_has_item === false) base.border = '2px solid #f44336'
  else base.border = '2px solid #88c'

  return base
}

function cluePawStyle(clue) {
  const base = {
    display: 'inline-block',
    cursor: 'pointer',
    transition: 'transform 0.2s',
  }

  if (clue?.fox_has_item === true) {
    base.filter = 'drop-shadow(0 0 4px #4caf50)'
  } else if (clue?.fox_has_item === false) {
    base.filter = 'drop-shadow(0 0 4px #f44336)'
  }

  return base
}

/* =========================================================
   BLOCK 12: POLL GAME STATE
   - fetch snapshot
   - update state
   - detect global end (serverResult)
   - update turn timer
   ========================================================= */
async function poll() {
  if (!alive) return
  if (inFlight) {
    timer = setTimeout(poll, 200)
    return
  }

  inFlight = true
  pollCount.value++

  try {
    const s = await getGameState(props.gameId)
    state.value = s
    error.value = ''

    // ===== BLOCK: detect global end from server =====
    const serverResult = s?.game?.result ?? null
    const foxposNow = (s?.fox?.foxpos ?? 0)
    const gameOverNow = (s?.game?.game_over === true) || (foxposNow < 0) || (foxposNow >= 37)

    // Показываем модалку окончания игры если игра закончена
    if (!endModalOpen.value && gameOverNow) {
      // Определяем тип результата
      let resultType = serverResult
      if (!resultType) {
        // Если сервер не вернул result, определяем сами
        if (foxposNow === -2) resultType = 'win'
        else if (foxposNow === -1 || foxposNow >= 37) resultType = 'lose'
      }
      
      if (resultType) {
        endModalType.value = resultType
        endModalOpen.value = true
        stopTurnTimer()
        stopPolling()
        return
      }
    }

    // ---- Timer logic based on SNAPSHOT "s" (avoid race with computed) ----
    const seconds = Number(s?.game?.turntime || 60)

    const myPlayer = Array.isArray(s?.players) ? s.players.find(p => p.login === props.login) : null
    const mySeat = myPlayer?.seatnumber

    const myPendingNow = Array.isArray(s?.pending_actions)
      ? s.pending_actions.find(a => a.login === props.login)
      : null

    const newKey = `${s?.game?.current_seat}|${myPendingNow?.direction ?? 'none'}|${myPendingNow?.result ?? 'none'}`

    if (mySeat && (s?.game?.current_seat === mySeat) && !gameOverNow) {
      if (turnKey !== newKey) {
        turnKey = newKey
        startTurnTimer(seconds)
        
        // Очищаем старые результаты при изменении состояния хода
        // НО не очищаем если есть активный diceResult - пользователь должен его увидеть
        if (!diceResult.value) {
          diceRolling.value = false
          pendingDirection.value = null
        }
      }
      
      // Автоматически показываем модальное окно при начале хода
      // Только если нет pending action и модальное окно еще не открыто
      const hasNoPending = !myPendingNow || !myPendingNow.direction
      if (hasNoPending && canChooseAction.value && !showActionModal.value && !diceRolling.value && !diceResult.value) {
        showActionModal.value = true
      }
    } else {
      turnKey = newKey
      stopTurnTimer()
      // Закрываем модальное окно, если не наш ход И нет показанного результата
      // Если есть diceResult, пользователь должен сам закрыть модалку (даже если ход переключился)
      if (!isMyTurn.value && !diceResult.value) {
        showActionModal.value = false
        diceResult.value = null
        pendingDirection.value = null
      }
      // Если есть diceResult, но ход переключился - оставляем модалку открытой, чтобы показать результат
      if (!isMyTurn.value && diceResult.value) {
        // Модалка остается открытой, чтобы пользователь увидел результат неуспеха
        showActionModal.value = true
      }
    }
  } catch (e) {
    error.value = e?.message || 'Ошибка game_state'
  } finally {
    inFlight = false
    timer = setTimeout(poll, 1000)
  }
}

/* =========================================================
   BLOCK 13: LIFECYCLE
   ========================================================= */
onMounted(() => {
  alive = true
  poll()
})

onBeforeUnmount(() => {
  alive = false
  if (timer) clearTimeout(timer)
  stopTurnTimer()
})

/* =========================================================
   BLOCK 14: ACTION HANDLERS (choose / skip / open suspect / accuse)
   ========================================================= */
// Анимация кубиков
function startDiceRoll(direction) {
  // Очищаем все старые результаты перед новым броском
  diceResult.value = null
  diceRolling.value = false
  pendingDirection.value = null
  
  // Устанавливаем новые значения
  pendingDirection.value = direction
  diceRolling.value = true
  
  // Массив всех возможных изображений для анимации
  const allDiceImages = [dice1PawImage, dice2PawsImage, diceEyeImage]
  
  // Анимация смены изображений кубиков (показываем случайные изображения)
  let rollCount = 0
  const rollInterval = setInterval(() => {
    // Меняем изображения на случайные для каждого кубика
    diceAnimationImages.value = [
      allDiceImages[Math.floor(Math.random() * allDiceImages.length)],
      allDiceImages[Math.floor(Math.random() * allDiceImages.length)],
      allDiceImages[Math.floor(Math.random() * allDiceImages.length)]
    ]
    rollCount++
    
    // Анимируем 12-15 раз для более реалистичного эффекта
    if (rollCount >= 15) {
      clearInterval(rollInterval)
      // Вызываем реальный API для получения результата
      performChooseAction(direction)
    }
  }, 120) // Быстрая смена изображений - 120ms для более плавной анимации
}

async function performChooseAction(direction) {
  actionLoading.value = true
  
  try {
    const resp = await chooseAction(props.gameId, props.login, direction)
    
    // Определяем успех по результату API
    const success = resp?.success === true
    
    // Используем значения кубиков из ответа API (0 или 1)
    const finalValues = resp?.dice || [0, 0, 0]
    
    // Получаем max_steps из ответа (только при success и direction = 'clue')
    const maxSteps = (success && pendingDirection.value === 'clue') ? (resp?.max_steps || 0) : 0
    
    // Останавливаем анимацию и показываем результат
    diceRolling.value = false
    
    // Небольшая задержка перед показом результата для плавности
    await new Promise(resolve => setTimeout(resolve, 200))
    
    diceResult.value = {
      success,
      direction,
      values: finalValues,
      max_steps: maxSteps,
      response: resp
    }
    
    diceValues.value = finalValues
    
    // Убеждаемся, что модалка открыта для показа результата
    showActionModal.value = true
    
  } catch (e) {
    diceRolling.value = false
    diceResult.value = {
      success: false,
      direction: pendingDirection.value || 'clue',
      values: [0, 0, 0],
      max_steps: 0,
      error: e?.message
    }
    diceValues.value = [0, 0, 0]
    // Убеждаемся, что модалка открыта для показа результата неуспеха
    showActionModal.value = true
  } finally {
    actionLoading.value = false
  }
}

function openActionModal() {
  // Очищаем все старые результаты перед открытием модалки
  diceResult.value = null
  diceRolling.value = false
  pendingDirection.value = null
  showActionModal.value = true
}

function confirmDiceResult() {
  if (!diceResult.value) return
  
  const direction = diceResult.value.direction ?? pendingDirection.value
  const success = diceResult.value.success
  const response = diceResult.value.response
  
  // Сохраняем max_steps перед очисткой
  if (success && direction === 'clue' && response?.max_steps) {
    lastMaxSteps.value = response.max_steps
    localStorage.setItem(`max_steps_${props.gameId}_${props.login}`, String(response.max_steps))
  } else {
    lastMaxSteps.value = null
  }
  
  // Закрываем модальное окно только по клику пользователя
  showActionModal.value = false
  diceResult.value = null
  pendingDirection.value = null
  diceRolling.value = false
  
  // Обновляем состояние игры
  poll()
}

async function handleChoose(direction) {
  actionMsg.value = ''
  moveMsg.value = ''
  moveError.value = ''

  if (!canChooseAction.value) return
  if (actionLoading.value) return

  // Показываем модальное окно вместо прямого вызова
  pendingDirection.value = direction
  startDiceRoll(direction)
}

async function handleSkipTurn() {
  skipMsg.value = ''
  if (!canSkipTurn.value) return
  if (skipLoading.value) return

  skipLoading.value = true
  try {
    const resp = await skipTurn(props.gameId, props.login)
    skipMsg.value = JSON.stringify(resp)
    stopTurnTimer()
    await poll()
  } catch (e) {
    skipMsg.value = e?.message || 'skip error'
  } finally {
    skipLoading.value = false
  }
}

/** Возвращает resp при успехе (с hints), иначе null. */
async function handleOpenSuspect(name) {
  suspectMsg.value = ''
  suspectError.value = ''
  
  // Проверяем suspectMode или наличие pending action с direction='suspect' и result=true
  const hasSuspectPending = myPending.value?.direction === 'suspect' && myPending.value?.result === true
  if (!suspectMode.value && !hasSuspectPending) return null
  if (suspectLoading.value) return null

  suspectLoading.value = true
  try {
    const resp = await openSuspect(props.gameId, props.login, name)
    suspectMsg.value = JSON.stringify(resp)
    stopTurnTimer()
    await poll()
    return resp
  } catch (e) {
    suspectError.value = e?.message || 'open_suspect error'
    return null
  } finally {
    suspectLoading.value = false
  }
}

// Переворот карточки → модалка с подсказками → по "Закрыть" карточка рубашкой вверх
function openRevealModal(suspect) {
  const hints = suspect.hints && suspect.hints.length ? [...suspect.hints] : []
  revealedCardSusname.value = suspect.susname
  revealModalSuspect.value = { susname: suspect.susname, hints }
  setTimeout(() => {
    revealModalOpen.value = true
  }, 400)
}

function closeRevealModal() {
  revealModalOpen.value = false
  revealModalSuspect.value = null
  revealedCardSusname.value = null
}

// Обработчик клика на карточку подозреваемого на поле
async function handleSuspectCardClick(suspect) {
  if (suspectLoading.value) return
  if (revealModalOpen.value) return

  if (isSuspectOpened(suspect)) {
    // Уже открыт — только показываем модалку с подсказками, карточка переворачивается и по закрытию — обратно
    revealedCardSusname.value = suspect.susname
    revealModalSuspect.value = { susname: suspect.susname, hints: suspect.hints || [] }
    setTimeout(() => {
      revealModalOpen.value = true
    }, 400)
    return
  }

  // Проверяем suspectMode или наличие pending action с direction='suspect' и result=true
  const hasSuspectPending = myPending.value?.direction === 'suspect' && myPending.value?.result === true
  if (!suspectMode.value && !hasSuspectPending) return

  const resp = await handleOpenSuspect(suspect.susname)
  if (resp) {
    const hints = (resp.hints && resp.hints.length) ? resp.hints : []
    openRevealModal({ susname: suspect.susname, hints })
  }
}

async function handleAccuse(name) {
  accuseMsg.value = ''
  accuseError.value = ''

  if (!canAccuse.value) return
  if (accuseLoading.value) return

  accuseLoading.value = true
  try {
    const resp = await accuse(props.gameId, props.login, name)
    accuseMsg.value = JSON.stringify(resp)

    stopTurnTimer()
    await poll() // сервер выставит итог, а poll откроет модалку всем
  } catch (e) {
    accuseError.value = e?.message || 'Ошибка обвинения'
  } finally {
    accuseLoading.value = false
  }
}

/* =========================================================
   BLOCK 15: MOVE HANDLER (board click)
   ========================================================= */
function manhattan(fromX, fromY, toX, toY) {
  return Math.abs(fromX - toX) + Math.abs(fromY - toY)
}

async function onCellClick(x, y) {
  moveMsg.value = ''
  moveError.value = ''

  if (!moveMode.value) return
  if (moveLoading.value) return
  if (!me.value) return
  if (isGameOver.value) return

  const dist = manhattan(me.value.x, me.value.y, x, y)
  if (dist > myMaxSteps.value) {
    moveError.value = `Слишком далеко: расстояние ${dist} > максимальных шагов ${myMaxSteps.value}`
    return
  }

  // Проверяем, что на клетке нет другого игрока
  const playerAtCell = playerAt(x, y)
  if (playerAtCell && playerAtCell.login !== props.login) {
    moveError.value = 'На этой клетке уже есть другой игрок'
    return
  }

  moveLoading.value = true
  try {
    const resp = await movePlayer(props.gameId, props.login, myMaxSteps.value, x, y)
    moveMsg.value = `move ok: ${JSON.stringify(resp)}`

    lastMaxSteps.value = null
    localStorage.removeItem(`max_steps_${props.gameId}_${props.login}`)

    stopTurnTimer()
    await poll()
    
    // Проверяем, была ли открыта подсказка
    if (resp?.opened_clue) {
      // Ждем немного, чтобы state обновился после poll
      setTimeout(() => {
        // Находим открытую подсказку в state по координатам и названию
        const openedClue = state.value?.clues?.find(c => 
          c.x === x && 
          c.y === y &&
          c.item_name === resp.opened_clue && 
          (c.status === 'вскрыт' || c.status === 'opened')
        )
        if (openedClue) {
          revealClueModalClue.value = {
            item_name: openedClue.item_name,
            fox_has_item: openedClue.fox_has_item
          }
          setTimeout(() => {
            revealClueModalOpen.value = true
          }, 100)
        }
      }, 100)
    }
  } catch (e) {
    moveError.value = e?.message || 'move error'
  } finally {
    moveLoading.value = false
  }
}

/* =========================================================
   BLOCK 16: BOARD HELPERS (cells / playerAt / clueAt / style)
   ========================================================= */
const cells = computed(() => {
  const out = []
  for (let y = 1; y <= 18; y++) {
    for (let x = 1; x <= 18; x++) {
      out.push({ x, y, key: `${x}-${y}` })
    }
  }
  return out
})

function playerAt(x, y) {
  return state.value?.players?.find(p => p.x === x && p.y === y) || null
}

function clueAt(x, y) {
  return state.value?.clues?.find(c => c.x === x && c.y === y) || null
}

// Вычисляет позицию лиса на поле по foxpos (путь по серым клеткам дороги)
// Маршрут: (2,1) → вниз 4 → вправо 10 → вниз 7 → вправо 7 → вниз 4 → влево 4 → вниз 4 → вправо 2 → вниз 3
function getFoxPosition() {
  const foxpos = state.value?.fox?.foxpos ?? 0
  if (foxpos < 0 || foxpos >= 37) return null
  
  // Сегмент 1: Вниз 4 клетки от (2,1) - позиции 0-3
  if (foxpos < 4) {
    return { x: 2, y: foxpos + 1 }
  }
  // Сегмент 2: Вправо 10 клеток от (2,4) - позиции 3-12
  if (foxpos < 13) {
    return { x: foxpos - 2, y: 4 }
  }
  // Сегмент 3: Вниз 7 клеток от (11,4) - позиции 12-18
  if (foxpos < 19) {
    return { x: 11, y: foxpos - 8 }
  }
  // Сегмент 4: Вправо 7 клеток от (11,10) - позиции 18-24
  if (foxpos < 25) {
    return { x: foxpos - 7, y: 10 }
  }
  // Сегмент 5: Вниз 4 клетки от (17,10) - позиции 24-27
  if (foxpos < 28) {
    return { x: 17, y: foxpos - 14 }
  }
  // Сегмент 6: Влево 4 клетки от (17,13) - позиции 27-30
  if (foxpos < 31) {
    return { x: 44 - foxpos, y: 13 }
  }
  // Сегмент 7: Вниз 4 клетки от (14,13) - позиции 30-33
  if (foxpos < 34) {
    return { x: 14, y: foxpos - 17 }
  }
  // Сегмент 8: Вправо 2 клетки от (14,16) - позиции 33-35
  if (foxpos < 36) {
    return { x: foxpos - 19, y: 16 }
  }
  // Сегмент 9: Вниз 3 клетки от (16,16) - позиции 35-37
  return { x: 16, y: foxpos - 19 }
}

function foxAt(x, y) {
  const foxPos = getFoxPosition()
  if (!foxPos) return false
  return foxPos.x === x && foxPos.y === y
}

// Функция для получения изображения кубика по индексу
// success: успех броска (true/false)
// maxSteps: максимальное количество шагов (3-6, только при success=true и direction='clue')
// index: индекс кубика (0, 1, 2)
// direction: тип действия ('clue' | 'suspect')
function getDiceImageForAnimation(index, success, maxSteps, direction = 'clue') {
  // Для 'suspect' логика обратная: success=true → eye, success=false → paws
  if (direction === 'suspect') {
    if (success) {
      // При успехе для suspect показываем eye на всех кубиках
      return diceEyeImage
    } else {
      // При неудаче для suspect показываем лапки (1paw на всех)
      return dice1PawImage
    }
  }
  
  // Для 'clue' обычная логика: success=true → paws, success=false → eye
  if (!success) {
    // При неудаче показываем eye на всех кубиках
    return diceEyeImage
  }
  
  // При успехе для 'clue' распределяем лапы так, чтобы сумма была равна max_steps
  // max_steps может быть от 3 до 6
  if (maxSteps === 3) {
    // 1 + 1 + 1 = 3
    return dice1PawImage
  } else if (maxSteps === 4) {
    // 2 + 1 + 1 = 4
    if (index === 0) return dice2PawsImage
    return dice1PawImage
  } else if (maxSteps === 5) {
    // 2 + 2 + 1 = 5
    if (index === 2) return dice1PawImage
    return dice2PawsImage
  } else if (maxSteps === 6) {
    // 2 + 2 + 2 = 6
    return dice2PawsImage
  } else {
    // Если max_steps не указан - показываем 1paw на всех
    return dice1PawImage
  }
}

function cellTitle(x, y) {
  const p = playerAt(x, y)
  const c = clueAt(x, y)
  const parts = [`(${x},${y})`]
  if (p) parts.push(`player:${p.login}`)
  if (c) parts.push(`clue:${translateItem(c.item_name)} (${c.status})`)
  return parts.join(' | ')
}

function cellStyle(x, y) {
  const base = {
    border: '1px solid transparent',
    borderRadius: '8px',
    padding: '2px',
    cursor: 'default',
    background: 'transparent',
    color: '#fff',
    opacity: 1,
  }

  const p = playerAt(x, y)
  const c = clueAt(x, y)

  if (moveMode.value && me.value) {
    const dist = manhattan(me.value.x, me.value.y, x, y)
    if (dist <= myMaxSteps.value) {
      base.cursor = 'pointer'
      base.border = '2px solid #4caf50'
      base.backgroundColor = 'rgba(76, 175, 80, 0.2)'
      base.boxShadow = '0 0 8px rgba(76, 175, 80, 0.5)'
    } else {
      // Убрано изменение opacity - подсказки всегда должны быть четко видны
      base.cursor = 'not-allowed'
    }
  }

  if (c && isClueOpened(c)) {
    if (c.fox_has_item === true) base.border = '2px solid #4caf50'
    else if (c.fox_has_item === false) base.border = '2px solid #f44336'
    else base.border = '2px solid #88c'
  }

  // Убрана обводка для клеток с игроками - фишки теперь видны без обводки
  return base
}

function cardPositionClass(idx) {
  const pos = idx % 16
  if (pos < 4) return `card-pos-top-${pos + 1}`
  if (pos < 8) return `card-pos-right-${pos - 3}`
  if (pos < 12) return `card-pos-bottom-${pos - 7}`
  return `card-pos-left-${pos - 11}`
}

/* =========================================================
   BLOCK 17: STATUS TEXT
   ========================================================= */
const statusText = computed(() => {
  if (!state.value) return 'Загрузка...'
  if (endModalOpen.value) return endModalType.value === 'win' ? 'ПОБЕДА' : 'ПОРАЖЕНИЕ'
  if (isGameOver.value) return 'Игра окончена'
  if (!me.value) return 'Вы ещё не присоединились? (нет me в players)'
  return isMyTurn.value ? 'Ваш ход' : 'Ожидание хода...'
})

const json = computed(() => JSON.stringify(state.value, null, 2))

const emit = defineEmits(['leave'])

function leaveRoom() {
  emit('leave')
}

</script>

<style scoped src="../styles/game-page.css"></style>
