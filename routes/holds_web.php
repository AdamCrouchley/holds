<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function () {
    // Reserved for Filament pages or a simple settings screen later.
});

Route::get('/_holds-ping', fn () => 'holds-ok')->name('holds.ping');
