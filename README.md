# studpechat

Сервис печати документов: бот в VK и MAX принимает PDF, считает страницы и стоимость по выбранному корпусу, после подтверждения создаёт заказ со счётом. Типография работает с заказами в веб-панели: скачивает файлы, отмечает их напечатанными, а клиент получает в мессенджер уведомление с адресом и номером кабинета.

**Стек:** Laravel 13 · PHP 8.4-fpm · MySQL 8 · Redis 7 · nginx · Vue 3 + Vite · Docker Compose.

## Содержание

- [studpechat](#studpechat)
  - [Содержание](#содержание)
  - [Сценарий работы](#сценарий-работы)
  - [Статусы заказа](#статусы-заказа)
  - [Панель типографии](#панель-типографии)
  - [Хранилища](#хранилища)
  - [Контейнеры и порты](#контейнеры-и-порты)
  - [Быстрый старт](#быстрый-старт)
  - [Вебхуки VK и MAX](#вебхуки-vk-и-max)
  - [Развёртывание на сервере](#развёртывание-на-сервере)
  - [Эксплуатация](#эксплуатация)
  - [Переменные окружения](#переменные-окружения)
  - [Структура кода](#структура-кода)
  - [Правила разработки](#правила-разработки)
  - [Частые проблемы](#частые-проблемы)
  - [Что дальше](#что-дальше)

## Сценарий работы

**Клиент в мессенджере:**

1. Открывает бота и нажимает «Начать» — приходит описание сервиса и кнопки корпусов.
2. Выбирает корпус. У корпуса свои адрес, кабинет, режим работы по дням недели и цена за страницу.
3. Нажимает «Отправить файл» и присылает PDF документом (не картинкой и не ссылкой).
4. Бот отвечает «Считаю страницы…», а обработка уходит в очередь.
5. Воркер скачивает файл на диск, `pdfinfo` считает страницы, стоимость = страницы × цена корпуса.
6. Бот присылает результат с кнопками **«Подтвердить»** и **«Отмена»**.
7. «Подтвердить» — черновик из Redis превращается в заказ в MySQL со статусом `awaiting_payment`, создаётся счёт `pending`, бот присылает ссылку на оплату.
8. «Отмена» — скачанный файл удаляется, черновик забывается.

**Типография в панели:**

9. В разделе «Печать» видит оплаченные файлы: очередь своего корпуса и общую очередь по всем корпусам.
10. Открывает PDF в браузере, печатает, нажимает «Напечатано» — заказ переходит в `printed`.
11. Клиенту в тот же чат уходит уведомление: номер заказа, файл, число страниц, корпус, адрес, кабинет.

**Ограничения:** только PDF, до 100 МБ, до 2000 страниц. Файлы другого типа и слишком большие отклоняются с подсказкой, как сохранить документ в PDF.

## Статусы заказа

| Статус | Что значит | Кто ставит |
|---|---|---|
| `new` | файл принят, страницы считаются | задание в очереди |
| `counted` | страницы посчитаны, ждём решения клиента | воркер |
| `awaiting_payment` | клиент подтвердил, счёт выставлен | «Подтвердить» |
| `paid` | оплачен, ждёт печати | уведомление платёжного провайдера |
| `printed` | напечатан, клиент уведомлён | кнопка «Напечатано» в панели |

Счёт (`payments`) живёт отдельно: `pending` → `paid`, там же `confirmation_url` на оплату и `provider_payment_id`.

## Панель типографии

Vue 3 + Vite. Собирается в статику и отдаётся nginx по адресу `/admin/`. **На сервере Node не нужен** — собранная панель лежит в репозитории в `php-producer/public/admin`.

**Что внутри:**

| Раздел | Возможности |
|---|---|
| Шапка | выбор корпуса, признак «включён/выключен», метка о несохранённых правках, выход |
| Настройки | реквизиты корпуса (название, адрес, кабинет), цена за страницу в копейках, включение/выключение; расписание по дням недели — рабочий день, часы открытия и закрытия, обед |
| Печать | две под-вкладки: очередь выбранного корпуса и общая очередь по всем корпусам; открыть PDF, отметить «Напечатано»; итоги по числу файлов, страниц и сумме |

**Сборка после правок фронта:**

```bash
docker compose --profile admin run --rm admin-build
```

Пакеты npm живут в docker-томе `admin-node-modules` — на хост ничего не устанавливается, `node_modules` в репозиторий не попадает. После сборки имена файлов меняются из-за хешей, поэтому в браузере нужно жёсткое обновление (`Ctrl+F5`).

**Разработка с горячей перезагрузкой:**

```bash
docker compose --profile admin up admin-dev
# http://127.0.0.1:5173, запросы /admin/api проксируются на webserver
```

**Защита:**

- логин и пароль — из `.env`, проверка fail-closed: пустые значения означают «вход запрещён», а не «вход разрешён»;
- логин сравнивается через `hash_equals`, пароль — `Hash::check` при заданном `ADMIN_PASSWORD_HASH`, иначе `hash_equals` для открытого;
- перебор ограничен: `throttle:6,1` на `POST /admin/login`;
- сессия серверная (Redis), в браузере только её идентификатор в cookie с `HttpOnly` и `SameSite=Lax`;
- `EnsureAdmin` проверяет флаг входа на всех `/admin/api/*` и отвечает `401` в JSON, по этому коду фронт показывает форму входа;
- CSRF-токен для панели отключён осознанно: статику отдаёт nginx, получить cookie с токеном неоткуда; от межсайтовых запросов защищает `SameSite=Lax`;
- PDF недоступны по прямой ссылке: они лежат вне `public` и отдаются только через `GET /admin/api/print/{document}/file` под сессией;
- перед публикацией обязателен HTTPS: `SESSION_SECURE_COOKIE=true`, `APP_URL=https://…`, `APP_ENV=production`, `APP_DEBUG=false`.

**Отозвать все входы** (например, при увольнении сотрудника) — поменять значение и сохранить, перезапуск не нужен:

```
ADMIN_SESSION_KEY=admin.authenticated.v2
```

## Хранилища

| Хранилище | Что держит | Срок жизни |
|---|---|---|
| MySQL | заказы `documents`, счета `payments`, чеки `receipts`, корпуса `campuses`, расписание `campus_days` | постоянно |
| Redis db 0 | очередь заданий и сессии панели `laravel-database-<id>` | до обработки / по `SESSION_LIFETIME` |
| Redis db 1 | черновик заказа `file:<платформа>:<userId>`, выбранный корпус `campus:…`, дедупликация событий `event:…` | черновик — сутки, подтверждённый — 7 суток |
| Диск `data/documents` | сами PDF: `<платформа>/<год>/<месяц>/<uuid>.pdf` | неоплаченные — сутки, оплаченные — 7 суток |

Подписанная ссылка из мессенджера нужна один раз — при скачивании. Дальше источник истины по файлу — диск плюс `documents.file_path` в БД.

## Контейнеры и порты

| Сервис | Роль | Порты |
|---|---|---|
| `webserver` | nginx: панель, вебхуки, PHP через FastCGI | `8080:80` |
| `app` | php-fpm 8.4 | не опубликован |
| `queue` | воркер: скачивание файла и подсчёт страниц | не опубликован |
| `scheduler` | `schedule:work`, ночная уборка | не опубликован |
| `migrate` | одноразовый: `composer install` при первом запуске и миграции | не опубликован |
| `db` | MySQL 8 | `127.0.0.1:3307:3306` |
| `redis` | Redis 7 | `127.0.0.1:6380:6379` |
| `admin-build` | профиль `admin`, разовая сборка панели | — |
| `admin-dev` | профиль `admin`, dev-сервер Vite | `127.0.0.1:5173:5173` |

Наружу смотрит только nginx. База и Redis привязаны к loopback, php-fpm не опубликован вовсе — снаружи до него не достучаться.

Внутри сети Docker контейнеры видны по именам: nginx обращается к `app:9000` через встроенный DNS `127.0.0.11` (это важно — см. [частые проблемы](#частые-проблемы)).

## Быстрый старт

```bash
git clone https://github.com/s4lf3tk4/studp.git
cd studp
cp .env.example .env
cp php-producer/.env.example php-producer/.env
```

Переменные живут в **двух** файлах, и это принципиально:

- **корневой `.env`** — читает Docker Compose и передаёт контейнерам через `env_file`: `DB_*`, `APP_ENV`, `APP_DEBUG`;
- **`php-producer/.env`** — читает Laravel: `APP_KEY`, токены `VK_*` и `MAX_*`, `ADMIN_*`, `SESSION_*`.

При совпадении ключей побеждает корневой файл: переменные из `env_file` уже лежат в окружении процесса, а Dotenv их не перезаписывает.

Заполнить `DB_PASSWORD` и токены, затем сгенерировать ключ и поднять стек:

```bash
docker compose run --rm --no-deps app php artisan key:generate
docker compose config -q
docker compose up -d --build
docker compose ps -a
curl -I http://localhost:8080/up
```

`studpechat-migrate` должен завершиться со статусом `Exited (0)`.

**Справочник корпусов.** Сервис `migrate` его намеренно не наполняет: иначе правки типографии затирались бы при каждом `up`. На свежей базе корпуса создаются один раз:

```bash
docker compose exec -u www-data app php artisan db:seed --class=CampusScheduleSeeder --force
```

Панель и вход в неё:

```bash
docker compose --profile admin run --rm admin-build
```

```
# php-producer/.env
ADMIN_LOGIN=admin
ADMIN_PASSWORD=admin
ADMIN_SESSION_KEY=admin.authenticated
```

Панель: http://localhost:8080/admin/

## Вебхуки VK и MAX

Точка входа одна: `POST /api/webhooks/incoming`. Платформа определяется по форме запроса (`MessageSourceDetector`), дальше `WebhookRouter` выбирает обработчик. Повторные доставки отсекаются по идентификатору события в Redis.

Подлинность проверяется по секрету: у VK — поле `secret` в событии и код подтверждения, у MAX — заголовок `X-Max-Bot-Api-Secret`.

Для вебхуков нужен публичный **HTTPS** — VK Callback API и MAX принимают только `https`. Локально это туннель:

```bash
cloudflared tunnel --url http://localhost:8080
# или: ngrok http 8080
```

После перезапуска туннеля адрес меняется — его нужно обновлять в настройках Callback API сообщества VK и в подписке MAX (`POST /subscriptions`).

## Развёртывание на сервере

```bash
git clone https://github.com/s4lf3tk4/studp.git && cd studp
cp .env.example .env
cp php-producer/.env.example php-producer/.env && nano php-producer/.env
chown -R 1000:1000 data php-producer/storage php-producer/bootstrap/cache
docker compose config -q
docker compose up -d --build
curl -I http://127.0.0.1:8080/up
```

Чеклист перед публикацией:

- [ ] `APP_ENV=production`, `APP_DEBUG=false` — иначе отладочная страница покажет пути и переменные окружения;
- [ ] реверс-прокси с сертификатом перед `8080`, наружу открыты только 80/443;
- [ ] `SESSION_SECURE_COOKIE=true` и `APP_URL=https://…`;
- [ ] пароль панели сменён с `admin` (лучше — bcrypt через `ADMIN_PASSWORD_HASH`);
- [ ] `ADMIN_*` лежат в `php-producer/.env`, а не в корневом;
- [ ] `.env` не попал в git (репозиторий публичный);
- [ ] бекапы MySQL и `data/documents` настроены.

**uid 1000.** В образе `www-data` переназначен на uid 1000, воркеры `queue`/`migrate` работают от него же. Владельцем `data`, `storage` и `bootstrap/cache` должен быть тот же uid, иначе получите `Permission denied` при записи логов, кеша и PDF.

**Первый `composer install`** выполняет сервис `migrate`. Если ставили зависимости вручную от root: `chown -R 1000:1000 php-producer/vendor`.

**Сборка панели** уже в репозитории. Если правили фронт на сервере, после сборки файлы в `public/admin` окажутся принадлежащими root — nginx это читает без проблем, но для коммита может понадобиться `chown`.

**Бэкапы.** Бекапить нужно MySQL и `data/documents`: код и Redis восстановимы, заказы и PDF — нет.

## Эксплуатация

**Заказы:**

```bash
docker compose exec -u www-data app php artisan documents:list
docker compose exec -u www-data app php artisan documents:list --status=paid --limit=50
docker compose exec -u www-data app php artisan documents:list --status=awaiting_payment
```

**Имитация успешной оплаты** (провайдер пока заглушка — команда отправляет уведомление в том же виде, в каком его пришлёт реальный провайдер):

```bash
docker compose exec -u www-data app php artisan payments:paid 12
```

**Уборка файлов и записей** (сначала всегда `--dry-run`):

```bash
docker compose exec -u www-data app php artisan documents:prune --dry-run
docker compose exec -u www-data app php artisan documents:prune
docker compose exec -u www-data app php artisan documents:prune --unpaid-days=1 --paid-days=7 --days=7
```

Что делает `prune`: удаляет неоплаченные заказы старше `--unpaid-days` (по умолчанию сутки) вместе с файлами, оплаченные и напечатанные — старше `--paid-days` (7 суток), плюс подчищает PDF, на которые нет записи в БД (`--days`, по умолчанию 7). Расписание: `dailyAt('04:00')` в `routes/console.php`.

**Диагностика и обслуживание:**

```bash
docker compose exec -u wwww-data app php artisan route:list --path=admin   # маршруты панели
docker compose exec -u www-data app php artisan schedule:list             # что и когда запускается
docker compose exec -u www-data app php artisan migrate:status            # применённые миграции
docker compose exec -u www-data app php artisan queue:failed              # упавшие задания
docker compose exec -u www-data app php artisan queue:retry all
docker compose logs queue --tail 50                                       # работа воркера
docker compose logs webserver --tail 50                                   # 502, 404, 500
```

**Данные напрямую:**

```bash
docker compose exec db mysql -ularavel -psecret studpechat --table -e "SELECT id, platform, campus, pages, status FROM documents ORDER BY id DESC LIMIT 10;"
docker compose exec db mysql -ularavel -psecret studpechat --table -e "SELECT id, code, title, address, room, price_per_page_kopecks, is_active FROM campuses;"
docker compose exec redis redis-cli -n 1 --scan --pattern 'laravel-database-laravel-cache-file:*'
docker compose exec redis redis-cli -n 0 LLEN "laravel-database-queues:default"
docker compose exec redis redis-cli -n 0 --scan --pattern 'laravel-database-*'   # сессии панели
```

**Правка справочника без панели** (обычно не нужна — всё правится в панели):

```bash
# цена за страницу (в копейках: 250 = 2,50 ₽)
docker compose exec db mysql -ularavel -psecret studpechat -e "UPDATE campuses SET price_per_page_kopecks = 250 WHERE code = 'campus2';"

# временно выключить корпус: в боте выбор заблокируется
docker compose exec db mysql -ularavel -psecret studpechat -e "UPDATE campuses SET is_active = 0 WHERE code = 'campus4';"
```

**Обновление кода на сервере:**

```bash
git pull
docker compose up -d --build
docker compose exec app kill -USR2 1      # свежий OPCache без разрыва соединений
docker compose exec app php artisan queue:restart
```

## Переменные окружения

Корневой `.env` (Docker Compose + `env_file` для php-контейнеров):

| Переменная | Назначение |
|---|---|
| `APP_ENV`, `APP_DEBUG` | на сервере `production` и `false` |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | доступ к MySQL |

`php-producer/.env` (читает Laravel):

| Переменная | Назначение |
|---|---|
| `APP_KEY` | ключ приложения, обязателен |
| `APP_URL` | публичный адрес, от него зависит ссылка на оплату |
| `DB_HOST`, `DB_PORT` | в контейнерах `db` и `3306` |
| `VK_CONFIRMATION_CODE` | код подтверждения Callback API |
| `VK_ACCESS_TOKEN`, `VK_SECRET_KEY`, `VK_API_VERSION` | токен сообщества, секрет вебхука, версия API |
| `MAX_ACCESS_TOKEN`, `MAX_WEBHOOK_SECRET`, `MAX_API_URL` | токен бота MAX, секрет вебхука, адрес API |
| `ADMIN_LOGIN`, `ADMIN_PASSWORD` | вход в панель (открытый пароль) |
| `ADMIN_PASSWORD_HASH` | bcrypt-хеш вместо открытого пароля, приоритетнее |
| `ADMIN_SESSION_KEY` | имя флага входа в сессии; смена отзывает все входы |
| `PAYMENT_PROVIDER`, `PAYMENT_UNIT_PRICE`, `PAYMENT_PUBLIC_URL` | заглушка оплаты: провайдер, цена по умолчанию, базовый адрес |
| `YOOKASSA_SHOP_ID`, `YOOKASSA_SECRET_KEY` | боевая ЮKassa (когда будет подключена) |
| `QUEUE_CONNECTION`, `CACHE_STORE`, `SESSION_DRIVER` | `redis`, `redis`, `redis` |
| `SESSION_LIFETIME` | время жизни сессии панели в минутах (по умолчанию 120) |
| `SESSION_SECURE_COOKIE` | `true` после перехода на HTTPS |
| `REDIS_QUEUE_RETRY_AFTER` | больше таймаута задания (280 с), рекомендуется 300 |

Хеш пароля содержит символы `$`, поэтому в `.env` он пишется **в одинарных кавычках**:

```
ADMIN_PASSWORD_HASH='$2y$12$R9h/cIPz0gi...'
```

Сгенерировать хеш (пароль не попадает в историю команд):

```bash
docker compose exec -u www-data app php -r "echo password_hash(readline('Пароль: '), PASSWORD_BCRYPT), PHP_EOL;"
```

Если `ADMIN_PASSWORD_HASH` задан, но содержит мусор, `Hash::check` всегда вернёт `false` — панель перестанет пускать. Проверить, что видит приложение:

```bash
docker compose exec -u www-data app php artisan tinker --execute="dump(config('admin'));"
```

## Структура кода

```
docker-compose.yml               сервисы, тома, профиль admin
docker/php/Dockerfile            php 8.4-fpm, poppler-utils, intl, phpredis, composer
docker/nginx/default.conf        панель, вебхуки, FastCGI на app:9000
docker/php/php.ini               настройки PHP
data/documents/                  PDF-файлы (в git только .gitignore)
admin/                           исходники панели: Vue 3 + Vite
php-producer/
  app/
    Http/Controllers/Api/IncomingWebhookController.php   вход вебхуков VK и MAX
    Http/Controllers/Api/PaymentWebhookController.php    уведомления платёжного провайдера
    Http/Controllers/Admin/AdminSessionController.php    вход и выход в панели
    Http/Controllers/Admin/ScheduleController.php        реквизиты и расписание корпусов
    Http/Controllers/Admin/PrintController.php           очередь печати, выдача PDF, «напечатано»
    Http/Middleware/EnsureAdmin.php                      проверка сессии панели
    Jobs/ProcessIncomingDocument.php                     скачивание, подсчёт страниц, черновик
    Services/MessageSourceDetector.php                   VK или MAX по форме запроса
    Services/Webhook/WebhookRouter.php                   выбор обработчика
    Services/Webhook/Vk/…                                события VK, клавиатуры, команды
    Services/Webhook/Max/…                               то же для MAX
    Services/DocumentStore.php                           черновик заказа в Redis
    Services/CampusChoice.php                            выбранный корпус в Redis
    Services/EventStore.php                              дедупликация повторных доставок
    Services/DocumentPageCounter.php                     подсчёт страниц через pdfinfo
    Services/OrderConfirmation.php                       подтверждение: черновик → MySQL
    Services/OrderCancellation.php                       отмена: удаление файла и черновика
    Services/BotNotifier.php                             сообщение клиенту по данным заказа
    Services/Payments/…                                  шлюзы оплаты (stub, yookassa)
    Models/{Campus,CampusDay,Document,Payment}.php
    Support/{BotTexts,ScheduleText,Money}.php
  config/admin.php               логин, пароль и ключ сессии панели
  database/migrations/           схема БД
  database/seeders/CampusScheduleSeeder.php   четыре корпуса с расписанием и ценами
  routes/web.php                 /admin/* (панель) и /pay/{uuid} (заглушка оплаты)
  routes/api.php                 /api/webhooks/incoming, /api/payments/{provider}/notify
  routes/console.php             documents:list, documents:prune, payments:paid, расписание
  public/admin/                  собранная панель — её отдаёт nginx
```

## Правила разработки

- **Миграции только новыми файлами.** Применённую и запушенную миграцию править нельзя: запись в `migrations` уже стоит, повторно файл не выполнится, и схема на другом окружении разъедется.
- **Данные — не миграции.** Корпуса, цены, часы меняются панелью, сидером или `UPDATE`.
- **После нового или переименованного класса** — `docker compose exec -u www-data app composer dump-autoload -o`. Без этого оптимизированная карта классов указывает на старое имя файла и Laravel скажет `Class ... does not exist`. Полезно и `composer dump-autoload -o --strict-psr` — проверка расхождений «имя файла ↔ имя класса».
- **После правок PHP** — `docker compose restart app queue`: в образе `opcache.validate_timestamps=0`, код сам не перечитается.
- **После правок фронта** — `docker compose --profile admin run --rm admin-build`. Собранные файлы в `php-producer/public/admin` коммитятся вместе с исходниками `admin/`.
- **Проверяйте синтаксис** (`php -l`) после правки файлов: фатальная ошибка в провайдере ломает все команды сразу.
- **`.env` не коммитить.** Репозиторий публичный: токены, пароль панели и `APP_KEY` живут только на диске. Перед коммитом смотреть `git status --short` глазами — там не должно быть `.env`, `vendor` и PDF.

## Частые проблемы

| Симптом | Причина | Решение |
|---|---|---|
| `500` на `/admin/` | панель не собрана, `try_files` уходит в цикл редиректов | `docker compose --profile admin run --rm admin-build` |
| `404` на `/admin/api/…` | маршрута нет (не перезапущен `app` после правки `routes/web.php`) или в БД нет объекта из URL | `docker compose restart app queue`; проверить `route:list --path=admin` и существование записи |
| `Target class […] does not exist` | устаревшая карта классов или имя файла не совпадает с классом | добавить `use`-импорт, затем `composer dump-autoload -o` |
| `Permission denied` на `storage`, `bootstrap/cache`, `vendor` | файлы принадлежат root | `docker compose exec -u root app chown -R www-data:www-data storage bootstrap/cache vendor` |
| `502 Bad Gateway` после пересборки `app` | nginx закешировал IP контейнера | конфиг уже использует `resolver 127.0.0.11` + переменную `$php_upstream`; после правки конфига — `docker compose restart webserver` |
| `419 Page Expired` | CSRF на маршруте панели | проверить `validateCsrfTokens(except: …)` в `bootstrap/app.php` |
| `429 Too Many Requests` | сработал лимит входа | подождать минуту, счётчик снимется сам |
| Вход не работает, хотя пароль верный | `ADMIN_PASSWORD_HASH` содержит невалидное значение и приоритетнее пароля | удалить строку или сгенерировать настоящий хеш |
| Панель открывается без пароля | `ADMIN_SESSION_KEY` пуст, проверка идёт по пустому ключу | задать `ADMIN_SESSION_KEY=admin.authenticated` и перезапустить `app` |
| Правки PHP не применяются | OPCache с `validate_timestamps=0` | `docker compose restart app queue` |
| На свежей базе нет корпусов | сидер убран из сервиса `migrate` | `php artisan db:seed --class=CampusScheduleSeeder --force` |
| Уведомление о печати не уходит | у заказа пустые `chat_id` и `external_user_id` | смотреть `documents` и `storage/logs/laravel.log` |
| Бот не отвечает на сообщения | неверный токен, секрет вебхука или адрес туннеля | `docker compose logs app --tail 50`, проверить `VK_*`/`MAX_*` |

## Что дальше

1. **Боевая ЮKassa:** `YooKassaGateway::createPayment()` с чеком по 54-ФЗ, `PAYMENT_PROVIDER=yookassa`, уведомления на `/api/payments/yookassa/notify`.
2. **Журнал действий администратора:** таблица `admin_actions` (кто, что, когда, IP) и просмотр в панели.
3. **Привязка логина к корпусу:** сейчас корпус выбирается в шапке; при доработке `PrintController::campus()` должен сверять корпус с сессией.
4. **`campus:seed-if-empty`** вместо ручного сида на свежей базе.
5. **Ссылка на оплату в панели:** показывать оператору счёт и статус, чтобы отвечать на вопросы клиентов.