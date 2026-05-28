<?php

namespace App\Providers;

use App\Services\CoreApiClient;
use App\Services\ProductsApiClient;
use Illuminate\Support\ServiceProvider;
use Telegram\Bot\Api as TelegramApi;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Регистрация Telegram API клиента
        $this->app->singleton(TelegramApi::class, function ($app) {
            return new TelegramApi(config('services.telegram.bot_token'));
        });

        // Регистрация Core API клиента
        $this->app->singleton(CoreApiClient::class, function ($app) {
            return new CoreApiClient(config('services.core_api.base_url'));
        });

        // Регистрация mock-сервиса продуктов
        $this->app->singleton(ProductsApiClient::class, function ($app) {
            return new ProductsApiClient(config('services.products_api.base_url'));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
