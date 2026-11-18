<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { api } from '../api';
import PrintTab from './PrintTab.vue';

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

/** Копейки → «2,30 ₽»: в полях вводятся копейки, человек читает рубли */
const rub = (kopecks) => `${((Number(kopecks) || 0) / 100).toFixed(2).replace('.', ',')} ₽`;

const kopeck = (value) => Number(value) || 0;

/** Обе цены нулевые — бот посчитает страницы, но не назовёт сумму */
const noPrices = computed(
  () => current.value !== null && kopeck(current.value.price_per_page_kopecks) === 0 && kopeck(current.value.color_price_per_page_kopecks) === 0,
);

/** Снимок редактируемых полей — по нему видно, есть ли несохранённые правки */
const snapshot = () =>
  JSON.stringify({
    title: current.value?.title ?? '',
    address: current.value?.address ?? '',
    room: current.value?.room ?? '',
    is_active: current.value?.is_active ?? false,
    price_per_page_kopecks: kopeck(current.value?.price_per_page_kopecks),
    color_price_per_page_kopecks: kopeck(current.value?.color_price_per_page_kopecks),
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
      price_per_page_kopecks: kopeck(current.value.price_per_page_kopecks),
      color_price_per_page_kopecks: kopeck(current.value.color_price_per_page_kopecks),
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
      <span v-if="message" class="ok">{{ message }}</span>
      <span v-if="error" class="err">{{ error }}</span>
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

      <div class="fields">
        <label class="field">
          <span class="field-label">Название корпуса</span>
          <input v-model="current.title" type="text" placeholder="Корпус 2" />
          <span class="hint">Так корпус называется в кнопках бота и в сообщениях клиенту.</span>
        </label>

        <label class="field">
          <span class="field-label">Адрес</span>
          <input v-model="current.address" type="text" placeholder="ул. Мира, 5" />
          <span class="hint">Уходит клиенту в сообщении «документ напечатан».</span>
        </label>

        <label class="field">
          <span class="field-label">Кабинет</span>
          <input v-model="current.room" type="text" placeholder="101" size="8" />
          <span class="hint">Куда идти за распечаткой.</span>
        </label>

        <label class="field">
          <span class="field-label">Цена за страницу, ЧБ (копеек)</span>
          <input v-model.number="current.price_per_page_kopecks" type="number" min="0" placeholder="230" />
          <span class="hint">
            Копейки: 230 = {{ rub(230) }}, то есть {{ rub(current.price_per_page_kopecks) }} за страницу.
            Ноль — бот цену не называет.
          </span>
        </label>

        <label class="field">
          <span class="field-label">Цена за страницу, цветной (копеек)</span>
          <input v-model.number="current.color_price_per_page_kopecks" type="number" min="0" placeholder="600" />
          <span class="hint">
            Копейки: 600 = {{ rub(600) }}, то есть {{ rub(current.color_price_per_page_kopecks) }} за страницу.
            Ноль — цветной принтер выключен, бот предлагает только ЧБ.
          </span>
        </label>

        <label class="field">
          <span class="field-label">Корпус принимает заказы</span>
          <span class="row gap-sm"><input v-model="current.is_active" type="checkbox" /> включён</span>
          <span class="hint">Снятая галочка — кнопка корпуса краснеет, файлы от него не принимаются.</span>
        </label>
      </div>

      <p v-if="noPrices" class="warn">
        Обе цены нулевые: бот посчитает страницы, но не назовёт стоимость и не выставит счёт. Заполни хотя бы ЧБ.
      </p>

      <div class="row" style="margin-top: 12px">
        <button :disabled="busy" @click="saveCampus">Сохранить реквизиты</button>
      </div>
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
      </div>
      <p class="muted">
        Снятая галочка «Работает» делает день выходным, время при этом сохраняется — пригодится, когда типография
        закрыта временно.
      </p>
    </div>
  </template>

  <PrintTab v-else :campus-id="currentId" :campus-title="current.title" />
</template>