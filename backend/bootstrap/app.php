<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role' => \App\Http\Middleware\CheckRole::class,
        ]);

        // เปิดโหมด SPA ของ Sanctum — คำขอจากโดเมนใน config('sanctum.stateful')
        // จะยืนยันตัวตนด้วย session cookie (HttpOnly) + CSRF แทน bearer token
        // ที่ต้องเก็บฝั่ง JavaScript ซึ่งอ่านได้หากเกิด XSS
        $middleware->statefulApi();

        // แอปนี้เป็น API ล้วน ไม่มีหน้าเว็บ login ให้ redirect
        // ค่า default ของ Laravel คือ fn () => route('login') ซึ่งจะระเบิดเป็น
        // RouteNotFoundException (500) เมื่อ client ไม่ได้ส่ง Accept: application/json
        // (เพราะ Authenticate จะเรียก redirectTo() แทนที่จะตอบ 401)
        // การตั้ง null จะทำให้ตอบ 401 JSON ตรง ๆ เหมือนกันทุกกรณี
        $middleware->redirectGuestsTo(null);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // คำขอใต้ /api/* ต้องตอบเป็น JSON เสมอ แม้ client จะไม่ได้ส่ง
        // Accept: application/json — ไม่งั้น Laravel จะมองเป็นคำขอเว็บ แล้วพยายาม
        // redirect ไป route ชื่อ "login" ซึ่งไม่มีในระบบนี้ ทำให้ได้ 500 ทั้งที่ควรเป็น 401
        $exceptions->shouldRenderJsonWhen(
            fn ($request) => $request->is('api/*') || $request->expectsJson()
        );
    })->create();
