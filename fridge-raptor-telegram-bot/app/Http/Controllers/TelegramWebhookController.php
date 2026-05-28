<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\GenerateRecipeJob;
use App\Services\CoreApiClient;
use App\Services\ProductsApiClient;
use App\Services\TelegramFormatter;
use App\Services\UserStateManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Telegram\Bot\Api as TelegramApi;
use Telegram\Bot\Exceptions\TelegramSDKException;
use Telegram\Bot\Objects\MessageEntity;
use Telegram\Bot\Objects\Update;

/**
 * Контроллер для обработки webhook от Telegram Bot API
 */
class TelegramWebhookController extends Controller
{
    /**
     * @var TelegramApi API клиент Telegram
     */
    private TelegramApi $telegram;

    /**
     * @var UserStateManager Менеджер состояний пользователей
     */
    private UserStateManager $stateManager;

    /**
     * @var TelegramFormatter Форматировщик сообщений
     */
    private TelegramFormatter $formatter;

    /**
     * @var CoreApiClient|null Клиент Core API (ленивая инициализация)
     */
    private ?CoreApiClient $apiClient = null;

    private ?ProductsApiClient $productsApiClient = null;

    /**
     * Конструктор контроллера
     */
    public function __construct(
        TelegramApi $telegram,
        UserStateManager $stateManager,
        TelegramFormatter $formatter
    ) {
        $this->telegram = $telegram;
        $this->stateManager = $stateManager;
        $this->formatter = $formatter;
    }

