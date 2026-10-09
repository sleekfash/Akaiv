<?php

use App\Http\Controllers\DownloadDocumentController;
use App\Http\Controllers\PreviewDocumentController;
use App\Http\Controllers\ShareController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/admin');
});

Route::middleware('auth')->get('/dev/tokens', function () {
    abort_unless(app()->environment(['local', 'testing']), 404);

    abort_unless(auth()->user()?->hasRole('Platform SuperAdmin'), 403);

    return view('dev.tokens');
})->name('dev.tokens');

Route::middleware(['auth', 'throttle:30,1'])->post(
    '/documents/{document}/analyze',
    function () {
        abort(404);
    },
)->name('documents.analyze');

Route::middleware(['signed', 'auth', 'throttle:60,1'])->get(
    '/documents/{document}/preview',
    PreviewDocumentController::class,
)->name('documents.preview');

Route::middleware(['signed', 'auth', 'throttle:60,1'])->get(
    '/documents/{document}/download',
    DownloadDocumentController::class,
)->name('documents.download');

Route::middleware('throttle:60,1')->group(function () {
    Route::get('/share/{share}', [ShareController::class, 'show'])->name('shares.show');
    Route::post('/share/{share}', [ShareController::class, 'unlock'])->name('shares.unlock');
});
