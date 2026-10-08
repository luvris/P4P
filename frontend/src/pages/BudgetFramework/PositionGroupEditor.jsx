import { useMemo, useState } from 'react';
import { AlertTriangle, Loader2, Save, Users } from 'lucide-react';

const formatNumber = (value) => new Intl.NumberFormat('th-TH').format(value ?? 0);

/** ตัวเลือกกลุ่มที่ระบบใช้เมื่อจับคู่คำสำคัญไม่พบ (รหัสเดียวกับ backend) */
const UNCLASSIFIED_OPTION = { code: 'unclassified', name: 'อื่น ๆ / ไม่ระบุกลุ่มวิชาชีพ' };

/**
 * แก้กลุ่มวิชาชีพของแต่ละตำแหน่งที่พบในไฟล์เงินเดือน
 *
 * ค่าที่แสดง = กลุ่มที่ระบบจับไว้ (position.group_code) ทับด้วยค่าที่ผู้ใช้แก้แต่ยังไม่บันทึก
 * จึงไม่ต้อง sync state ด้วย effect และข้อความยืนยันการบันทึกจะไม่ถูกล้างเมื่อโหลดข้อมูลใหม่
 */
const PositionGroupEditor = ({ positions, groupOptions = [], periodLabel, saving, onSave }) => {
    const [open, setOpen] = useState(false);

    // ค่าที่ผู้ใช้แก้แต่ยังไม่บันทึก (position_name → group_code)
    const [overrides, setOverrides] = useState({});
    const [status, setStatus] = useState(null);

    const edits = useMemo(() => {
        const next = {};

        positions.forEach((position) => {
            next[position.position_name] =
                overrides[position.position_name] ?? position.group_code ?? '';
        });

        return next;
    }, [positions, overrides]);

    const options = useMemo(() => {
        const hasUnclassified =
            groupOptions.some((group) => group.code === UNCLASSIFIED_OPTION.code) ||
            Object.values(edits).includes(UNCLASSIFIED_OPTION.code);

        return hasUnclassified ? [...groupOptions, UNCLASSIFIED_OPTION] : groupOptions;
    }, [groupOptions, edits]);

    // ตำแหน่งที่ยังไม่ถูกกำหนดกลุ่ม หรือกำหนดเป็น "ไม่ระบุ" — จะถูกนับเป็นกลุ่มอื่น ๆ
    const unassigned = positions.filter((position) => {
        const code = edits[position.position_name];
        return !code || code === UNCLASSIFIED_OPTION.code;
    });
    const unassignedCount = unassigned.reduce((sum, position) => sum + (position.headcount || 0), 0);

    const handleSave = async () => {
        setStatus(null);
        const result = await onSave(edits);

        if (result?.success) {
            // ค่าที่บันทึกแล้วจะกลับมาจาก positionGroups จึงไม่ต้องเก็บ override ต่อ
            setOverrides({});
            setStatus({ type: 'success', message: result.message || 'บันทึกกลุ่มวิชาชีพเรียบร้อย' });
        } else {
            setStatus({ type: 'error', message: result?.error || 'ไม่สามารถบันทึกกลุ่มวิชาชีพได้' });
        }
    };

    const handleChange = (positionName, code) => {
        setOverrides((prev) => ({ ...prev, [positionName]: code }));
        setStatus(null);
    };

    return (
        <div className="rounded-xl border border-gray-200 bg-white p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex items-center gap-3">
                    <div className="rounded-lg p-2.5 bg-[#FBF7EE] text-[#8B5E3C] shrink-0">
                        <Users className="w-5 h-5" />
                    </div>
                    <div>
                        <p className="text-sm font-semibold text-gray-800">กลุ่มวิชาชีพของแต่ละตำแหน่ง</p>
                        <p className="text-xs text-gray-500">
                            จับคู่จากคอลัมน์ «ตำแหน่ง» ในไฟล์เงินเดือน
                            {periodLabel ? ` (งวด ${periodLabel})` : ''}
                            {positions.length > 0 ? ` · ${positions.length} ตำแหน่ง` : ''}
                        </p>
                    </div>
                </div>
                {positions.length > 0 && (
                    <button
                        type="button"
                        onClick={() => setOpen((prev) => !prev)}
                        className="rounded-lg border border-[#8B5E3C] px-3 py-2 text-sm font-medium text-[#8B5E3C] hover:bg-[#FBF7EE]"
                    >
                        {open ? 'ซ่อน' : 'แก้กลุ่มวิชาชีพ'}
                    </button>
                )}
            </div>

            {positions.length === 0 && (
                <div className="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 flex items-start gap-2">
                    <AlertTriangle className="w-4 h-4 mt-0.5 shrink-0" />
                    <span>ยังไม่มีข้อมูล payroll ของปีงบนี้ จึงยังไม่มีตำแหน่งให้จับกลุ่ม</span>
                </div>
            )}

            {open && positions.length > 0 && (
                <>
                    {unassigned.length > 0 && (
                        <div className="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 flex items-start gap-2">
                            <AlertTriangle className="w-4 h-4 mt-0.5 shrink-0" />
                            <span>
                                ยังไม่ได้กำหนดกลุ่มวิชาชีพ {unassigned.length} ตำแหน่ง ({formatNumber(unassignedCount)} คน)
                                — ตำแหน่งเหล่านี้จะถูกนับรวมในกลุ่ม «อื่น ๆ / ไม่ระบุกลุ่มวิชาชีพ»
                            </span>
                        </div>
                    )}

                    <div className="mt-3 max-h-96 overflow-y-auto rounded-lg border border-gray-200">
                        <table className="w-full text-sm">
                            <thead className="sticky top-0 bg-gray-50 text-left text-gray-600">
                                <tr>
                                    <th scope="col" className="px-3 py-2 font-semibold">ตำแหน่งในไฟล์</th>
                                    <th scope="col" className="px-3 py-2 font-semibold text-right">จำนวนคน</th>
                                    <th scope="col" className="px-3 py-2 font-semibold">กลุ่มวิชาชีพ</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {positions.map((position) => (
                                    <tr key={position.position_name}>
                                        <td className="px-3 py-1.5 text-gray-700">
                                            {position.position_name}
                                            {position.is_overridden && (
                                                <span className="ml-2 rounded-full bg-[#F5EEDC] px-2 py-0.5 text-[10px] text-[#8B5E3C]">
                                                    กำหนดเอง
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-3 py-1.5 text-right text-gray-700">
                                            {formatNumber(position.headcount)}
                                        </td>
                                        <td className="px-3 py-1.5">
                                            <select
                                                value={edits[position.position_name] ?? ''}
                                                onChange={(e) => handleChange(position.position_name, e.target.value)}
                                                aria-label={`กลุ่มวิชาชีพของ ${position.position_name}`}
                                                className="w-full rounded-lg border border-gray-300 bg-white px-2 py-1 text-sm text-gray-700 focus:border-[#C5A059] focus:outline-none focus:ring-1 focus:ring-[#C5A059]"
                                            >
                                                <option value="">— ไม่กำหนด (ใช้กฎคำสำคัญ) —</option>
                                                {options.map((group) => (
                                                    <option key={group.code} value={group.code}>
                                                        {group.name}
                                                    </option>
                                                ))}
                                            </select>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <div className="mt-3 flex flex-wrap items-center gap-3">
                        <button
                            type="button"
                            onClick={handleSave}
                            disabled={saving}
                            className="inline-flex items-center gap-2 rounded-lg bg-[#8B5E3C] px-3 py-2 text-sm font-medium text-white hover:bg-[#6B4F3A] disabled:opacity-50"
                        >
                            {saving ? <Loader2 className="w-4 h-4 animate-spin" /> : <Save className="w-4 h-4" />}
                            บันทึกกลุ่มวิชาชีพ
                        </button>
                        {status && (
                            <span
                                className={`text-sm ${
                                    status.type === 'error' ? 'text-red-700' : 'text-green-700'
                                }`}
                            >
                                {status.message}
                            </span>
                        )}
                    </div>
                </>
            )}
        </div>
    );
};

export default PositionGroupEditor;
