<?php

namespace App\Support;

/**
 * กลุ่มวิชาชีพ 9 กลุ่ม ที่ใช้ในเอกสาร "กรอบวงเงิน P4P"
 *
 * แต่ละกลุ่มมี "สัดส่วน" (weight) เริ่มต้นตามข้อตกลงในเอกสารต้นแบบ
 * ผู้ใช้ปรับได้ที่หน้าจอ แล้วระบบนำไปคำนวณการเฉลี่ยวงเงินตามสัดส่วน
 *
 * กลุ่มของแต่ละคนมาจาก "ชื่อตำแหน่ง" (positions.name) ที่ผูกกับทะเบียนบุคลากร
 * โดยจับคู่คำสำคัญ (keyword) ตามลำดับของ self::RULES
 *
 * คลาสนี้เป็นของฟีเจอร์ใหม่ทั้งหมด ไม่แก้ไขคลาสเดิมในระบบ
 */
class ProfessionalGroupCatalog
{
    /** รหัสกลุ่มที่ใช้เมื่อจับคู่ตำแหน่งไม่ได้ */
    public const UNCLASSIFIED = 'unclassified';

    /**
     * ลำดับการจับคู่คำสำคัญจากบนลงล่าง — เจอก่อนได้ก่อน
     *
     * @var array<int, array{code:string, keywords:array<int,string>, exclude:array<int,string>}>
     */
    protected const RULES = [
        [
            'code' => 'dentist',
            'keywords' => ['ทันตแพทย์', 'ทันตแพทย'],
            'exclude' => [],
        ],
        [
            // 'แพทย์' ครอบคลุม นายแพทย์/แพทย์หญิง แต่ต้องไม่กลืนสายวิชาชีพอื่นที่มีคำว่า "แพทย์" ต่อท้าย
            //   แผนไทย/แผนจีน      = แพทย์แผนไทย → กลุ่ม 5
            //   ทันต               = ทันตแพทย์ (จับไปแล้วที่กลุ่ม 2)
            //   เทคนิคการแพทย์      = นักเทคนิคการแพทย์ → กลุ่ม 5
            //   รังสี               = นักรังสีการแพทย์ → กลุ่ม 5
            'code' => 'doctor',
            'keywords' => ['นายแพทย์', 'แพทย์'],
            'exclude' => ['แผนไทย', 'แผนจีน', 'ทันต', 'เทคนิคการแพทย์', 'รังสี'],
        ],
        [
            // 'เภสัชกร' เป็น substring ของ "เภสัชกรรม" — ต้องกันเจ้าพนักงาน/พนักงานเภสัชกรรม
            // ซึ่งเป็นสายสนับสนุน ไม่ใช่วิชาชีพเภสัชกร
            'code' => 'pharmacist',
            'keywords' => ['เภสัชกร'],
            'exclude' => ['เจ้าพนักงานเภสัชกรรม', 'พนักงานเภสัชกรรม'],
        ],
        [
            // พยาบาลเทคนิค/พนักงานช่วยการพยาบาล ต้องไม่ถูกนับเป็นพยาบาลวิชาชีพ
            'code' => 'nurse',
            'keywords' => ['พยาบาลวิชาชีพ', 'พยาบาล'],
            'exclude' => ['เทคนิค', 'ช่วย'],
        ],
        [
            'code' => 'professional_subdegree',
            'keywords' => [
                'เจ้าพนักงานเภสัชกรรม', 'พยาบาลเทคนิค', 'เจ้าพนักงานทันตสาธารณสุข',
                'ทันตาภิบาล', 'เจ้าพนักงานวิทยาศาสตร์การแพทย์', 'เจ้าพนักงานรังสี',
                'เจ้าพนักงานกายภาพบำบัด', 'เจ้าพนักงานสาธารณสุข', 'เจ้าพนักงานวิทยาศาสตร์',
            ],
            'exclude' => [],
        ],
        [
            'code' => 'professional_degree',
            'keywords' => [
                'นักจิตวิทยา', 'นักสังคมสงเคราะห์', 'นักวิชาการสาธารณสุข', 'นักสาธารณสุข',
                'นักวิทยาศาสตร์การแพทย์', 'นักเทคนิคการแพทย์', 'นักกายภาพบำบัด',
                'นักโภชนาการ', 'โภชนากร', 'นักรังสี', 'นักกิจกรรมบำบัด', 'นักกายอุปกรณ์',
                'นักเวชศาสตร์', 'แพทย์แผนไทย', 'แพทย์แผนจีน', 'นักจิตวิทยาคลินิก',
            ],
            'exclude' => [],
        ],
        [
            'code' => 'support_degree',
            'keywords' => [
                'นักจัดการงานทั่วไป', 'นักทรัพยากรบุคคล', 'นักวิเคราะห์นโยบาย',
                'นักวิชาการคอมพิวเตอร์', 'นักวิชาการเงิน', 'นักวิชาการพัสดุ',
                'นักวิชาการศึกษา', 'นักประชาสัมพันธ์', 'นักวิเทศสัมพันธ์',
                'บรรณารักษ์', 'นักวิชาการสถิติ', 'นักวิชาการ',
            ],
            'exclude' => [],
        ],
        [
            // ต้องมาก่อนกลุ่มบริการ เพื่อไม่ให้ "พนักงานการเงิน" ตกไปกลุ่ม 9
            'code' => 'support_subdegree',
            'keywords' => [
                'เจ้าพนักงาน', 'นายช่าง', 'ช่างเทคนิค', 'พนักงานการเงิน',
                'พนักงานธุรการ', 'พนักงานพัสดุ', 'เจ้าหน้าที่',
            ],
            'exclude' => [],
        ],
        [
            'code' => 'service_other',
            'keywords' => [
                'พนักงานช่วย', 'พนักงานบริการ', 'พนักงานขับรถ', 'พนักงานทำความสะอาด',
                'พนักงาน', 'คนงาน', 'ลูกจ้าง', 'แม่บ้าน',
            ],
            'exclude' => [],
        ],
    ];

