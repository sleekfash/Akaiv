<?php

namespace App\Services;

use InvalidArgumentException;
use ZipArchive;

class DocumentFormats
{
    public const TEXT = ['txt', 'csv', 'md'];

    public const OFFICE = ['doc', 'docx', 'dot', 'dotx', 'xls', 'xlsx', 'xlt', 'xltx', 'ppt', 'pptx', 'pps', 'ppsx', 'pot', 'potx', 'odt', 'ods', 'odp', 'rtf'];

    public const IMAGES = ['jpg', 'jpeg', 'png', 'tif', 'tiff', 'bmp', 'gif', 'webp'];

    public static function extensions(): array
    {
        return array_merge(self::TEXT, self::OFFICE, self::IMAGES, ['pdf']);
    }

    public static function acceptedMimeTypes(): array
    {
        $types = [];
        foreach (self::extensions() as $extension) {
            $types = array_merge($types, self::mimeTypes($extension));
        }

        // ZIP/OLE are detection fallbacks, not upload picker categories.
        return array_values(array_diff(array_unique($types), ['application/zip', 'application/x-ole-storage', 'application/CDFV2']));
    }

    public static function validate(string $path, string $extension): string
    {
        $extension = strtolower($extension);
        $mime = mime_content_type($path);
        if (! in_array($mime, self::mimeTypes($extension), true)) {
            throw new InvalidArgumentException('Unsupported extension or detected content type.');
        }
        $packageEntry = match ($extension) {
            'docx', 'dotx' => 'word/document.xml',
            'xlsx', 'xltx' => 'xl/workbook.xml',
            'pptx', 'ppsx', 'potx' => 'ppt/presentation.xml',
            'odt', 'ods', 'odp' => 'content.xml',
            default => null,
        };
        if ($packageEntry !== null) {
            $zip = new ZipArchive();
            if ($zip->open($path) !== true) {
                throw new InvalidArgumentException('Invalid or encrypted Office package.');
            }
            try {
                if ($zip->locateName($packageEntry) === false) {
                    throw new InvalidArgumentException('Office package does not match the file extension.');
                }
                if (in_array($extension, ['odt', 'ods', 'odp'], true)
                    && $zip->getFromName('mimetype') !== self::mimeTypes($extension)[0]) {
                    throw new InvalidArgumentException('OpenDocument package does not match the file extension.');
                }
            } finally {
                $zip->close();
            }
        }

        return $mime;
    }

    private static function mimeTypes(string $extension): array
    {
        $ole = ['application/x-ole-storage', 'application/CDFV2'];

        return match ($extension) {
            'txt', 'csv', 'md' => ['text/plain', 'text/csv', 'text/markdown', 'application/csv'],
            'pdf' => ['application/pdf'],
            'jpg', 'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'tif', 'tiff' => ['image/tiff'],
            'bmp' => ['image/bmp', 'image/x-ms-bmp'],
            'gif' => ['image/gif'],
            'webp' => ['image/webp'],
            'doc', 'dot' => array_merge(['application/msword'], $ole),
            'xls', 'xlt' => array_merge(['application/vnd.ms-excel'], $ole),
            'ppt', 'pps', 'pot' => array_merge(['application/vnd.ms-powerpoint'], $ole),
            'rtf' => ['application/rtf', 'text/rtf'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
            'dotx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.template', 'application/zip'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
            'xltx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.template', 'application/zip'],
            'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
            'ppsx' => ['application/vnd.openxmlformats-officedocument.presentationml.slideshow', 'application/zip'],
            'potx' => ['application/vnd.openxmlformats-officedocument.presentationml.template', 'application/zip'],
            'odt' => ['application/vnd.oasis.opendocument.text', 'application/zip'],
            'ods' => ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip'],
            'odp' => ['application/vnd.oasis.opendocument.presentation', 'application/zip'],
            default => [],
        };
    }
}
