<template>
  <div class="login-container">
    <div class="login-content" :class="{ 'login-content--visible': fieldsVisible }">
      <h2 class="login-title">{{ isRegisterMode ? 'Регистрация' : 'Вход' }}</h2>

      <div class="login-tabs">
        <button
          @click="isRegisterMode = false"
          class="login-tab"
          :class="{ 'login-tab--active': !isRegisterMode }"
        >
          Вход
        </button>
        <button
          @click="isRegisterMode = true"
          class="login-tab"
          :class="{ 'login-tab--active': isRegisterMode }"
        >
          Регистрация
        </button>
      </div>

      <label class="login-field">
        Логин
        <input
          v-model.trim="login"
          @keydown.enter="submit"
          placeholder="например: s368719"
          class="login-input"
        />
        <div v-if="isRegisterMode" class="login-hint">
          3-30 символов, только буквы, цифры и подчёркивание
        </div>
      </label>

      <label class="login-field">
        Пароль
        <input
          v-model="password"
          type="password"
          @keydown.enter="submit"
          :placeholder="isRegisterMode ? 'минимум 4 символа' : 'пароль'"
          class="login-input"
        />
        <div v-if="isRegisterMode" class="login-hint">
          4-50 символов
        </div>
      </label>

      <button 
        :disabled="!login || !password || loading" 
        @click="submit" 
        class="login-submit"
        :class="{ 'login-submit--disabled': !login || !password || loading }"
      >
        {{ loading ? '...' : (isRegisterMode ? 'Зарегистрироваться' : 'Войти') }}
      </button>

      <p v-if="error" class="login-error">{{ error }}</p>
      <p v-if="success" class="login-success">{{ success }}</p>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { authLogin, createPlayer } from '../api'

const emit = defineEmits(['login', 'enter-game'])

const isRegisterMode = ref(false)
const login = ref(localStorage.getItem('login') || '')
const password = ref('')
const loading = ref(false)
const error = ref('')
const success = ref('')
const fieldsVisible = ref(false)

onMounted(() => {
  // Задержка появления полей
  setTimeout(() => {
    fieldsVisible.value = true
  }, 500)
})

async function submit() {
  error.value = ''
  success.value = ''
  if (!login.value || !password.value) return
  loading.value = true
  
  try {
    if (isRegisterMode.value) {
      // Регистрация
      const data = await createPlayer(login.value, password.value)
      if (!data?.ok) throw new Error(data?.error || 'Registration failed')
      if (data?.token) {
        const storage = typeof sessionStorage !== 'undefined' ? sessionStorage : localStorage
        storage.setItem('token', String(data.token).trim())
      }
      success.value = 'Регистрация успешна! Выполняется вход...'
      // Автоматически входим после регистрации
      setTimeout(() => {
        emit('login', login.value)
      }, 1000)
    } else {
      // Вход
      const data = await authLogin(login.value, password.value)
      if (!data?.ok) throw new Error(data?.error || 'Auth failed')
      if (data?.token) {
        const storage = typeof sessionStorage !== 'undefined' ? sessionStorage : localStorage
        storage.setItem('token', String(data.token).trim())
      }
      emit('login', login.value)
      if (data?.game_id != null) {
        emit('enter-game', Number(data.game_id))
      }
    }
  } catch (e) {
    error.value = e?.message || (isRegisterMode.value ? 'Ошибка регистрации' : 'Ошибка авторизации')
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
.login-container {
  width: 100vw;
  height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background-image: url('/loginbg.png');
  background-size: 100% 100%;
  background-position: center;
  background-repeat: no-repeat;
  padding: 20px;
  box-sizing: border-box;
  position: fixed;
  top: 0;
  left: 0;
}

.login-content {
  max-width: 420px;
  width: 100%;
  padding: 24px;
  border: 1px solid #ddd;
  border-radius: 10px;
  background: rgba(255, 255, 255, 0.95);
  opacity: 0;
  transform: translateY(20px);
  transition: opacity 0.6s ease-out, transform 0.6s ease-out;
}

.login-content--visible {
  opacity: 1;
  transform: translateY(0);
}

.login-title {
  margin: 0 0 12px;
  font-family: 'Gaegu', cursive;
  color: #65430D;
  font-size: 28px;
}

.login-tabs {
  margin-bottom: 20px;
  display: flex;
  gap: 8px;
  opacity: 0;
  transform: translateY(10px);
  transition: opacity 0.5s ease-out, transform 0.5s ease-out;
}

.login-content--visible .login-tabs {
  opacity: 1;
  transform: translateY(0);
  transition-delay: 0.1s;
}

.login-tab {
  flex: 1;
  padding: 10px 16px;
  background: #f5f5f5;
  color: #666;
  border: 2px solid #ddd;
  border-radius: 6px;
  cursor: pointer;
  font-size: 14px;
  font-weight: bold;
  transition: all 0.2s;
}

.login-tab--active {
  background: #4caf50;
  color: #fff;
  border-color: #4caf50;
}

.login-field {
  display: block;
  margin-bottom: 10px;
  opacity: 0;
  transform: translateY(10px);
  transition: opacity 0.5s ease-out, transform 0.5s ease-out;
}

.login-content--visible .login-field {
  opacity: 1;
  transform: translateY(0);
}

.login-content--visible .login-field:nth-child(3) {
  transition-delay: 0.2s;
}

.login-content--visible .login-field:nth-child(4) {
  transition-delay: 0.4s;
}

.login-input {
  width: 100%;
  padding: 10px;
  margin-top: 6px;
  box-sizing: border-box;
  border: 1px solid #ddd;
  border-radius: 4px;
  font-size: 14px;
}

.login-hint {
  font-size: 12px;
  color: #666;
  margin-top: 4px;
}

.login-submit {
  width: 100%;
  padding: 10px 14px;
  margin-bottom: 12px;
  background: #4caf50;
  color: #fff;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  font-size: 16px;
  opacity: 0;
  transform: translateY(10px);
  transition: opacity 0.5s ease-out, transform 0.5s ease-out;
}

.login-content--visible .login-submit {
  opacity: 1;
  transform: translateY(0);
  transition-delay: 0.6s;
}

.login-submit--disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.login-error {
  color: #b00020;
  margin-top: 12px;
  opacity: 0;
  transform: translateY(10px);
  transition: opacity 0.5s ease-out, transform 0.5s ease-out;
}

.login-content--visible .login-error {
  opacity: 1;
  transform: translateY(0);
}

.login-success {
  color: #4caf50;
  margin-top: 12px;
  opacity: 0;
  transform: translateY(10px);
  transition: opacity 0.5s ease-out, transform 0.5s ease-out;
}

.login-content--visible .login-success {
  opacity: 1;
  transform: translateY(0);
}
</style>
