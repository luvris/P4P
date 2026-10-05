<?php

namespace App\Services;

use App\Models\PayrollExtraColumn;

/**
 * จัดการคอลัมน์เพิ่มเติมของไฟล์เงินเดือน
 *
 * หน้าที่ของ service นี้คือทำให้ "คอลัมน์ที่ประกาศไว้" กับ "ค่าที่อ่านได้จากไฟล์"
 * อยู่ในรูปเดียวกันเสมอ — ทั้งตอนสร้างไฟล์ต้นแบบ ตอนอัปโหลด และตอนแสดงผล
 */
class PayrollExtraColumnService
{
    /** คอลัมน์ที่ประกาศไว้และใช้งานอยู่ (เรียกครั้งเดียวต่อ request) */
    protected ?array $cache = null;

    /**
     * รายการคอลัมน์ที่ใช้งานอยู่ เรียงตามลำดับแสดงผล
     *
     * @return array<int, PayrollExtraColumn>
     */
    public function activeColumns(): array
    {
        if ($this->cache === null) {
            $this->cache = PayrollExtraColumn::active()->all();
        }

        return $this->cache;
    }

    public function flushCache(): void
    {
        $this->cache = null;
    }

    /**
     * ชื่อคอลัมน์ทั้งหมด (39 คอลัมน์เดิม + คอลัมน์ที่เพิ่ม) สำหรับไฟล์ต้นแบบ
     *
     * @param  array<int, string>  $baseColumns
     * @return array<int, string>
     */
    public function columnsWithExtras(array $baseColumns): array
    {
        foreach ($this->activeColumns() as $column) {
            $baseColumns[] = $column->name;
        }

        return $baseColumns;
    }

    /**
     * แปลงค่าตามชนิดที่ประกาศไว้ และตัดค่าที่ใช้ไม่ได้ทิ้ง
     *
     * @param  array<string, mixed>|string|null  $values  ค่าดิบจากไฟล์ (key คือ slug ของคอลัมน์)
     * @return array<string, string|null>
     */
    public function normalizeValues(mixed $values): array
    {
        // ค่าควรเป็น array แต่ถ้ามาจาก JSON string (ข้อมูลเก่าในฐาน) ต้องยังไม่ทำให้ทั้ง import ล้ม
        if (is_string($values)) {
            $decoded = json_decode($values, true);
            $values = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($values)) {
            return [];
        }

        $out = [];

        foreach ($this->activeColumns() as $column) {
            $raw = $values[$column->key] ?? null;

            $out[$column->key] = $this->cast($raw, $column->data_type);
        }

        return $out;
    }

    /**
     * แปลงค่าตามชนิด — คืน null เมื่อค่าว่างหรือใช้ไม่ได้
     */
    protected function cast(mixed $raw, string $type): ?string
    {
        if ($raw === null || is_array($raw)) {
            return null;
        }

        $value = trim((string) $raw);

        if ($value === '') {
            return null;
        }

        if ($type === 'number') {
            // ยอดเงินในไฟล์มักมีลูกค้าจุดพัน — ตัดออกก่อนตรวจว่าเป็นตัวเลขไหม
            $normalized = str_replace([',', ' '], '', $value);

            return is_numeric($normalized) ? (string) (0 + $normalized) : null;
        }

        if ($type === 'date') {
            // ยอมรับเฉพาะรูปแบบที่อ่านได้จริง ปี ค.ศ. 4 หลัก
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
        }

        return $value;
    }

    /**
     * คำอธิบันและชนิดข้อมูลของคอลัมน์เพิ่มเติม สำหรับส่งให้หน้าเว็บแสดงผล
     *
     * @return array<int, array<string, mixed>>
     */
    public function metadata(): array
    {
        return array_map(fn (PayrollExtraColumn $c) => [
            'id'          => $c->id,
            'name'        => $c->name,
            'key'         => $c->key,
            'description' => $c->description,
            'data_type'   => $c->data_type,
            'is_active'   => $c->is_active,
            'sort_order'  => $c->sort_order,
        ], $this->activeColumns());
    }
}