<?php

use App\Http\Controllers\StaffAppController;
use App\Http\Middleware\TelegramWebApp;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

/*
 * The district staff Mini App, under /tg. Loaded WITHOUT the "web" group (bootstrap/app.php): no session, no cookie,
 * so no CSRF token either. Every API call is authenticated by Telegram's signed initData instead.
 */

Route::get('/app', fn () => response()
    ->view('tg.app', ['version' => config('telegram.asset_version')])
    ->header('Cache-Control', 'no-store'))->name('tg.app');

// street concept PDFs: the browser cannot send the initData header, so the API hands out signed links
// (relative signature: the link stays valid behind the proxy whatever host/scheme Laravel sees)
Route::get('/file/{file}', [StaffAppController::class, 'file'])->middleware(['signed:relative', SubstituteBindings::class])->name('tg.file');

Route::prefix('api')->middleware([TelegramWebApp::class, 'throttle:tg', SubstituteBindings::class])->group(function () {
    Route::get('/me', [StaffAppController::class, 'me']);
    Route::post('/nearby', [StaffAppController::class, 'nearby']);
    Route::get('/streets/{street}/objects', [StaffAppController::class, 'objects']);
    Route::post('/streets/{street}/objects', [StaffAppController::class, 'create']);
    Route::get('/objects/{object}', [StaffAppController::class, 'show']);
    Route::post('/objects/{object}', [StaffAppController::class, 'update']);
    Route::post('/objects/{object}/inspections', [StaffAppController::class, 'start']);
    Route::post('/inspections/{inspection}/location', [StaffAppController::class, 'location']);
    Route::post('/inspections/{inspection}/point', [StaffAppController::class, 'point']);
    Route::post('/inspections/{inspection}/photo', [StaffAppController::class, 'photo']);
    Route::post('/inspections/{inspection}/video', [StaffAppController::class, 'video']);
    Route::post('/inspections/{inspection}/submit', [StaffAppController::class, 'submit']);
    Route::post('/inspections/{inspection}/discard', [StaffAppController::class, 'discard']);
});
