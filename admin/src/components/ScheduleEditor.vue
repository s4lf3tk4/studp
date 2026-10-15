<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { api } from '../api';

const emit = defineEmits(['logged-out']);

/** Разделы панели: расписание живёт в «Настройках», «Печать» пока заглушка */
const TABS = [
  { key: 'settings', label: 'Настройки' },
  { key: 'print', label: 'Печать' },
];

const campuses = ref([]);
const weekdays = ref({});
const currentId = ref(null);
const draft = ref([]);
const tab = ref('settings');
const busy = ref(false);
const message = ref('');
const error = ref('');
const baseline = ref('');

const current = computed(() => campuses.value.find((campus) => campus.id === currentId.value) ?? null);

/** Снимок редактируемых полей — по нему видно, есть ли несохранённые правки */
const snapshot = () =>
  JSON.stringify({
    title: current.value?.title ?? '',
    address: current.value?.address ?? '',
    room: current.value?.room ?? '',
    is_active: current.value?.is_active ?? false,
    price_per_page_kopecks: current.value?.price_per_page_kopecks ?? 0,
    days: draft.value,
  });

const dirty = computed(() => current.value !== null && baseline.value !== snapshot());

const load = async () => {
  const data = await api('/admin/api/state');

  campuses.value = data.campuses;
  weekdays.value = data.weekdays;
  currentId.value = currentId.value ?? campuses.value[0]?.id ?? null;

  selectCurrent();
};

/** Черновик расписания выбранного корпуса + точка отсчёта «сохранено» */
const selectCurrent = () => {
  message.value = '';
  error.value = '';
  draft.value = JSON.parse(JSON.stringify(current.value?.days ?? []));
  baseline.value = snapshot();
};

/** Смена корпуса: несохранённые правки не теряем молча */
const pickCampus = (event) => {
  const next = Number(event.target.value);

  if (dirty.value && ! window.confirm('Есть несохранённые изменения. Переключить корпус?')) {
    event.target.value = String(currentId.value);

    return;
  }

  currentId.value = next;
  selectCurrent();
};

/** Вкладки: держим выбор в адресной строке, чтобы F5 не сбрасывал раздел */
const setTab = (key) => {
  tab.value = key;

  if (window.location.hash !== `#${key}`) {
    window.location.hash = key;
  }
};

const applyHash = () => {
  const next = window.location.hash === '#print' ? 'print' : 'settings';

  if (next !== tab.value) {
    tab.value = next;
  }
};

const run = async (action, okMessage) => {
  busy.value = true;
  message.value = '';
  error.value = '';

  try {
    await action();
    message.value = okMessage;
  } catch (e) {
    if (e.unauthenticated) {
      emit('logged-out');

      return;
    }

    error.value = e.message;
  } finally {
    busy.value = false;
  }
};

const saveCampus = () =>
  run(async () => {
    const { campus } = await api(`/admin/api/campuses/${current.value.id}`, 'PUT', {
      title: current.value.title,
      address: current.value.address,
      room: current.value.room,
      is_active: current.value.is_active,
      price_per_page_kopecks: current.value.price_per_page_kopecks,
    });

    replaceCampus(campus);
    baseline.value = snapshot();
  }, 'Реквизиты сохранены');

const saveDays = () =>
  run(async () => {
    const { campus } = await api(`/admin/api/campuses/${current.value.id}/days`, 'PUT', { days: draft.value });

    replaceCampus(campus);
    // сервер вернул нормализованное время («09:10») — берём его как новый черновик
    draft.value = JSON.parse(JSON.stringify(campus?.days ?? draft.value));
    baseline.value = snapshot();
  }, 'Расписание сохранено');

/** Сервер — источник истины: подменяем локальную копию корпуса его ответом */
const replaceCampus = (campus) => {
  if (! campus) {
    return;
  }

  const index = campuses.value.findIndex((item) => item.id === campus.id);

  if (index !== -1) {
    campuses.value[index] = { ...campuses.value[index], ...campus };
  }
};

const logout = async () => {
  await api('/admin/logout', 'POST');
  emit('logged-out');
};

onMounted(() => {
  applyHash();
  window.addEventListener('hashchange', applyHash);
  load().catch((e) => (e.unauthenticated ? emit('logged-out') : (error.value = e.message)));
});

onUnmounted(() => window.removeEventListener('hashchange', applyHash));
</script>

<template>
  <div class="topbar">
    <div class="row gap-sm">
      <label class="muted" for="campus">Корпус:</label>

      <select id="campus" :value="currentId ?? ''" @change="pickCampus">
        <option v-for="campus in campuses" :key="campus.id" :value="campus.id">{{ campus.title }}</option>
      </select>

      <span v-if="current" :class="current.is_active ? 'ok' : 'err'">
        {{ current.is_active ? 'включён' : 'выключен' }}
      </span>

      <span v-if="dirty" class="warn">не сохранено</span>
    </div>

    <button class="ghost" @click="logout">Выйти</button>
  </div>

  <nav class="tabs">
    <button
      v-for="item in TABS"
      :key="item.key"
      type="button"
      :class="{ active: tab === item.key }"
      @click="setTab(item.key)"
    >
      {{ item.label }}
    </button>
  </nav>

  <div v-if="!current" class="card muted">Корпуса не найдены — проверь справочник корпусов на сервере.</div>

  <template v-else-if="tab === 'settings'">
    <div class="card">
      <h2>Реквизиты</h2>
      <div class="row">
        <input v-model="current.title" type="text" placeholder="Название" />
        <input v-model="current.address" type="text" placeholder="Адрес" size="30" />
        <input v-model="current.room" type="text" placeholder="Кабинет" size="8" />
        <input v-model.number="current.price_per_page_kopecks" type="number" placeholder="копеек за страницу" />
        <label class="row gap-sm"><input v-model="current.is_active" type="checkbox" /> включён</label>
        <button :disabled="busy" @click="saveCampus">Сохранить</button>
      </div>
      <p class="muted">Цена в копейках: 240 = 2,40 ₽ за страницу.</p>
    </div>

    <div class="card">
      <h2>Расписание</h2>
      <table>
        <thead>
          <tr>
            <th>День</th>
            <th>Работает</th>
            <th>Открытие</th>
            <th>Закрытие</th>
            <th>Обед с</th>
            <th>Обед по</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="day in draft" :key="day.weekday">
            <td>{{ weekdays[day.weekday] }}</td>
            <td><input v-model="day.is_working" type="checkbox" /></td>
            <td><input v-model="day.opens_at" type="time" :disabled="!day.is_working" /></td>
            <td><input v-model="day.closes_at" type="time" :disabled="!day.is_working" /></td>
            <td><input v-model="day.lunch_starts_at" type="time" :disabled="!day.is_working" /></td>
            <td><input v-model="day.lunch_ends_at" type="time" :disabled="!day.is_working" /></td>
          </tr>
        </tbody>
      </table>

      <div class="row" style="margin-top: 12px">
        <button :disabled="busy" @click="saveDays">Сохранить расписание</button>
        <span v-if="message" class="ok">{{ message }}</span>
        <span v-if="error" class="err">{{ error }}</span>
      </div>
      <p class="muted">
        Снятая галочка «Работает» делает день выходным, время при этом сохраняется — пригодится, когда типография
        закрыта временно.
      </p>
    </div>
  </template>

  <div v-else class="card">
    <h2>Печать</h2>
    <p class="muted">
      Раздел в разработке. Здесь будет очередь оплаченных заказов корпуса «{{ current.title }}»: файл, число страниц,
      сумма и кнопка «Напечатано».
    </p>
  </div>
</template>