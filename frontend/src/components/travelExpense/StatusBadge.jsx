import { CLAIM_STATUS } from '../../utils/travelExpense';

/** ป้ายสถานะเอกสาร: ร่าง / ยืนยันแล้ว / ยกเลิก */
const StatusBadge = ({ status }) => {
    const meta = CLAIM_STATUS[status] || CLAIM_STATUS.draft;

    return (
        <span
            className={`inline-block rounded-md border px-2.5 py-1 text-xs font-medium ${meta.className}`}
        >
            {meta.label}
        </span>
    );
};

export default StatusBadge;
