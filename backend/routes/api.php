<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;


//Public Routes (ไม่ต้อง Login)

Route::post('/login', [AuthController::class, 'login']);

//Protected Routes (ต้อง Login)
Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    //Admin only
    Route::middleware('role:admin')->prefix('admin')->group(function () {});

    //HR Routes (admin, hr)
    Route::middleware('role:admin,hr')->prefix('hr')->group(function () {});
    //Finance Routes (admin, finance)
    Route::middleware('role:admin,finance')->prefix('finance')->group(function () {});
});
