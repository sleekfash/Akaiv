<?php

namespace App\Services;

use App\Models\User;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class DocumentUploads
{
    public function organization(User $user): int
    {
        $id = (int) session('active_organization_id');
        if (! $id || ! $user->organizations()->where('organizations.id', $id)->exists()) {
            throw ValidationException::withMessages(['data.storage_path' => 'Select an organization you belong to before uploading.']);
        }

        return $id;
    }

    public function store(UploadedFile $file, User $user): string
    {
        $organization = $this->organization($user);
        if ($file->getSize() > 268435456 || $file->getSize() === 0) {
            throw ValidationException::withMessages(['data.storage_path' => 'Choose a nonempty file no larger than 256 MB.']);
        }
        $path = 'documents/'.$organization.'/'.$user->id.'/'.Str::uuid().'.'.strtolower($file->getClientOriginalExtension());
        $stream = fopen($file->getRealPath(), 'rb');
        try {
            if (! is_resource($stream) || ! Storage::disk('s3')->put($path, $stream, ['visibility' => 'private'])) {
                throw ValidationException::withMessages(['data.storage_path' => 'Unable to store upload.']);
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        try {
            $attributes = app(DocumentStoredFile::class)->inspect($path, 's3');
            DB::table('document_uploads')->insert([
                'organization_id' => $organization, 'user_id' => $user->id, 'path' => $path, 'disk' => 's3',
                'checksum' => $attributes['sha256_checksum'], 'original_filename' => $file->getClientOriginalName(),
                'expires_at' => now()->addDay(),
            ]);
        } catch (Throwable $exception) {
            Storage::disk('s3')->delete($path);
            throw $exception;
        }

        return $path;
    }

    public function consume(string $path, User $user, Closure $save): mixed
    {
        $organization = $this->organization($user);

        return DB::transaction(function () use ($path, $user, $organization, $save) {
            $upload = DB::table('document_uploads')->where('path', $path)->where('disk', 's3')
                ->where('organization_id', $organization)->where('user_id', $user->id)
                ->whereNull('consumed_at')->where('expires_at', '>', now())->lockForUpdate()->first();
            if (! $upload) {
                throw ValidationException::withMessages(['data.storage_path' => 'Upload this file using your own account and active organization.']);
            }
            $attributes = app(DocumentStoredFile::class)->inspect($path, 's3');
            if (! hash_equals($upload->checksum, $attributes['sha256_checksum'])) {
                throw ValidationException::withMessages(['data.storage_path' => 'This upload has changed. Please upload it again.']);
            }
            DB::table('document_uploads')->where('id', $upload->id)->update(['consumed_at' => now()]);

            return $save(array_merge($attributes, ['organization_id' => $organization,
                'original_filename' => $upload->original_filename, 'storage_path' => $upload->path,
                'uploaded_by' => $user->id]));
        });
    }
}
