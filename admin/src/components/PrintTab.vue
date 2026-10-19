<script setup>
import { computed, ref, watch } from 'vue';
import { api } from '../api';

const props = defineProps({
  campusId: { type: Number, default: null },
  campusTitle: { type: String, default: '' },
});

const SUBTABS = [
  { key: 'campus', label: 'Выбранный корпус' },
  { key: 'all', label: 'Все корпуса' },
];

const subtab = ref('campus');
const files = ref([]);
const totalPages = ref(0);
const totalAmount = ref('');
const loading = ref(false);
const error = ref('');
const busyId = ref(null);

/** Адрес запроса зависит от под-вкладки и выбранного корпуса */
const target = computed(() => {
  if (subtab.value === 'all') {
    return '/admin/api/print';
  }

  return props.campusId === null ? null : `/admin/api/print/campuses/${props.campusId}`;
});

const load = async () => {
  error.value = '';

  if (target.value === null) {
    files.value = [];
    totalPages.value = 0;
    totalAmount.value = '';

    return;
  }

  loading.value = true;

  try {
    const data = await api(target.value);

    files.value = data.files ?? [];
    totalPages.value = data.total_pages ?? 0;
    totalAmount.value = data.total_amount ?? '';
  } catch (e) {
    error.value = e.message;
    files.value = [];
    totalPages.value = 0;
    totalAmount.value = '';
  } finally {
    loading.value = false;
  }
};

watch(() => [subtab.value, props.campusId], load, { immediate: true });

const markPrinted = async (file) => {
  busyId.value = file.id;
  error.value = '';
  notice.value = '';

  try {
    const data = await api(`/admin/api/print/${file.id}/printed`, 'POST', {});

    notice.value = data.notified
      ? `Заказ №${data.id}: документ «${file.name}» напечатан, клиент уведомлён.`
      : `Заказ №${data.id}: документ «${file.name}» напечатан, но уведомление не ушло — предупредите клиента вручную.`;

    await load();
  } catch (e) {
    error.value = e.message;
  } finally {
    busyId.value = null;
  }
};
</script>

<template>
  <div class="card">
    <div class="row space-between">
      <div class="subtabs">
        <button
          v-for="item in SUBTABS"
          :key="item.key"
          type="button"
          :class="{ active: subtab === item.key }"
          @click="subtab = item.key"
        >
          {{ item.label }}
        </button>
      </div>

      <div class="row gap-sm">
        <span class="muted">Файлов: {{ files.length }}</span>
        <span class="muted">Страниц: {{ totalPages }}</span>
        <span v-if="totalAmount" class="muted">На сумму: {{ totalAmount }}</span>
        <button class="ghost" :disabled="loading" @click="load">Обновить</button>
      </div>
    </div>

    <div v-if="loading" class="muted">Загрузка…</div>

    <div v-else-if="files.length === 0" class="muted">
      <template v-if="subtab === 'campus'">
        У корпуса «{{ campusTitle }}» нет файлов, ожидающих печати.
      </template>
      <template v-else>Ни у одного корпуса нет файлов, ожидающих печати.</template>
    </div>

    <table v-else>
      <thead>
        <tr>
          <th>№</th>
          
          <th>Файл</th>
          <th v-if="subtab === 'all'">Корпус</th>
          <th>Страниц</th>
          <th>Сумма</th>
          <th>Оплачен</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <td class="muted">{{ file.id }}</td>
        <tr v-for="file in files" :key="file.id">
          <td>
            <a :href="file.file_url" target="_blank" rel="noopener">{{ file.name }}</a>
            <div class="muted">{{ file.size }}</div>
          </td>
          <td v-if="subtab === 'all'">{{ file.campus }}</td>
          <td>{{ file.pages }}</td>
          <td>{{ file.amount }}</td>
          <td class="muted">{{ file.created_at }}</td>
          <td><button :disabled="busyId === file.id" @click="markPrinted(file)">Напечатано</button></td>
        </tr>
      </tbody>
    </table>

    <p v-if="error" class="err">{{ error }}</p>
  </div>
</template>