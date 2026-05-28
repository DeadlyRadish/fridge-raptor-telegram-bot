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
    private string $baseUrl;

    /**
     * @param  string  $baseUrl  Базовый URL API
     */
    public function __construct(string $baseUrl)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * Генерация нового рецепта через Core API
     *
     * @param  array  $products  Список продуктов
     * @param  array  $preferences  Предпочтения пользователя
     * @param  int  $userId  Идентификатор пользователя
     * @return array Данные рецепта
     *
     * @throws RuntimeException При ошибке запроса
     */
    public function generateRecipe(
        array $products,
        array $preferences,
        int $userId
    ): array {
        $response = Http::acceptJson()
            ->timeout(30)
            ->post("{$this->baseUrl}/api/v1/recipes", [
                'products' => $products,
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

        if (! isset($data['success']) || ! $data['success']) {
            throw new RuntimeException('API вернуло unsuccessful ответ');
        }

        return $data['data'] ?? [];
    }

    /**
     * Получение всех рецептов
     *
     * @throws RuntimeException При ошибке запроса
     */
    public function getRecipes(): array
    {
        $response = Http::acceptJson()
            ->timeout(10)
            ->get("{$this->baseUrl}/api/v1/recipes");

        if ($response->failed()) {
            throw new RuntimeException(
                "Ошибка получения истории: {$response->status()}",
                $response->status()
            );
        }

        $data = $response->json();

        if (! isset($data['success']) || ! $data['success']) {
            throw new RuntimeException('API вернуло unsuccessful ответ');
        }

        return $data['data'] ?? [];
    }

    /**
     * Проверка доступности Core API
     *
     * @return array Данные healthcheck
     *
     * @throws RuntimeException При ошибке запроса
     */
    public function checkHealth(): array
    {
        $response = Http::acceptJson()
            ->timeout(10)
            ->get("{$this->baseUrl}/api/health");

        if ($response->failed()) {
            throw new RuntimeException(
                "Ошибка health check: {$response->status()}",
                $response->status()
            );
        }

        return $response->json();
    }

    /**
     * Удаление рецепта
     *
     * @param  int  $recipeId  ID рецепта
     * @return bool Успешность удаления
     *
     * @throws RuntimeException При ошибке запроса
     */
    public function deleteRecipe(int $recipeId): bool
    {
        $response = Http::acceptJson()
            ->timeout(10)
            ->delete("{$this->baseUrl}/api/v1/recipes/{$recipeId}");

        if ($response->failed()) {
            throw new RuntimeException(
                "Ошибка удаления рецепта: {$response->status()}",
                $response->status()
            );
        }

        return $response->successful();
    }
}
