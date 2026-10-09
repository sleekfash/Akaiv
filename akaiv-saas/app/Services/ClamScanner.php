<?php

namespace App\Services;

use Socket\Raw\Factory;
use Xenolope\Quahog\Client;

class ClamScanner
{
    public function scan($stream): bool
    {
        $socket = (new Factory)->createClient(sprintf('tcp://%s:%d', config('services.clamav.host'), config('services.clamav.port', 3310)), 10);
        $client = new Client($socket);
        try {
            $result = $client->scanResourceStream($stream);
            if ($result->isError()) {
                throw new \RuntimeException('Antivirus returned an error');
            }
            if (! $result->isOk() && ! $result->isFound()) {
                throw new \RuntimeException('Unrecognized antivirus result');
            }

            return $result->isOk();
        } finally {
            $client->disconnect();
        }
    }
}
