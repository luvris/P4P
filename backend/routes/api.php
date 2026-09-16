<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\ReserveFundController;
use App\Http\Controllers\Api\SalaryAdjustmentController;

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
            Route::get('/',               [EmployeeController::class, 'index']);
            Route::post('/',              [EmployeeController::class, 'store']);
            Route::get('/stats',          [EmployeeController::class, 'stats']);
            Route::put('/{employee}',     [EmployeeController::class, 'update']);
        });

        // Lookups (dropdown ทั้งหมดในคำขอเดียว)
        Route::get('/lookups', [EmployeeController::class, 'lookups']);

        // เงินสำรอง 3% (คำนวณจาก payroll.net_income)
        Route::get('/reserve-fund',          [ReserveFundController::class, 'summary']);
        Route::get('/reserve-fund/imports',  [ReserveFundController::class, 'imports']);

        // การปรับฐานเงินเดือน (log: เงินเดือนเก่า/ใหม่/ปรับเพิ่ม)
        Route::prefix('salary-adjustments')->group(function () {
            Route::get('/',                 [SalaryAdjustmentController::class, 'index']);
            Route::get('/summary',          [SalaryAdjustmentController::class, 'summary']);
            Route::post('/',                [SalaryAdjustmentController::class, 'store']);
        });
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
