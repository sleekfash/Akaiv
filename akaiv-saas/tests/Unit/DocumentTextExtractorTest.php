<?php

namespace Tests\Unit;

use App\Services\DocumentFormats;
use App\Services\DocumentTextExtractor;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Tests\TestCase;
use ZipArchive;

class DocumentTextExtractorTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/akaiv-extractor-test-'.bin2hex(random_bytes(12));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_text_is_read_without_an_ocr_process(): void
    {
        $path = $this->directory.'/notes.txt';
        file_put_contents($path, "First line\nSecond line: café");
        $extractor = new class extends DocumentTextExtractor
        {
            protected function run(array $command): string
            {
                throw new \RuntimeException('Text must not invoke external tools');
            }
        };
        $this->assertSame("First line\nSecond line: café", $extractor->extract($path, 'txt')['text']);
    }

    public function test_pdf_includes_every_page_and_ocr_for_image_only_pages(): void
    {
        $path = $this->directory.'/mixed.pdf';
        file_put_contents($path, "%PDF-1.4\n%%EOF\n");
        $extractor = new class extends DocumentTextExtractor
        {
            public array $ocrPages = [];

            protected function run(array $command): string
            {
                if ($command[0] === 'pdfinfo') {
                    return "Pages: 3\n";
                }
                if ($command[0] === 'pdftotext') {
                    return $command[2] === '2' ? "\f\n" : 'Native page '.$command[2];
                }
                if ($command[0] === 'pdftoppm') {
                    $this->ocrPages[] = $command[2];
                    file_put_contents(end($command).'.png', 'Fixture');

                    return '';
                }

                return 'Scanned page 2';
            }
        };
        $result = $extractor->extract($path, 'pdf');
        $this->assertSame("Native page 1\n\nScanned page 2\n\nNative page 3", $result['text']);
        $this->assertSame(3, $result['page_count']);
        $this->assertSame(['2'], $extractor->ocrPages);
    }

    public function test_word_uses_office_conversion_before_pdf_extraction(): void
    {
        $path = $this->directory.'/letter.docx';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('word/document.xml', '<document>Fixture</document>');
        $zip->close();
        $extractor = new class extends DocumentTextExtractor
        {
            public bool $converted = false;

            protected function run(array $command): string
            {
                if ($command[0] === 'libreoffice') {
                    $this->converted = true;
                    $directory = $command[array_search('--outdir', $command, true) + 1];
                    file_put_contents($directory.'/source.pdf', '%PDF-1.4');

                    return '';
                }

                return $command[0] === 'pdfinfo' ? "Pages: 1\n" : 'Word document content';
            }
        };
        $this->assertSame('Word document content', $extractor->extract($path, 'docx')['text']);
        $this->assertTrue($extractor->converted);
    }

    public function test_an_arbitrary_zip_cannot_masquerade_as_word(): void
    {
        $path = $this->directory.'/fake.docx';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('unrelated.txt', 'Not a Word document');
        $zip->close();
        $this->expectException(InvalidArgumentException::class);
        DocumentFormats::validate($path, 'docx');
    }

    public function test_a_fake_image_is_not_accepted_from_its_extension(): void
    {
        $path = $this->directory.'/fake.png';
        file_put_contents($path, 'This is not an image');
        $this->expectException(InvalidArgumentException::class);
        DocumentFormats::validate($path, 'png');
    }

    public function test_oversized_pdf_is_not_reported_as_partially_complete(): void
    {
        $path = $this->directory.'/large.pdf';
        file_put_contents($path, "%PDF-1.4\n%%EOF\n");
        $extractor = new class extends DocumentTextExtractor
        {
            protected function run(array $command): string
            {
                return "Pages: 301\n";
            }
        };
        $this->expectException(\RuntimeException::class);
        $extractor->extract($path, 'pdf');
    }
}
