<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | เมื่อก่อนโปรเจกต์นี้ไม่มีไฟล์นี้ ทำให้ใช้ค่า default ของ framework ซึ่งเปิด
    | allowed_origins = ['*'] ไฟล์นี้จำกัด origin ให้เหลือเฉพาะ frontend ที่ระบุ
    | ใน CORS_ALLOWED_ORIGINS เท่านั้น
    |
    | จำเป็นต้องระบุ origin ตรง ๆ (ใช้ * ไม่ได้) เพราะระบบยืนยันตัวตนด้วย
    | session cookie ของ Sanctum จึงต้องเปิด supports_credentials = true
    | และเบราว์เซอร์ไม่อนุญาตให้ใช้ * ร่วมกับ credentials
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'CORS_ALLOWED_ORIGINS',
            'http://localhost:5173,http://127.0.0.1:5173'
        ))
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    // เปิดให้ JavaScript อ่านส่วนหัวเหล่านี้ได้ (ไม่ใช่ค่า default ที่เบราว์เซอร์เปิดให้)
    // Retry-After ใช้บอกฝั่ง frontend ว่าต้องรออีกกี่วินาทีเมื่อถูกจำกัดจำนวนครั้ง
    'exposed_headers' => ['Retry-After'],

    'max_age' => 0,

    'supports_credentials' => true,

];
