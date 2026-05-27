<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Redis;
use InvalidArgumentException;

/**
 * Машина состояний (FSM) для управления диалогом с пользователем Telegram
 * 
 * Хранит состояние пользователя в Redis и управляет переходами между состояниями
 */
class UserStateManager
{
    /**
     * Префикс ключа в Redis для хранения состояния пользователя
     */
    private const KEY_PREFIX = 'telegram_fsm:';
    
    /**
     * Время жизни ключа состояния в секундах (24 часа)
     */
    private const TTL = 86400;

    /**
     * Состояния диалога
     */
    public const STATE_IDLE = 'idle';
    public const STATE_WAITING_INGREDIENTS = 'waiting_ingredients';
    public const STATE_CLARIFYING_PARAMETERS = 'clarifying_parameters';
    public const STATE_VIEWING_RECIPE = 'viewing_recipe';

    /**
     * Возможные переходы между состояниями
     * [текущее_состояние => [допустимые_следующие_состояния]]
     */
    private const TRANSITIONS = [
        self::STATE_IDLE => [
            self::STATE_WAITING_INGREDIENTS,
        ],
        self::STATE_WAITING_INGREDIENTS => [
            self::STATE_CLARIFYING_PARAMETERS,
            self::STATE_IDLE, // Отмена
        ],
        self::STATE_CLARIFYING_PARAMETERS => [
            self::STATE_VIEWING_RECIPE,
            self::STATE_WAITING_INGREDIENTS, // Назад к ингредиентам
            self::STATE_IDLE, // Отмена
        ],
        self::STATE_VIEWING_RECIPE => [
            self::STATE_IDLE,
            self::STATE_WAITING_INGREDIENTS, // Новый рецепт
        ],
    ];

    /**
     * Данные по умолчанию для нового состояния
     */
    private const DEFAULT_STATE_DATA = [
        'state' => self::STATE_IDLE,
        'ingredients' => [],
        'preferences' => [
            'time_minutes' => null,
            'difficulty' => null,
            'dietary' => [],
        ],
        'current_recipe_id' => null,
        'message' => null,
    ];

    /**
     * Получить текущее состояние пользователя
     *
     * @param string|int $userId Идентификатор пользователя
     * @return array Массив с данными состояния
     */
    public function getState(string|int $userId): array
    {
        $key = $this->getKey($userId);
        $data = Redis::get($key);

        if ($data === null) {
            return self::DEFAULT_STATE_DATA;
        }

        $decoded = json_decode($data, true);
        
        if (!is_array($decoded)) {
            return self::DEFAULT_STATE_DATA;
        }

        return $decoded;
    }

    /**
     * Установить новое состояние для пользователя
     *
     * @param string|int $userId Идентификатор пользователя
     * @param string $newState Новое состояние
     * @param array $additionalData Дополнительные данные состояния
     * @return bool Успешность операции
     * @throws InvalidArgumentException Если переход недопустим
     */
    public function setState(
        string|int $userId,
        string $newState,
        array $additionalData = []
    ): bool {
        $currentState = $this->getState($userId);
        
        // Проверка допустимости перехода
        if (!$this->isValidTransition($currentState['state'], $newState)) {
            throw new InvalidArgumentException(
                "Недопустимый переход из '{$currentState['state']}' в '{$newState}'"
            );
        }

        $newData = array_merge($currentState, [
            'state' => $newState,
            'updated_at' => now()->timestamp,
        ], $additionalData);

        // Очистка временных данных при переходе в IDLE
        if ($newState === self::STATE_IDLE) {
            $newData = self::DEFAULT_STATE_DATA;
        }

        return Redis::setex(
            $this->getKey($userId),
            self::TTL,
            json_encode($newData, JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * Обновить данные текущего состояния без смены состояния
     *
     * @param string|int $userId Идентификатор пользователя
     * @param array $data Данные для обновления
     * @return bool Успешность операции
     */
    public function updateStateData(string|int $userId, array $data): bool
    {
        $currentState = $this->getState($userId);
        $mergedData = array_merge_recursive($currentState, $data);
        
        return Redis::setex(
            $this->getKey($userId),
            self::TTL,
            json_encode($mergedData, JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * Добавить ингредиент в список текущего состояния
     *
     * @param string|int $userId Идентификатор пользователя
     * @param string $ingredient Название ингредиента
     * @return bool Успешность операции
     */
    public function addIngredient(string|int $userId, string $ingredient): bool
    {
        $currentState = $this->getState($userId);
        
        if (!in_array($ingredient, $currentState['ingredients'], true)) {
            $currentState['ingredients'][] = $ingredient;
            
            return Redis::setex(
                $this->getKey($userId),
                self::TTL,
                json_encode($currentState, JSON_UNESCAPED_UNICODE)
            );
        }

        return true; // Ингредиент уже есть
    }

    /**
     * Очистить состояние пользователя (сброс в IDLE)
     *
     * @param string|int $userId Идентификатор пользователя
     * @return bool Успешность операции
     */
    public function clearState(string|int $userId): bool
    {
        return Redis::del($this->getKey($userId)) > 0;
    }

    /**
     * Проверка, находится ли пользователь в определенном состоянии
     *
     * @param string|int $userId Идентификатор пользователя
     * @param string $state Проверяемое состояние
     * @return bool
     */
    public function isInState(string|int $userId, string $state): bool
    {
        return $this->getState($userId)['state'] === $state;
    }

    /**
     * Проверка допустимости перехода между состояниями
     *
     * @param string $from Текущее состояние
     * @param string $to Целевое состояние
     * @return bool
     */
    private function isValidTransition(string $from, string $to): bool
    {
        if (!isset(self::TRANSITIONS[$from])) {
            return false;
        }

        return in_array($to, self::TRANSITIONS[$from], true);
    }

    /**
     * Получить ключ Redis для пользователя
     *
     * @param string|int $userId Идентификатор пользователя
     * @return string
     */
    private function getKey(string|int $userId): string
    {
        return self::KEY_PREFIX . $userId;
    }
}
