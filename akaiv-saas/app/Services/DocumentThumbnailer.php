<?php

namespace App\Services;

use Symfony\Component\Process\Process;

class DocumentThumbnailer
{
    public function render(string $input): void
    {
        $process = new Process(['pdftoppm', '-f', '1', '-l', '1', '-png', '-singlefile', '-scale-to', '1200', $input, $input]);
        $process->setTimeout(240)->mustRun();
    }
}
