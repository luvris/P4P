import { useState, useEffect, useRef, useCallback, useMemo, memo } from 'react';
import { X, Save, UserPlus } from 'lucide-react';

/**
 * Initial form state
 */
const INITIAL_FORM = {
    prefix_id: '',
    first_name: '',
    last_name: '',
    citizen_id: '',
    position_number: '',
    salary: '',
    employee_type_id: '',
    position_id: '',
    duty_id: '',
    group_id: '',
    work_id: '',
    status_id: '',
    bank_account: '',
    note: '',
};

/**
 * Field — ย้ายออกนอก component หลัก
 */
const Field = memo(({ label, name, required, error, children }) => (
    <div>
        <label className="block text-xs font-medium text-gray-700 mb-1">
            {label}
            {required && <span className="text-red-500 ml-0.5">*</span>}
        </label>
        {children}
        {error && <p className="mt-1 text-xs text-red-600">{error[0]}</p>}
    </div>
));
Field.displayName = 'Field';

const AddEmployeeDrawer = ({ open, onClose, lookups = {}, onSubmit }) => {
    const [form, setForm] = useState(INITIAL_FORM);
    const [errors, setErrors] = useState({});
    const [submitting, setSubmitting] = useState(false);
    const [globalError, setGlobalError] = useState('');

    const onCloseRef = useRef(onClose);
    useEffect(() => {
        onCloseRef.current = onClose;
    }, [onClose]);

    // Reset form เมื่อเปิด drawer ใหม่
    useEffect(() => {
        if (open) {
            setForm(INITIAL_FORM);
            setErrors({});
            setGlobalError('');
        }
    }, [open]);

    // ปิดด้วย ESC
    useEffect(() => {
        if (!open) return;
        const handler = (e) => {
            if (e.key === 'Escape') onCloseRef.current?.();
        };
        window.addEventListener('keydown', handler);
        return () => window.removeEventListener('keydown', handler);
    }, [open]);

    // ============================================
    // Dependent dropdowns
    // ============================================
    const duties = lookups.duties || [];

    const availableGroups = useMemo(() => {
        if (!form.duty_id) return [];
        const duty = duties.find((d) => String(d.id) === String(form.duty_id));
        return duty?.groups || [];
    }, [duties, form.duty_id]);

    const availableWorks = useMemo(() => {
        if (!form.group_id) return [];
        const group = availableGroups.find((g) => String(g.id) === String(form.group_id));
        return group?.works || [];
    }, [availableGroups, form.group_id]);

    // ============================================
    // setField + reset ลูกเมื่อเปลี่ยน parent
    // ============================================
    const setField = useCallback((key, value) => {
        setForm((prev) => {
            const next = { ...prev, [key]: value };

            // Reset ลูก
            if (key === 'duty_id') {
                next.group_id = '';
                next.work_id = '';
            }
            if (key === 'group_id') {
                next.work_id = '';
            }

            return next;
        });
        setErrors((prev) => ({ ...prev, [key]: undefined }));
        setGlobalError('');
    }, []);

    const handleSubmit = async (e) => {
        e.preventDefault();
        setSubmitting(true);
        setErrors({});
        setGlobalError('');

        const payload = {
            ...form,
            salary: form.salary === '' ? null : Number(form.salary),
            position_id: form.position_id === '' ? null : form.position_id,
        };

        const result = await onSubmit?.(payload);

        setSubmitting(false);

        if (result?.success) {
            onClose?.();
        } else {
            setErrors(result?.errors || {});
            setGlobalError(result?.error || 'ไม่สามารถบันทึกข้อมูลได้');
        }
    };

    if (!open) return null;

    const inputCls = (name) =>
        `w-full px-3 py-2 text-sm border rounded-lg transition-colors
     focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-transparent
     ${errors[name] ? 'border-red-400 bg-red-50' : 'border-gray-300'}
     ${name === 'group_id' && !form.duty_id ? 'bg-gray-50 text-gray-400 cursor-not-allowed' : ''}
     ${name === 'work_id' && !form.group_id ? 'bg-gray-50 text-gray-400 cursor-not-allowed' : ''}
     ${name !== 'group_id' && name !== 'work_id' ? 'bg-white' : ''}`;

    return (
        <>
            <div className="fixed inset-0 bg-black/30 z-40" onClick={onClose} />

            <aside className="fixed top-0 right-0 h-full w-full max-w-md bg-white shadow-2xl z-50 flex flex-col">
                {/* Header */}
                <div className="flex items-center justify-between px-5 py-4 border-b border-gray-200">
                    <div className="flex items-center gap-2">
                        <UserPlus className="w-5 h-5 text-amber-600" />
                        <h2 className="text-base font-semibold text-gray-800">เพิ่มบุคลากร</h2>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        className="p-1.5 rounded-lg hover:bg-gray-100 text-gray-500"
                        aria-label="ปิด"
                    >
                        <X className="w-5 h-5" />
                    </button>
                </div>

                {/* Body */}
                <form
                    id="add-employee-form"
                    onSubmit={handleSubmit}
                    className="flex-1 overflow-y-auto px-5 py-4 space-y-4"
                >
                    {globalError && (
                        <div className="rounded-lg bg-red-50 border border-red-200 px-3 py-2 text-xs text-red-700">
                            {globalError}
                        </div>
                    )}

                    {/* คำนำหน้า */}
                    <Field label="คำนำหน้า" name="prefix_id" required error={errors.prefix_id}>
                        <select
                            value={form.prefix_id}
                            onChange={(e) => setField('prefix_id', e.target.value)}
                            className={inputCls('prefix_id')}
                        >
                            <option value="">-- เลือกคำนำหน้า --</option>
                            {lookups.prefixes?.map((p) => (
                                <option key={p.id} value={p.id}>{p.name}</option>
                            ))}
                        </select>
                    </Field>

                    {/* ชื่อ */}
                    <Field label="ชื่อ" name="first_name" required error={errors.first_name}>
                        <input
                            type="text"
                            value={form.first_name}
                            onChange={(e) => setField('first_name', e.target.value)}
                            placeholder="กรอกชื่อ"
                            className={inputCls('first_name')}
                        />
                    </Field>

                    {/* นามสกุล */}
                    <Field label="นามสกุล" name="last_name" required error={errors.last_name}>
                        <input
                            type="text"
                            value={form.last_name}
                            onChange={(e) => setField('last_name', e.target.value)}
                            placeholder="กรอกนามสกุล"
                            className={inputCls('last_name')}
                        />
                    </Field>

                    {/* เลขบัตรประชาชน */}
                    <Field label="เลขบัตรประชาชน" name="citizen_id" required error={errors.citizen_id}>
                        <input
                            type="text"
                            value={form.citizen_id}
                            onChange={(e) => setField('citizen_id', e.target.value.replace(/\D/g, '').slice(0, 13))}
                            placeholder="กรอกเลขบัตรประชาชน 13 หลัก"
                            maxLength={13}
                            className={inputCls('citizen_id')}
                        />
                    </Field>

                    {/* เลขที่ตำแหน่ง */}
                    <Field label="เลขที่ตำแหน่ง" name="position_number" error={errors.position_number}>
                        <input
                            type="text"
                            value={form.position_number}
                            onChange={(e) => setField('position_number', e.target.value)}
                            placeholder="ระบุเลขที่ตำแหน่ง"
                            className={inputCls('position_number')}
                        />
                    </Field>

                    {/* เงินเดือน */}
                    <Field label="เงินเดือน" name="salary" error={errors.salary}>
                        <input
                            type="number"
                            value={form.salary}
                            onChange={(e) => setField('salary', e.target.value)}
                            placeholder="ระบุจำนวนเงินเดือน"
                            min="0"
                            step="0.01"
                            className={inputCls('salary')}
                        />
                    </Field>

                    {/* ประเภทบุคลากร */}
                    <Field label="ประเภทบุคลากร" name="employee_type_id" required error={errors.employee_type_id}>
                        <select
                            value={form.employee_type_id}
                            onChange={(e) => setField('employee_type_id', e.target.value)}
                            className={inputCls('employee_type_id')}
                        >
                            <option value="">-- เลือกประเภทบุคลากร --</option>
                            {lookups.employee_types?.map((t) => (
                                <option key={t.id} value={t.id}>{t.name}</option>
                            ))}
                        </select>
                    </Field>

                    {/* ตำแหน่ง */}
                    <Field label="ตำแหน่ง" name="position_id" error={errors.position_id}>
                        <select
                            value={form.position_id}
                            onChange={(e) => setField('position_id', e.target.value)}
                            className={inputCls('position_id')}
                        >
                            <option value="">-- เลือกตำแหน่ง --</option>
                            {lookups.positions?.map((p) => (
                                <option key={p.id} value={p.id}>{p.name}</option>
                            ))}
                        </select>
                    </Field>

                    {/* ภารกิจ */}
                    <Field label="ภารกิจ" name="duty_id" required error={errors.duty_id}>
                        <select
                            value={form.duty_id}
                            onChange={(e) => setField('duty_id', e.target.value)}
                            className={inputCls('duty_id')}
                        >
                            <option value="">-- เลือกภารกิจ --</option>
                            {duties.map((d) => (
                                <option key={d.id} value={d.id}>{d.name}</option>
                            ))}
                        </select>
                    </Field>

                    {/* กลุ่มงาน — dependent */}
                    <Field label="กลุ่มงาน" name="group_id" required error={errors.group_id}>
                        <select
                            value={form.group_id}
                            onChange={(e) => setField('group_id', e.target.value)}
                            disabled={!form.duty_id}
                            className={inputCls('group_id')}
                        >
                            <option value="">
                                {!form.duty_id ? '-- เลือกภารกิจก่อน --' : '-- เลือกกลุ่มงาน --'}
                            </option>
                            {availableGroups.map((g) => (
                                <option key={g.id} value={g.id}>{g.name}</option>
                            ))}
                        </select>
                    </Field>

                    {/* งาน — dependent */}
                    <Field label="งาน" name="work_id" required error={errors.work_id}>
                        <select
                            value={form.work_id}
                            onChange={(e) => setField('work_id', e.target.value)}
                            disabled={!form.group_id}
                            className={inputCls('work_id')}
                        >
                            <option value="">
                                {!form.group_id ? '-- เลือกกลุ่มงานก่อน --' : '-- เลือกงาน --'}
                            </option>
                            {availableWorks.map((w) => (
                                <option key={w.id} value={w.id}>{w.name}</option>
                            ))}
                        </select>
                    </Field>

                    {/* สถานะ */}
                    <Field label="สถานะ" name="status_id" required error={errors.status_id}>
                        <select
                            value={form.status_id}
                            onChange={(e) => setField('status_id', e.target.value)}
                            className={inputCls('status_id')}
                        >
                            <option value="">-- เลือกสถานะ --</option>
                            {lookups.employee_statuses?.map((s) => (
                                <option key={s.id} value={s.id}>{s.name}</option>
                            ))}
                        </select>
                    </Field>

                    {/* เลขที่บัญชี */}
                    <Field label="เลขที่บัญชี" name="bank_account" error={errors.bank_account}>
                        <input
                            type="text"
                            value={form.bank_account}
                            onChange={(e) => setField('bank_account', e.target.value)}
                            placeholder="ระบุเลขที่บัญชี"
                            className={inputCls('bank_account')}
                        />
                    </Field>

                    {/* หมายเหตุ */}
                    <Field label="หมายเหตุ" name="note" error={errors.note}>
                        <textarea
                            value={form.note}
                            onChange={(e) => setField('note', e.target.value)}
                            placeholder="ระบุหมายเหตุ..."
                            rows={3}
                            className={`${inputCls('note')} resize-none`}
                        />
                    </Field>
                </form>

                {/* Footer */}
                <div className="border-t border-gray-200 px-5 py-3 flex gap-3 bg-gray-50">
                    <button
                        type="button"
                        onClick={onClose}
                        disabled={submitting}
                        className="flex-1 px-4 py-2.5 text-sm font-medium border border-gray-300 rounded-lg hover:bg-gray-100 disabled:opacity-50"
                    >
                        ยกเลิก
                    </button>
                    <button
                        type="submit"
                        form="add-employee-form"
                        disabled={submitting}
                        className="flex-1 flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-medium text-white bg-amber-600 hover:bg-amber-700 rounded-lg disabled:opacity-50"
                    >
                        {submitting ? (
                            <>
                                <span className="w-4 h-4 border-2 border-white/40 border-t-white rounded-full animate-spin" />
                                กำลังบันทึก...
                            </>
                        ) : (
                            <>
                                <Save className="w-4 h-4" />
                                บันทึก
                            </>
                        )}
                    </button>
                </div>
            </aside>
        </>
    );
};

export default AddEmployeeDrawer;