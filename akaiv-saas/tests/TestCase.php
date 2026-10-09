<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        foreach (['cache', 'sessions', 'views'] as $directory) {
            $path = __DIR__.'/../storage/framework/'.$directory;
            if (! is_dir($path)) {
                mkdir($path, 0700, true);
            }
        }
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->loadEnvironmentFrom('tests/fixtures/testing.env');

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
