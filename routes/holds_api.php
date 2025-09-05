<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Holds\Api\V1\HoldsApiController;

Route::prefix('api/v1')->middleware('auth.apikey')->group(function () {
    Route::post('/holds',                 [HoldsApiController::class, 'store']);
    Route::get('/holds/{id}',             [HoldsApiController::class, 'show']);
    Route::patch('/holds/{id}/capture',   [HoldsApiController::class, 'capture']);
    Route::patch('/holds/{id}/release',   [HoldsApiController::class, 'release']);
});
