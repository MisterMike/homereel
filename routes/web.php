<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\UploadController;
use App\Http\Controllers\VideoController;
use App\Http\Middleware\Administrator;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:10,1');
});
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
    Route::get('/', [VideoController::class, 'index'])->name('videos.index');
    Route::get('/videos/{video}', [VideoController::class, 'show'])->name('videos.show');
    Route::get('/videos/{video}/stream', [VideoController::class, 'stream'])->name('videos.stream');
    Route::get('/videos/{video}/thumbnail', [VideoController::class, 'thumbnail'])->name('videos.thumbnail');
    Route::prefix('uploads')->middleware([Administrator::class])->group(function () {
        Route::post('/', [UploadController::class, 'create'])->middleware('throttle:upload-creation')->name('uploads.create');
        Route::get('/{upload}', [UploadController::class, 'show'])->middleware('throttle:uploads')->name('uploads.show');
        Route::patch('/{upload}', [UploadController::class, 'append'])->middleware('throttle:upload-chunks')->name('uploads.append');
        Route::post('/{upload}/finish', [UploadController::class, 'finish'])->middleware('throttle:uploads')->name('uploads.finish');
    });
});
