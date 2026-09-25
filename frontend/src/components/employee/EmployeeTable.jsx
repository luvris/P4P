import { useState, useEffect } from 'react';
import { ChevronLeft, ChevronRight, Inbox } from 'lucide-react';

/**
 * Mapping สีของ status badge ตามค่า `color` จาก backend
 */
const STATUS_COLORS = {
    green: 'bg-green-100 text-green-700 border-green-200',
    yellow: 'bg-yellow-100 text-yellow-700 border-yellow-200',
    red: 'bg-red-100 text-red-700 border-red-200',
    blue: 'bg-blue-100 text-blue-700 border-blue-200',
    gray: 'bg-gray-100 text-gray-700 border-gray-200',
};

/**
 * EmployeeTable — ตารางรายชื่อบุคลากร + pagination
 */
const EmployeeTable = ({
    employees = [],
    meta = null,
    loading = false,
    onPageChange,
    onPerPageChange,
    onEdit,
}) => {
    // ============================================
    // State: Jump to page input
    // ============================================
    const [jumpPage, setJumpPage] = useState('');

    // Sync ค่า input กับหน้าปัจจุบัน
    useEffect(() => {
        if (meta?.current_page) {
            setJumpPage(String(meta.current_page));
        }
    }, [meta?.current_page]);

    // ============================================
    // สร้างเลขหน้าแบบย่อ (1 2 3 ... 34)
    // ============================================
    const buildPageNumbers = (current, last) => {
        if (!last || last <= 1) return [];
        if (last <= 7) {
            return Array.from({ length: last }, (_, i) => i + 1);
        }

        const pages = new Set([1, last, current, current - 1, current + 1]);
        const sorted = [...pages].filter((p) => p >= 1 && p <= last).sort((a, b) => a - b);

        const result = [];
        for (let i = 0; i < sorted.length; i++) {
            if (i > 0 && sorted[i] - sorted[i - 1] > 1) {
                result.push('...');
            }
            result.push(sorted[i]);
        }
        return result;
    };

    const pageNumbers = meta
        ? buildPageNumbers(meta.current_page, meta.last_page)
        : [];

    const canPrev = meta && meta.current_page > 1;
    const canNext = meta && meta.current_page < meta.last_page;

    // ============================================
    // Handle Jump to Page
    // ============================================
    const handleJumpSubmit = (e) => {
        e.preventDefault();

        const page = parseInt(jumpPage, 10);

        // Validate
        if (isNaN(page) || page < 1 || page > meta.last_page) {
            // Reset กลับหน้าปัจจุบัน
            setJumpPage(String(meta.current_page));
            return;
        }

        // ถ้าเป็นหน้าเดิม ก็ไม่ต้องเปลี่ยน
        if (page === meta.current_page) return;

        onPageChange(page);
    };

    return (
        <div className="rounded-xl border border-gray-200 bg-white overflow-hidden">
            {/* ตาราง */}
            <div className="overflow-x-auto">
                <table className="w-full text-sm">
                    <thead className="bg-gray-50 text-gray-600 border-b border-gray-200">
                        <tr>
                            <th className="px-4 py-3 text-left font-medium w-16">ID</th>
                            <th className="px-4 py-3 text-left font-medium">ชื่อ-นามสกุล</th>
                            <th className="px-4 py-3 text-left font-medium">ประเภท</th>
                            <th className="px-4 py-3 text-left font-medium">ตำแหน่ง</th>
                            <th className="px-4 py-3 text-left font-medium">ภารกิจ</th>
                            <th className="px-4 py-3 text-left font-medium">เลขที่บัญชี</th>
                            <th className="px-4 py-3 text-left font-medium">สถานะ</th>
                        </tr>
                    </thead>

                    <tbody className="divide-y divide-gray-100">
                        {loading && (
                            <tr>
                                <td colSpan={7} className="px-4 py-12 text-center text-gray-400">
                                    <div className="inline-block w-6 h-6 border-2 border-gray-300 border-t-amber-600 rounded-full animate-spin" />
                                    <p className="mt-2 text-xs">กำลังโหลด...</p>
                                </td>
                            </tr>
                        )}

                        {!loading && employees.length === 0 && (
                            <tr>
                                <td colSpan={7} className="px-4 py-12 text-center text-gray-400">
                                    <Inbox className="w-10 h-10 mx-auto mb-2 text-gray-300" />
                                    <p className="text-sm">ไม่พบข้อมูลบุคลากร</p>
                                </td>
                            </tr>
                        )}

                        {!loading &&
                            employees.map((emp) => (
                                <tr key={emp.id} className="hover:bg-amber-50/40 transition-colors">
                                    <td className="px-4 py-3 text-gray-500">{emp.id}</td>
                                    <td className="px-4 py-3 font-medium text-gray-800">
                                        {onEdit ? (
                                            <button
                                                type="button"
                                                onClick={() => onEdit(emp)}
                                                className="text-left text-amber-700 hover:text-amber-900 hover:underline"
                                            >
                                                {emp.full_name || `${emp.first_name} ${emp.last_name}`}
                                            </button>
                                        ) : (
                                            emp.full_name || `${emp.first_name} ${emp.last_name}`
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-gray-600">
                                        {emp.employee_type?.name || '-'}
                                    </td>
                                    <td className="px-4 py-3 text-gray-600">
                                        {emp.position?.name || '-'}
                                    </td>
                                    <td className="px-4 py-3 text-gray-600">
                                        {emp.duty?.name || '-'}
                                    </td>
                                    <td className="px-4 py-3 text-gray-700 font-mono text-xs">
                                        {emp.latest_payroll?.bank_account || (
                                            <span className="text-gray-400 font-sans">-</span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3">
                                        {emp.status ? (
                                            <span
                                                className={`inline-block px-3 py-1 rounded-md text-xs font-medium border
                          ${STATUS_COLORS[emp.status.color] || STATUS_COLORS.gray}`}
                                            >
                                                {emp.status.name}
                                            </span>
                                        ) : (
                                            <span className="text-gray-400">-</span>
                                        )}
                                    </td>
                                </tr>
                            ))}
                    </tbody>
                </table>
            </div>

            {/* ============================================
                Pagination
            ============================================ */}
            {meta && (
                <div className="flex flex-col lg:flex-row items-center justify-between gap-3 px-4 py-3 border-t border-gray-200 text-sm text-gray-600">
                    {/* Left — summary + per_page */}
                    <div className="flex items-center gap-3">
                        <span className="text-xs">
                            แสดง <strong>{meta.from || 0}</strong> - <strong>{meta.to || 0}</strong>{' '}
                            จาก <strong>{meta.total || 0}</strong> รายการ
                        </span>

                        {onPerPageChange && (
                            <select
                                value={meta.per_page}
                                onChange={(e) => onPerPageChange(Number(e.target.value))}
                                className="text-xs border border-gray-300 rounded px-2 py-1
                           focus:outline-none focus:ring-1 focus:ring-amber-500"
                            >
                                {[10, 25, 50, 100].map((n) => (
                                    <option key={n} value={n}>
                                        {n} / หน้า
                                    </option>
                                ))}
                            </select>
                        )}
                    </div>

                    {/* Right — page navigation */}
                    <div className="flex items-center gap-2 flex-wrap justify-center">
                        {/* Prev */}
                        <button
                            type="button"
                            onClick={() => canPrev && onPageChange(meta.current_page - 1)}
                            disabled={!canPrev}
                            className="p-2 rounded-lg hover:bg-gray-100 disabled:opacity-30 disabled:cursor-not-allowed"
                            aria-label="หน้าก่อน"
                        >
                            <ChevronLeft className="w-4 h-4" />
                        </button>

                        {/* Page numbers */}
                        {pageNumbers.map((p, idx) =>
                            p === '...' ? (
                                <span key={`dots-${idx}`} className="px-2 text-gray-400">
                                    …
                                </span>
                            ) : (
                                <button
                                    key={p}
                                    type="button"
                                    onClick={() => onPageChange(p)}
                                    className={`min-w-[32px] h-8 px-2 rounded-lg text-xs font-medium transition-colors
                    ${p === meta.current_page
                                            ? 'bg-amber-100 text-amber-700 border border-amber-300'
                                            : 'hover:bg-gray-100 text-gray-600'
                                        }`}
                                >
                                    {p}
                                </button>
                            )
                        )}

                        {/* Next */}
                        <button
                            type="button"
                            onClick={() => canNext && onPageChange(meta.current_page + 1)}
                            disabled={!canNext}
                            className="p-2 rounded-lg hover:bg-gray-100 disabled:opacity-30 disabled:cursor-not-allowed"
                            aria-label="หน้าถัดไป"
                        >
                            <ChevronRight className="w-4 h-4" />
                        </button>

                        {/*Jump to page */}
                        <form onSubmit={handleJumpSubmit} className="flex items-center gap-1 ml-2 pl-2 border-l border-gray-200">
                            <span className="text-xs text-gray-500 whitespace-nowrap">
                                ไปหน้า
                            </span>
                            <input
                                type="number"
                                min="1"
                                max={meta.last_page}
                                value={jumpPage}
                                onChange={(e) => setJumpPage(e.target.value)}
                                onBlur={(e) => {
                                    // ถ้าออก input แล้วค่าว่าง/ผิด → reset
                                    const page = parseInt(e.target.value, 10);
                                    if (isNaN(page) || page < 1 || page > meta.last_page) {
                                        setJumpPage(String(meta.current_page));
                                    }
                                }}
                                className="w-14 px-2 py-1 text-xs text-center border border-gray-300 rounded
                                           focus:outline-none focus:ring-1 focus:ring-amber-500 focus:border-transparent
                                           [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none"
                                aria-label="ไปหน้าที่"
                            />
                            <button
                                type="submit"
                                className="px-2 py-1 text-xs font-medium text-amber-700 bg-amber-100 hover:bg-amber-200 rounded"
                            >
                                ไป
                            </button>
                        </form>
                    </div>
                </div>
            )}
        </div>
    );
};

export default EmployeeTable;