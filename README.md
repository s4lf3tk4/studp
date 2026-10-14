# studpechat

Бот для печати документов: принимает PDF в VK и MAX, считает страницы, называет стоимость по выбранному корпусу и после подтверждения создаёт заказ со счётом.

## Как это работает

1. Пользователь открывает бота и нажимает «Начать» — бот показывает список корпусов.
2. Выбирает корпус. У каждого корпуса свой адрес, кабинет, режим работы по дням недели и цена за страницу.
3. Отправляет PDF документом (не картинкой и не ссылкой). Бот отвечает «Считаю страницы…».
4. В очереди файл скачивается на диск, `pdfinfo` считает страницы, стоимость = страницы × цена корпуса.
5. Бот присылает результат с кнопками **«Подтвердить»** и **«Отмена»**.
6. «Подтвердить» — черновик из Redis превращается в заказ в MySQL со статусом `awaiting_payment` и счётом `pending`; бот присылает ссылку на оплату.
7. «Отмена» — скачанный файл удаляется, черновик забывается.
8. После оплаты заказ переходит в `paid`, после печати — в `printed` (провайдер оплаты пока не подключён, статус ставится вручную).

## Хранилища

| Хранилище | Что держит | Срок жизни |
|---|---|---|
| MySQL | заказы `documents`, счета `payments`, чеки `receipts`, корпуса `campuses`, расписание `campus_days` | постоянно |
| Redis db 1 | черновик заказа `file:<платформа>:<userId>`, выбранный корпус `campus:…`, дедупликация событий `event:…` | черновик — сутки, подтверждённый — 7 суток |
| Redis db 0 | очередь заданий | до обработки |
| Диск `data/documents` | сами PDF: `<платформа>/<год>/<месяц>/<uuid>.pdf` | неоплаченные — сутки, оплаченные — 7 суток |

Подписанная ссылка из мессенджера нужна один раз — при скачивании. Дальше источник истины по файлу — диск плюс запись в БД: `documents.file_path`.

## Контейнеры

| Сервис | Роль |
|---|---|
| `webserver` | nginx, порт 8080 снаружи; вебхуки на `POST /api/webhooks/incoming` |
| `app` | php-fpm 8.4 |
| `queue` | воркер очереди: скачивание файла и подсчёт страниц |
| `scheduler` | `schedule:work` — ночная уборка `documents:prune` |
| `migrate` | одноразовый: `composer install` при первом запуске, миграции, сидер |
| `db` | MySQL 8, порт только `127.0.0.1:3307` |
| `redis` | Redis 7, порт только `127.0.0.1:6380` |

## Быстрый старт (локально)

```bash
git clone https://github.com/s4lf3tk4/studp.git
cd studp
cp .env.example .env
```

В `.env` заполнить `DB_PASSWORD`, токены `VK_*` и `MAX_*`, затем сгенерировать ключ приложения:

```bash
docker compose run --rm --no-deps app php artisan key:generate --show
```

Полученную строку вписать как `APP_KEY=base64:...` и поднять стек:

```bash
docker compose config -q
docker compose up -d --build
docker compose ps -a
curl -I http://localhost:8080/up
```

`studpechat-migrate` должен завершиться со статусом `Exited (0)` — именно он ставит зависимости, применяет миграции и наполняет справочник корпусов.

Для вебхуков нужен публичный HTTPS (VK Callback API и MAX принимают только `https`). Локально это туннель:

```bash
cloudflared tunnel --url http://localhost:8080
# или: ngrok http 8080
```

После каждого перезапуска туннеля адрес меняется — его нужно обновлять в настройках Callback API сообщества VK и в подписке MAX (`POST /subscriptions`).

## Развёртывание на сервере

```bash
git clone https://github.com/s4lf3tk4/studp.git && cd studp
cp .env.example .env && nano .env          # APP_KEY, настоящий пароль БД, токены
chown -R 1000:1000 data php-producer/storage php-producer/bootstrap/cache
docker compose config -q
docker compose up -d --build
curl -I http://127.0.0.1:8080/up
```

