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

// object page pictures: an <img> cannot send the initData header, so the API hands out signed links
// relative signature: the link stays valid behind the proxy whatever host/scheme Laravel sees
Route::get('/img/{object}', [StaffAppController::class, 'image'])->middleware(['signed:relative', SubstituteBindings::class])->name('tg.image');

Route::prefix('api')->middleware([TelegramWebApp::class, 'throttle:tg', SubstituteBindings::class])->group(function () {
    Route::get('/me', [StaffAppController::class, 'me']);
    Route::get('/streets/{street}/objects', [StaffAppController::class, 'objects']);
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
