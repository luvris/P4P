<?php

namespace Tests\Unit;

use App\Support\ProfessionalGroupCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * กฎคำสำคัญที่จับชื่อตำแหน่งจากไฟล์เงินเดือน → กลุ่มวิชาชีพ 9 กลุ่ม
 *
 * ชื่อตำแหน่งในไฟล์จริงมีทั้งคำต่อท้ายระดับวิทยฐานะ (ชำนาญการ/ปฏิบัติการ)
 * และคำที่ดูคล้ายกันแต่ต่างกลุ่ม (เภสัชกร vs เจ้าพนักงานเภสัชกรรม,
 * แพทย์ vs นักเทคนิคการแพทย์) จึงต้องล็อกพฤติกรรมไว้กันคำใหม่ไปจับผิดกลุ่ม
 */
class ProfessionalGroupCatalogTest extends TestCase
{
    #[DataProvider('positions')]
    public function test_it_classifies_position_names_into_the_documented_groups(string $position, ?string $expected): void
    {
        $this->assertSame($expected, ProfessionalGroupCatalog::classify($position));
    }

    public static function positions(): array
    {
        return [
            // 1. แพทย์
            ['นายแพทย์ชำนาญการ', 'doctor'],
            ['นายแพทย์ชำนาญการพิเศษ', 'doctor'],
            ['นายแพทย์เชี่ยวชาญ', 'doctor'],
            ['แพทย์หญิง', 'doctor'],
            ['ผู้อำนวยการเฉพาะด้าน(แพทย์)อำนวยการสูง', 'doctor'],

            // 2. ทันตแพทย์
            ['ทันตแพทย์ชำนาญการ', 'dentist'],
            ['ผู้ช่วยทันตแพทย์', 'dentist'],

            // 3. เภสัชกร — ต้องไม่กลืน \"เภสัชกรรม\" ของสายสนับสนุน
            ['เภสัชกร', 'pharmacist'],
            ['เภสัชกรชำนาญการ', 'pharmacist'],
            ['เภสัชกรชำนาญการพิเศษ', 'pharmacist'],
            ['เจ้าพนักงานเภสัชกรรม', 'professional_subdegree'],
            ['เจ้าพนักงานเภสัชกรรมชำนาญงาน', 'professional_subdegree'],
            ['พนักงานเภสัชกรรม', 'service_other'],

            // 4. พยาบาลวิชาชีพ — พยาบาลเทคนิค/พนักงานช่วยการพยาบาล ต้องไม่ถูกนับรวม
            ['พยาบาลวิชาชีพ', 'nurse'],
            ['พยาบาลวิชาชีพชำนาญการ', 'nurse'],
            ['พยาบาลเทคนิค', 'professional_subdegree'],
            ['พนักงานช่วยการพยาบาล', 'service_other'],

            // 5. สายวิชาชีพอื่น ๆ ปริญญาตรี — ต้องไม่ถูกนายแพทย์กลืน
            ['นักเทคนิคการแพทย์', 'professional_degree'],
            ['นักเทคนิคการแพทย์ปฏิบัติการ', 'professional_degree'],
            ['นักรังสีการแพทย์ชำนาญการ', 'professional_degree'],
            ['นักสาธารณสุขชำนาญการ', 'professional_degree'],
            ['โภชนากรระดับ ส3', 'professional_degree'],
            ['แพทย์แผนไทย', 'professional_degree'],
            ['แพทย์แผนจีน', 'professional_degree'],

            // 6. สายวิชาชีพอื่น ๆ ต่ำกว่าปริญญาตรี
            ['เจ้าพนักงานทันตสาธารณสุขชำนาญงาน', 'professional_subdegree'],

            // 7. สายสนับสนุนบริการ ปริญญาตรี
            ['นักจัดการงานทั่วไป', 'support_degree'],
            ['นักวิชาการเงินและบัญชี', 'support_degree'],
            ['นักวิเคราะห์นโยบายและแผนชำนาญการ', 'support_degree'],

            // 8. สายสนับสนุนบริการ ต่ำกว่าปริญญาตรี
            ['เจ้าพนักงานการเงินและบัญชีปฏิบัติงาน', 'support_subdegree'],
            ['นายช่างเทคนิค', 'support_subdegree'],

            // 9. สายบริการอื่น ๆ
            ['พนักงานช่วยเหลือคนไข้', 'service_other'],
            ['พนักงานขับรถยนต์', 'service_other'],
            ['พนักงานเปล', 'service_other'],

            // ตำแหน่งที่กฎยังจับไม่ได้ → ให้ผู้ใช้กำหนดเองผ่านหน้าจอ
            ['วิศวกร', null],
            ['นักบินอวกาศ', null],
            ['', null],
            ['   ', null],
        ];
    }

    public function test_the_catalog_lists_the_nine_documented_groups_in_order(): void
    {
        $codes = ProfessionalGroupCatalog::codes();

        $this->assertSame([
            'doctor',
            'dentist',
            'pharmacist',
            'nurse',
            'professional_degree',
            'professional_subdegree',
            'support_degree',
            'support_subdegree',
            'service_other',
        ], $codes);
    }

    public function test_default_weights_match_the_source_document(): void
    {
        $weights = [];
        foreach (ProfessionalGroupCatalog::definitions() as $code => $definition) {
            $weights[$code] = $definition['default_weight'];
        }

        $this->assertEqualsWithDelta(1.00, $weights['doctor'], 0.001);
        $this->assertEqualsWithDelta(1.00, $weights['dentist'], 0.001);
        $this->assertEqualsWithDelta(0.65, $weights['pharmacist'], 0.001);
        $this->assertEqualsWithDelta(0.54, $weights['nurse'], 0.001);
        $this->assertEqualsWithDelta(0.45, $weights['professional_degree'], 0.001);
        $this->assertEqualsWithDelta(0.37, $weights['professional_subdegree'], 0.001);
        $this->assertEqualsWithDelta(0.37, $weights['support_degree'], 0.001);
        $this->assertEqualsWithDelta(0.35, $weights['support_subdegree'], 0.001);
        $this->assertEqualsWithDelta(0.35, $weights['service_other'], 0.001);

        // ผลรวม 5.08 ตรงกับช่อง \"รวม\" ของเอกสารต้นแบบ
        $this->assertEqualsWithDelta(5.08, array_sum($weights), 0.001);
    }
}