Важные детали:

- **uid 1000.** В образе `www-data` переназначен на uid 1000, и воркеры `queue`/`migrate` работают от него же. Владельцем `data`, `storage` и `bootstrap/cache` должен быть тот же uid, иначе получите `Permission denied` при записи логов, кеша и PDF.
- **HTTPS.** Снаружи нужен реверс-прокси с сертификатом (nginx или Caddy на хосте) перед портом 8080: MAX требует `https` на 443, VK — публичный адрес. Наружу открывать только 80/443, порты `8080`, `3307`, `6380` держать на `127.0.0.1`.
- **Первый `composer install`** выполняет сервис `migrate` от uid 1000. Если ставили зависимости вручную от root, поправьте владельца: `chown -R 1000:1000 php-producer/vendor`.
- **Бэкапы.** Бекапить нужно MySQL и `data/documents` — код и Redis восстановимы, заказы и PDF нет.

## Эксплуатация

Список заказов (что оплачено, что ждёт оплату):

```bash
docker compose exec -u www-data app php artisan documents:list
docker compose exec -u www-data app php artisan documents:list --status=awaiting_payment --limit=50
```

Уборка файлов и записей (сначала всегда `--dry-run`):

```bash
docker compose exec -u www-data app php artisan documents:prune --dry-run
docker compose exec -u www-data app php artisan documents:prune
docker compose exec -u www-data app php artisan documents:prune --unpaid-days=1 --paid-days=7 --days=7
```

Что делает `prune`: удаляет неоплаченные заказы старше `--unpaid-days` (по умолчанию сутки) вместе с файлами, оплаченные и напечатанные — старше `--paid-days` (7 суток), плюс подчищает PDF, на которые нет записи в БД (`--days`, по умолчанию 7). Расписание: `Schedule::command('documents:prune')->dailyAt('04:00')`.

```bash
docker compose exec -u www-data app php artisan schedule:list      # что и когда запускается
docker compose exec -u www-data app php artisan migrate:status     # какие миграции применены
docker compose exec -u www-data app php artisan queue:failed       # упавшие задания
docker compose exec -u www-data app php artisan queue:retry all
docker compose logs queue --tail 50                                # работа воркера
```

Данные напрямую:

```bash
docker compose exec db mysql -ularavel -psecret studpechat --table -e "SELECT id, platform, campus, pages, status FROM documents ORDER BY id DESC LIMIT 10;"
docker compose exec redis redis-cli -n 1 --scan --pattern 'laravel-database-laravel-cache-file:*'
docker compose exec redis redis-cli -n 0 LLEN "laravel-database-queues:default"
```

Настройка справочника без правки кода:

```bash
# цена за страницу для корпуса (в копейках: 250 = 2,50 ₽)
docker compose exec db mysql -ularavel -psecret studpechat -e "UPDATE campuses SET price_per_page_kopecks = 250 WHERE code = 'campus2';"

# временно выключить корпус: кнопка станет красной, выбор заблокируется
docker compose exec db mysql -ularavel -psecret studpechat -e "UPDATE campuses SET is_active = 0 WHERE code = 'campus4';"
```

Учтите: сервис `migrate` при каждом `up -d` прогоняет `CampusScheduleSeeder` и возвращает адреса, часы и цены к значениям из сидера. Если справочник ведётся в БД, уберите `db:seed` из команды сервиса `migrate` или правьте сам сидер.

Обновление кода на сервере:

```bash
git pull
docker compose up -d --build
docker compose exec app kill -USR2 1      # свежий OPCache без разрыва соединений
docker compose exec app php artisan queue:restart
```

## Переменные окружения

Корневой `.env` (он же передаётся контейнерам через `env_file`):

