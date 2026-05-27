<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * HTTP-клиент для взаимодействия с Core Service API
 */
class CoreApiClient
{
    /**
     * @var string Базовый URL API
     */
    private string $baseUrl;

    /**
     * @var string|null API ключ для аутентификации
     */
    private ?string $apiKey;

    /**
     * @param string $baseUrl Базовый URL API
     * @param string|null $apiKey API ключ
     */
    public function __construct(
        string $baseUrl,
        ?string $apiKey = null
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
    }

    /**
     * Генерация нового рецепта через Core API
     *
     * @param array $ingredients Список ингредиентов
     * @param array $preferences Предпочтения пользователя
     * @param string $userId Идентификатор пользователя
     * @return array Данные рецепта
     * @throws RuntimeException При ошибке запроса
     */
    public function generateRecipe(
        array $ingredients,
        array $preferences = [],
        string $userId
    ): array {
        $response = Http::withHeaders([
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'X-API-Key' => $this->apiKey ?? '',
        ])
        ->timeout(30)
        ->post("{$this->baseUrl}/api/v1/recipes/generate", [
            'ingredients' => $ingredients,
            'preferences' => $preferences,
            'user_id' => $userId,
        ]);

        if ($response->failed()) {
            throw new RuntimeException(
                "Ошибка генерации рецепта: {$response->status()} - {$response->body()}",
                $response->status()
            );
        }

        $data = $response->json();

        if (!isset($data['success']) || !$data['success']) {
            throw new RuntimeException('API вернуло unsuccessful ответ');
        }

        return $data['data'] ?? [];
    }

    /**
     * Получение истории рецептов пользователя
     *
     * @param string $userId Идентификатор пользователя
     * @param int $page Номер страницы
     * @param int $perPage Количество записей на странице
     * @return array История рецептов и пагинация
     * @throws RuntimeException При ошибке запроса
     */
    public function getRecipeHistory(
        string $userId,
        int $page = 1,
        int $perPage = 5
    ): array {
        $response = Http::withHeaders([
            'Accept' => 'application/json',
            'X-API-Key' => $this->apiKey ?? '',
        ])
        ->timeout(10)
        ->get("{$this->baseUrl}/api/v1/recipes/history", [
            'user_id' => $userId,
            'page' => $page,
            'per_page' => $perPage,
        ]);

        if ($response->failed()) {
            throw new RuntimeException(
                "Ошибка получения истории: {$response->status()}",
                $response->status()
            );
        }

        $data = $response->json();

        if (!isset($data['success']) || !$data['success']) {
            throw new RuntimeException('API вернуло unsuccessful ответ');
        }

        return [
            'recipes' => $data['data'] ?? [],
            'pagination' => $data['pagination'] ?? [],
        ];
    }

    /**
     * Получение деталей конкретного рецепта
     *
     * @param string $recipeId ID рецепта
     * @param string $userId Идентификатор пользователя
     * @return array Данные рецепта
     * @throws RuntimeException При ошибке запроса
     */
    public function getRecipeDetails(string $recipeId, string $userId): array
    {
        $response = Http::withHeaders([
            'Accept' => 'application/json',
            'X-API-Key' => $this->apiKey ?? '',
            'X-User-ID' => $userId,
        ])
        ->timeout(10)
        ->get("{$this->baseUrl}/api/v1/recipes/{$recipeId}");

        if ($response->failed()) {
            throw new RuntimeException(
                "Ошибка получения рецепта: {$response->status()}",
                $response->status()
            );
        }

        $data = $response->json();

        if (!isset($data['success']) || !$data['success']) {
            throw new RuntimeException('API вернуло unsuccessful ответ');
        }

        return $data['data'] ?? [];
    }

    /**
     * Удаление рецепта
     *
     * @param string $recipeId ID рецепта
     * @param string $userId Идентификатор пользователя
     * @return bool Успешность удаления
     * @throws RuntimeException При ошибке запроса
     */
    public function deleteRecipe(string $recipeId, string $userId): bool
    {
        $response = Http::withHeaders([
            'Accept' => 'application/json',
            'X-API-Key' => $this->apiKey ?? '',
            'X-User-ID' => $userId,
        ])
        ->timeout(10)
        ->delete("{$this->baseUrl}/api/v1/recipes/{$recipeId}");

        if ($response->failed()) {
            throw new RuntimeException(
                "Ошибка удаления рецепта: {$response->status()}",
                $response->status()
            );
        }

        $data = $response->json();

        return isset($data['success']) && $data['success'];
    }
}
