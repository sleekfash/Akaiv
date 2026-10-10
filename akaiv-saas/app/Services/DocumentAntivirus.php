<?php

namespace App\Services;

use RuntimeException;
use Socket\Raw\Factory;
use Xenolope\Quahog\Client;

class DocumentAntivirus
{
    public function scan(string $path): array
    {
        $stream = fopen($path, 'rb');
        if (! is_resource($stream)) {
            throw new RuntimeException('Unable to open antivirus input.');
        }
        $socket = null;
        try {
            $socket = (new Factory)->createClient(sprintf('tcp://%s:%d', config('services.clamav.host'), config('services.clamav.port', 3310)), 30);
            $client = new Client($socket, 300, PHP_NORMAL_READ);
            $result = $client->scanResourceStream($stream);
            if ($result->isFound()) {
                return ['verdict' => 'infected', 'reason' => $result->getReason()];
            }
            if (! $result->isOk()) {
                throw new RuntimeException('Antivirus did not return an explicit clean verdict.');
            }

            return ['verdict' => 'clean'];
        } finally {
            fclose($stream);
            $socket?->close();
        }
    }
}
