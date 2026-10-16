<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('rejects control-character email header injection', function (string $email) {
    expect(Validator::make(['email' => $email], ['email' => 'required|email'])->fails())->toBeTrue();
})->with([
    'CRLF' => ["synthetic@example.test\r\nBcc: victim@example.test"],
    'LF' => ["synthetic@example.test\nBcc: victim@example.test"],
    'CR' => ["synthetic@example.test\rBcc: victim@example.test"],
]);

it('keeps ordinary email validation compatible', function () {
    expect(Validator::make(['email' => 'synthetic@example.test'], ['email' => 'required|email'])->passes())->toBeTrue();
});

it('rejects an expired signed archive URL without starting a file access audit', function () {
    [$org, $actor, $case] = archiveContext($this);
    Storage::fake('private');
    $document = archiveDocument($org, $actor, $case, ['status' => 'published', 'scan_state' => 'clean', 'virus_scanned' => true, 'sha256_checksum' => hash('sha256', 'synthetic')]);
    Storage::disk('private')->put($document->storage_path, 'synthetic');
    $url = URL::temporarySignedRoute('documents.preview', now()->addMinute(), ['document' => $document->uuid]);
    $this->travel(2)->minutes();
    $this->get($url)->assertForbidden();
    expect($document->fresh()->view_count)->toBe(0);
});

it('rejects a signed URL reused for a different archive document', function () {
    [$org, $actor, $case] = archiveContext($this);
    $first = archiveDocument($org, $actor, $case);
    $other = archiveDocument($org, $actor, $case);
    $url = URL::temporarySignedRoute('documents.preview', now()->addMinute(), ['document' => $first->uuid]);
    $this->get(str_replace($first->uuid, $other->uuid, $url))->assertForbidden();
});
