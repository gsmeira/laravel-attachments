<?php

namespace GSMeira\LaravelAttachments;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use GSMeira\LaravelAttachments\Controllers\SignedStorageUrlController;

class AttachmentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/attachments.php', 'attachments');

        $this->loadRoutes();
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'laravel-attachments');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/attachments.php' => config_path('attachments.php'),
            ], 'laravel-attachments');
        }
    }

    protected function loadRoutes(): void
    {
        if (!config('attachments.signed_storage.enabled', true)) {
            return;
        }

        Route::post(
            config('attachments.signed_storage.route.url', '/attachments/signed-storage-url'),
            [SignedStorageUrlController::class, 'store']
        )
        ->middleware(config('attachments.signed_storage.route.middleware', 'web'));
    }
}
