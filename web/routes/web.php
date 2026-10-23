<?php

use App\Models\StreetFile;
use App\Models\UserPhoto;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', fn () => redirect('/admin'));

Route::get('/login', fn () => redirect('/admin/login'))->name('login');

// Both routes below are for allow-listed admins only (Gate `access-admin`, see AppServiceProvider).

// Registration photos: stored by the bot on disk. Only images are returned, never as a page.
Route::middleware(['auth', 'can:access-admin'])->get('/bot-photo/{photo}', function (UserPhoto $photo) {
    $root = realpath(config('bot.uploads_path'));
    $file = $root ? realpath($root.DIRECTORY_SEPARATOR.$photo->path) : false;
    // the trailing separator stops a sibling folder such as "uploads_old" from matching the prefix
    abort_unless($file && str_starts_with($file, $root.DIRECTORY_SEPARATOR) && is_file($file), 404);

    return response()->file($file, [
        'Content-Type' => 'image/jpeg',
        'X-Content-Type-Options' => 'nosniff',
        'Content-Security-Policy' => 'sandbox',
    ]);
})->name('bot-photo');

// A street file from the bot's data/ folder. PDFs open in the browser, anything else is only downloaded, and the
// response can never run script on the admin origin (nosniff + sandbox).
Route::middleware(['auth', 'can:access-admin'])->get('/bot-file/{file}', function (StreetFile $file) {
    $disk = Storage::disk('bot_data');
    abort_unless($disk->exists($file->path), 404);

    $isPdf = strtolower(pathinfo($file->file_name, PATHINFO_EXTENSION)) === 'pdf';
    $disposition = ($isPdf ? 'inline' : 'attachment').'; filename*=UTF-8\'\''.rawurlencode($file->file_name);

    return response()->file($disk->path($file->path), [
        'Content-Type' => $isPdf ? 'application/pdf' : 'application/octet-stream',
        'Content-Disposition' => $disposition,
        'X-Content-Type-Options' => 'nosniff',
        'Content-Security-Policy' => 'sandbox',
    ]);
})->name('bot-file');
