<script setup>
import { onMounted, ref } from 'vue';
import { api } from './api';
import LoginForm from './components/LoginForm.vue';
import ScheduleEditor from './components/ScheduleEditor.vue';

const ready = ref(false);
const authenticated = ref(false);

onMounted(async () => {
  try {
    await api('/admin/api/state');
    authenticated.value = true;
  } catch {
    authenticated.value = false;
  } finally {
    ready.value = true;
  }
});
</script>

<template>
  <div class="wrap">
    <div v-if="!ready" class="muted">Загрузка…</div>
    <LoginForm v-else-if="!authenticated" @logged-in="authenticated = true" />
    <ScheduleEditor v-else @logged-out="authenticated = false" />
  </div>
</template>