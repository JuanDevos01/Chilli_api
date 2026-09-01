<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\CoupleController;
use App\Http\Controllers\QuestionnaireController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });
});

Route::middleware('auth:sanctum')->prefix('couples')->group(function () {
    Route::post('/invitations', [CoupleController::class, 'createInvitation']);
    Route::post('/invitations/{code}/accept', [CoupleController::class, 'acceptInvitation']);
});

Route::middleware('auth:sanctum')->prefix('questionnaire')->group(function () {
    Route::get('/', [QuestionnaireController::class, 'index']);
    Route::post('/answers', [QuestionnaireController::class, 'answer']);
});
