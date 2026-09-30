import { Link } from 'react-router-dom';
import { Eye, Pencil, CheckCircle2, Ban, Download } from 'lucide-react';

/**
 * ปุ่มจัดการของแต่ละแถว — แสดงตามสถานะเอกสาร
 *
 * draft     : ดู / แก้ไข / ยืนยัน / ยกเลิก
 * confirmed : ดู / Export / ยกเลิก
 * cancelled : ดูได้เท่านั้น (ไม่ export, ไม่ลบข้อมูลจริง)
 */
const ClaimRowActions = ({ claim, onConfirm, onCancel, onExport }) => (
    <div className="flex items-center gap-1">
        <Link
            to={`/finance/travel-expense-claims/${claim.id}`}
            title="ดูรายละเอียด"
            className="rounded-lg p-1.5 text-gray-600 hover:bg-gray-100"
        >
            <Eye className="h-4 w-4" />
        </Link>

        {claim.is_editable && (
            <>
                <Link
                    to={`/finance/travel-expense-claims/${claim.id}/edit`}
                    title="แก้ไข"
                    className="rounded-lg p-1.5 text-amber-700 hover:bg-amber-50"
                >
                    <Pencil className="h-4 w-4" />
                </Link>
                <button
                    type="button"
                    onClick={() => onConfirm(claim)}
                    title="ยืนยันเอกสาร"
                    className="rounded-lg p-1.5 text-green-700 hover:bg-green-50"
                >
                    <CheckCircle2 className="h-4 w-4" />
                </button>
            </>
        )}

        {claim.status === 'confirmed' && (
            <button
                type="button"
                onClick={() => onExport(claim)}
                title="ส่งออก Excel"
                className="rounded-lg p-1.5 text-[#8B5E3C] hover:bg-[#FBF7EE]"
            >
                <Download className="h-4 w-4" />
            </button>
        )}

        {claim.status !== 'cancelled' && (
            <button
                type="button"
                onClick={() => onCancel(claim)}
                title="ยกเลิกเอกสาร"
                className="rounded-lg p-1.5 text-red-600 hover:bg-red-50"
            >
                <Ban className="h-4 w-4" />
            </button>
        )}
    </div>
);

export default ClaimRowActions;
