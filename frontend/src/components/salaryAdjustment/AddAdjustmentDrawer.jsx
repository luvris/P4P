import { useState, useEffect, useRef, useMemo, useCallback } from 'react';
import { X, Save, TrendingUp, Search } from 'lucide-react';
import { employeeService } from '../../services/employeeService';
import { ADJUSTMENT_TYPES } from '../../services/salaryAdjustmentService';
import useDebounce from '../../hooks/useDebounce';
import { formatCurrency } from '../../utils/format';

const todayStr = () => {
    const d = new Date();
    const off = d.getTimezoneOffset();
    const local = new Date(d.getTime() - off * 60 * 1000);
    return local.toISOString().slice(0, 10);
};

const INITIAL_FORM = {
    employee_id: '',
    old_salary: '',
    new_salary: '',
    adjustment_date: todayStr(),
    adjustment_type: 'ครบ 6 เดือน',
    note: '',
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

    const [employees, setEmployees] = useState([]);
    const [empLoading, setEmpLoading] = useState(false);
    const [empSearch, setEmpSearch] = useState('');
    const debouncedEmpSearch = useDebounce(empSearch, 400);

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
            setEmpSearch('');
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

    // โหลดรายชื่อพนักงาน (ค้นหาได้)
    useEffect(() => {
        if (!open) return;
        let cancelled = false;
        const fetchEmp = async () => {
            setEmpLoading(true);
            try {
                const params = { per_page: 50 };
                if (debouncedEmpSearch) params.search = debouncedEmpSearch;
                const res = await employeeService.getEmployees(params);
                if (!cancelled) setEmployees(res.data || []);
            } catch (err) {
                console.error('Failed to load employees:', err);
                if (!cancelled) setEmployees([]);
            } finally {
                if (!cancelled) setEmpLoading(false);
            }
        };
        fetchEmp();
        return () => {
            cancelled = true;
        };
    }, [open, debouncedEmpSearch]);

    const selectedEmployee = useMemo(
        () => employees.find((e) => String(e.id) === String(form.employee_id)),
        [employees, form.employee_id],
    );

    const setField = useCallback((key, value) => {
        setForm((prev) => ({ ...prev, [key]: value }));
        setErrors((prev) => ({ ...prev, [key]: undefined }));
        setGlobalError('');
    }, []);

    const handleSelectEmployee = useCallback(
        (e) => {
            const id = e.target.value;
            const emp = employees.find((x) => String(x.id) === String(id));
            setForm((prev) => ({
                ...prev,
                employee_id: id,
                old_salary: emp?.salary != null ? String(emp.salary) : prev.old_salary,
            }));
            setErrors((prev) => ({ ...prev, employee_id: undefined }));
            setGlobalError('');
        },
        [employees],
    );

    const increaseAmount = useMemo(() => {
        const o = Number(form.old_salary);
        const n = Number(form.new_salary);
        if (form.old_salary === '' || form.new_salary === '' || Number.isNaN(o) || Number.isNaN(n)) {
            return null;
        }
        return n - o;
    }, [form.old_salary, form.new_salary]);

    const increasePercent = useMemo(() => {
        const o = Number(form.old_salary);
        if (increaseAmount === null || o === 0) return null;
        return (increaseAmount / o) * 100;
    }, [increaseAmount, form.old_salary]);

    const handleSubmit = async (e) => {
        e.preventDefault();
        setSubmitting(true);
        setErrors({});
        setGlobalError('');

        const payload = {
            employee_id: form.employee_id,
            old_salary: form.old_salary === '' ? null : Number(form.old_salary),
            new_salary: form.new_salary === '' ? null : Number(form.new_salary),
            adjustment_date: form.adjustment_date,
            adjustment_type: form.adjustment_type || null,
            note: form.note || null,
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

    const increaseDisplay =
        increaseAmount === null
            ? null
            : {
                  amount: formatCurrency(increaseAmount),
                  percent:
                      increasePercent === null
                          ? '-'
                          : `${increasePercent >= 0 ? '+' : ''}${increasePercent.toFixed(2)}%`,
                  positive: increaseAmount > 0,
                  negative: increaseAmount < 0,
              };

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

                    {/* เลือกพนักงาน */}
                    <Field label="เลือกบุคลากร" required error={errors.employee_id}>
                        <div className="relative">
                            <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
                            <input
                                type="text"
                                value={empSearch}
                                onChange={(e) => setEmpSearch(e.target.value)}
                                placeholder="ค้นหาชื่อ / เลขบัตรประชาชน..."
                                className={`${inputCls(errors.employee_id)} pl-9`}
                                autoComplete="off"
                            />
                        </div>
                        <select
                            value={form.employee_id}
                            onChange={handleSelectEmployee}
                            className={`${inputCls(errors.employee_id)} mt-2`}
                        >
                            <option value="">
                                {empLoading ? 'กำลังโหลด...' : '-- เลือกรายชื่อบุคลากร --'}
                            </option>
                            {employees.map((emp) => (
                                <option key={emp.id} value={emp.id}>
                                    {emp.full_name || `${emp.first_name} ${emp.last_name}`}
                                    {emp.citizen_id ? ` (${emp.citizen_id})` : ''}
                                </option>
                            ))}
                        </select>
                        {selectedEmployee && (
                            <p className="mt-1 text-xs text-gray-500">
                                เงินเดือนปัจจุบัน: {formatCurrency(selectedEmployee.salary)} บาท
                            </p>
                        )}
                    </Field>

                    {/* เงินเดือนเก่า */}
                    <Field label="เงินเดือนเก่า" required error={errors.old_salary}>
                        <input
                            type="number"
                            value={form.old_salary}
                            onChange={(e) => setField('old_salary', e.target.value)}
                            placeholder="ระบุเงินเดือนเดิม"
                            min="0"
                            step="0.01"
                            className={inputCls(errors.old_salary)}
                        />
                    </Field>

                    {/* เงินเดือนใหม่ */}
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

                    {/* สรุปยอดปรับ */}
                    {increaseDisplay && (
                        <div
                            className={`rounded-lg px-3 py-2 text-sm border ${
                                increaseDisplay.negative
                                    ? 'bg-red-50 border-red-200 text-red-700'
                                    : 'bg-emerald-50 border-emerald-200 text-emerald-700'
                            }`}
                        >
                            ปรับ{' '}
                            <span className="font-semibold">
                                {increaseDisplay.amount} บาท ({increaseDisplay.percent})
                            </span>
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

export default AddAdjustmentDrawer;