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

const FILTERS = [
  { key: 'all', label: 'Все' },
  { key: 'paid', label: 'Ждут печати' },
  { key: 'printed', label: 'Напечатаны' },
  { key: 'issued', label: 'Выданы' },
];

const subtab = ref('campus');
const filter = ref('all');
const files = ref([]);
const loading = ref(false);
const error = ref('');
const notice = ref('');
const busyId = ref(null);

/** Адрес запроса зависит от под-вкладки и выбранного корпуса */
const target = computed(() => {
  if (subtab.value === 'all') {
    return '/admin/api/print';
  }

  return props.campusId === null ? null : `/admin/api/print/campuses/${props.campusId}`;
});

/** Фильтр по статусу считается на клиенте: данные приходят целиком */
const visible = computed(() =>
  filter.value === 'all' ? files.value : files.value.filter((file) => file.status === filter.value),
);

const counts = computed(() => ({
  all: files.value.length,
  paid: files.value.filter((file) => file.status === 'paid').length,
  printed: files.value.filter((file) => file.status === 'printed').length,
  issued: files.value.filter((file) => file.status === 'issued').length,
}));

/** Итоги считаем по видимым строкам, чтобы они совпадали с фильтром */
const totalPages = computed(() => visible.value.reduce((sum, file) => sum + (file.pages ?? 0), 0));

const totalAmount = computed(() => {
  const kopecks = visible.value.reduce((sum, file) => sum + (file.amount_kopecks ?? 0), 0);

  return `${(kopecks / 100).toFixed(2).replace('.', ',')} ₽`;
});

const load = async () => {
  error.value = '';

  if (target.value === null) {
    files.value = [];

    return;
  }

  loading.value = true;

  try {
    const data = await api(target.value);
    files.value = data.files ?? [];
  } catch (e) {
    error.value = e.message;
    files.value = [];
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

const markIssued = async (file) => {
  busyId.value = file.id;
  error.value = '';
  notice.value = '';

  try {
    const data = await api(`/admin/api/print/${file.id}/issued`, 'POST', {});

    notice.value = `Заказ №${data.id}: клиент забрал документ, файл удалён с диска.`;

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
        <span class="muted">Файлов: {{ visible.length }}</span>
        <span class="muted">Страниц: {{ totalPages }}</span>
        <span v-if="totalPages" class="muted">На сумму: {{ totalAmount }}</span>
        <button class="ghost" :disabled="loading" @click="load">Обновить</button>
      </div>
    </div>

    <div class="chips">
      <button
        v-for="item in FILTERS"
        :key="item.key"
        type="button"
        :class="{ active: filter === item.key }"
        @click="filter = item.key"
      >
        {{ item.label }} ({{ counts[item.key] }})
      </button>
    </div>

    <div v-if="loading" class="muted">Загрузка…</div>

    <div v-else-if="visible.length === 0" class="muted">
      <template v-if="subtab === 'campus'">У корпуса «{{ campusTitle }}» файлов нет.</template>
      <template v-else>Файлов нет ни у одного корпуса.</template>
    </div>

    <table v-else>
      <thead>
        <tr>
          <th>№</th>
          <th>Файл</th>
          <th v-if="subtab === 'all'">Корпус</th>
          <th>Страниц</th>
          <th>Сумма</th>
          <th>Статус</th>
          <th>Создан</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="file in visible" :key="file.id">
          <td class="muted">{{ file.id }}</td>
          <td>
            <a v-if="file.file_available" :href="file.file_url" target="_blank" rel="noopener">{{ file.name }}</a>
            <span v-else :title="'Файл удалён — документ выдан'">{{ file.name }}</span>
            <div class="muted">{{ file.size }}</div>
          </td>
          <td v-if="subtab === 'all'">{{ file.campus }}</td>
          <td>{{ file.pages }}</td>
          <td>{{ file.amount }}</td>
          <td>
            <span :class="file.status === 'paid' ? 'warn' : file.status === 'printed' ? 'ok' : 'muted'">
              {{ file.status_label }}
            </span>
          </td>
          <td class="muted">{{ file.created_at }}</td>
          <td>
            <button v-if="file.status === 'paid'" :disabled="busyId === file.id" @click="markPrinted(file)">
              Напечатано
            </button>
            <button
              v-else-if="file.status === 'printed'"
              class="ghost"
              :disabled="busyId === file.id"
              @click="markIssued(file)"
            >
              Забрали
            </button>
            <span v-else class="muted">—</span>
          </td>
        </tr>
      </tbody>
    </table>

    <p v-if="notice" class="ok">{{ notice }}</p>
    <p v-if="error" class="err">{{ error }}</p>
  </div>
</template>