    /**
     * นิยามกลุ่มทั้งหมด เรียงตามลำดับที่แสดงบนเอกสาร (1–9)
     *
     * @return array<int, array<string, mixed>>
     */
    public static function groups(): array
    {
        return [
            [
                'code' => 'doctor',
                'name' => 'แพทย์',
                'short_name' => 'แพทย์',
                'examples' => '',
                'default_weight' => 1.00,
            ],
            [
                'code' => 'dentist',
                'name' => 'ทันตแพทย์',
                'short_name' => 'ทันตแพทย์',
                'examples' => '',
                'default_weight' => 1.00,
            ],
            [
                'code' => 'pharmacist',
                'name' => 'เภสัชกร',
                'short_name' => 'เภสัชกร',
                'examples' => '',
                'default_weight' => 0.65,
            ],
            [
                'code' => 'nurse',
                'name' => 'พยาบาลวิชาชีพ',
                'short_name' => 'พยาบาลวิชาชีพ',
                'examples' => '',
                'default_weight' => 0.54,
            ],
            [
                'code' => 'professional_degree',
                'name' => 'สายวิชาชีพอื่น ๆ ปริญญาตรี',
                'short_name' => 'สายวิชาชีพอื่น ๆ ปริญญาตรี',
                'examples' => 'นักจิตวิทยาคลินิก, นักสังคมสงเคราะห์, นักวิชาการสาธารณสุข, นักวิทยาศาสตร์การแพทย์ ฯลฯ',
                'default_weight' => 0.45,
            ],
            [
                'code' => 'professional_subdegree',
                'name' => 'สายวิชาชีพอื่น ๆ ต่ำกว่าปริญญาตรี',
                'short_name' => 'สายวิชาชีพอื่น ๆ ต่ำกว่าปริญญาตรี',
                'examples' => 'เจ้าพนักงานเภสัชกรรม, พยาบาลเทคนิค, เจ้าพนักงานทันตสาธารณสุข ฯลฯ',
                'default_weight' => 0.37,
            ],
            [
                'code' => 'support_degree',
                'name' => 'สายสนับสนุนบริการ ปริญญาตรี',
                'short_name' => 'สายสนับสนุนบริการ ปริญญาตรี',
                'examples' => 'นักจัดการงานทั่วไป, นักทรัพยากรบุคคล, นักวิเคราะห์นโยบายและแผน, นักวิชาการคอมพิวเตอร์ ฯลฯ',
                'default_weight' => 0.37,
            ],
            [
                'code' => 'support_subdegree',
                'name' => 'สายสนับสนุนบริการ ต่ำกว่าปริญญาตรี',
                'short_name' => 'สายสนับสนุนบริการ ต่ำกว่าปริญญาตรี',
                'examples' => 'เจ้าพนักงานการเงิน, เจ้าพนักงานพัสดุ, นายช่างเทคนิค, พนักงานการเงิน, พนักงานธุรการ ฯลฯ',
                'default_weight' => 0.35,
            ],
            [
                'code' => 'service_other',
                'name' => 'สายบริการอื่น ๆ (ไม่จำกัดวุฒิ ม.6)',
                'short_name' => 'สายบริการอื่น ๆ',
                'examples' => 'พนักงานช่วยการพยาบาล, พนักงานช่วยเหลือคนไข้, พนักงานบริการ, พนักงานขับรถยนต์ ฯลฯ',
                'default_weight' => 0.35,
            ],
        ];
    }

