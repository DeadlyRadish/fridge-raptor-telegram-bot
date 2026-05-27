# Telegram Bot Service for Recipe Generation

Laravel-based Telegram bot that interacts with users and calls the Core Service API to generate recipes using AI.

## Architecture

- **Laravel 10+** - Backend framework
- **Redis** - Queue backend and FSM state storage
- **Telegram Bot SDK** - Telegram API integration
- **Guzzle HTTP** - Core API client

## Installation

### 1. Clone and Install Dependencies

```bash
composer install
npm install
```

### 2. Environment Configuration

Copy `.env.example` to `.env` and configure:

```env
# Application
APP_NAME="Recipe Bot"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com

# Database
DB_CONNECTION=pgsql
DB_HOST=localhost
DB_PORT=5432
DB_DATABASE=fridge_raptor_telegram_bot
DB_USERNAME=your_user
DB_PASSWORD=your_password

# Redis (required for queues and FSM)
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
QUEUE_CONNECTION=redis

# Telegram Bot
TELEGRAM_BOT_TOKEN=your_bot_token_from_botfather
TELEGRAM_WEBHOOK_URL=https://your-domain.com/webhook/telegram
TELEGRAM_WEBHOOK_SECRET=your_secret_key

# Core Service API
CORE_API_BASE_URL=http://localhost:8000/api/v1
CORE_API_KEY=your_api_key
```

Generate application key:
```bash
php artisan key:generate
```

### 3. Database Setup

```bash
php artisan migrate
```

### 4. Queue Worker

Start the queue worker to process recipe generation jobs:

```bash
php artisan queue:work --tries=3
```

For production, use supervisor to keep the worker running:

```ini
[program:laravel-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/app/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/path/to/app/storage/logs/worker.log
stopwaitsecs=3600
```

### 5. Set Telegram Webhook

```bash
php artisan telegram:set-webhook
```

Or with custom URL:
```bash
php artisan telegram:set-webhook --url=https://your-domain.com/webhook/telegram
```

## Usage

### Bot Commands

- `/start` - Start conversation and welcome message
- `/help` - Show help information
- `/history` - View recipe history with pagination
- `/cancel` - Cancel current order

### Conversation Flow

1. **User sends ingredients** (e.g., "картофель, курица, лук")
2. **Bot asks for preferences** (time, difficulty, dietary restrictions)
3. **User specifies preferences** or says "готовь" for defaults
4. **Bot generates recipe** via Core API (async job)
5. **Bot sends formatted recipe** in Markdown

### State Machine (FSM)

The bot uses a finite state machine stored in Redis:

- `idle` - No active conversation
- `waiting_ingredients` - Waiting for ingredient list
- `clarifying_parameters` - Asking for cooking preferences
- `viewing_recipe` - Recipe generation in progress

States are automatically managed by `UserStateManager` service.

## Project Structure

```
app/
├── Console/Commands/
│   └── SetTelegramWebhookCommand.php
├── Http/Controllers/
│   └── TelegramWebhookController.php
├── Jobs/
│   └── GenerateRecipeJob.php
├── Services/
│   ├── CoreApiClient.php      # HTTP client for Core API
│   ├── TelegramFormatter.php  # Message formatting (JSON → Markdown)
│   └── UserStateManager.php   # FSM state management in Redis
```

## API Integration

### Core Service Endpoints

- `POST /api/v1/recipes/generate` - Generate new recipe
- `GET /api/v1/recipes/history` - Get user's recipe history
- `GET /api/v1/recipes/{id}` - Get recipe details
- `DELETE /api/v1/recipes/{id}` - Delete recipe

Authentication: `X-API-Key` header

## Development

### Running Locally

1. Start Laravel development server:
```bash
php artisan serve
```

2. Use ngrok for webhook testing:
```bash
ngrok http 8000
```

3. Update TELEGRAM_WEBHOOK_URL with ngrok URL

### Testing

```bash
php artisan test
```

## Error Handling

- All API errors are logged to Laravel log
- Users receive friendly error messages
- Failed jobs are retried up to 3 times with exponential backoff

## Security

- Webhook secret validation (optional)
- API key stored in environment variables
- User states expire after 24 hours

## License

MIT License
