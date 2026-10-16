<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__, 2);
require $root.'/.preview/app/vendor/autoload.php';
$app = require $root.'/.preview/app/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$connection = DB::connection();
if (! app()->environment('pilot') || config('database.default') !== 'pgsql'
    || $connection->getConfig('database') !== 'akaiv_preview_pilot_test'
    || $connection->getConfig('host') !== '127.0.0.1'
    || (string) $connection->getConfig('port') !== '5432'
    || config('filesystems.disks.s3.endpoint') !== 'http://127.0.0.1:9000'
    || config('filesystems.disks.s3.bucket') !== 'akaiv-preview') {
    throw new RuntimeException('Refusing to initialize services outside the disposable preview.');
}
echo "Disposable preview boundary verified.\n";
