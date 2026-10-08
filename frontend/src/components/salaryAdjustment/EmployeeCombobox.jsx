import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Search, X, Loader2, AlertTriangle, UserCheck } from 'lucide-react';
import { employeeService } from '../../services/employeeService';
import useDebounce from '../../hooks/useDebounce';
import { formatCurrency } from '../../utils/format';

/**
 * ไฮไลต์ส่วนของข้อความที่ตรงกับคำค้น
 */
const Highlight = ({ text, term }) => {
    const value = text ?? '';

    if (!term) return <>{value || '-'}</>;

    const index = value.toLowerCase().indexOf(term.toLowerCase());
    if (index === -1) return <>{value}</>;

    return (
        <>
            {value.slice(0, index)}
            <mark className="bg-amber-100 text-amber-800 rounded px-0.5">
                {value.slice(index, index + term.length)}
            </mark>
            {value.slice(index + term.length)}
        </>
    );
};

/**
 * เงินเดือนปัจจุบันของพนักงาน = salary (ฐานที่ปรับแล้ว) ?? latest_payroll_income
 * (ยอดรวมรายรับทั้งหมดรายบุคคลจากไฟล์งวดล่าสุด)
 * ใช้ null check ไม่ใช่ truthy check — ค่า 0 เป็นค่าจริงห้ามถูกข้าม
 */
const currentSalaryOf = (emp) => {
    if (emp?.salary != null && emp?.salary !== '') return emp.salary;
    if (emp?.latest_payroll_income != null && emp?.latest_payroll_income !== '') {
        return emp.latest_payroll_income;
    }
    return null;
};

/**
 * เลือกบุคลากรแบบพิมพ์แล้วเด้ง
 *
 * พิมพ์ชื่อหรือเลขบัตรประชาชนแล้วรายการเด้งขึ้นทันที
 * ถ้าพิมพ์เป็นตัวเลขแล้วไม่มีเลขที่ตรง ระบบจะแนบเลขที่ "ใกล้เคียง" พร้อมป้ายเตือนให้ตรวจสอบก่อนเลือก
 */
