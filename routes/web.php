<?php

use Illuminate\Support\Facades\Route;

// This Laravel app is an API only. The website itself is the React app.
Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'api' => url('/api/v1'),
]));
