<?php

namespace App\Http\Controllers;

use App\Models\Share;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ShareController extends Controller
{
    public function show(Request $request, Share $share): View|StreamedResponse|RedirectResponse
    {
        $this->assertAccessible($request, $share);

        if ($share->hasPassword() && ! $request->session()->get('share_unlocked_'.$share->token)) {
            return view('share.password', ['share' => $share]);
        }

        return $this->serve($request, $share);
    }

    public function unlock(Request $request, Share $share): RedirectResponse
    {
        $this->assertAccessible($request, $share);

        $request->validate(['password' => ['required', 'string']]);

        if (! $share->checkPassword($request->string('password')->toString())) {
            return back()->withErrors(['password' => 'The password is incorrect.']);
        }

        $request->session()->put('share_unlocked_'.$share->token, true);

        return redirect()->route('shares.show', $share);
    }

    private function assertAccessible(Request $request, Share $share): void
    {
        abort_unless(config('archive.public_sharing_enabled'), 404);
        abort_if($share->isExpired(), 410, 'This share link has expired.');
        abort_if($share->hasAccessLimitReached(), 410, 'This share link has reached its access limit.');
        abort_if(! $share->isIpAllowed((string) $request->ip()), 403, 'Access from this address is not permitted.');
    }

    private function serve(Request $request, Share $share): StreamedResponse
    {
        $document = $share->document;
        abort_if($document === null, 404);

        $disk = Storage::disk($document->storage_disk);
        abort_unless($disk->exists($document->storage_path), 404);

        $share->recordAccess();

        $stream = $disk->readStream($document->storage_path);
        abort_unless(is_resource($stream), 404);

        $disposition = $share->can_download && $request->boolean('download') ? 'attachment' : 'inline';

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $document->mime_type ?: 'application/octet-stream',
            'Content-Disposition' => $disposition.'; filename="'.addcslashes($document->original_filename, '"\\').'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
