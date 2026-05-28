# Fridge Raptor Telegram Bot

Telegram-бот для генерации рецептов из продуктов.  
Проект работает только в режиме **long polling** (без webhook и без публичного HTTPS).

## Что внутри

- `telegram-bot` (Laravel): логика бота, FSM, интеграция с Core API.
- `queue-worker` (Laravel): асинхронная генерация рецептов.
- `redis`: хранение очереди и состояния пользователя.
- `products-mock` (Node.js): mock-хранилище продуктов.

## Архитектура взаимодействия

1. Пользователь пишет боту в Telegram.
2. `telegram-bot` делает `getUpdates` (long polling) и получает сообщения.
3. Бот получает продукты:
   - либо из сообщения пользователя,
   - либо из `products-mock` по команде `/cook`.
4. `queue-worker` вызывает Core API (`POST /api/v1/recipes`).
5. Результат форматируется и отправляется обратно в Telegram.

## Поддерживаемые внешние API

### Core Service (`CORE_API_BASE_URL`)

- `GET /api/health`
- `POST /api/v1/recipes`
- `GET /api/v1/recipes`
- `DELETE /api/v1/recipes/{id}`

### Products Mock (`PRODUCTS_API_BASE_URL`)

- `GET /health`
- `GET /api/v1/products?user_id=...`
- `POST /api/v1/products`
- `DELETE /api/v1/products/{id}`

## Быстрый старт (рекомендуется)

### 1) Подготовьте `.env`

Скопируйте пример:

```bash
cp .env.example .env
```

Минимально заполните:

```env
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost

TELEGRAM_BOT_TOKEN=your_bot_token_from_botfather

CORE_API_BASE_URL=http://host.docker.internal:8000
PRODUCTS_API_BASE_URL=http://products-mock:8090

QUEUE_CONNECTION=redis
REDIS_HOST=redis
REDIS_PORT=6379
```

> `CORE_API_BASE_URL` указывает на ваш локальный контейнер/сервис генерации рецептов.

### 2) Запустите проект

```bash
docker compose up --build
```

Поднимутся сервисы:

- `telegram-bot` (polling-цикл)
- `queue-worker`
- `redis`
- `products-mock`

### 3) Проверьте, что mock продуктов жив

```bash
curl http://localhost:8090/health
```

## Команды бота

- `/start` - приветствие и старт диалога
- `/help` - справка
- `/history` - история рецептов (берется из `GET /api/v1/recipes`)
- `/products` - показать продукты из mock-сервиса
- `/cook` - готовить по продуктам из mock-сервиса
- `/cancel` - сброс текущего сценария

## Формат ввода продуктов вручную

Можно отправлять:

- `картошка, лук, морковь`
- `свинина 500 г, лук 2 шт`

Если количество не указано, по умолчанию ставится `1 шт`.

## Управление mock-сервисом продуктов

Добавить продукт:

```bash
curl -X POST http://localhost:8090/api/v1/products \
  -H "Content-Type: application/json" \
  -d "{\"user_id\":123456,\"name\":\"осьминог\",\"quantity\":100,\"unit\":\"г\"}"
```

Получить продукты пользователя:

```bash
curl "http://localhost:8090/api/v1/products?user_id=123456"
```

Удалить продукт:

```bash
curl -X DELETE http://localhost:8090/api/v1/products/1001
```

## Локальная разработка без Docker (опционально)

1. `composer install`
2. `cp .env.example .env`
3. `php artisan key:generate`
4. Поднимите Redis локально
5. Запустите воркер: `php artisan queue:work --tries=3`
6. Запустите polling: `php artisan telegram:poll --timeout=25 --sleep=1`
7. Отдельно запустите `products-mock` (`npm install && npm run start` в `products-mock`)

## Отладка

- Логи Laravel: `storage/logs/laravel.log`
- Проверка, что polling активен: смотрите логи контейнера `telegram-bot`
- Проверка очереди: логи контейнера `queue-worker`

## Важное ограничение

Проект intentionally работает в одном режиме запуска: **long polling**.  
Webhook-путь и публичный HTTPS для локальной разработки не используются.
