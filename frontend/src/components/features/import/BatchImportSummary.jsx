import React from 'react';
import { CheckCircle, XCircle, AlertTriangle, Users, Unlink } from 'lucide-react';

/**
 * ผลการนำเข้าแบบหลายไฟล์ — อัปโหลดทีละไฟล์ ถ้าไฟล์ใดพลาด ไฟล์ที่เหลือยังทำงานต่อ
 *
 * @param {{name: string, ok: boolean, data?: object, message?: string}[]} results
 */
const BatchImportSummary = ({ results }) => {
    if (!results?.length) return null;

    const succeeded = results.filter((result) => result.ok);
    const failed = results.length - succeeded.length;

    const totalRows = succeeded.reduce(
        (sum, result) => sum + Number(result.data?.import?.success_rows || 0),
        0,
    );

    return (
        <div className="rounded-2xl border border-[#E6D3A3] bg-white p-6">
            <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
                <h3 className="text-lg font-semibold text-gray-700">
                    ผลการนำเข้า {results.length} ไฟล์
                </h3>
                <div className="flex items-center gap-2 text-xs">
                    <span className="rounded-full bg-emerald-50 px-2.5 py-1 font-medium text-emerald-700">
                        สำเร็จ {succeeded.length}
                    </span>
                    {failed > 0 && (
                        <span className="rounded-full bg-red-50 px-2.5 py-1 font-medium text-red-600">
                            ไม่สำเร็จ {failed}
                        </span>
                    )}
                    <span className="text-gray-400">
                        รวม {totalRows.toLocaleString('th-TH')} แถว
                    </span>
                </div>
            </div>

            <ul className="space-y-2">
                {results.map((result) => {
                    const data = result.data;
                    const payroll = data?.import;
                    const unlinked = data?.unlinked_summary;
                    const warnings = [
                        ...(data?.scope_warnings || []),
                        ...(data?.warning ? [data.warning] : []),
                    ];

                    return (
                        <li
                            key={result.name}
                            className={`rounded-xl border p-3 text-sm ${
                                result.ok
                                    ? 'border-emerald-200 bg-emerald-50/40'
                                    : 'border-red-200 bg-red-50/60'
                            }`}
                        >
                            <div className="flex items-start gap-2">
                                {result.ok ? (
                                    <CheckCircle className="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" />
                                ) : (
                                    <XCircle className="mt-0.5 h-4 w-4 shrink-0 text-red-500" />
                                )}

                                <div className="min-w-0 flex-1">
                                    <div className="truncate font-medium text-gray-700">
                                        {result.name}
                                    </div>

                                    {result.ok ? (
                                        <div className="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs text-gray-500">
                                            <span>
                                                เงินเดือน {Number(payroll?.success_rows || 0).toLocaleString('th-TH')} แถว
                                            </span>
                                            {Number(payroll?.error_rows) > 0 && (
                                                <span className="text-red-600">
                                                    ผิดพลาด {Number(payroll.error_rows).toLocaleString('th-TH')} แถว
                                                </span>
                                            )}
                                            {data?.employee && (
                                                <span className="inline-flex items-center gap-1">
                                                    <Users size={12} />
                                                    ทะเบียน เพิ่ม {Number(data.employee.inserted).toLocaleString('th-TH')}
                                                    {' / '}อัปเดต {Number(data.employee.updated).toLocaleString('th-TH')} คน
                                                </span>
                                            )}
                                            {unlinked?.rows > 0 && (
                                                <span className="inline-flex items-center gap-1 text-amber-700">
                                                    <Unlink size={12} />
                                                    ไม่เข้าฐานเงินสำรอง {Number(unlinked.rows).toLocaleString('th-TH')} คน-งวด
                                                </span>
                                            )}
                                        </div>
                                    ) : (
                                        <p className="mt-1 text-xs text-red-600">{result.message}</p>
                                    )}

                                    {warnings.map((text, i) => (
                                        <p
                                            key={i}
                                            className="mt-1.5 flex items-start gap-1.5 text-xs text-amber-700"
                                        >
                                            <AlertTriangle className="mt-0.5 h-3 w-3 shrink-0" />
                                            <span>{text}</span>
                                        </p>
                                    ))}
                                </div>
                            </div>
                        </li>
                    );
                })}
            </ul>
        </div>
    );
};

export default BatchImportSummary;