    /**
     * กลุ่มที่พบข้อมูลจริง แต่จับคู่กลุ่มไม่ได้ (แสดงเฉพาะเมื่อมีคนตกหล่น)
     *
     * @return array<string, mixed>
     */
    public static function unclassifiedGroup(): array
    {
        return [
            'code' => self::UNCLASSIFIED,
            'name' => 'อื่น ๆ / ไม่ระบุกลุ่มวิชาชีพ',
            'short_name' => 'อื่น ๆ / ไม่ระบุ',
            'examples' => 'ตำแหน่งที่ยังจับคู่กลุ่มวิชาชีพไม่ได้ — ตรวจสอบชื่อตำแหน่งในทะเบียนบุคลากร',
            'default_weight' => 0.00,
        ];
    }

    /** รหัสกลุ่มตามลำดับที่แสดงบนเอกสาร */
    public static function codes(): array
    {
        return array_column(self::groups(), 'code');
    }

    /**
     * นิยามกลุ่ม + น้ำหนักเริ่มต้น (keyed by code)
     *
     * @return array<string, array<string, mixed>>
     */
    public static function definitions(): array
    {
        $definitions = [];

        foreach (self::groups() as $group) {
            $definitions[$group['code']] = $group;
        }

        $definitions[self::UNCLASSIFIED] = self::unclassifiedGroup();

        return $definitions;
    }

    /**
     * จับคู่ชื่อตำแหน่ง → รหัสกลุ่มวิชาชีพ
     * คืนค่า null เมื่อจับคู่ไม่ได้
     */
    public static function classify(?string $positionName): ?string
    {
        $name = trim((string) $positionName);

        if ($name === '') {
            return null;
        }

        foreach (self::RULES as $rule) {
            $matched = false;

            foreach ($rule['keywords'] as $keyword) {
                if (str_contains($name, $keyword)) {
                    $matched = true;
                    break;
                }
            }

            if (! $matched) {
                continue;
            }

            foreach ($rule['exclude'] as $exclude) {
                if (str_contains($name, $exclude)) {
                    $matched = false;
                    break;
                }
            }

            if ($matched) {
                return $rule['code'];
            }
        }

        return null;
    }
}
