<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\EmployeeController;

/*
|--------------------------------------------------------------------------
| Public Routes (ไม่ต้อง Login)
|--------------------------------------------------------------------------
*/

Route::post('/login', [AuthController::class, 'login']);

/*
|--------------------------------------------------------------------------
| Protected Routes (ต้อง Login)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me',      [AuthController::class, 'me']);

    // ========================================
    // Admin Routes (role: admin)
    // ========================================
    Route::middleware('role:admin')->prefix('admin')->group(function () {
        // เพิ่ม admin endpoints ที่นี่
    });

    // ========================================
    // HR Routes (role: admin, hr)
    // ========================================
    Route::middleware('role:admin,hr')->prefix('hr')->group(function () {

        // Employee Management
        Route::prefix('employees')->group(function () {
            Route::get('/',      [EmployeeController::class, 'index']);
            Route::post('/',     [EmployeeController::class, 'store']);
            Route::get('/stats', [EmployeeController::class, 'stats']);
        });

        // Lookups (dropdown ทั้งหมดในคำขอเดียว)
        Route::get('/lookups', [EmployeeController::class, 'lookups']);
    });

    // ========================================
    // Finance Routes (role: admin, finance)
    // ========================================
    Route::middleware('role:admin,finance')->prefix('finance')->group(function () {
        Route::post('/imports',           [ImportController::class, 'store']);
        Route::get('/imports',            [ImportController::class, 'index']);
        Route::get('/imports/{import}',   [ImportController::class, 'show']);
    });
});
