import { useState, useEffect, useRef, useMemo, useCallback } from 'react';
import { X, Save, TrendingUp } from 'lucide-react';
import { ADJUSTMENT_TYPES } from '../../services/salaryAdjustmentService';
import { formatCurrency } from '../../utils/format';
import EmployeeCombobox from './EmployeeCombobox';

const todayStr = () => {
    const d = new Date();
    const off = d.getTimezoneOffset();
    const local = new Date(d.getTime() - off * 60 * 1000);
    return local.toISOString().slice(0, 10);
};

const INITIAL_FORM = {
    employee_id: '',
    expected_old_salary: '',
    new_salary: '',
    adjustment_date: todayStr(),
    adjustment_type: 'ครบ 6 เดือน',
    note: '',
};

// ที่มาของฐานเงินเดือนที่แสดงให้ HR เห็น
const BASE_SOURCE_LABELS = {
    salary: 'เงินเดือนปัจจุบันในทะเบียน',
    payroll_total_income: 'ฐานเริ่มต้นจากยอดรวมรายรับล่าสุดในไฟล์เงินเดือน',
};

/**
 * เงินเดือนก่อนปรับ = salary ?? latest_payroll_income (ค่า 0 เป็นค่าจริง — ห้ามใช้ truthy check)
 * คืนทั้งค่าที่แสดงและแหล่งที่มา
 */
const baseFromEmployee = (emp) => {
    if (!emp) return { value: '', source: null };
    if (emp.salary != null && emp.salary !== '') {
        return { value: String(emp.salary), source: 'salary' };
    }
    if (emp.latest_payroll_income != null && emp.latest_payroll_income !== '') {
        return { value: String(emp.latest_payroll_income), source: 'payroll_total_income' };
    }
    return { value: '', source: null };
};

// แสดงส่วนต่าง — ติดลบมี "-" มากกว่าศูนย์มี "+" ศูนย์ไม่มีเครื่องหมาย
const signedCurrency = (value) => {
    const sign = value > 0 ? '+' : value < 0 ? '-' : '';
    return `${sign}${formatCurrency(Math.abs(value))}`;
};

const Field = ({ label, required, error, children }) => (
    <div>
        <label className="block text-xs font-medium text-gray-700 mb-1">
            {label}
            {required && <span className="text-red-500 ml-0.5">*</span>}
        </label>
        {children}
        {error && <p className="mt-1 text-xs text-red-600">{error[0]}</p>}
    </div>
);

const inputCls = (error) =>
    `w-full px-3 py-2 text-sm border rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-transparent ${
        error ? 'border-red-400 bg-red-50' : 'border-gray-300 bg-white'
    }`;

