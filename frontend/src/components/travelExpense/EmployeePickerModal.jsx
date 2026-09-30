import { useCallback, useEffect, useState } from 'react';
import { Search, X, UserPlus, Loader2 } from 'lucide-react';
import { travelExpenseClaimService } from '../../services/travelExpenseClaimService';

/**
 * เลือกบุคลากรจากระบบเดิม (ค้นด้วย PID, ชื่อ, นามสกุล, ตำแหน่ง)
 *
 * excludeIds = employee_id ที่มีอยู่ในเอกสารแล้ว — กันเพิ่มซ้ำ
 */
/** รายชื่อผลการค้นหา — แยกออกมาให้ component หลักอ่านง่าย */
const ResultList = ({ loading, error, results, excluded, selected, onToggle }) => (
    <div className="flex-1 overflow-y-auto px-5 py-3">
        {error && (
            <p className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                {error}
            </p>
        )}

        {loading && (
            <p className="flex items-center gap-2 py-6 text-sm text-gray-500">
                <Loader2 className="h-4 w-4 animate-spin" />
                กำลังค้นหา...
            </p>
        )}

        {!loading && !error && results.length === 0 && (
            <p className="py-6 text-center text-sm text-gray-500">
                ไม่พบบุคลากรที่ตรงกับคำค้นหา
            </p>
        )}

        {!loading && results.length > 0 && (
            <ul className="divide-y divide-gray-100">
                {results.map((employee) => {
                    const already = excluded.has(employee.id);
                    const checked = Boolean(selected[employee.id]);

                    return (
                        <li key={employee.id}>
                            <label
                                className={`flex items-center gap-3 py-2.5 ${
                                    already ? 'cursor-not-allowed opacity-50' : 'cursor-pointer'
                                }`}
                            >
                                <input
                                    type="checkbox"
                                    checked={checked}
                                    disabled={already}
                                    onChange={() => onToggle(employee)}
                                    className="h-4 w-4 rounded border-gray-300 text-amber-600 focus:ring-amber-500"
                                />
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-sm font-medium text-gray-800">
                                        {employee.first_name} {employee.last_name}
                                        {already && (
                                            <span className="ml-2 text-xs font-normal text-gray-500">
                                                (อยู่ในเอกสารแล้ว)
                                            </span>
                                        )}
                                    </span>
                                    <span className="block truncate text-xs text-gray-500">
                                        เลขบัตรประชาชน: {employee.citizen_id || '-'} · {employee.position_name || 'ไม่ระบุตำแหน่ง'}
                                    </span>
                                </span>
                            </label>
                        </li>
                    );
                })}
            </ul>
        )}
    </div>
);

const EmployeePickerModal = ({ open, onClose, onConfirm, excludeIds = [] }) => {
    const [term, setTerm] = useState('');
    const [results, setResults] = useState([]);
    const [selected, setSelected] = useState({});
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');

    const excluded = new Set(excludeIds);

    const search = useCallback(async (keyword) => {
        setLoading(true);
        setError('');
        try {
            const response = await travelExpenseClaimService.searchEmployees({
                search: keyword || undefined,
                limit: 50,
            });
            setResults(response.data || []);
        } catch (err) {
            setError(err.response?.data?.message || 'ไม่สามารถค้นหาบุคลากรได้');
            setResults([]);
        } finally {
            setLoading(false);
        }
    }, []);

    // เปิด modal → ล้างคำค้นและสิ่งที่เลือกไว้เดิม
    useEffect(() => {
        if (!open) return;
        setTerm('');
        setSelected({});
    }, [open]);

    // debounce การค้นหา (ครั้งแรกที่เปิดก็โหลดรายชื่อชุดแรกด้วย)
    useEffect(() => {
        if (!open) return undefined;
        const timer = setTimeout(() => search(term), term ? 400 : 0);
        return () => clearTimeout(timer);
    }, [term, open, search]);

    if (!open) return null;

    const toggle = (employee) => {
        setSelected((prev) => {
            const next = { ...prev };
            if (next[employee.id]) {
                delete next[employee.id];
            } else {
                next[employee.id] = employee;
            }
            return next;
        });
    };

    const chosen = Object.values(selected);

    const handleConfirm = () => {
        onConfirm?.(chosen);
        onClose?.();
    };

    return (
        <>
            <div className="fixed inset-0 bg-black/30 z-40" onClick={onClose} />

            <div
                role="dialog"
                aria-modal="true"
                aria-label="เลือกผู้เบิก"
                className="fixed inset-0 z-50 flex items-center justify-center p-4 pointer-events-none"
            >
                <div className="pointer-events-auto w-full max-w-2xl max-h-[85vh] flex flex-col rounded-xl bg-white shadow-2xl">
                    <div className="flex items-center justify-between border-b border-gray-200 px-5 py-4">
                        <div className="flex items-center gap-2">
                            <UserPlus className="h-5 w-5 text-amber-600" />
                            <h2 className="text-base font-semibold text-gray-800">เพิ่มผู้เบิก</h2>
                        </div>
                        <button
                            type="button"
                            onClick={onClose}
                            className="rounded-lg p-1.5 text-gray-500 hover:bg-gray-100"
                            aria-label="ปิด"
                        >
                            <X className="h-5 w-5" />
                        </button>
                    </div>

                    <div className="border-b border-gray-100 px-5 py-3">
                        <div className="relative">
                            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" />
                            <input
                                type="text"
                                value={term}
                                onChange={(e) => setTerm(e.target.value)}
                                placeholder="ค้นหาด้วยเลขบัตรประชาชน, ชื่อ, นามสกุล, ตำแหน่ง"
                                className="w-full rounded-lg border border-gray-300 py-2.5 pl-9 pr-3 text-sm focus:border-transparent focus:outline-none focus:ring-2 focus:ring-amber-500"
                            />
                        </div>
                    </div>

                    <ResultList
                        loading={loading}
                        error={error}
                        results={results}
                        excluded={excluded}
                        selected={selected}
                        onToggle={toggle}
                    />

                    <div className="flex items-center gap-3 border-t border-gray-200 bg-gray-50 px-5 py-3">
                        <span className="text-xs text-gray-600">เลือกแล้ว {chosen.length} คน</span>
                        <button
                            type="button"
                            onClick={onClose}
                            className="ml-auto rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium hover:bg-gray-100"
                        >
                            ยกเลิก
                        </button>
                        <button
                            type="button"
                            onClick={handleConfirm}
                            disabled={chosen.length === 0}
                            className="rounded-lg bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700 disabled:opacity-50"
                        >
                            เพิ่มผู้เบิก
                        </button>
                    </div>
                </div>
            </div>
        </>
    );
};

export default EmployeePickerModal;
