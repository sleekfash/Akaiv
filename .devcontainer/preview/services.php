<?php

require __DIR__.'/guard.php';

use App\Models\CaseFile;
use App\Models\Document;
use App\Models\User;
use App\Services\FileIngestion;
use Illuminate\Support\Facades\Storage;

$user = User::where('email', 'clerk@example.test')->firstOrFail();
auth()->login($user);
session(['active_organization_id' => $user->organizations()->firstOrFail()->id]);
$case = CaseFile::firstOrCreate(['title' => 'Preview Sample Case'], [
    'suit_number' => 'PREVIEW/1/2026', 'subject_matter' => 'Synthetic browser review',
]);
$bytes = 'Synthetic preview judgment for testing only.';
$document = Document::where('friendly_name', 'Preview Sample Judgment')->first();
if (! $document) {
    $source = 'org_'.$case->organization_id.'/incoming/user_'.$user->id.'/preview.txt';
    if (! Storage::disk('s3')->put($source, $bytes, ['visibility' => 'private'])) {
        throw new RuntimeException('Preview object upload failed.');
    }
    $document = app(FileIngestion::class)->save([
        'storage_path' => $source, 'case_id' => $case->id,
        'friendly_name' => 'Preview Sample Judgment', 'judicial_document_type' => 'judgment',
    ], $user);
}
for ($attempt = 0; $attempt < 60 && $document->fresh()->scan_state === 'pending'; $attempt++) {
    sleep(2);
}
$document->refresh();
if ($document->scan_state !== 'clean' || $document->sha256_checksum !== hash('sha256', $bytes)
    || Storage::disk('s3')->get($document->storage_path) !== $bytes || $document->status !== 'draft') {
    throw new RuntimeException('Preview queue, antivirus or stored-record check failed.');
}
echo "Real Redis queue, ClamAV scan, private storage and retained draft verified.\n";
