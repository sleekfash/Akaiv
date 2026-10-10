<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class DocumentStoredFile
{
    public function inspect(string $path, string $diskName): array
    {
        $temporary = tempnam(sys_get_temp_dir(), 'akaiv-validate-');
        if ($temporary === false) {
            throw ValidationException::withMessages(['data.storage_path' => 'Unable to validate this file. Please retry.']);
        }
        try {
            $source = Storage::disk($diskName)->readStream($path);
            if (! is_resource($source)) {
                throw new RuntimeException('Stored upload is unavailable.');
            }
            try {
                $destination = fopen($temporary, 'wb');
                if (! is_resource($destination)) {
                    throw new RuntimeException('Unable to allocate file validation input.');
                }
                try {
                    if (stream_copy_to_stream($source, $destination) === false) {
                        throw new RuntimeException('Unable to read upload.');
                    }
                } finally {
                    fclose($destination);
                }
            } finally {
                fclose($source);
            }
            if (filesize($temporary) === 0) {
                throw new RuntimeException('Empty upload.');
            }
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

            return [
                'storage_disk' => $diskName,
                'file_extension' => $extension,
                'mime_type' => DocumentFormats::validate($temporary, $extension),
                'size_bytes' => filesize($temporary),
                'sha256_checksum' => hash_file('sha256', $temporary),
                'status' => 'uploading',
                'virus_scanned' => false,
                'virus_found' => false,
                'virus_scanned_at' => null,
                'ocr_required' => true,
                'ocr_completed' => false,
                'extracted_text' => null,
                'page_count' => null,
            ];
        } catch (Throwable) {
            throw ValidationException::withMessages(['data.storage_path' => 'The file is empty, unreadable, or does not match a supported document format.']);
        } finally {
            @unlink($temporary);
        }
    }
}
