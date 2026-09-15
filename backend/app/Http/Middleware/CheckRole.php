<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (!$request->user()) {
            return response()->json([
                'message' => 'กรุณาเข้าสู่ระบบก่อน',
            ], 401);
        }

        if (!in_array($request->user()->role, $roles, true)) {
            return response()->json([
                'message' => 'คุณไม่มีสิทธิ์เข้าถึงส่วนนี้',
            ], 403);
        }

        return $next($request);
    }
}
