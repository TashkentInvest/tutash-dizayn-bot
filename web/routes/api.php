<?php

use App\Http\Controllers\StreetLinesApiController;
use Illuminate\Support\Facades\Route;

// For other systems (stateless, a bearer token from INTEGRATION_TOKEN): see StreetLinesApiController
Route::middleware('throttle:60,1')->get('/v1/streets', StreetLinesApiController::class)->name('api.streets');
