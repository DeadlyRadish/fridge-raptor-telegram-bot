<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Telegram\Bot\Api as TelegramApi;
use Illuminate\Support\Facades\Log;

/**
 * Artisan команда для установки webhook в Telegram
 */
class SetTelegramWebhookCommand extends Command
{
    /**
     * @var string Имя и сигнатура команды
     */
    protected $signature = 'telegram:set-webhook 
                            {--url= : URL webhook (из TELEGRAM_WEBHOOK_URL)}
                            {--drop-pending-updates : Удалить ожидающие обновления}';

    /**
     * @var string Описание команды
     */
    protected $description = 'Устанавливает webhook для Telegram бота';

    /**
     * @var TelegramApi API клиент Telegram
     */
    private TelegramApi $telegram;

    /**
     * Конструктор команды
     *
     * @param TelegramApi $telegram
     */
    public function __construct(TelegramApi $telegram)
    {
        parent::__construct();
        $this->telegram = $telegram;
    }

    /**
     * Выполнение команды
     *
     * @return int
     */
    public function handle(): int
    {
        $this->info('🔧 Установка webhook для Telegram бота...');

        // Получение URL из опции или конфигурации
        $webhookUrl = $this->option('url') ?? config('services.telegram.webhook_url');

        if (empty($webhookUrl)) {
            $this->error('❌ URL webhook не указан. Используйте --url или настройте TELEGRAM_WEBHOOK_URL в .env');
            return Command::FAILURE;
        }

        // Валидация URL
        if (!filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
            $this->error("❌ Неверный формат URL: {$webhookUrl}");
            return Command::FAILURE;
        }

        // URL должен использовать HTTPS
        if (!str_starts_with($webhookUrl, 'https://')) {
            $this->warn('⚠️  Внимание: Telegram требует HTTPS для webhook!');
        }

        try {
            // Установка webhook
            $this->info("📡 URL webhook: {$webhookUrl}");

            $this->telegram->setWebhook([
                'url' => $webhookUrl,
                'drop_pending_updates' => $this->option('drop-pending-updates') ?? true,
            ]);

            // Проверка установленного webhook
            $webhookInfo = $this->telegram->getWebhookInfo();

            $this->info('✅ Webhook успешно установлен!');
            $this->table(
                ['Параметр', 'Значение'],
                [
                    ['URL', $webhookInfo->getUrl()],
                    ['Статус', $webhookInfo->hasActiveWebhook() ? 'Активен' : 'Неактивен'],
                    ['Ожидающие обновления', $webhookInfo->getPendingUpdateCount() ?? 0],
                    ['Последняя ошибка', $webhookInfo->getLastErrorMessage() ?? 'Нет ошибок'],
                ]
            );

            Log::info('Webhook установлен', ['url' => $webhookUrl]);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('❌ Ошибка при установке webhook: ' . $e->getMessage());
            Log::error('Ошибка установки webhook', [
                'error' => $e->getMessage(),
                'url' => $webhookUrl,
            ]);

            return Command::FAILURE;
        }
    }
}
