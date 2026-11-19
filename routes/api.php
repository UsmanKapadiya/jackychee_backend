<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Admin\PageController as AdminPageController;
use App\Http\Controllers\Admin\PortfolioController as AdminPortfolioController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Api\PageController;
use App\Http\Controllers\Api\PortfolioController;
use App\Http\Controllers\Api\SiteController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    /* ---------------- Public (read-only) ---------------- */
    Route::get('site', [SiteController::class, 'show']);
    Route::get('portfolio', [PortfolioController::class, 'index']);
    Route::get('portfolio/{slug}', [PortfolioController::class, 'show'])->where('slug', '[A-Za-z0-9\-_]+');
    Route::get('pages/{slug}', [PageController::class, 'show'])->where('slug', '[A-Za-z0-9\-_]+');

    /* ---------------- Admin ---------------- */
    Route::prefix('admin')->group(function () {
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');

        Route::middleware('auth:sanctum')->group(function () {
            Route::get('me', [AuthController::class, 'me']);
            Route::post('logout', [AuthController::class, 'logout']);
            Route::put('password', [AuthController::class, 'updatePassword']);

            Route::post('portfolio/reorder', [AdminPortfolioController::class, 'reorder']);
            Route::apiResource('portfolio', AdminPortfolioController::class)->parameters(['portfolio' => 'item']);
            Route::apiResource('categories', AdminCategoryController::class)->except('show');
            Route::apiResource('pages', AdminPageController::class);

            Route::get('settings', [SettingController::class, 'show']);
            Route::put('settings', [SettingController::class, 'update']);
            Route::post('settings/logo', [SettingController::class, 'uploadLogo']);
        });
    });
});
