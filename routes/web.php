<?php

use Illuminate\Support\Facades\Route;

Route::get('/', [\App\Http\Controllers\DefaultController::class, 'healthz']);
Route::options('/{path}', [\App\Http\Controllers\DefaultController::class, 'cors'])->where('path', '.*');

Route::middleware(['cors'])->group(function () {
    Route::middleware(['auth:repo'])->group(function () {
        Route::get('/{module}/@v/list', [\App\Http\Controllers\GoController::class, 'listVersions'])->where('module', '.+');
        Route::get('/{module}/@v/{version}.info', [\App\Http\Controllers\GoController::class, 'versionInfo'])->where(['module' => '.+', 'version' => 'v[0-9].+']);
        Route::get('/{module}/@v/{version}.mod', [\App\Http\Controllers\GoController::class, 'goMod'])->where(['module' => '.+', 'version' => 'v[0-9].+']);
        Route::get('/{module}/@v/{version}.zip', [\App\Http\Controllers\GoController::class, 'moduleZip'])->where(['module' => '.+', 'version' => 'v[0-9].+']);
        Route::get('/{module}/@latest', [\App\Http\Controllers\GoController::class, 'latest'])->where('module', '.+');
    });

    Route::middleware(['auth:token'])->group(function () {
        Route::post('/upload', [\App\Http\Controllers\GoController::class, 'upload']);
    });
});
