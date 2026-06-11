<?php

namespace KhindIq\Docling;

use Illuminate\Support\ServiceProvider;

class DoclingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/docling.php', 'docling');

        $this->app->singleton(DoclingService::class, function () {
            return new DoclingService();
        });

        $this->app->alias(DoclingService::class, 'docling');
    }

    public function boot(): void
    {
        $this->registerDefaultLogChannel();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/docling.php' => config_path('docling.php'),
            ], 'docling-config');
        }
    }

    /**
     * Register the configured log channel as a daily file unless the app
     * already defines it in config/logging.php.
     */
    private function registerDefaultLogChannel(): void
    {
        $config = $this->app->make('config');
        $channel = $config->get('docling.log_channel');

        if ($channel && ! $config->has("logging.channels.{$channel}")) {
            $config->set("logging.channels.{$channel}", [
                'driver' => 'daily',
                'path' => $this->app->storagePath('logs/docling.log'),
                'level' => 'debug',
                'days' => 14,
                'replace_placeholders' => true,
            ]);
        }
    }
}
