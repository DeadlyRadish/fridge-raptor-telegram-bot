# Полный план запуска Telegram Bot (Fridge Raptor)

## 1. Требования к окружению

- **PHP**: 8.2+
- **Расширения PHP**: mbstring, xml, curl, zip, sqlite3 (или pgsql + redis для production)
- **Composer**: 2.x
- **SQLite** (для разработки) или **PostgreSQL + Redis** (для production)

## 2. Установка зависимостей

```bash
cd /workspace/fridge-raptor-telegram-bot

# Установка PHP расширений (если не установлены)
apt-get update
apt-get install -y php8.2-cli php8.2-common php8.2-mbstring php8.2-xml php8.2-curl php8.2-zip php8.2-sqlite3

# Установка Composer (если не установлен)
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Установка зависимостей проекта
composer install --no-interaction
```

## 3. Настройка окружения

```bash
# Копирование файла окружения
cp .env.example .env

# Генерация ключа приложения
php artisan key:generate

# Редактирование .env (обязательные параметры):
```

### Минимальная конфигурация .env для разработки:

```env
APP_NAME="Fridge Raptor Telegram Bot"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8001

DB_CONNECTION=sqlite
DB_DATABASE=database/database.sqlite

QUEUE_CONNECTION=sync
CACHE_STORE=file
SESSION_DRIVER=file

# Telegram Bot (получить у @BotFather)
TELEGRAM_BOT_TOKEN=your_bot_token_here
TELEGRAM_WEBHOOK_URL=https://your-domain.com/webhook/telegram
TELEGRAM_WEBHOOK_SECRET=your_secret_key

# Core Service API
CORE_API_BASE_URL=http://localhost:8000/api/v1
CORE_API_KEY=your_api_key_here
```

## 4. Подготовка базы данных

```bash
# Создание файла SQLite
touch database/database.sqlite

# Запуск миграций
php artisan migrate --force
```

## 5. Проверка установки

```bash
# Проверка маршрутов
php artisan route:list

# Проверка доступных команд
php artisan list | grep telegram

# Запуск тестов
php artisan test
```

## 6. Запуск приложения

### Вариант A: Laravel встроенный сервер (разработка)

```bash
# В одном терминале - запуск сервера
php artisan serve --port=8001

# В другом терминале - обработка очередей (если QUEUE_CONNECTION !== sync)
php artisan queue:work --tries=3
```

### Вариант B: Production (Nginx + PHP-FPM)

1. Настройте Nginx virtual host на порт 8001
2. Укажите public/ как корневую директорию
3. Настройте PHP-FPM pool
4. Запустите supervisor для queue worker

## 7. Настройка Telegram Webhook

### Через Artisan команду:

```bash
php artisan telegram:set-webhook
```

### Вручную через curl:

```bash
curl -X POST "https://api.telegram.org/bot<YOUR_BOT_TOKEN>/setWebhook?url=https://your-domain.com/webhook/telegram&secret_token=<YOUR_SECRET>"
```

### Проверка статуса webhook:

```bash
curl "https://api.telegram.org/bot<YOUR_BOT_TOKEN>/getWebhookInfo"
```

## 8. Проверка работы бота

1. Откройте Telegram и найдите вашего бота
2. Отправьте команду `/start`
3. Бот должен ответить приветственным сообщением
4. Отправьте список ингредиентов (например: "картофель курица лук")
5. Бот отправит запрос к Core API и вернёт рецепт

## 9. Production конфигурация

### Для production замените:

```env
# База данных
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=fridge_raptor_telegram_bot
DB_USERNAME=your_db_user
DB_PASSWORD=your_db_password

# Очереди
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

# Кэш
CACHE_STORE=redis

# Сессии
SESSION_DRIVER=redis
SESSION_CONNECTION=default

# Режим отладки
APP_DEBUG=false
APP_ENV=production
```

### Запуск сервисов:

```bash
# Redis
service redis-server start

# PostgreSQL
service postgresql start

# Queue worker (через supervisor)
php artisan queue:work --sleep=3 --tries=3 --max-time=3600
```

## 10. Мониторинг и логи

```bash
# Просмотр логов Laravel
tail -f storage/logs/laravel.log

# Мониторинг очередей
php artisan queue:monitor database

# Статистика бота (кастомная команда)
php artisan telegram:stats
```

## 11. Решение проблем

### Ошибка "Token not provided":
- Убедитесь, что TELEGRAM_BOT_TOKEN установлен в .env
- Очистите кэш конфигурации: `php artisan config:clear`

### Ошибка подключения к базе данных:
- Проверьте DB_* параметры в .env
- Убедитесь, что файл database.sqlite существует и доступен для записи

### Webhook не работает:
- Проверьте, что ваш домен доступен из интернета
- Убедитесь, что SSL сертификат действителен
- Проверьте SECRET_TOKEN в запросе от Telegram

### Ошибки Core API:
- Проверьте CORE_API_BASE_URL и CORE_API_KEY
- Убедитесь, что Core Service запущен и доступен
- Проверьте логи: `storage/logs/laravel.log`

## 12. Полезные команды

```bash
# Очистка кэша
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

# Оптимизация для production
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Тестирование API
php artisan test --filter=Telegram

# Логи очереди
php artisan queue:listen --tries=3 --verbose
```

---

## Контакты и поддержка

При возникновении проблем проверьте:
1. Файл логов: `storage/logs/laravel.log`
2. Статус вебхука: `https://api.telegram.org/bot<TOKEN>/getWebhookInfo`
3. Доступность Core API: `curl http://localhost:8000/api/v1/health`
