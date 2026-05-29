<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api as TelegramApi;
use Telegram\Bot\Objects\Update;

class RunTelegramPollingCommand extends Command
{
    protected $signature = 'telegram:poll
                            {--timeout=25 : Long polling timeout in seconds}
                            {--sleep=1 : Sleep seconds between cycles}
                            {--drop-pending-updates : Drop backlog at startup}';

    protected $description = 'Запускает Telegram бота в режиме long polling';

    public function __construct(private readonly TelegramApi $telegram)
    {
        parent::__construct();
    }

    public function handle(TelegramWebhookController $controller): int
    {
        $this->info('Starting Telegram long polling...');

        try {
            // Для polling webhook должен быть снят, иначе Telegram не отдает updates.
            $this->telegram->deleteWebhook([
                'drop_pending_updates' => (bool) $this->option('drop-pending-updates'),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Не удалось удалить webhook перед polling', [
                'error' => $e->getMessage(),
            ]);
        }

        $offset = 0;
        $timeout = max(1, (int) $this->option('timeout'));
        $sleep = max(0, (int) $this->option('sleep'));
        $token = (string) config('services.telegram.bot_token');

        if ($token === '') {
            $this->error('TELEGRAM_BOT_TOKEN is empty.');

            return self::FAILURE;
        }

        while (true) {
            try {
                $response = Http::acceptJson()
                    ->connectTimeout(10)
                    ->timeout($timeout + 10)
                    ->get("https://api.telegram.org/bot{$token}/getUpdates", [
                        'offset' => $offset,
                        'timeout' => $timeout,
                        'allowed_updates' => ['message', 'callback_query'],
                    ]);

                if ($response->failed()) {
                    throw new \RuntimeException("Telegram API HTTP error: {$response->status()}");
                }

                $payload = $response->json();
                if (! ($payload['ok'] ?? false)) {
                    throw new \RuntimeException('Telegram API returned non-ok response');
                }

                foreach (($payload['result'] ?? []) as $updateData) {
                    $update = new Update(collect($updateData));
                    $offset = ((int) $update->getUpdateId()) + 1;
                
                    Log::debug('Получен апдейт', [
                        'update_id'   => $update->getUpdateId(),
                        'has_message' => $update->hasMessage(),
                        'text'        => $update->getMessage()?->getText(),
                    ]);
                
                    $controller->handleUpdate($update);
                }
            } catch (ConnectionException $e) {
                Log::error('Ошибка long polling: Telegram connection issue', ['error' => $e->getMessage()]);
                $this->warn('Polling error: connection timeout to api.telegram.org');
                $this->warn('Проверьте доступ контейнера к https://api.telegram.org (VPN/фаервол/сеть).');
            } catch (\Throwable $e) {
                Log::error('Ошибка long polling', ['error' => $e->getMessage()]);
                $this->warn("Polling error: {$e->getMessage()}");
                $this->warn('Проверьте доступ контейнера к https://api.telegram.org (VPN/фаервол/сеть).');
            }

            if ($sleep > 0) {
                sleep($sleep);
            }
        }
    }
}
