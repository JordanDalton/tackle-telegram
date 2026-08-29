<?php

namespace TackleTelegram;

use Illuminate\Support\ServiceProvider;
use TackleTelegram\Commands\TelegramCommand;

class TackleTelegramServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/tackle-telegram.php', 'tackle-telegram');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([TelegramCommand::class]);

            $this->publishes([
                __DIR__.'/../config/tackle-telegram.php' => config_path('tackle-telegram.php'),
            ], 'tackle-telegram-config');
        }
    }
}
