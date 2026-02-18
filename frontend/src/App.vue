<template>
  <div class="app-root" :style="{ '--bg-image': `url(${bgImage})` }">
    <div style="display:flex; gap: 8px; align-items:center; margin-bottom: 12px;">
      <strong>Fox MVP</strong>
      <span v-if="login" style="opacity:.7;">login: {{ login }}</span>
      <span v-if="gameId" style="opacity:.7;">game_id: {{ gameId }}</span>

      <div style="margin-left:auto; display:flex; gap: 8px;">
        <button v-if="gameId" @click="leaveGame">Leave game</button>
        <button v-if="login" @click="logout">Logout</button>
      </div>
    </div>

    <LoginPage v-if="!login" @login="onLogin" @enter-game="onEnterGame" />

    <LobbyPage
      v-else-if="login && !gameId"
      :login="login"
      @enter-game="onEnterGame"
    />

    <GamePage
    v-else
    :login="login"
    :gameId="gameId"
    @leave="leaveGame"
  />

  </div>
</template>

<script setup>
import { ref, watch } from 'vue'
import LoginPage from './pages/LoginPage.vue'
import LobbyPage from './pages/LobbyPage.vue'
import GamePage from './pages/GamePage.vue'
import bgImage from './assets/bg.png'
import { leaveGame as leaveGameAPI } from './api'

const login = ref(localStorage.getItem('login') || '')
const gameId = ref(localStorage.getItem('game_id') || '')

// чтобы видеть, меняется ли gameId вообще
watch(gameId, (v) => console.log('[App] gameId changed:', v))

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