    /**
     * Обработка входящего webhook от Telegram
     */
    public function handle(Request $request): JsonResponse
    {
        try {
            $payload = $request->all();
            $update = $payload !== []
                ? new Update($payload)
                : $this->telegram->getWebhookUpdate();

            Log::info('Получен webhook от Telegram', [
                'update_id' => $update->getId(),
            ]);

            // Игнорируем сообщения от ботов
            if ($update->getMessage()?->getFrom()?->getIsBot()) {
                return response()->json(['success' => true]);
            }

            // Обработка команд
            if ($update->hasMessage() && $update->getMessage()->hasEntities()) {
                $entities = $update->getMessage()->getEntities();

                foreach ($entities as $entity) {
                    if ($entity->getType() === 'bot_command') {
                        return $this->handleCommand($update, $entity);
                    }
                }
            }

            // Обработка текстовых сообщений
            if ($update->hasMessage() && $update->getMessage()->hasText()) {
                return $this->handleTextMessage($update);
            }

            // Обработка callback query (инлайн-кнопки)
            if ($update->hasCallbackQuery()) {
                return $this->handleCallbackQuery($update);
            }

            return response()->json(['success' => true]);

        } catch (TelegramSDKException $e) {
            Log::error('Telegram SDK ошибка', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Telegram SDK error',
            ], 500);
        } catch (\Throwable $e) {
            Log::error('Общая ошибка webhook', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Internal server error',
            ], 500);
        }
    }

    /**
     * Обработка команд бота
     *
     * @param  Update  $update
     * @param  MessageEntity  $entity
     */
    private function handleCommand($update, $entity): JsonResponse
    {
        $message = $update->getMessage();
        $chatId = $message->getChat()->getId();
        $userId = (string) $message->getFrom()->getId();
        $userName = $message->getFrom()->getFirstName();

        // Получаем текст команды
        $commandText = mb_substr(
            $message->getText(),
            $entity->getOffset(),
            $entity->getLength()
        );

        $command = strtolower(str_replace('/', '', $commandText));

        Log::info("Команда {$command} от пользователя {$userId}");

        return match ($command) {
            'start' => $this->handleStartCommand($chatId, $userId, $userName),
            'help' => $this->handleHelpCommand($chatId),
            'history' => $this->handleHistoryCommand($chatId, $userId),
            'cancel' => $this->handleCancelCommand($chatId, $userId),
            'products' => $this->handleProductsCommand($chatId, $userId),
            'cook' => $this->handleCookFromProductsCommand($chatId, $userId),
            default => response()->json(['success' => true]),
        };
    }

    /**
     * Обработка текстовых сообщений
     *
     * @param  Update  $update
     */
    private function handleTextMessage($update): JsonResponse
    {
        $message = $update->getMessage();
        $chatId = $message->getChat()->getId();
        $userId = (string) $message->getFrom()->getId();
        $text = trim($message->getText());

        Log::info("Текстовое сообщение от {$userId}: {$text}");

        // Получаем текущее состояние пользователя
        $state = $this->stateManager->getState($userId);

        // Обработка в зависимости от состояния
        return match ($state['state']) {
            UserStateManager::STATE_WAITING_INGREDIENTS => $this->handleIngredientsInput($chatId, $userId, $text),

            UserStateManager::STATE_CLARIFYING_PARAMETERS => $this->handleParametersInput($chatId, $userId, $text),

            default => $this->handleDefaultMessage($chatId, $userId, $text),
        };
    }

    /**
     * Обработка callback query от инлайн-кнопок
     *
     * @param  Update  $update
     */
    private function handleCallbackQuery($update): JsonResponse
    {
        $callbackQuery = $update->getCallbackQuery();
        $chatId = $callbackQuery->getMessage()->getChat()->getId();
        $userId = (string) $callbackQuery->getFrom()->getId();
        $data = $callbackQuery->getData();

        Log::info("Callback query от {$userId}: {$data}");

        // Парсинг данных callback
        [$action, $param] = explode(':', $data.':');

        return match ($action) {
            'history_page' => $this->handleHistoryPagination(
                $chatId,
                $userId,
                (int) $param
            ),
            'recipe_action' => $this->handleRecipeAction(
                $chatId,
                $userId,
                $param
            ),
            default => response()->json(['success' => true]),
        };
    }

    /**
     * Команда /start - приветствие
     */
    private function handleStartCommand(int $chatId, string $userId, string $userName): JsonResponse
    {
        // Сброс состояния
        $this->stateManager->clearState($userId);

        $message = $this->formatter->formatWelcome($userName);

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'Markdown',
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Команда /help - справка
     */
    private function handleHelpCommand(int $chatId): JsonResponse
    {
        $message = $this->formatter->formatHelp();

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'Markdown',
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Команда /history - история рецептов
     */
    private function handleHistoryCommand(int $chatId, string $userId, int $page = 1): JsonResponse
    {
        try {
            $apiClient = $this->getApiClient();
            $allRecipes = $apiClient->getRecipes();
            $filteredRecipes = array_values(array_filter(
                $allRecipes,
                static fn (array $recipe): bool => (int) ($recipe['user_id'] ?? 0) === (int) $userId
            ));
            $perPage = 5;
            $totalPages = max(1, (int) ceil(count($filteredRecipes) / $perPage));
            $currentPage = min(max($page, 1), $totalPages);
            $recipes = array_slice($filteredRecipes, ($currentPage - 1) * $perPage, $perPage);

            $message = $this->formatter->formatRecipeHistory($recipes, $currentPage, $totalPages);
            $keyboard = $this->createHistoryKeyboard($currentPage, $totalPages);

            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'Markdown',
                'reply_markup' => $keyboard,
            ]);

        } catch (\Exception $e) {
            Log::error("Ошибка получения истории для {$userId}", [
                'error' => $e->getMessage(),
            ]);

            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "*😕 Ошибка*\n\nНе удалось загрузить историю рецептов.\nПопробуйте позже.",
                'parse_mode' => 'Markdown',
            ]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Команда /cancel - отмена текущего действия
     */
    private function handleCancelCommand(int $chatId, string $userId): JsonResponse
    {
        $this->stateManager->clearState($userId);

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => "*❌ Отменено*\n\nТекущий заказ отменен.\nОтправьте новые ингредиенты или используйте /help",
            'parse_mode' => 'Markdown',
        ]);

        return response()->json(['success' => true]);
    }

    private function handleProductsCommand(int $chatId, string $userId): JsonResponse
    {
        try {
            $products = $this->getProductsApiClient()->getProducts((int) $userId);
            $message = $this->formatter->formatProducts($products);
        } catch (\Throwable $e) {
            Log::error("Ошибка загрузки продуктов для {$userId}", [
                'error' => $e->getMessage(),
            ]);
            $message = "*😕 Ошибка*\n\nНе удалось получить продукты из mock-сервиса.";
        }

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'Markdown',
        ]);

        return response()->json(['success' => true]);
    }

    private function handleCookFromProductsCommand(int $chatId, string $userId): JsonResponse
    {
        try {
            $products = $this->getProductsApiClient()->getProducts((int) $userId);
        } catch (\Throwable $e) {
            Log::error("Ошибка команды /cook для {$userId}", [
                'error' => $e->getMessage(),
            ]);

            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "*😕 Ошибка*\n\nНе удалось получить продукты из mock-сервиса.",
                'parse_mode' => 'Markdown',
            ]);

            return response()->json(['success' => true]);
        }

        if ($products === []) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "*📦 Список пуст*\n\nВ хранилище нет продуктов. Добавьте их и повторите /cook.",
                'parse_mode' => 'Markdown',
            ]);

            return response()->json(['success' => true]);
        }

        $this->stateManager->setState($userId, UserStateManager::STATE_CLARIFYING_PARAMETERS, [
            'ingredients' => $products,
        ]);

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $this->formatter->formatParametersQuestion($products),
            'parse_mode' => 'Markdown',
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Обработка ввода ингредиентов (состояние WAITING_INGREDIENTS)
     */
    private function handleIngredientsInput(int $chatId, string $userId, string $text): JsonResponse
    {
        // Проверка на команду "назад" или "отмена"
        if (in_array(strtolower($text), ['назад', 'отмена', 'cancel'])) {
            $this->stateManager->clearState($userId);

            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "*❌ Отменено*\n\nВведите новые ингредиенты:",
                'parse_mode' => 'Markdown',
            ]);

            return response()->json(['success' => true]);
        }

        // Парсинг ингредиентов из текста
        $ingredients = $this->parseProducts($text);

        if (empty($ingredients)) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "*⚠️ Не распознано*\n\nЯ не нашел ингредиентов в вашем сообщении.\nПопробуйте формат: _картофель, курица, лук_",
                'parse_mode' => 'Markdown',
            ]);

            return response()->json(['success' => true]);
        }

        // Сохранение ингредиентов и переход к уточнению параметров
        try {
            $this->stateManager->setState($userId, UserStateManager::STATE_CLARIFYING_PARAMETERS, [
                'ingredients' => $ingredients,
            ]);

            $message = $this->formatter->formatParametersQuestion($ingredients);

            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'Markdown',
            ]);

        } catch (InvalidArgumentException $e) {
            Log::error("Ошибка перехода состояния для {$userId}", [
                'error' => $e->getMessage(),
            ]);

            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "*😕 Ошибка*\n\nПроизошла ошибка. Попробуйте /cancel и начните заново.",
                'parse_mode' => 'Markdown',
            ]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Обработка ввода параметров (состояние CLARIFYING_PARAMETERS)
     */
    private function handleParametersInput(int $chatId, string $userId, string $text): JsonResponse
    {
        $textLower = strtolower($text);

        // Команда "готовь" - использование настроек по умолчанию
        if (in_array($textLower, ['готовь', 'готово', 'ok', 'да'])) {
            return $this->startRecipeGeneration($chatId, $userId);
        }

        // Команда "назад" - возврат к ингредиентам
        if (in_array($textLower, ['назад', 'изменить ингредиенты'])) {
            $this->stateManager->setState($userId, UserStateManager::STATE_WAITING_INGREDIENTS);

            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "*✏️ Изменение ингредиентов*\n\nВведите новый список продуктов:",
                'parse_mode' => 'Markdown',
            ]);

            return response()->json(['success' => true]);
        }

        // Парсинг предпочтений из текста
        $preferences = $this->parsePreferences($text);

        // Обновление состояния с предпочтениями
        $this->stateManager->updateStateData($userId, [
            'preferences' => $preferences,
        ]);

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => "*✅ Параметры сохранены*\n\nНапишите *\"готовь\"* для начала генерации\nили измените параметры:",
            'parse_mode' => 'Markdown',
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Обработка сообщения по умолчанию (состояние IDLE)
     */
    private function handleDefaultMessage(int $chatId, string $userId, string $text): JsonResponse
    {
        // Если пользователь написал текст - предполагаем что это ингредиенты
        $this->stateManager->setState($userId, UserStateManager::STATE_WAITING_INGREDIENTS);

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => "*📝 Ингредиенты*\n\nЯ запомнил ваши продукты.\nТеперь введите дополнительные ингредиенты через запятую или напишите *\"готовь\"* для начала:",
            'parse_mode' => 'Markdown',
        ]);

        // Сразу обрабатываем как ингредиенты
        return $this->handleIngredientsInput($chatId, $userId, $text);
    }

    /**
     * Обработка пагинации истории
     */
    private function handleHistoryPagination(int $chatId, string $userId, int $page): JsonResponse
    {
        return $this->handleHistoryCommand($chatId, $userId, $page);
    }

    /**
     * Обработка действий с рецептом
     */
    private function handleRecipeAction(int $chatId, string $userId, string $action): JsonResponse
    {
        // TODO: Реализация действий с рецептом (удаление, повторный просмотр)
        return response()->json(['success' => true]);
    }

    /**
     * Запуск генерации рецепта
     */
    private function startRecipeGeneration(int $chatId, string $userId): JsonResponse
    {
        $state = $this->stateManager->getState($userId);

        if (empty($state['ingredients'])) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "*⚠️ Ошибка*\n\nНет ингредиентов для генерации.\nНачните заново с /start",
                'parse_mode' => 'Markdown',
            ]);

            return response()->json(['success' => true]);
        }

        // Переход в состояние просмотра рецепта
        $this->stateManager->setState($userId, UserStateManager::STATE_VIEWING_RECIPE);

        // Сообщение о начале генерации
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => "*👨‍🍳 Готовлю рецепт...*\n\nЭто займет несколько секунд.",
            'parse_mode' => 'Markdown',
        ]);

        // Отправка задачи в очередь
        GenerateRecipeJob::dispatch(
            $chatId,
            $userId,
            $state['ingredients'],
            $state['preferences']
        );

        return response()->json(['success' => true]);
    }

    /**
     * Создание клавиатуры для пагинации истории
     */
    private function createHistoryKeyboard(int $currentPage, int $totalPages): array
    {
        $keyboard = [];

        $row = [];

        // Кнопка "Предыдущая"
        if ($currentPage > 1) {
            $row[] = [
                'text' => '⬅️ Назад',
                'callback_data' => 'history_page:'.($currentPage - 1),
            ];
        }

        // Кнопка "Следующая"
        if ($currentPage < $totalPages) {
            $row[] = [
                'text' => 'Вперед ➡️',
                'callback_data' => 'history_page:'.($currentPage + 1),
            ];
        }

        if (! empty($row)) {
            $keyboard[] = $row;
        }

        return [
            'inline_keyboard' => $keyboard,
        ];
    }

    /**
     * Парсинг ингредиентов из текста
     */
    private function parseIngredients(string $text): array
    {
        return $this->parseProducts($text);
    }

    private function parseProducts(string $text): array
    {
        $items = preg_split('/[,\n]+/', $text);
        $products = [];

        foreach ($items as $item) {
            $trimmed = trim($item);

            if (strlen($trimmed) < 2) {
                continue;
            }

            if (preg_match('/^(.+?)\s+(\d+(?:[.,]\d+)?)\s*([[:alpha:]]+|шт|г|кг|мл|л)$/u', $trimmed, $matches)) {
                $products[] = [
                    'name' => trim($matches[1]),
                    'quantity' => (float) str_replace(',', '.', $matches[2]),
                    'unit' => trim($matches[3]),
                ];

                continue;
            }

            $products[] = [
                'name' => $trimmed,
                'quantity' => 1,
                'unit' => 'шт',
            ];
        }

        return $products;
    }

    /**
     * Парсинг предпочтений из текста
     */
    private function parsePreferences(string $text): array
    {
        $preferences = [];
        $textLower = strtolower($text);

        // Время приготовления
        if (str_contains($textLower, 'быстро') || str_contains($textLower, '15 мин')) {
            $preferences['time_minutes'] = 15;
        } elseif (str_contains($textLower, 'средне') || str_contains($textLower, '30 мин')) {
            $preferences['time_minutes'] = 30;
        } elseif (str_contains($textLower, 'долго')) {
            $preferences['time_minutes'] = 60;
        }

        // Сложность
        if (str_contains($textLower, 'легк')) {
            $preferences['difficulty'] = 'easy';
        } elseif (str_contains($textLower, 'средн')) {
            $preferences['difficulty'] = 'medium';
        } elseif (str_contains($textLower, 'высок') || str_contains($textLower, 'тяжел')) {
            $preferences['difficulty'] = 'hard';
        }

        // Диетические ограничения
        $dietary = [];
        if (str_contains($textLower, 'без глютена') || str_contains($textLower, 'gluten')) {
            $dietary[] = 'no_gluten';
        }
        if (str_contains($textLower, 'вегетариан')) {
            $dietary[] = 'vegetarian';
        }
        if (str_contains($textLower, 'низкокалорий') || str_contains($textLower, 'диет')) {
            $dietary[] = 'low_calorie';
        }

        if (! empty($dietary)) {
            $preferences['dietary'] = $dietary;
        }

        return $preferences;
    }

    /**
     * Получение экземпляра CoreApiClient
     */
    private function getApiClient(): CoreApiClient
    {
        if ($this->apiClient === null) {
            $this->apiClient = new CoreApiClient(
                config('services.core_api.base_url', 'http://localhost:8000')
            );
        }

        return $this->apiClient;
    }

    private function getProductsApiClient(): ProductsApiClient
    {
        if ($this->productsApiClient === null) {
            $this->productsApiClient = new ProductsApiClient(
                config('services.products_api.base_url', 'http://localhost:8090')
            );
        }

        return $this->productsApiClient;
    }
}