| Переменная | Назначение |
|---|---|
| `APP_KEY` | ключ приложения, обязателен |
| `APP_ENV`, `APP_DEBUG` | на сервере `production` и `false` |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | доступ к MySQL |
| `DB_HOST`, `DB_PORT` | в контейнерах `db` и `3306` (задаются в `php-producer/.env`) |
| `VK_CONFIRMATION_CODE` | код подтверждения Callback API |
| `VK_ACCESS_TOKEN`, `VK_SECRET_KEY`, `VK_API_VERSION` | токен сообщества, секрет вебхука, версия API |
| `MAX_ACCESS_TOKEN`, `MAX_WEBHOOK_SECRET`, `MAX_API_URL` | токен бота MAX, секрет вебхука, адрес API |
| `PAYMENT_UNIT_PRICE`, `PAYMENT_PUBLIC_URL`, `PAYMENT_PROVIDER` | заглушка оплаты: цена по умолчанию, базовый адрес ссылки, имя провайдера |
| `REDIS_QUEUE_RETRY_AFTER` | должен быть больше таймаута задания (280 с), рекомендуется 300 |
| `QUEUE_CONNECTION`, `CACHE_STORE`, `SESSION_DRIVER` | `redis`, `redis`, `redis` |

## Структура кода

```
php-producer/app/
  Http/Controllers/Api/IncomingWebhookController.php   единая точка входа вебхуков
  Services/MessageSourceDetector.php                   VK или MAX по форме запроса
  Services/Webhook/WebhookRouter.php                   выбор обработчика
  Services/Webhook/Vk/…                                события VK, клавиатуры, команды
  Services/Webhook/Max/…                               то же для MAX
  Services/DocumentStore.php                           черновик заказа в Redis
  Services/CampusChoice.php                            выбранный корпус в Redis
  Services/EventStore.php                              дедупликация повторных доставок
  Services/OrderConfirmation.php                       подтверждение: черновик → MySQL
  Services/OrderCancellation.php                       отмена: удаление файла и черновика
  Jobs/ProcessIncomingDocument.php                     скачивание, подсчёт страниц, черновик
  Models/{Campus,CampusDay,Document,Payment}.php
  Support/{BotTexts,ScheduleText,Money}.php
routes/api.php        POST /api/webhooks/incoming
routes/console.php    documents:list, documents:prune, расписание уборки
```

## Правила разработки

- **Миграции только новыми файлами.** Применённую и запушенную миграцию править нельзя: запись в таблице `migrations` уже стоит, повторно файл не выполнится, и схема на другом окружении разъедется.
- **Данные — не миграции.** Корпуса, цены, часы меняются сидером или `UPDATE`.
- **После нового или переименованного класса** — `docker compose exec -u www-data app composer dump-autoload -o`. Без этого оптимизированная карта классов указывает на старое имя файла, и Laravel скажет `Class ... does not exist`. Полезно и `composer dump-autoload -o --strict-psr` — проверка расхождений «имя файла ↔ имя класса».
- **После правок PHP** — `docker compose restart app queue`: в образе `opcache.validate_timestamps=0`, сам по себе код не перечитается.
- **Проверяйте синтаксис** (`php -l`) после правки файлов: фатальная ошибка в провайдере ломает все команды сразу.
- **`.env` не коммитить.** Репозиторий публичный, токены VK и MAX живут только на диске. Перед коммитом смотреть `git status --short` глазами: там не должно быть `.env`, `vendor` и PDF.

## Что дальше

1. Платёжный провайдер: callback вызывает `Document::markPaid($providerPaymentId)` и заполняет `receipts`.
2. Подписанный роут на скачивание файла для типографии (`URL::temporarySignedRoute` + `Storage::disk('documents')->download(...)`).
3. Уведомление пользователю после печати (`Document::markPrinted()`).
4. Список заказов для оператора: сейчас это консольная команда `documents:list`.
