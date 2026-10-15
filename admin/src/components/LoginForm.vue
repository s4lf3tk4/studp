<script setup>
import { ref } from 'vue';
import { api } from '../api';

const emit = defineEmits(['logged-in']);

const login = ref('');
const password = ref('');
const error = ref('');
const busy = ref(false);

const submit = async () => {
  busy.value = true;
  error.value = '';

  try {
    await api('/admin/login', 'POST', { login: login.value, password: password.value });
    password.value = '';
    emit('logged-in');
  } catch (e) {
    error.value = e.message === 'unauthenticated' ? 'Неверный логин или пароль' : e.message;
  } finally {
    busy.value = false;
  }
};
</script>

<template>
  <div class="card login">
    <h1>Вход</h1>
    <div class="stack">
      <input v-model="login" type="text" placeholder="Логин" autocomplete="username" @keyup.enter="submit" />
      <input v-model="password" type="password" placeholder="Пароль" autocomplete="current-password" @keyup.enter="submit" />
      <button :disabled="busy" @click="submit">Войти</button>
      <div v-if="error" class="err">{{ error }}</div>
    </div>
  </div>
</template>