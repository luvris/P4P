import { CalendarClock } from 'lucide-react';
import StatusBadge from './StatusBadge';

/**
 * ส่วน A: ข้อมูลเอกสาร
 * ปีงบประมาณมาจาก Header — แสดงแบบ read-only ไม่ hardcode
 */
const ClaimDocumentSection = ({ fiscalYear, options, form, setField, readOnly, status, claim, fieldErrors }) => (
    <div className="rounded-xl border border-gray-200 bg-white p-4 space-y-4">
        <div className="flex flex-wrap items-center justify-between gap-3">
            <h2 className="text-base font-semibold text-gray-800">ข้อมูลเอกสาร</h2>
            <div className="flex items-center gap-2">
                {claim?.document_no && (
                    <span className="text-sm text-gray-600">เลขที่ {claim.document_no}</span>
                )}
                <StatusBadge status={status} />
            </div>
        </div>

        <div className="grid gap-4 md:grid-cols-2">
            <div>
                <label className="mb-1 block text-xs font-medium text-gray-700">ปีงบประมาณ</label>
                <div className="flex items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700">
                    <CalendarClock className="h-4 w-4 shrink-0 text-gray-400" />
                    <span className="font-medium">ปีงบประมาณ {fiscalYear}</span>
                </div>
            </div>

            <div>
                <label htmlFor="claim-period" className="mb-1 block text-xs font-medium text-gray-700">
                    เดือนที่เบิก<span className="ml-0.5 text-red-500">*</span>
                </label>
                <select
                    id="claim-period"
                    value={form.claim_period}
                    onChange={(e) => setField('claim_period', e.target.value)}
                    disabled={readOnly}
                    className="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm disabled:bg-gray-50 disabled:text-gray-600"
                >
                    <option value="">-- เลือกเดือนที่เบิก --</option>
                    {options?.months?.map((month) => (
                        <option key={month.month} value={month.value}>
                            {month.label} {month.calendar_year}
                        </option>
                    ))}
                </select>
                {fieldErrors?.claim_period && (
                    <p className="mt-1 text-xs text-red-600">{fieldErrors.claim_period[0]}</p>
                )}
            </div>

            <div>
                <label htmlFor="expense-category" className="mb-1 block text-xs font-medium text-gray-700">
                    ประเภทค่าใช้จ่าย<span className="ml-0.5 text-red-500">*</span>
                </label>
                <select
                    id="expense-category"
                    value={form.expense_category}
                    onChange={(e) => setField('expense_category', e.target.value)}
                    disabled={readOnly}
                    className="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm disabled:bg-gray-50 disabled:text-gray-600"
                >
                    <option value="">-- เลือกประเภทค่าใช้จ่าย --</option>
                    {options?.expense_categories?.map((category) => (
                        <option key={category.value} value={category.value}>
                            {category.label}
                        </option>
                    ))}
                </select>
                {/* ข้อความเต็มตามที่จะพิมพ์ลงแบบฟอร์ม Excel */}
                {form.expense_category && (
                    <p className="mt-1 text-xs text-gray-500">{form.expense_category}</p>
                )}
                {fieldErrors?.expense_category && (
                    <p className="mt-1 text-xs text-red-600">{fieldErrors.expense_category[0]}</p>
                )}
            </div>

            <div className="md:col-span-2">
                <label htmlFor="claim-note" className="mb-1 block text-xs font-medium text-gray-700">
                    หมายเหตุ
                </label>
                <textarea
                    id="claim-note"
                    value={form.note}
                    onChange={(e) => setField('note', e.target.value)}
                    disabled={readOnly}
                    rows={2}
                    maxLength={2000}
                    className="w-full resize-none rounded-lg border border-gray-300 px-3 py-2 text-sm disabled:bg-gray-50 disabled:text-gray-600"
                />
            </div>
        </div>

        {status === 'cancelled' && (
            <p className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">
                เอกสารนี้ถูกยกเลิกแล้ว
                {claim?.cancel_reason ? ` — เหตุผล: ${claim.cancel_reason}` : ''}
                {' '}ข้อมูลยังอยู่ในระบบ แต่ส่งออก Excel ไม่ได้
            </p>
        )}
    </div>
);

export default ClaimDocumentSection;
