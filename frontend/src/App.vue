<template>
  <div class="app-root" :style="{ '--bg-image': `url(${bgImage})` }">
    <header v-if="login" class="app-header">
      <div class="app-header-left">
        <img src="/fox.png" alt="" class="app-header-logo" />
        <h1 class="app-header-title">Коварный лис</h1>
      </div>
      <div class="app-header-center">
        <template v-if="gameId">
          <strong class="app-header-game-num">Игра №{{ gameId }}</strong>
          <span v-if="headerPlayerLogins.length" class="app-header-game-players">({{ headerPlayerLogins.join(', ') }})</span>
        </template>
      </div>
      <div class="app-header-actions">
        <div class="app-header-user-block">
          <span class="app-header-user-nick">{{ login }}</span>
          <button v-if="gameId" type="button" class="app-header-btn app-header-btn--podlozhka" @click="leaveGame">Выйти из игры</button>
          <button v-else type="button" class="app-header-btn app-header-btn--podlozhka" @click="logout">Выйти из аккаунта</button>
        </div>
      </div>
    </header>

    <div class="app-content">
      <LoginPage v-if="!login" @login="onLogin" @enter-game="onEnterGame" />

      <LobbyPage
        v-else-if="login && !gameId"
        :login="login"
        @enter-game="onEnterGame"
        @logout="logout"
      />

      <GamePage
        v-else
        :login="login"
        :gameId="gameId"
        @leave="leaveGame"
        @header-players="headerPlayerLogins = $event"
      />
    </div>

  </div>
</template>

<style scoped>
.app-header {
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 10px 20px;
  width: 100%;
  box-sizing: border-box;
  background: #4caf50;
  color: #fff;
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.2);
  margin-bottom: 20px;
}
.app-header-left {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-shrink: 0;
}
.app-header-logo {
  width: 40px;
  height: 40px;
  object-fit: contain;
  flex-shrink: 0;
}
.app-header-title {
  margin: 0;
  font-size: 1.35rem;
  font-weight: 600;
  font-family: 'Montserrat', sans-serif;
  font-style: italic;
}
.app-header-center {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  min-width: 0;
}
.app-header-game-num {
  font-weight: 700;
}
.app-header-game-players {
  font-weight: 400;
  opacity: 0.95;
}
.app-header-actions {
  display: flex;
  gap: 8px;
  flex-shrink: 0;
}

/* Блок ник + кнопка выйти в стиле подложки (пергамент, бамбук) */
.app-header-user-block {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 6px 10px 6px 14px;
  background: rgba(255, 248, 230, 0.95);
  border: 2px solid #8B6914;
  border-radius: 999px;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
  color: #65430D;
  font-family: 'Montserrat', sans-serif;
  font-style: italic;
}

.app-header-user-nick {
  font-size: 14px;
  font-weight: 600;
  color: #65430D;
  padding-right: 4px;
}

.app-header-btn {
  padding: 6px 12px;
  border-radius: 8px;
  border: 1px solid rgba(255, 255, 255, 0.5);
  background: rgba(255, 255, 255, 0.2);
  color: #fff;
  cursor: pointer;
  font-size: 13px;
}

.app-header-btn--podlozhka {
  padding: 6px 14px;
  border-radius: 999px;
  border: 2px solid #8B6914;
  background: rgba(101, 67, 13, 0.12);
  color: #65430D;
  font-size: 13px;
  font-weight: 600;
  font-family: 'Montserrat', sans-serif;
  font-style: italic;
}

.app-header-btn--podlozhka:hover {
  background: rgba(101, 67, 13, 0.25);
}

.app-header-btn:not(.app-header-btn--podlozhka):hover {
  background: rgba(255, 255, 255, 0.3);
}
.app-content {
  flex: 1;
  min-height: 0;
  overflow: hidden;
  padding: 16px;
  box-sizing: border-box;
}
</style>

<script setup>
import { ref, watch } from 'vue'
import LoginPage from './pages/LoginPage.vue'
import LobbyPage from './pages/LobbyPage.vue'
import GamePage from './pages/GamePage.vue'
import bgImage from './assets/bg.png'
import { leaveGame as leaveGameAPI } from './api'

const login = ref(localStorage.getItem('login') || '')
const gameId = ref(localStorage.getItem('game_id') || '')
const headerPlayerLogins = ref([])

watch(gameId, () => {
  if (!gameId.value) headerPlayerLogins.value = []
})

function onLogin(newLogin) {
  console.log('[App] onLogin', newLogin)
  login.value = newLogin
  localStorage.setItem('login', newLogin)
}

function onEnterGame(newGameId) {
  console.log('[App] onEnterGame', newGameId)
  gameId.value = String(newGameId)
  localStorage.setItem('game_id', String(newGameId))
}

async function leaveGame() {
  console.log('[App] leaveGame')
  
  // Вызываем API для удаления игрока из игры
  if (gameId.value && login.value) {
    try {
      await leaveGameAPI(Number(gameId.value), login.value)
      console.log('[App] Successfully left game via API')
    } catch (error) {
      console.error('[App] Error leaving game:', error)
      // Продолжаем выход даже если API вернул ошибку
    }
  }

  gameId.value = ''
  headerPlayerLogins.value = []
  localStorage.removeItem('game_id')
}



function logout() {
  console.log('[App] logout')
  leaveGame()
  login.value = ''
  localStorage.removeItem('login')
  const storage = typeof sessionStorage !== 'undefined' ? sessionStorage : localStorage
  storage.removeItem('token')
}
</script>
