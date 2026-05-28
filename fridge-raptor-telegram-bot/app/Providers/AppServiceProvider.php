<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Telegram\Bot\Api as TelegramApi;
use App\Services\CoreApiClient;

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
            return new CoreApiClient(
                config('services.core_api.base_url'),
                config('services.core_api.key')
            );
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
