<?php

namespace Tests\Feature;

use App\Services\DocumentTextExtractor;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DocumentConvertersIntegrationTest extends TestCase
{
    private ?string $directory = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('AKAIV_RUN_CONVERTERS') !== '1') {
            $this->markTestSkipped('Set AKAIV_RUN_CONVERTERS=1 in the PHP container to run real converters.');
        }
        $this->directory = sys_get_temp_dir().'/akaiv-converters-'.bin2hex(random_bytes(12));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        if ($this->directory !== null) {
            File::deleteDirectory($this->directory);
        }
        parent::tearDown();
    }

    public static function officeFormats(): array
    {
        return [
            'Word binary' => ['text', 'doc'],
            'Word OOXML' => ['text', 'docx'],
            'rich text' => ['text', 'rtf'],
            'OpenDocument text' => ['text', 'odt'],
            'PDF' => ['text', 'pdf'],
            'Excel binary' => ['spreadsheet', 'xls'],
            'Excel OOXML' => ['spreadsheet', 'xlsx'],
            'OpenDocument spreadsheet' => ['spreadsheet', 'ods'],
            'PowerPoint binary' => ['presentation', 'ppt'],
            'PowerPoint OOXML' => ['presentation', 'pptx'],
            'OpenDocument presentation' => ['presentation', 'odp'],
        ];
    }

    #[DataProvider('officeFormats')]
    public function test_real_office_and_pdf_extraction(string $family, string $extension): void
    {
        $sourceExtension = ['text' => 'fodt', 'spreadsheet' => 'fods', 'presentation' => 'fodp'][$family];
        $source = $this->directory.'/fixture.'.$sourceExtension;
        $body = match ($family) {
            'text' => '<office:text><text:p>ARCHIVE VALIDATION DOCUMENT</text:p></office:text>',
            'spreadsheet' => '<office:spreadsheet><table:table table:name="Records"><table:table-row>'
                .'<table:table-cell office:value-type="string"><text:p>ARCHIVE VALIDATION DOCUMENT</text:p>'
                .'</table:table-cell></table:table-row></table:table></office:spreadsheet>',
            'presentation' => '<office:presentation><draw:page draw:name="Slide1" draw:master-page-name="Default">'
                .'<draw:frame svg:x="1cm" svg:y="1cm" svg:width="20cm" svg:height="5cm">'
                .'<draw:text-box><text:p>ARCHIVE VALIDATION DOCUMENT</text:p></draw:text-box>'
                .'</draw:frame></draw:page></office:presentation>',
        };
        file_put_contents($source, $this->flatDocument($family, $body));
        $path = $this->convert($source, $extension);

        $result = app(DocumentTextExtractor::class)->extract($path, $extension);

        $this->assertStringContainsString('ARCHIVE VALIDATION DOCUMENT', preg_replace('/\s+/', ' ', $result['text']));
        $this->assertGreaterThanOrEqual(1, $result['page_count']);
    }

    public static function imageFormats(): array
    {
        return array_map(fn (string $extension): array => [$extension], ['png', 'jpg', 'tiff', 'bmp', 'gif', 'webp']);
    }

    #[DataProvider('imageFormats')]
    public function test_real_image_ocr(string $extension): void
    {
        $path = $this->directory.'/image.'.$extension;
        $image = $this->textImage('ARCHIVE VALIDATION DOCUMENT');
        try {
            $image->setImageFormat($extension);
            $image->writeImage($path);
        } finally {
            $image->clear();
        }

        $result = app(DocumentTextExtractor::class)->extract($path, $extension);

        $this->assertStringContainsString('ARCHIVE VALIDATION DOCUMENT', $result['text']);
        $this->assertSame(1, $result['page_count']);
    }

    public function test_real_mixed_pdf_extracts_native_and_scanned_pages(): void
    {
        $image = $this->textImage('SCANNED SECOND PAGE');
        try {
            $image->setImageFormat('png');
            $encoded = base64_encode($image->getImageBlob());
        } finally {
            $image->clear();
        }
        $body = '<office:text><text:p>NATIVE FIRST PAGE</text:p><text:p text:style-name="NewPage">'
            .'<draw:frame text:anchor-type="as-char" svg:width="16cm" svg:height="3cm">'
            .'<draw:image><office:binary-data>'.$encoded.'</office:binary-data></draw:image>'
            .'</draw:frame></text:p></office:text>';
        $source = $this->directory.'/mixed.fodt';
        file_put_contents($source, $this->flatDocument('text', $body));
        $path = $this->convert($source, 'pdf');

        $result = app(DocumentTextExtractor::class)->extract($path, 'pdf');

        $this->assertSame(2, $result['page_count']);
        $this->assertStringContainsString('NATIVE FIRST PAGE', $result['text']);
        $this->assertStringContainsString('SCANNED SECOND PAGE', $result['text']);
    }

    private function flatDocument(string $family, string $body): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<office:document xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" '
            .'xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" '
            .'xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" '
            .'xmlns:draw="urn:oasis:names:tc:opendocument:xmlns:drawing:1.0" '
            .'xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0" '
            .'xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0" '
            .'xmlns:svg="urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0" '
            .'office:version="1.2" office:mimetype="application/vnd.oasis.opendocument.'.$family.'">'
            .'<office:automatic-styles><style:style style:name="NewPage" style:family="paragraph">'
            .'<style:paragraph-properties fo:break-before="page"/></style:style>'
            .'<style:page-layout style:name="Layout"><style:page-layout-properties fo:page-width="28cm" '
            .'fo:page-height="21cm" style:print-orientation="landscape"/></style:page-layout></office:automatic-styles>'
            .'<office:master-styles><style:master-page style:name="Default" style:page-layout-name="Layout"/></office:master-styles>'
            .'<office:body>'.$body.'</office:body></office:document>';
    }

    private function convert(string $source, string $extension): string
    {
        $profile = 'file://'.$this->directory.'/fixture-profile';
        $process = new Process(['libreoffice', '-env:UserInstallation='.$profile, '--headless', '--nologo',
            '--nodefault', '--norestore', '--convert-to', $extension, '--outdir', $this->directory, $source]);
        $process->setTimeout(120);
        $process->mustRun();
        $target = $this->directory.'/'.pathinfo($source, PATHINFO_FILENAME).'.'.$extension;
        $this->assertFileExists($target, 'Fixture conversion did not produce output: '.$process->getErrorOutput());

        return $target;
    }

    private function textImage(string $text): \Imagick
    {
        $image = new \Imagick();
        $image->newImage(1800, 300, 'white');
        $draw = new \ImagickDraw();
        $fonts = $image->queryFonts('*Sans*');
        $this->assertNotEmpty($fonts, 'Install a sans-serif font for converter fixtures.');
        $draw->setFont($fonts[0]);
        $draw->setFontSize(64);
        $draw->setFillColor('black');
        $image->annotateImage($draw, 50, 160, 0, $text);
        $draw->clear();

        return $image;
    }
}
