import { Link } from 'react-router-dom';
import toast from 'react-hot-toast';
import { Plus } from 'lucide-react';

import useTravelExpenseClaims from '../../hooks/useTravelExpenseClaims';
import { travelExpenseClaimService } from '../../services/travelExpenseClaimService';
import ClaimListFilters from '../../components/travelExpense/ClaimListFilters';
import ClaimListTable from '../../components/travelExpense/ClaimListTable';
import Pagination from '../../components/travelExpense/Pagination';

/**
 * หน้ารายการใบเบิกค่าใช้จ่าย — แสดงเฉพาะเอกสารของปีงบประมาณที่เลือกบน Header
 */
const ClaimListPage = () => {
    const {
        fiscalYear,
        claims,
        meta,
        loading,
        error,
        filters,
        updateFilters,
        confirmClaim,
        cancelClaim,
    } = useTravelExpenseClaims();

    const handleConfirm = async (claim) => {
        if (!window.confirm(`ยืนยันใบเบิก ${claim.document_no}?\nหลังยืนยันจะแก้ไขไม่ได้`)) return;

        const result = await confirmClaim(claim.id);
        if (result.success) {
            toast.success(result.message || 'ยืนยันเอกสารเรียบร้อย');
        } else {
            toast.error(result.error);
        }
    };

    const handleCancel = async (claim) => {
        if (!window.confirm(`ยกเลิกใบเบิก ${claim.document_no}?\nข้อมูลเอกสารยังอยู่ในระบบ`)) return;

        const result = await cancelClaim(claim.id, null);
        if (result.success) {
            toast.success(result.message || 'ยกเลิกเอกสารแล้ว');
        } else {
            toast.error(result.error);
        }
    };

    const handleExport = async (claim) => {
        try {
            await travelExpenseClaimService.exportExcel(
                claim.id,
                `travel-expense-claim-${claim.document_no}-FY${claim.fiscal_year}.xlsx`,
            );
        } catch {
            toast.error('ส่งออกได้เฉพาะเอกสารที่ยืนยันแล้ว');
        }
    };

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <p className="text-sm text-gray-600">
                    ปีงบประมาณ <span className="font-semibold text-gray-800">{fiscalYear}</span>
                </p>
                <Link
                    to="/finance/travel-expense-claims/create"
                    className="inline-flex items-center gap-2 rounded-lg bg-amber-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-amber-700"
                >
                    <Plus className="h-4 w-4" />
                    สร้างใบเบิก
                </Link>
            </div>

            {error && (
                <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                    {error}
                </div>
            )}

            <ClaimListFilters
                fiscalYear={fiscalYear}
                filters={filters}
                onChange={updateFilters}
            />

            <ClaimListTable
                claims={claims}
                loading={loading}
                fiscalYear={fiscalYear}
                onConfirm={handleConfirm}
                onCancel={handleCancel}
                onExport={handleExport}
            />

            <Pagination
                meta={meta}
                onPageChange={(page) => updateFilters({ page }, false)}
            />
        </div>
    );
};

export default ClaimListPage;
