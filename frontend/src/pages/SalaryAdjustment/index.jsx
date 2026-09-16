import { useCallback, useEffect, useState } from 'react';
import {
    TrendingUp,
    Plus,
    Search,
    Loader2,
    Wallet,
    Users,
    ArrowUpRight,
    ArrowDownRight,
    Calendar,
    ChevronLeft,
    ChevronRight,
} from 'lucide-react';

import useSalaryAdjustments from '../../hooks/useSalaryAdjustments';
import useDebounce from '../../hooks/useDebounce';
import AddAdjustmentDrawer from '../../components/salaryAdjustment/AddAdjustmentDrawer';
import { ADJUSTMENT_TYPES } from '../../services/salaryAdjustmentService';
import { formatCurrency, formatDate } from '../../utils/format';

const formatNumber = (value) => new Intl.NumberFormat('th-TH').format(value ?? 0);

const ADJUSTMENT_TYPE_OPTIONS = [
    { value: '', label: 'ทุกประเภท' },
    ...ADJUSTMENT_TYPES,
];

const SalaryAdjustmentPage = () => {
    const {
        items,
        meta,
        summary,
        loading,
        summaryLoading,
        error,
        filters,
        updateFilters,
        setPage,
        createAdjustment,
    } = useSalaryAdjustments();

    const [drawerOpen, setDrawerOpen] = useState(false);
    const [searchInput, setSearchInput] = useState(filters.search || '');
    const debouncedSearch = useDebounce(searchInput, 500);

    // Apply debounced search to filters
    useEffect(() => {
        if (debouncedSearch !== (filters.search || '')) {
            updateFilters({ search: debouncedSearch }, true);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [debouncedSearch]);

    const handleSearchChange = useCallback((value) => {
        setSearchInput(value);
    }, []);

    return (
        <div className="space-y-4">
            {error && (
                <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                    {error}
                </div>
            )}

            {/* Summary Cards */}
            <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
                <div className="rounded-xl border border-gray-200 bg-white p-4 flex items-center gap-3">
                    <div className="rounded-lg p-2.5 bg-amber-100 text-amber-700 shrink-0">
                        <TrendingUp className="w-5 h-5" />
                    </div>
                    <div className="min-w-0">
                        <p className="text-xs text-gray-500 truncate">จำนวนครั้งที่ปรับ</p>
                        <p className="text-2xl font-semibold text-gray-800">
                            {summaryLoading ? (
                                <span className="inline-block w-14 h-6 bg-gray-200 rounded animate-pulse" />
                            ) : (
                                <>
                                    {formatNumber(summary.total_adjustments)}
                                    <span className="text-sm font-normal text-gray-500 ml-1">ครั้ง</span>
                                </>
                            )}
                        </p>
                    </div>
                </div>

                <div className="rounded-xl border border-gray-200 bg-white p-4 flex items-center gap-3">
                    <div className="rounded-lg p-2.5 bg-emerald-100 text-emerald-700 shrink-0">
                        <Wallet className="w-5 h-5" />
                    </div>
                    <div className="min-w-0">
                        <p className="text-xs text-gray-500 truncate">ยอดปรับเพิ่มรวม</p>
                        <p className="text-2xl font-semibold text-emerald-700">
                            {summaryLoading ? (
                                <span className="inline-block w-20 h-6 bg-gray-200 rounded animate-pulse" />
                            ) : (
                                <>
                                    {formatCurrency(summary.total_increase)}
                                    <span className="text-sm font-normal text-gray-500 ml-1">บาท</span>
                                </>
                            )}
                        </p>
                    </div>
                </div>

                <div className="rounded-xl border border-gray-200 bg-white p-4 flex items-center gap-3">
                    <div className="rounded-lg p-2.5 bg-blue-100 text-blue-700 shrink-0">
                        <Users className="w-5 h-5" />
                    </div>
                    <div className="min-w-0">
                        <p className="text-xs text-gray-500 truncate">จำนวนบุคลากรที่ปรับ</p>
                        <p className="text-2xl font-semibold text-gray-800">
                            {summaryLoading ? (
                                <span className="inline-block w-14 h-6 bg-gray-200 rounded animate-pulse" />
                            ) : (
                                <>
                                    {formatNumber(summary.total_employees)}
                                    <span className="text-sm font-normal text-gray-500 ml-1">คน</span>
                                </>
                            )}
                        </p>
                    </div>
                </div>
            </div>

            {/* Filter + Add */}
            <div className="rounded-xl border border-gray-200 bg-white p-4">
                <div className="flex flex-col md:flex-row gap-3 md:items-center">
                    <div className="relative flex-1">
                        <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
                        <input
                            type="text"
                            value={searchInput}
                            onChange={(e) => handleSearchChange(e.target.value)}
                            placeholder="ค้นหาชื่อ / เลขบัตรประชาชน..."
                            className="w-full pl-9 pr-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-transparent"
                        />
                    </div>

                    <select
                        value={filters.adjustment_type || ''}
                        onChange={(e) =>
                            updateFilters({ adjustment_type: e.target.value }, true)
                        }
                        className="w-full md:w-48 px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-transparent bg-white"
                    >
                        {ADJUSTMENT_TYPE_OPTIONS.map((opt) => (
                            <option key={opt.value} value={opt.value}>
                                {opt.label}
                            </option>
                        ))}
                    </select>

                    <div className="flex items-center gap-2">
                        <Calendar className="w-4 h-4 text-gray-400 shrink-0" />
                        <input
                            type="date"
                            value={filters.date_from || ''}
                            onChange={(e) =>
                                updateFilters({ date_from: e.target.value }, true)
                            }
                            className="px-2 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-transparent"
                        />
                        <span className="text-gray-400 text-sm">-</span>
                        <input
                            type="date"
                            value={filters.date_to || ''}
                            onChange={(e) =>
                                updateFilters({ date_to: e.target.value }, true)
                            }
                            className="px-2 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-transparent"
                        />
                    </div>

                    <button
                        type="button"
                        onClick={() => setDrawerOpen(true)}
                        className="inline-flex items-center justify-center gap-2 px-4 py-2 text-sm font-medium text-white bg-amber-600 hover:bg-amber-700 rounded-lg shrink-0"
                    >
                        <Plus className="w-4 h-4" />
                        ปรับฐานเงินเดือน
                    </button>
                </div>
            </div>

            {/* Log Table */}
            <div className="rounded-xl border border-gray-200 bg-white overflow-hidden">
                <div className="px-4 py-3 border-b border-gray-200 flex items-center gap-2">
                    <TrendingUp className="w-4 h-4 text-[#8B5E3C]" />
                    <h3 className="font-semibold text-gray-800">ประวัติการปรับฐานเงินเดือน</h3>
                </div>

                {loading ? (
                    <div className="flex items-center justify-center py-16 text-gray-400 gap-2">
                        <Loader2 className="w-5 h-5 animate-spin" />
                        <span>กำลังโหลดข้อมูล...</span>
                    </div>
                ) : items.length === 0 ? (
                    <div className="py-16 text-center text-gray-500 text-sm">
                        ยังไม่มีประวัติการปรับฐานเงินเดือน
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                                    <th className="px-4 py-3 font-semibold">บุคลากร</th>
                                    <th className="px-4 py-3 font-semibold">วันที่ปรับ</th>
                                    <th className="px-4 py-3 font-semibold">ประเภท</th>
                                    <th className="px-4 py-3 font-semibold text-right">เงินเดือนเก่า</th>
                                    <th className="px-4 py-3 font-semibold text-right">เงินเดือนใหม่</th>
                                    <th className="px-4 py-3 font-semibold text-right">ปรับเพิ่ม</th>
                                    <th className="px-4 py-3 font-semibold text-right">%</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {items.map((item) => (
                                    <AdjustmentRow key={item.id} item={item} />
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {/* Pagination */}
                {meta.last_page > 1 && (
                    <div className="px-4 py-3 border-t border-gray-200 flex items-center justify-between">
                        <span className="text-xs text-gray-500">
                            แสดง {meta.from ?? 0}–{meta.to ?? 0} จาก {meta.total} รายการ
                        </span>
                        <div className="flex items-center gap-1">
                            <button
                                type="button"
                                disabled={meta.current_page <= 1}
                                onClick={() => setPage(meta.current_page - 1)}
                                className="p-1.5 rounded-lg border border-gray-200 text-gray-500 hover:bg-gray-50 disabled:opacity-40"
                                aria-label="หน้าก่อนหน้า"
                            >
                                <ChevronLeft className="w-4 h-4" />
                            </button>
                            <span className="px-2 text-sm text-gray-600">
                                {meta.current_page} / {meta.last_page}
                            </span>
                            <button
                                type="button"
                                disabled={meta.current_page >= meta.last_page}
                                onClick={() => setPage(meta.current_page + 1)}
                                className="p-1.5 rounded-lg border border-gray-200 text-gray-500 hover:bg-gray-50 disabled:opacity-40"
                                aria-label="หน้าถัดไป"
                            >
                                <ChevronRight className="w-4 h-4" />
                            </button>
                        </div>
                    </div>
                )}
            </div>

            <AddAdjustmentDrawer
                open={drawerOpen}
                onClose={() => setDrawerOpen(false)}
                onSubmit={createAdjustment}
            />
        </div>
    );
};

const AdjustmentRow = ({ item }) => {
    const positive = Number(item.increase_amount) > 0;
    const negative = Number(item.increase_amount) < 0;

    return (
        <tr className="hover:bg-gray-50">
            <td className="px-4 py-3">
                <div className="font-medium text-gray-800">
                    {item.employee?.full_name ||
                        `${item.employee?.first_name ?? ''} ${item.employee?.last_name ?? ''}`.trim() ||
                        '-'}
                </div>
                {item.employee?.citizen_id && (
                    <div className="text-xs text-gray-500">{item.employee.citizen_id}</div>
                )}
                {item.employee?.position?.name && (
                    <div className="text-xs text-gray-400">{item.employee.position.name}</div>
                )}
            </td>
            <td className="px-4 py-3 whitespace-nowrap text-gray-600">
                {formatDate(item.adjustment_date)}
            </td>
            <td className="px-4 py-3">
                <span className="inline-flex rounded-full bg-amber-50 border border-amber-200 px-2 py-0.5 text-xs font-medium text-amber-700">
                    {item.adjustment_type || '-'}
                </span>
            </td>
            <td className="px-4 py-3 text-right text-gray-500">
                {formatCurrency(item.old_salary)}
            </td>
            <td className="px-4 py-3 text-right font-medium text-gray-800">
                {formatCurrency(item.new_salary)}
            </td>
            <td className="px-4 py-3 text-right">
                <span
                    className={`inline-flex items-center gap-1 font-semibold ${
                        positive
                            ? 'text-emerald-600'
                            : negative
                                ? 'text-red-600'
                                : 'text-gray-500'
                    }`}
                >
                    {positive ? (
                        <ArrowUpRight className="w-4 h-4" />
                    ) : negative ? (
                        <ArrowDownRight className="w-4 h-4" />
                    ) : null}
                    {formatCurrency(Math.abs(Number(item.increase_amount)))}
                </span>
            </td>
            <td className="px-4 py-3 text-right text-gray-500 whitespace-nowrap">
                {item.increase_percent != null
                    ? `${Number(item.increase_percent) >= 0 ? '+' : ''}${Number(
                          item.increase_percent,
                      ).toFixed(2)}%`
                    : '-'}
            </td>
        </tr>
    );
};

export default SalaryAdjustmentPage;