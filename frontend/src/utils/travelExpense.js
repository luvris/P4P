/** สถานะเอกสารใบเบิก */
export const CLAIM_STATUS = {
    draft: { label: 'ร่าง', className: 'bg-gray-100 text-gray-700 border-gray-200' },
    confirmed: { label: 'ยืนยันแล้ว', className: 'bg-green-100 text-green-700 border-green-200' },
    cancelled: { label: 'ยกเลิก', className: 'bg-red-100 text-red-700 border-red-200' },
};

export const AMOUNT_FIELDS = [
    'allowance_amount',
    'accommodation_amount',
    'transportation_amount',
    'other_amount',
];

export const AMOUNT_LABELS = {
    allowance_amount: 'ค่าเบี้ยเลี้ยง',
    accommodation_amount: 'ค่าที่พัก',
    transportation_amount: 'ค่าพาหนะ',
    other_amount: 'ค่าใช้จ่ายอื่น',
};

export const toAmount = (value) => {
    const numeric = Number(value);
    return Number.isFinite(numeric) && numeric >= 0 ? numeric : 0;
};

/** รวมเงินของแถว — ฝั่ง backend คำนวณซ้ำอีกครั้งเสมอ */
export const rowTotal = (row) =>
    AMOUNT_FIELDS.reduce((sum, field) => sum + toAmount(row[field]), 0);

export const formatMoney = (value) =>
    toAmount(value).toLocaleString('th-TH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

export const formatDateTime = (value) =>
    value ? new Date(value).toLocaleString('th-TH', { dateStyle: 'short', timeStyle: 'short' }) : '-';
