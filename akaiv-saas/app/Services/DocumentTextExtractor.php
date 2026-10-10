<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

class DocumentTextExtractor
{
    public function extract(string $source, string $extension): array
    {
        $extension = strtolower($extension);
        DocumentFormats::validate($source, $extension);
        if (in_array($extension, DocumentFormats::TEXT, true)) {
            $text = file_get_contents($source);
            if (str_starts_with($text, "\xFF\xFE")) {
                $text = mb_convert_encoding(substr($text, 2), 'UTF-8', 'UTF-16LE');
            } elseif (str_starts_with($text, "\xFE\xFF")) {
                $text = mb_convert_encoding(substr($text, 2), 'UTF-8', 'UTF-16BE');
            } elseif (! mb_check_encoding($text, 'UTF-8')) {
                throw new RuntimeException('Text encoding needs explicit conversion to UTF-8.');
            }

            return ['text' => preg_replace('/^\x{FEFF}/u', '', $text), 'page_count' => null];
        }

        $directory = sys_get_temp_dir().'/akaiv-extract-'.bin2hex(random_bytes(16));
        if (! mkdir($directory, 0700)) {
            throw new RuntimeException('Unable to create extraction workspace.');
        }
        try {
            $input = $directory.'/source.'.$extension;
            if (! copy($source, $input)) {
                throw new RuntimeException('Unable to copy extraction input.');
            }
            if (in_array($extension, DocumentFormats::OFFICE, true)) {
                $profile = 'file://'.str_replace('\\', '/', $directory).'/profile';
                $this->run(['libreoffice', '-env:UserInstallation='.$profile, '--headless', '--nologo',
                    '--nodefault', '--norestore', '--convert-to', 'pdf', '--outdir', $directory, $input]);
                $input = $directory.'/source.pdf';
                if (! is_file($input) || filesize($input) === 0) {
                    throw new RuntimeException('Office conversion produced no readable PDF.');
                }

                return $this->pdf($input, $directory);
            }
            if ($extension === 'pdf') {
                return $this->pdf($input, $directory);
            }

            return $this->images($input, $directory);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    private function pdf(string $input, string $directory): array
    {
        $info = $this->run(['pdfinfo', $input]);
        if (! preg_match('/^Pages:\s+(\d+)\s*$/m', $info, $match)) {
            throw new RuntimeException('Unable to determine PDF page count.');
        }
        $pages = (int) $match[1];
        $this->checkPageCount($pages);
        $text = [];
        for ($page = 1; $page <= $pages; $page++) {
            $pageText = $this->run(['pdftotext', '-f', (string) $page, '-l', (string) $page, '-layout', $input, '-']);
            if (preg_match('/\S/u', $pageText) !== 1) {
                $prefix = $directory.'/page';
                $this->run(['pdftoppm', '-f', (string) $page, '-l', (string) $page, '-r', '150', '-png', '-singlefile', $input, $prefix]);
                $pageText = $this->run(['tesseract', $prefix.'.png', 'stdout', '-l', 'eng']);
                unlink($prefix.'.png');
            }
            $text[] = preg_replace('/^\s+|\s+$/u', '', $pageText);
        }

        return ['text' => implode("\n\n", $text), 'page_count' => $pages];
    }

    private function images(string $input, string $directory): array
    {
        $images = new \Imagick;
        try {
            $images->readImage($input);
            $this->checkPageCount($images->getNumberImages());
            $frames = $images->coalesceImages();
            try {
                $text = [];
                foreach ($frames as $frame) {
                    $frame->setImageFormat('png');
                    $path = $directory.'/frame.png';
                    $frame->writeImage($path);
                    $text[] = trim($this->run(['tesseract', $path, 'stdout', '-l', 'eng']));
                    unlink($path);
                }

                return ['text' => implode("\n\n", $text), 'page_count' => count($text)];
            } finally {
                $frames->clear();
            }
        } finally {
            $images->clear();
        }
    }

    private function checkPageCount(int $pages): void
    {
        if ($pages < 1 || $pages > 300) {
            throw new RuntimeException('Documents over 300 pages or frames require a separate extraction workflow.');
        }
    }

    protected function run(array $command): string
    {
        $process = new Process($command, null, ['LC_ALL' => 'C']);
        $process->setTimeout(120);
        $process->mustRun();

        return $process->getOutput();
    }
}
