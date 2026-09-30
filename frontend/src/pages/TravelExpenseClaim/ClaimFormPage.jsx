import { useState } from 'react';
import { useNavigate, useParams, Link } from 'react-router-dom';
import toast from 'react-hot-toast';
import { Plus, Save, CheckCircle2, ArrowLeft, Download, Loader2 } from 'lucide-react';

import useTravelExpenseClaimForm from '../../hooks/useTravelExpenseClaimForm';
import { travelExpenseClaimService } from '../../services/travelExpenseClaimService';
import ClaimDocumentSection from '../../components/travelExpense/ClaimDocumentSection';
import ClaimItemsTable from '../../components/travelExpense/ClaimItemsTable';
import EmployeePickerModal from '../../components/travelExpense/EmployeePickerModal';

/**
 * หน้าเพิ่ม / แก้ไข / ดูรายละเอียดใบเบิกค่าใช้จ่าย
 *
 * mode = 'view' → อ่านเท่านั้น
 * เอกสารที่ confirmed/cancelled เป็น read-only เสมอ (ตาม is_editable จาก backend)
 */
const ClaimFormPage = ({ mode = 'edit' }) => {
    const { id } = useParams();
    const navigate = useNavigate();

    const {
        fiscalYear,
        options,
        claim,
        status,
        readOnly: statusReadOnly,
        form,
        setField,
        items,
        addEmployees,
        removeItem,
        setItemAmount,
        totals,
        loading,
        saving,
        error,
        fieldErrors,
        save,
    } = useTravelExpenseClaimForm(id || null);

    const [pickerOpen, setPickerOpen] = useState(false);

    const readOnly = mode === 'view' || statusReadOnly;

    const handleSave = async (nextStatus) => {
        if (nextStatus === 'confirmed') {
            if (items.length === 0) {
                toast.error('ต้องมีรายการผู้เบิกอย่างน้อย 1 รายการก่อนยืนยันเอกสาร');
                return;
            }
            if (!window.confirm('ยืนยันเอกสารนี้?\nหลังยืนยันจะแก้ไขไม่ได้ แต่ส่งออก Excel ได้')) return;
        }

        const result = await save(nextStatus);
        if (!result.success) {
            toast.error(result.error);
            return;
        }

        toast.success(result.message || 'บันทึกเรียบร้อย');

        // สร้างใหม่แล้วพาไปหน้าเอกสารนั้น เพื่อไม่ให้กดบันทึกซ้ำเป็นเอกสารใหม่
        if (!id && result.data?.id) {
            navigate(`/finance/travel-expense-claims/${result.data.id}`, { replace: true });
        }
    };

    const handleExport = async () => {
        try {
            await travelExpenseClaimService.exportExcel(
                claim.id,
                `travel-expense-claim-${claim.document_no}-FY${claim.fiscal_year}.xlsx`,
            );
        } catch (err) {
            // แสดงข้อความจริงจาก backend ไม่เดาสาเหตุเอง
            toast.error(err.message || 'ไม่สามารถส่งออก Excel ได้');
        }
    };

    if (loading) {
        return (
            <p className="flex items-center gap-2 py-10 text-sm text-gray-500">
                <Loader2 className="h-4 w-4 animate-spin" />
                กำลังโหลดเอกสาร...
            </p>
        );
    }

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <Link
                    to="/finance/travel-expense-claims"
                    className="inline-flex items-center gap-2 text-sm text-gray-600 hover:text-gray-800"
                >
                    <ArrowLeft className="h-4 w-4" />
                    กลับไปรายการใบเบิก
                </Link>

                {status === 'confirmed' && (
                    <button
                        type="button"
                        onClick={handleExport}
                        className="inline-flex items-center gap-2 rounded-lg bg-[#8B5E3C] px-3 py-2 text-sm font-medium text-white hover:bg-[#6B4F3A]"
                    >
                        <Download className="h-4 w-4" />
                        ส่งออก Excel
                    </button>
                )}
            </div>

            {error && (
                <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                    {error}
                </div>
            )}

            <ClaimDocumentSection
                fiscalYear={fiscalYear}
                options={options}
                form={form}
                setField={setField}
                readOnly={readOnly}
                status={status}
                claim={claim}
                fieldErrors={fieldErrors}
            />

            {/* ส่วน B: รายการผู้เบิก */}
            <div className="space-y-3 rounded-xl border border-gray-200 bg-white p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h2 className="text-base font-semibold text-gray-800">รายการผู้เบิก</h2>
                    {!readOnly && (
                        <button
                            type="button"
                            onClick={() => setPickerOpen(true)}
                            className="inline-flex items-center gap-2 rounded-lg bg-amber-600 px-3 py-2 text-sm font-medium text-white hover:bg-amber-700"
                        >
                            <Plus className="h-4 w-4" />
                            เพิ่มผู้เบิก
                        </button>
                    )}
                </div>

                {fieldErrors?.items && (
                    <p className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">
                        {fieldErrors.items[0]}
                    </p>
                )}

                <ClaimItemsTable
                    items={items}
                    totals={{ ...totals, people: items.length }}
                    readOnly={readOnly}
                    onAmountChange={setItemAmount}
                    onRemove={removeItem}
                />
            </div>

            {/* ส่วน C: ปุ่มดำเนินการ */}
            {!readOnly && (
                <div className="flex flex-wrap gap-3">
                    <button
                        type="button"
                        onClick={() => handleSave('draft')}
                        disabled={saving}
                        className="inline-flex items-center gap-2 rounded-lg border border-[#8B5E3C] px-4 py-2.5 text-sm font-medium text-[#8B5E3C] hover:bg-[#FBF7EE] disabled:opacity-50"
                    >
                        {saving ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />}
                        บันทึกร่าง
                    </button>
                    <button
                        type="button"
                        onClick={() => handleSave('confirmed')}
                        disabled={saving || items.length === 0}
                        title={items.length === 0 ? 'ต้องมีผู้เบิกอย่างน้อย 1 รายการ' : undefined}
                        className="inline-flex items-center gap-2 rounded-lg bg-green-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-50"
                    >
                        <CheckCircle2 className="h-4 w-4" />
                        ยืนยันและบันทึก
                    </button>
                </div>
            )}

            <EmployeePickerModal
                open={pickerOpen}
                onClose={() => setPickerOpen(false)}
                onConfirm={addEmployees}
                excludeIds={items.map((item) => item.employee_id).filter(Boolean)}
            />
        </div>
    );
};

export default ClaimFormPage;
