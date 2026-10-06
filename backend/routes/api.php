<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\ImportTemplateController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\PayrollExtraColumnController;
use App\Http\Controllers\Api\FiscalYearController;
use App\Http\Controllers\Api\ReserveFundController;
use App\Http\Controllers\Api\SalaryAdjustmentController;
use App\Http\Controllers\Api\TravelExpenseClaimController;

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

    // โปรไฟล์ของฉัน — ดู/แก้ไขข้อมูลตัวเองได้ทุก role (แก้ได้เฉพาะชื่อ/อีเมล/รหัสผ่าน)
    Route::get('/profile',          [ProfileController::class, 'show']);
    Route::put('/profile',          [ProfileController::class, 'update']);
    Route::put('/profile/password', [ProfileController::class, 'updatePassword']);

    // ตัวเลือกปีงบประมาณบน Header — state ระดับแอป ทุก role ที่ login อ่านได้
    Route::get('/fiscal-years', [FiscalYearController::class, 'index']);

    // ========================================
    // Admin Routes (role: admin)
    // ========================================
    Route::middleware('role:admin')->prefix('admin')->group(function () {
        // คอลัมน์เพิ่มเติมของไฟล์เงินเดือน — admin เท่านั้น เพราะมีผลกับไฟล์ต้นแบบของทุกคน
        Route::prefix('payroll-extra-columns')->group(function () {
            Route::get('/',    [PayrollExtraColumnController::class, 'index']);
            Route::post('/',   [PayrollExtraColumnController::class, 'store']);
            Route::put('/{column}',  [PayrollExtraColumnController::class, 'update']);
            Route::delete('/{column}', [PayrollExtraColumnController::class, 'destroy']);
        });
    });

    // ========================================
    // HR Read-only Routes (role: admin, hr, finance)
    // finance ดูหน้า Dashboard ได้ แต่แก้ข้อมูลไม่ได้
    // ========================================
    Route::middleware('role:admin,hr,finance')->prefix('hr')->group(function () {
        Route::get('/employees',         [EmployeeController::class, 'index']);
        Route::get('/employees/stats',   [EmployeeController::class, 'stats']);

        // ค้นหาแบบพิมพ์แล้วเด้ง (autocomplete) + แนะนำเลขบัตรประชาชนที่ใกล้เคียง
        Route::get('/employees/suggest', [EmployeeController::class, 'suggest']);

        // Lookups (dropdown ทั้งหมดในคำขอเดียว) — ใช้กรองข้อมูลในหน้า view
        Route::get('/lookups', [EmployeeController::class, 'lookups']);
    });

    // ========================================
    // HR Routes (role: admin, hr)
    // ========================================
    Route::middleware('role:admin,hr')->prefix('hr')->group(function () {

        // Employee Management — เขียนข้อมูลได้เฉพาะ admin, hr
        Route::prefix('employees')->group(function () {
            Route::post('/',              [EmployeeController::class, 'store']);
            Route::put('/{employee}',     [EmployeeController::class, 'update']);
        });

        // เงินสำรอง (คำนวณจาก payroll — ต้องระบุเปอร์เซ็นต์เอง)
        Route::get('/reserve-fund',          [ReserveFundController::class, 'summary']);
        Route::get('/reserve-fund/imports',  [ReserveFundController::class, 'imports']);

        // เงินสำรองรายปี — รวม payroll ทุกงวดของปีงบประมาณ (1 ปีงบ = 1 ผลลัพธ์)
        Route::get('/reserve-fund/annual',  [ReserveFundController::class, 'annualSummary']);
        Route::post('/reserve-fund/annual', [ReserveFundController::class, 'storeAnnual']);

        // บันทึก/ดูประวัติผลการคำนวณตามปีงบประมาณ (รายงวด)
        Route::get('/reserve-fund/fiscal-years',  [ReserveFundController::class, 'fiscalYears']);
        Route::get('/reserve-fund/accumulated',   [ReserveFundController::class, 'accumulatedSummary']);
        Route::get('/reserve-fund/calculations',  [ReserveFundController::class, 'calculations']);
        Route::post('/reserve-fund/calculations', [ReserveFundController::class, 'store']);

        // ยืนยัน/ยกเลิกการยืนยันงวด — ยอดสะสมนับเฉพาะงวดที่ยืนยันแล้ว
        Route::post('/reserve-fund/calculations/{calculation}/confirm',   [ReserveFundController::class, 'confirm']);
        Route::post('/reserve-fund/calculations/{calculation}/unconfirm', [ReserveFundController::class, 'unconfirm']);

        // การปรับฐานเงินเดือน (log: เงินเดือนเก่า/ใหม่/ปรับเพิ่ม)
        Route::prefix('salary-adjustments')->group(function () {
            Route::get('/',                 [SalaryAdjustmentController::class, 'index']);
            Route::get('/summary',          [SalaryAdjustmentController::class, 'summary']);
            Route::post('/',                [SalaryAdjustmentController::class, 'store']);
        });
    });

    // ========================================
    // การนำเข้าไฟล์เงินเดือน (role: admin, hr, finance)
    // ========================================
    // ไฟล์เดียวให้ทั้งทะเบียนบุคลากรและแถวเงินเดือน จึงไม่ต้องแยกตามแผนก
    Route::middleware('role:admin,hr,finance')->prefix('imports')->group(function () {
        Route::post('/preview', [ImportController::class, 'preview']);
        Route::post('/',        [ImportController::class, 'store']);
        Route::get('/',         [ImportController::class, 'index']);

        // ต้องประกาศก่อน /{import} ไม่อย่างนั้น "template" จะถูกตีความเป็น id
        Route::get('/template',         [ImportTemplateController::class, 'payroll']);
        Route::get('/template/columns', [ImportTemplateController::class, 'columns']);

        // ส่งออกข้อมูลจริงเป็นไฟล์หัวตารางเดียวกับแบบฟอร์ม
        Route::get('/export',           [ImportTemplateController::class, 'export']);
        Route::get('/periods',          [ImportTemplateController::class, 'periods']);

        Route::get('/{import}', [ImportController::class, 'show']);
    });

    // ========================================
    // Finance Routes (role: admin, finance)
    // ========================================
    Route::middleware('role:admin,finance')->prefix('finance')->group(function () {
        // ใบเบิกค่าใช้จ่ายเดินทางไปราชการ
        Route::prefix('travel-expense-claims')->group(function () {
            // route คงที่ต้องมาก่อน {claim} เพื่อไม่ให้ถูกจับเป็น parameter
            Route::get('/options',   [TravelExpenseClaimController::class, 'options']);
            Route::get('/employees', [TravelExpenseClaimController::class, 'employees']);

            Route::get('/',          [TravelExpenseClaimController::class, 'index']);
            Route::post('/',         [TravelExpenseClaimController::class, 'store']);
            Route::get('/{claim}',   [TravelExpenseClaimController::class, 'show']);
            Route::put('/{claim}',   [TravelExpenseClaimController::class, 'update']);

            Route::post('/{claim}/confirm', [TravelExpenseClaimController::class, 'confirm']);
            Route::post('/{claim}/cancel',  [TravelExpenseClaimController::class, 'cancel']);
            Route::get('/{claim}/export',   [TravelExpenseClaimController::class, 'export']);
        });
    });
});
