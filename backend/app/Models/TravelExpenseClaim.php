<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * ใบเบิกค่าใช้จ่ายเดินทางไปราชการ (หัวเอกสาร)
 */
class TravelExpenseClaim extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_CANCELLED = 'cancelled';

    /** ประเภทค่าใช้จ่ายเริ่มต้นตามแบบฟอร์มอ้างอิง */
    public const DEFAULT_CATEGORY = 'เดินทางไปราชการ';

    public const CATEGORY_TRAVEL = 'เดินทางไปราชการ';
    public const CATEGORY_TRAINING = 'เดินทางไปราชการโดยฝึกอบรม';

    /** ประเภทค่าใช้จ่ายที่เลือกได้ (dropdown) */
    public const CATEGORIES = [
        self::CATEGORY_TRAVEL,
        self::CATEGORY_TRAINING,
    ];

    /**
     * ข้อความประเภทค่าใช้จ่ายที่จะพิมพ์ลงแบบฟอร์ม Excel
     *
     * ชื่อที่โชว์ในแอปกับข้อความบนแบบฟอร์มไม่เหมือนกัน —
     * แบบฟอร์มราชการต้องใช้ข้อความเต็มตามต้นฉบับ และใช้ข้อความนี้
     * เหมือนกันทุกประเภท (ทั้ง เดินทางไปราชการ และ เดินทางไปราชการโดยฝึกอบรม)
     */
    public const EXCEL_CATEGORY_LABEL = 'ค่าเบี้ยเลี้ยง ค่าที่พัก ค่าพาหนะ ภายในประเทศ';

    protected $fillable = [
        'document_no',
        'fiscal_year',
        'claim_period',
        'expense_category',
        'organization_name',
        'note',
        'status',
        'created_by',
        'confirmed_by',
        'confirmed_at',
        'cancelled_by',
        'cancelled_at',
        'cancel_reason',
    ];

    protected $casts = [
        'fiscal_year'  => 'integer',
        'claim_period' => 'date',
        'confirmed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    // ========== Relationships ==========

    public function items()
    {
        return $this->hasMany(TravelExpenseClaimItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function confirmer()
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function canceller()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    // ========== Scopes ==========

    public function scopeForFiscalYear($query, int $fiscalYear)
    {
        return $query->where('fiscal_year', $fiscalYear);
    }

    // ========== Helpers ==========

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isConfirmed(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /** แก้ไขได้เฉพาะเอกสารร่าง */
    public function isEditable(): bool
    {
        return $this->isDraft();
    }

    /**
     * ออกเลขเอกสารถัดไปของปีงบประมาณ เช่น TEC-2569-0001
     */
    public static function nextDocumentNo(int $fiscalYear): string
    {
        $prefix = "TEC-{$fiscalYear}-";

        $last = static::query()
            ->where('document_no', 'like', $prefix . '%')
            ->orderByDesc('document_no')
            ->value('document_no');

        $running = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix . str_pad((string) $running, 4, '0', STR_PAD_LEFT);
    }
}
