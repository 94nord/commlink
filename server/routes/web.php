<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['status' => 'ok']));

if (app()->environment('testing')) {
    require __DIR__.'/testing.php';
}

if (app()->environment('local')) {
    require __DIR__.'/dev.php';
}
