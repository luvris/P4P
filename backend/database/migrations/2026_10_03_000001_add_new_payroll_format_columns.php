<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * คอลัมน์ทั้งหมดของไฟล์ payroll รูปแบบใหม่
 *
 * ไฟล์ใหม่มี 39 คอลัมน์ และบางส่วนไม่มีในตารางเดิม เช่น ตกเบิก, ง.บ.ส.ก., ปจต.,
 * ไม่ทำเวชฯ, บ่าย-ดึก (งบประมาณ/บำรุง), ค่าตอบแทนโควิด, รายได้อื่น,
 * ค่ารักษา, ค่าเล่าเรียน, ค่าเบี้ยเลี้ยง, ค่าเช่าที่พัก, ค่าพาหนะ,
 * ค่าใช้จ่ายอื่น ๆ, ต้นทุนจัดโครงการ รวมถึงคำนำหน้า ตำแหน่ง และตำแหน่งเลขที่
 *
 * เก็บ "รวมรายรับทางตรง / ทางอ้อม" แยกจาก "ยอดรวมรายรับทั้งหมด รายบุคคล" เพื่อให้ตรวจยอดได้
 * และงวด (ปี/เดือน) เก็บรายแถว เพราะไฟล์เดียวอาจครอบคลุมหลายงวด
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            // ---- งวดของข้อมูล (อ่านจากคอลัมน์ ปี / เดือน ในไฟล์) ----
            $table->unsignedSmallInteger('fiscal_year')->nullable()->after('import_id')
                ->comment('ปีงบประมาณ (พ.ศ.) คำนวณจากคอลัมน์ ปี + เดือน ในไฟล์');
            $table->unsignedTinyInteger('period_month')->nullable()->after('fiscal_year')
                ->comment('งวดเดือน 1-12');
            $table->unsignedSmallInteger('period_year')->nullable()->after('period_month')
                ->comment('ปีปฏิทิน (พ.ศ.) ตามคอลัมน์ ปี ในไฟล์');

            $table->unsignedSmallInteger('seq_number')->nullable()->after('period_year')
                ->comment('ลำดับที่');

            // ---- ข้อมูลพนักงาน ----
            $table->string('prefix', 30)->nullable()->after('employee_type')->comment('คำนำหน้า');
            $table->string('position_name')->nullable()->after('last_name')->comment('ตำแหน่ง');
            $table->string('position_number', 30)->nullable()->after('position_name')
                ->comment('ตำแหน่งเลขที่');

            // ---- รายรับทางตรง ----
            $table->decimal('salary_deduction', 12, 2)->default(0)->after('salary')->comment('ตกเบิก');
            $table->decimal('project_budget', 12, 2)->default(0)->comment('ง.บ.ส.ก. (งบประมาณส่วนกลาง)');
            $table->decimal('regular_allowance', 12, 2)->default(0)->comment('ปจต.');
            $table->decimal('no_medical_service', 12, 2)->default(0)->after('living_allowance')
                ->comment('ไม่ทำเวชฯ');
            $table->decimal('night_shift_budget', 12, 2)->default(0)->after('overtime')
                ->comment('บ่าย-ดึก เงินงบประมาณ');
            $table->decimal('night_shift_maintenance', 12, 2)->default(0)->after('night_shift_budget')
                ->comment('บ่าย-ดึก เงินบำรุง');
            $table->decimal('covid_allowance', 12, 2)->default(0)->after('p4p_quality_project')
                ->comment('ค่าตอบแทนปฏิบัติงาน COVID-19');
            $table->decimal('other_income', 12, 2)->default(0)->after('covid_allowance')
                ->comment('รายได้อื่น');
            $table->decimal('total_direct_income', 12, 2)->default(0)->after('other_income')
                ->comment('รวมรายรับทางตรง');

            // ---- รายการหัก / รายรับทางอ้อม ----
            $table->decimal('medical_treatment', 12, 2)->default(0)->after('total_direct_income')
                ->comment('ค่ารักษา');
            $table->decimal('education_allowance', 12, 2)->default(0)->after('medical_treatment')
                ->comment('ค่าเล่าเรียน');
            $table->decimal('meal_allowance', 12, 2)->default(0)->after('education_allowance')
                ->comment('ค่าเบี้ยเลี้ยง');
            $table->decimal('housing_allowance', 12, 2)->default(0)->after('meal_allowance')
                ->comment('ค่าเช่าที่พัก');
            $table->decimal('transport_allowance', 12, 2)->default(0)->after('housing_allowance')
                ->comment('ค่าพาหนะ');
            $table->decimal('other_expenses', 12, 2)->default(0)->after('transport_allowance')
                ->comment('ค่าใช้จ่ายอื่น ๆ');
            $table->decimal('project_cost', 12, 2)->default(0)->after('other_expenses')
                ->comment('ต้นทุนจัดโครงการ');
            $table->decimal('total_indirect_income', 12, 2)->default(0)->after('project_cost')
                ->comment('รวมรายรับทางอ้อม');

            $table->text('note')->nullable()->after('net_income')->comment('หมายเหตุ');

            $table->index(['fiscal_year', 'period_month'], 'payrolls_period_index');
        });
    }

    public function down(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropIndex('payrolls_period_index');

            $table->dropColumn([
                'fiscal_year', 'period_month', 'period_year', 'seq_number',
                'prefix', 'position_name', 'position_number',
                'salary_deduction', 'project_budget', 'regular_allowance', 'no_medical_service',
                'night_shift_budget', 'night_shift_maintenance',
                'covid_allowance', 'other_income', 'total_direct_income',
                'medical_treatment', 'education_allowance', 'meal_allowance',
                'housing_allowance', 'transport_allowance', 'other_expenses', 'project_cost',
                'total_indirect_income', 'note',
            ]);
        });
    }
};