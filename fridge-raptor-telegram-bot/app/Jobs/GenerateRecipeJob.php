<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\CoreApiClient;
use App\Services\TelegramFormatter;
use App\Services\UserStateManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api as TelegramApi;

/**
 * Задача для асинхронной генерации рецепта через Core API
 */
class GenerateRecipeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @var int Идентификатор чата Telegram
     */
    private int $chatId;

    /**
     * @var string Идентификатор пользователя Telegram
     */
    private string $userId;

    /**
     * @var array Список ингредиентов
     */
    private array $ingredients;

    /**
     * @var array Предпочтения пользователя
     */
    private array $preferences;

    /**
     * @var int Максимальное количество попыток выполнения
     */
    public int $tries = 3;

    /**
     * @var int Время ожидания между попытками в секундах
     */
    public int $backoff = 5;

    /**
     * Создание нового экземпляра задачи
     *
     * @param  string  $userId  Идентификатор пользователя
     * @param  array  $ingredients  Список ингредиентов
     * @param  array  $preferences  Предпочтения пользователя
     */
    public function __construct(
        int $chatId,
        string $userId,
        array $ingredients,
        array $preferences = []
    ) {
        $this->chatId = $chatId;
        $this->userId = $userId;
        $this->ingredients = $ingredients;
        $this->preferences = $preferences;
    }

    /**
     * Выполнение задачи
     *
     * @param  CoreApiClient  $apiClient  Внедряется через контейнер
     * @param  TelegramFormatter  $formatter  Внедряется через контейнер
     * @param  TelegramApi  $telegram  Внедряется через контейнер
     */
    public function handle(
        CoreApiClient $apiClient,
        TelegramFormatter $formatter,
        TelegramApi $telegram,
        UserStateManager $stateManager
    ): void {
        try {
            Log::info("Генерация рецепта для пользователя {$this->userId}", [
                'ingredients' => $this->ingredients,
                'preferences' => $this->preferences,
            ]);

            // Запрос к Core API
            $recipe = $apiClient->generateRecipe(
                $this->ingredients,
                $this->preferences,
                (int) $this->userId
            );

            // Форматирование ответа
            $message = $formatter->formatRecipe($recipe);

            // Отправка пользователю
            $telegram->sendMessage([
                'chat_id' => $this->chatId,
                'text' => $message,
                'parse_mode' => 'Markdown',
            ]);

            $stateManager->setState($this->userId, UserStateManager::STATE_IDLE);

            Log::info("Рецепт успешно отправлен пользователю {$this->userId}", [
                'recipe_id' => $recipe['id'] ?? null,
            ]);

        } catch (\Exception $e) {
            Log::error("Ошибка генерации рецепта для пользователя {$this->userId}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Уведомление пользователя об ошибке
            $telegram->sendMessage([
                'chat_id' => $this->chatId,
                'text' => "*😕 Ошибка при генерации рецепта*\n\n".
                    "К сожалению, не удалось создать рецепт.\n".
                    'Попробуйте еще раз или измените ингредиенты.',
                'parse_mode' => 'Markdown',
            ]);

            // Пробрасываем исключение для обработки retry
            throw $e;
        }
    }

    /**
     * Обработка неудачного выполнения задачи
     */
    public function failed(\Throwable $exception): void
    {
        Log::critical('Задача генерации рецепта полностью провалена', [
            'user_id' => $this->userId,
            'error' => $exception->getMessage(),
        ]);
    }
}
