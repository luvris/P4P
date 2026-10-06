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
     * คอลัมน์ที่ระบุ before_column จะถูกแทรกก่อนคอลัมน์นั้นทันที
     * คอลัมน์ที่ระบุ after_column จะถูกแทรกต่อจากคอลัมน์นั้น
     * ที่เหลือต่อท้ายไฟล์ตามลำดับเดิม
     *
     * @param  array<int, string>  $baseColumns
     * @return array<int, string>
     */
    public function columnsWithExtras(array $baseColumns): array
    {
        // แยกเป็นสามกอง: ยัดก่อน / ยัดหลัง / ต่อท้ายไฟล์
        $before = [];
        $after = [];
        $appended = [];

        foreach ($this->activeColumns() as $column) {
            $beforeAnchor = $this->anchor($column->before_column);
            $afterAnchor = $this->anchor($column->after_column);

            if ($beforeAnchor !== null) {
                $before[$beforeAnchor][] = $column->name;
            } elseif ($afterAnchor !== null) {
                $after[$afterAnchor][] = $column->name;
            } else {
                $appended[] = $column->name;
            }
        }

        $ordered = [];

        foreach ($baseColumns as $name) {
            // ชื่อคอลัมน์ในไฟล์อาจมีช่องว่างรอบ ๆ จึงเทียบด้วยชื่อที่ trim แล้ว
            $key = trim((string) $name);

            // ก่อนคอลัมน์นี้ → ตัวคอลัมน์นี้ → หลังคอลัมน์นี้
            foreach ($before[$key] ?? [] as $extra) {
                $ordered[] = $extra;
            }

            $ordered[] = $name;

            foreach ($after[$key] ?? [] as $extra) {
                $ordered[] = $extra;
            }
        }

        $placed = array_flip($ordered);

        // คอลัมน์ที่อ้างชื่อคอลัมน์ซึ่งไม่มีอยู่จริง ต้องไม่หายไปจากไฟล์
        // (ถ้าหายไป ค่าที่ผู้ใช้กรอกในคอลัมน์นั้นจะถูกทิ้งทั้งแถวเงินเดือน)
        foreach ([$before, $after] as $groups) {
            foreach ($groups as $names) {
                foreach ($names as $name) {
                    if (! isset($placed[$name])) {
                        $ordered[] = $name;
                        $placed[$name] = true;
                    }
                }
            }
        }

        return array_merge($ordered, $appended);
    }

    /**
     * ชื่อคอลัมน์ที่ใช้ยึดตำแหน่ง — คืน null เมื่อไม่ได้ระบุ
     */
    protected function anchor(?string $column): ?string
    {
        $column = trim((string) $column);

        return $column === '' ? null : $column;
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
            'id'            => $c->id,
            'name'          => $c->name,
            'key'           => $c->key,
            'description'   => $c->description,
            'data_type'     => $c->data_type,
            'is_active'     => $c->is_active,
            'sort_order'    => $c->sort_order,
            'before_column' => $c->before_column,
            'after_column'  => $c->after_column,
        ], $this->activeColumns());
    }
}