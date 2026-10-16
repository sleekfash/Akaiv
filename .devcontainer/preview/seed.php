<?php

use App\Models\Organization;
use App\Models\User;
use Aws\S3\S3Client;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__, 2);
require $root.'/.preview/app/vendor/autoload.php';
$app = require $root.'/.preview/app/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('pilot') || config('database.connections.pgsql.database') !== 'akaiv_preview_pilot_test'
    || config('filesystems.disks.s3.endpoint') !== 'http://127.0.0.1:9000') {
    throw new RuntimeException('Disposable preview database and storage required.');
}
$client = new S3Client([
    'version' => 'latest', 'region' => 'us-east-1', 'endpoint' => 'http://127.0.0.1:9000',
    'use_path_style_endpoint' => true,
    'credentials' => ['key' => config('filesystems.disks.s3.key'), 'secret' => config('filesystems.disks.s3.secret')],
]);
if (! $client->doesBucketExistV2('akaiv-preview')) {
    $client->createBucket(['Bucket' => 'akaiv-preview']);
}
$logins = json_decode(file_get_contents($root.'/.preview/logins.json'), true, flags: JSON_THROW_ON_ERROR);
DB::transaction(function () use ($logins): void {
    $org = Organization::firstOrCreate(['slug' => 'private-preview'], [
        'name' => 'Preview Court', 'contact_email' => 'preview@example.test',
    ]);
    foreach ($logins as $name => $login) {
        $user = User::firstOrCreate(['email' => $login['email']], [
            'name' => 'Preview '.ucfirst($name), 'password' => $login['password'],
        ]);
        $user->organizations()->syncWithoutDetaching([$org->id => ['role' => 'member_write']]);
        if ($user->wasRecentlyCreated) {
            $status = Artisan::call('archive:provision-role', ['email' => $user->email, 'role' => 'Administrator']);
            if ($status !== 0) {
                throw new RuntimeException('Preview role provisioning failed.');
            }
        }
    }
});
echo "Private preview accounts and storage ready.\n";
