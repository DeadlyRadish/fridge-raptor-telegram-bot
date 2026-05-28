<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class ProductsApiClient
{
    public function __construct(private readonly string $baseUrl) {}

    public function getProducts(int $userId): array
    {
        $response = Http::acceptJson()
            ->timeout(10)
            ->get("{$this->baseUrl}/api/v1/products", [
                'user_id' => $userId,
            ]);

        if ($response->failed()) {
            throw new RuntimeException(
                "Ошибка получения продуктов: {$response->status()}",
                $response->status()
            );
        }

        $data = $response->json();

        return $data['data'] ?? [];
    }
}
