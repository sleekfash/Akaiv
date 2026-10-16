<?php

use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->loadEnvironmentFrom('tests/fixtures/testing.env');
$app->make(Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, get_class($error).': '.$error->getMessage().PHP_EOL);
    exit(1);
});
$database = (string) config('database.connections.'.config('database.default').'.database');
if (! app()->environment(['testing', 'pilot']) || getenv('PILOT_TEST_MODE') !== '1' || ! (str_ends_with($database, '_pilot_test') || str_ends_with($database, '_restore_test') || str_ends_with($database, 'akaiv-pilot.sqlite'))) {
    throw new RuntimeException('Pilot scripts require an explicitly opted-in disposable test database.');
}