const AddAdjustmentDrawer = ({ open, onClose, onSubmit }) => {
    const [form, setForm] = useState(INITIAL_FORM);
    const [errors, setErrors] = useState({});
    const [globalError, setGlobalError] = useState('');
    const [submitting, setSubmitting] = useState(false);

    const [selectedEmployee, setSelectedEmployee] = useState(null);
    // แหล่งที่มาของฐานที่แสดง ('salary' | 'latest_salary' | null)
    const [baseSource, setBaseSource] = useState(null);

    // id ของพนักงานที่เลือกอยู่จริง ณ ตอนนี้ — ใช้ตรวจว่า 409 ที่กลับมาเป็นของคนนี้หรือไม่
    const employeeIdRef = useRef('');

    const onCloseRef = useRef(onClose);
    useEffect(() => {
        onCloseRef.current = onClose;
    }, [onClose]);

    // Reset form เมื่อเปิด drawer
    useEffect(() => {
        if (open) {
            setForm(INITIAL_FORM);
            setErrors({});
            setGlobalError('');
            setSelectedEmployee(null);
            setBaseSource(null);
            employeeIdRef.current = '';
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

    const setField = useCallback((key, value) => {
        setForm((prev) => ({ ...prev, [key]: value }));
        setErrors((prev) => ({ ...prev, [key]: undefined }));
        setGlobalError('');
    }, []);

    // เลือก/เปลี่ยน/ล้างพนักงาน — ฐานและแหล่งที่มาถูกอัปเดต/ล้างทันที ห้ามค้างของคนก่อน
    const applyEmployee = useCallback((emp) => {
        const { value, source } = baseFromEmployee(emp);
        employeeIdRef.current = emp ? String(emp.id) : '';
        setForm((prev) => ({
            ...prev,
            employee_id: emp ? emp.id : '',
            expected_old_salary: value,
        }));
        setSelectedEmployee(emp ?? null);
        setBaseSource(emp ? source : null);
        setErrors((prev) => ({ ...prev, employee_id: undefined }));
        setGlobalError('');
    }, []);

    const handleSelectEmployee = useCallback(
        (emp) => {
            if (!emp) return;
            applyEmployee(emp);
        },
        [applyEmployee],
    );

    const handleClearEmployee = useCallback(() => {
        applyEmployee(null);
    }, [applyEmployee]);

    // คำนวณสดระหว่างกรอก — ค่าว่างไม่ถูกตีความเป็น 0
    const baseAmount = useMemo(() => {
        if (form.expected_old_salary === '') return null;
        const n = Number(form.expected_old_salary);
        return Number.isNaN(n) ? null : n;
    }, [form.expected_old_salary]);

    const newAmount = useMemo(() => {
        if (form.new_salary === '') return null;
        const n = Number(form.new_salary);
        return Number.isNaN(n) ? null : n;
    }, [form.new_salary]);

    const increaseAmount = baseAmount !== null && newAmount !== null ? newAmount - baseAmount : null;

    const hasBase = form.employee_id !== '' && form.expected_old_salary !== '';

    const handleSubmit = async (e) => {
        e.preventDefault();

        // กันการ submit ตอนไม่มีฐาน (ปุ่มปิดอยู่แล้ว แต่กันเคส Enter ในฟอร์ม)
        if (form.employee_id !== '' && form.expected_old_salary === '') {
            setGlobalError('ไม่มีข้อมูลฐานเงินเดือน');
            return;
        }

        setSubmitting(true);
        setErrors({});
        setGlobalError('');

        const payload = {
            employee_id: form.employee_id,
            new_salary: form.new_salary === '' ? null : Number(form.new_salary),
            expected_old_salary: form.expected_old_salary === '' ? null : Number(form.expected_old_salary),
            adjustment_date: form.adjustment_date,
            adjustment_type: form.adjustment_type || null,
            note: form.note || null,
        };
        const submittedEmployeeId = String(form.employee_id);

        const result = await onSubmit?.(payload);
        setSubmitting(false);

        if (result?.success) {
            onClose?.();
            return;
        }

        // 409 SALARY_BASE_CHANGED — อัปเดตฐานให้ตรวจใหม่ แล้วรอให้ HR กดยืนยันเอง (ไม่ส่งซ้ำอัตโนมัติ)
        if (result?.conflict?.code === 'SALARY_BASE_CHANGED') {
            const conflict = result.conflict;

            // response ไม่ใช่ของพนักงานที่เลือกอยู่ → ห้ามนำมาทับฟอร์มปัจจุบัน
            if (String(employeeIdRef.current) !== submittedEmployeeId) {
                setGlobalError('ข้อมูลบุคลากรที่เลือกเปลี่ยนแปลงระหว่างบันทึก กรุณาตรวจสอบและลองใหม่');
                return;
            }

            const data = conflict.data || {};
            const latestBase = data.base ?? '';

            setForm((prev) => ({
                ...prev,
                expected_old_salary: latestBase === '' ? '' : String(Number(latestBase)),
            }));
            setBaseSource(data.base_source ?? null);
            setSelectedEmployee((prev) =>
                prev
                    ? {
                        ...prev,
                        salary: data.salary ?? null,
                        latest_payroll_income:
                            data.latest_payroll_income ?? prev.latest_payroll_income ?? null,
                    }
                    : prev,
            );
            setGlobalError(
                conflict.message || 'เงินเดือนของพนักงานถูกเปลี่ยนระหว่างทำรายการ กรุณาตรวจสอบยอดใหม่และยืนยันอีกครั้ง',
            );
            return;
        }

        setErrors(result?.errors || {});
        setGlobalError(result?.error || 'ไม่สามารถบันทึกข้อมูลได้');
    };

    if (!open) return null;

    return (
        <>
            <div className="fixed inset-0 bg-black/30 z-40" onClick={onClose} />

            <aside className="fixed top-0 right-0 h-full w-full max-w-md bg-white shadow-2xl z-50 flex flex-col">
                {/* Header */}
                <div className="flex items-center justify-between px-5 py-4 border-b border-gray-200">
                    <div className="flex items-center gap-2">
                        <TrendingUp className="w-5 h-5 text-amber-600" />
                        <h2 className="text-base font-semibold text-gray-800">ปรับฐานเงินเดือน</h2>
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
                    id="add-adjustment-form"
                    onSubmit={handleSubmit}
                    className="flex-1 overflow-y-auto px-5 py-4 space-y-4"
                >
                    {globalError && (
                        <div className="rounded-lg bg-red-50 border border-red-200 px-3 py-2 text-xs text-red-700">
                            {globalError}
                        </div>
                    )}

                    {/* เลือกบุคลากร — พิมพ์ชื่อ/เลขบัตรประชาชนแล้วรายการเด้งขึ้นทันที */}
                    <Field label="เลือกบุคลากร" required error={errors.employee_id}>
                        <EmployeeCombobox
                            value={selectedEmployee}
                            onSelect={handleSelectEmployee}
                            onClear={handleClearEmployee}
                            error={errors.employee_id}
                        />
                    </Field>

                    {/* เงินเดือนก่อนปรับ — ฐานจากระบบ (readOnly) HR ดูอย่างเดียว */}
                    <Field label="เงินเดือนก่อนปรับ" required error={errors.expected_old_salary}>
                        <input
                            type="number"
                            value={form.expected_old_salary}
                            readOnly
                            placeholder={
                                selectedEmployee ? 'ไม่มีข้อมูลฐานเงินเดือน' : 'เลือกบุคลากรเพื่อดูฐานเงินเดือน'
                            }
                            min="0"
                            step="0.01"
                            className={`${inputCls(errors.expected_old_salary)} bg-gray-50 text-gray-700 cursor-default`}
                        />
                        {selectedEmployee && baseSource && (
                            <p className="mt-1 text-xs text-gray-500">{BASE_SOURCE_LABELS[baseSource]}</p>
                        )}
                        {selectedEmployee && !baseSource && (
                            <p className="mt-1 text-xs text-red-600">ไม่มีข้อมูลฐานเงินเดือน</p>
                        )}
                    </Field>

                    {/* เงินเดือนใหม่ — HR กรอกเฉพาะช่องนี้ */}
                    <Field label="เงินเดือนใหม่" required error={errors.new_salary}>
                        <input
                            type="number"
                            value={form.new_salary}
                            onChange={(e) => setField('new_salary', e.target.value)}
                            placeholder="ระบุเงินเดือนใหม่"
                            min="0"
                            step="0.01"
                            className={inputCls(errors.new_salary)}
                        />
                    </Field>

                    {/* สรุปยอดปรับ — คำนวณสด */}
                    {increaseAmount !== null && (
                        <div
                            className={`rounded-lg px-3 py-2 text-sm border ${
                                increaseAmount < 0
                                    ? 'bg-red-50 border-red-200 text-red-700'
                                    : 'bg-emerald-50 border-emerald-200 text-emerald-700'
                            }`}
                        >
                            <div>
                                ปรับ{' '}
                                <span className="font-semibold">{signedCurrency(increaseAmount)} บาท</span>
                            </div>
                        </div>
                    )}

                    {/* วันที่ปรับ */}
                    <Field label="วันที่ปรับฐานเงินเดือน" required error={errors.adjustment_date}>
                        <input
                            type="date"
                            value={form.adjustment_date}
                            onChange={(e) => setField('adjustment_date', e.target.value)}
                            className={inputCls(errors.adjustment_date)}
                        />
                    </Field>

                    {/* ประเภทการปรับ */}
                    <Field label="ประเภทการปรับ" error={errors.adjustment_type}>
                        <select
                            value={form.adjustment_type}
                            onChange={(e) => setField('adjustment_type', e.target.value)}
                            className={inputCls(errors.adjustment_type)}
                        >
                            {ADJUSTMENT_TYPES.map((t) => (
                                <option key={t.value} value={t.value}>
                                    {t.label}
                                </option>
                            ))}
                        </select>
                    </Field>

                    {/* หมายเหตุ */}
                    <Field label="หมายเหตุ" error={errors.note}>
                        <textarea
                            value={form.note}
                            onChange={(e) => setField('note', e.target.value)}
                            placeholder="ระบุหมายเหตุ..."
                            rows={3}
                            className={`${inputCls(errors.note)} resize-none`}
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
                        form="add-adjustment-form"
                        disabled={submitting || !hasBase}
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

export default AddAdjustmentDrawer;