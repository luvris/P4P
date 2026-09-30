<?php

namespace App\Http\Requests;

/**
 * แก้ไขใบเบิกค่าใช้จ่าย — กฎเดียวกับตอนสร้าง
 * (การตรวจว่าเอกสารแก้ไขได้หรือไม่ อยู่ใน controller เพราะต้องอ่าน status จาก DB)
 */
class UpdateTravelExpenseClaimRequest extends StoreTravelExpenseClaimRequest
{
}
