<?php

namespace TackleTelegram\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use TackleTelegram\TackleTelegramServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [TackleTelegramServiceProvider::class];
    }
}