const EmployeeCombobox = ({ value, onSelect, onClear, error, disabled = false }) => {
    const [open, setOpen] = useState(false);
    const [term, setTerm] = useState('');
    const [results, setResults] = useState([]);
    const [near, setNear] = useState(false);
    const [loading, setLoading] = useState(false);
    const [active, setActive] = useState(-1);
    const [failed, setFailed] = useState('');

    const boxRef = useRef(null);
    const inputRef = useRef(null);
    const debouncedTerm = useDebounce(term, 250);

    // ค้นหาเฉพาะตัวเลขที่ผู้ใช้พิมพ์ — ใช้ไฮไลต์เลขบัตรประชาชน
    const digitTerm = useMemo(() => term.replace(/\D/g, ''), [term]);

    const fetchSuggestions = useCallback(async (q) => {
        setLoading(true);
        setFailed('');
        try {
            const res = await employeeService.suggestEmployees({ q: q || undefined, limit: 20 });
            const list = res.data || [];
            setResults(list);
            setNear(Boolean(res.meta?.near));
            setActive(list.length > 0 ? 0 : -1); // เลือกรายการแรกไว้ กด Enter ได้ทันที
        } catch (err) {
            setResults([]);
            setNear(false);
            setActive(-1);
            setFailed(err.response?.data?.message || 'ค้นหาบุคลากรไม่สำเร็จ');
        } finally {
            setLoading(false);
        }
    }, []);

    // โหลดรายการทุกครั้งที่เปิด dropdown หรือคำค้นเปลี่ยน
    useEffect(() => {
        if (!open) return;
        fetchSuggestions(debouncedTerm);
    }, [open, debouncedTerm, fetchSuggestions]);

    // ปิดเมื่อคลิกนอกกล่อง
    useEffect(() => {
        if (!open) return undefined;
        const handleClickOutside = (e) => {
            if (!boxRef.current?.contains(e.target)) setOpen(false);
        };
        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, [open]);

    const handlePick = useCallback(
        (employee) => {
            onSelect?.(employee);
            setOpen(false);
            setTerm('');
            setFailed('');
            inputRef.current?.blur();
        },
        [onSelect],
    );

    const handleClear = useCallback(
        (e) => {
            e?.stopPropagation();
            onClear?.();
            setTerm('');
            setOpen(true);
            requestAnimationFrame(() => inputRef.current?.focus());
        },
        [onClear],
    );

    const handleKeyDown = (e) => {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (!open) setOpen(true);
            setActive((prev) => (results.length === 0 ? -1 : (prev + 1) % results.length));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActive((prev) => (results.length === 0 ? -1 : (prev - 1 + results.length) % results.length));
        } else if (e.key === 'Enter') {
            if (open && active >= 0 && results[active]) {
                e.preventDefault();
                handlePick(results[active]);
            }
        } else if (e.key === 'Escape') {
            setOpen(false);
        }
    };

    const borderCls = error ? 'border-red-400 bg-red-50' : 'border-gray-300 bg-white';

    // เลือกแล้ว → แสดงสรุปผู้ที่เลือก พร้อมปุ่มเปลี่ยน/ล้าง
    if (value) {
        const currentSalary = currentSalaryOf(value);

        return (
            <div className="rounded-lg border border-amber-300 bg-amber-50/60 px-3 py-2">
                <div className="flex items-start gap-2">
                    <UserCheck className="mt-0.5 h-4 w-4 shrink-0 text-amber-600" />
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-medium text-gray-800">
                            {value.full_name || `${value.first_name} ${value.last_name}`}
                        </p>
                        <p className="truncate text-xs text-gray-600">
                            เลขบัตรประชาชน: {value.citizen_id || '-'}
                            {value.position_name ? ` · ${value.position_name}` : ''}
                        </p>
                        {currentSalary != null && (
                            <p className="mt-0.5 text-xs text-gray-500">
                                เงินเดือนปัจจุบัน: {formatCurrency(currentSalary)} บาท
                            </p>
                        )}
                    </div>
                    <div className="flex shrink-0 items-center gap-1">
                        <button
                            type="button"
                            onClick={handleClear}
                            className="rounded-md border border-amber-300 bg-white px-2 py-1 text-xs text-amber-700 hover:bg-amber-100"
                        >
                            เปลี่ยน
                        </button>
                        <button
                            type="button"
                            onClick={handleClear}
                            className="rounded-md p-1 text-gray-500 hover:bg-amber-100"
                            aria-label="ล้างบุคลากรที่เลือก"
                        >
                            <X className="h-4 w-4" />
                        </button>
                    </div>
                </div>
            </div>
        );
    }

    return (
        <div className="relative" ref={boxRef}>
            <div className="relative">
                <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                <input
                    ref={inputRef}
                    type="text"
                    role="combobox"
                    aria-expanded={open}
                    aria-autocomplete="list"
                    value={term}
                    disabled={disabled}
                    autoComplete="off"
                    onChange={(e) => {
                        setTerm(e.target.value);
                        setOpen(true);
                    }}
                    onFocus={() => setOpen(true)}
                    onKeyDown={handleKeyDown}
                    placeholder="พิมพ์ชื่อ หรือเลขบัตรประชาชน..."
                    className={`w-full rounded-lg border py-2 pl-9 pr-9 text-sm transition-colors focus:border-transparent focus:outline-none focus:ring-2 focus:ring-amber-500 ${borderCls} disabled:opacity-60`}
                />
                {loading && (
                    <Loader2 className="absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 animate-spin text-amber-500" />
                )}
            </div>

            {open && (
                <div className="absolute z-20 mt-1 w-full overflow-hidden rounded-lg border border-gray-200 bg-white shadow-lg">
                    {near && (
                        <div className="flex items-start gap-2 border-b border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                            <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                            <span>ไม่พบเลขที่ตรงทั้งหมด — รายการด้านล่างเป็นเลขบัตรประชาชนที่ใกล้เคียง กรุณาตรวจสอบก่อนเลือก</span>
                        </div>
                    )}

                    {failed && (
                        <p className="px-3 py-3 text-xs text-red-600">{failed}</p>
                    )}

                    {!failed && !loading && results.length === 0 && (
                        <p className="px-3 py-3 text-center text-xs text-gray-500">
                            ไม่พบบุคลากรที่ตรงกับ “{term}”
                        </p>
                    )}

                    {results.length > 0 && (
                        <ul role="listbox" className="max-h-60 overflow-y-auto py-1">
                            {results.map((emp, index) => {
                                const currentSalary = currentSalaryOf(emp);
                                return (
                                <li key={emp.id}>
                                    <button
                                        type="button"
                                        role="option"
                                        aria-selected={index === active}
                                        onMouseEnter={() => setActive(index)}
                                        onClick={() => handlePick(emp)}
                                        className={`flex w-full items-start gap-2 px-3 py-2 text-left ${
                                            index === active ? 'bg-amber-50' : 'hover:bg-gray-50'
                                        }`}
                                    >
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate text-sm font-medium text-gray-800">
                                                <Highlight text={emp.full_name} term={term} />
                                                {emp.near && (
                                                    <span className="ml-2 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-normal text-amber-700">
                                                        ใกล้เคียง
                                                    </span>
                                                )}
                                            </span>
                                            <span className="block truncate text-xs text-gray-500">
                                                <Highlight text={emp.citizen_id} term={digitTerm || term} />
                                                {emp.pid ? ` · ${emp.pid}` : ''}
                                                {emp.position_name ? ` · ${emp.position_name}` : ''}
                                            </span>
                                        </span>
                                        {currentSalary != null && (
                                            <span className="shrink-0 text-xs text-gray-500">
                                                {formatCurrency(currentSalary)} บาท
                                            </span>
                                        )}
                                    </button>
                                </li>
                                );
                            })}
                        </ul>
                    )}
                </div>
            )}
        </div>
    );
};

export default EmployeeCombobox;
