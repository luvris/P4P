import React, { useCallback, useEffect, useState } from 'react';
import toast from 'react-hot-toast';
import { Pencil, Plus, Trash2, X, Loader2 } from 'lucide-react';
import { payrollExtraColumnService } from '../../../services/payrollExtraColumnService';

/**
 * ป้ายชนิดข้อมูล — ผู้ใช้ต้องเข้าใจว่าค่าที่กรอกจะถูกตรวจแบบไหน
 */
const TYPE_LABELS = {
    text: 'ข้อความ',
    number: 'ตัวเลข',
    date: 'วันที่ (YYYY-MM-DD)',
};

const TYPE_HINTS = {
    text: 'เก็บตามที่พิมพ์ ไม่ตรวจรูปแบบ',
    number: 'ตัดลูกค้าจุดพันออกให้ ค่าที่ไม่ใช่ตัวเลขจะถูกทิ้ง',
    date: 'ต้องเป็น ปี-เดือน-วัน เช่น 2569-01-15 ค่าอื่นจะถูกทิ้ง',
};

/** ตำแหน่งที่ไม่ต้องยึดกับคอลัมน์ใด คือต่อท้ายไฟล์ */
const END_OF_FILE = 'end';

/**
 * ตำแหน่งของคอลัมน์เก็บเป็นข้อความกลับด้าน เพื่อส่งให้ backend เขียนได้ตรงช่อง
 *   before:<ชื่อคอลัมน์> = ยัดไว้ก่อนคอลัมน์นั้น
 *   after:<ชื่อคอลัมน์>  = ยัดไว้ต่อจากคอลัมน์นั้น
 */
const positionValue = (column) => {
    if (column.before_column) return `before:${column.before_column}`;
    if (column.after_column) return `after:${column.after_column}`;

    return END_OF_FILE;
};

/** แปลงค่าใน select กลับเป็นข้อความอ่านเข้าใจง่าย */
const positionLabel = (position) => {
    const [side, ...rest] = position.split(':');

    if (side === END_OF_FILE || rest.length === 0) {
        return 'ต่อท้ายไฟล์';
    }

    return `${side === 'before' ? 'ก่อน' : 'หลัง'} ${rest.join(':')}`;
};

/** แยกค่าใน select เป็น [ด้าน, ชื่อคอลัมน์] — ต่อท้ายไฟล์คืน [null, null] */
const splitPosition = (position) => {
    const cut = position.indexOf(':');

    if (cut < 0) {
        return [null, null];
    }

    return [position.slice(0, cut), position.slice(cut + 1)];
};

const EMPTY_FORM = { name: '', description: '', data_type: 'text', position: END_OF_FILE };

/**
 * จัดการคอลัมน์ที่ต้องการเพิ่มในไฟล์เงินเดือน
 *
 * คอลัมน์ที่เพิ่มจะถูกยัดลงไฟล์ต้นแบบตามตำแหน่งที่เลือกไว้ (ก่อน/หลังคอลัมน์ที่ระบุ
 * หรือต่อท้ายไฟล์) และค่าที่อัปโหลดจะถูกเก็บแยกใน payrolls.extra_data
 *
 * ใช้ได้เฉพาะ admin เพราะคอลัมน์ที่เพิ่มส่งผลต่อไฟล์ต้นแบบของทุกคน
 */
const ExtraColumnsPanel = () => {
    const [columns, setColumns] = useState([]);
    const [baseColumns, setBaseColumns] = useState([]);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [editingId, setEditingId] = useState(null);
    const [form, setForm] = useState(EMPTY_FORM);

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const result = await payrollExtraColumnService.list();
            setColumns(result.data || []);
            // ชื่อคอลัมน์มาตรฐาน 39 ช่อง ใช้เป็นตัวเลือกตำแหน่งวางคอลัมน์
            setBaseColumns(result.base_columns || []);
        } catch (error) {
            toast.error(error.response?.data?.message || 'โหลดรายการคอลัมน์ไม่สำเร็จ');
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        load();
    }, [load]);

    const resetForm = () => {
        setForm(EMPTY_FORM);
        setEditingId(null);
    };

    const handleSubmit = async (event) => {
        event.preventDefault();

        if (!form.name.trim()) {
            toast.error('กรุณากรอกชื่อคอลัมน์');
            return;
        }

        const [side, anchor] = splitPosition(form.position);

        setSaving(true);
        try {
            const payload = {
                name: form.name.trim(),
                description: form.description.trim() || null,
                data_type: form.data_type,
                // ส่งทั้งสองช่องเสมอ — ค่าว่างแปลว่าไม่ได้ยึดตำแหน่งนั้น
                before_column: side === 'before' ? anchor : null,
                after_column: side === 'after' ? anchor : null,
            };

            if (editingId) {
                const result = await payrollExtraColumnService.update(editingId, payload);
                toast.success(result.message || 'บันทึกคอลัมน์แล้ว');
            } else {
                const result = await payrollExtraColumnService.create(payload);
                toast.success(result.message || 'เพิ่มคอลัมน์แล้ว');
            }

            resetForm();
            await load();
        } catch (error) {
            const message = error.response?.data?.errors?.name?.[0]
                || error.response?.data?.errors?.before_column?.[0]
                || error.response?.data?.errors?.after_column?.[0]
                || error.response?.data?.message
                || 'บันทึกคอลัมน์ไม่สำเร็จ';
            toast.error(message);
        } finally {
            setSaving(false);
        }
    };

    const handleDisable = async (column) => {
        const confirmed = window.confirm(
            `ปิดใช้งานคอลัมน์ "${column.name}"?\n\n`
            + 'คอลัมน์นี้จะหายจากแบบฟอร์มใหม่ แต่ข้อมูลที่บันทึกไว้แล้วยังอยู่ครบ'
        );

        if (!confirmed) return;

        try {
            const result = await payrollExtraColumnService.disable(column.id);
            toast.success(result.message || 'ปิดใช้งานคอลัมน์แล้ว');
            await load();
        } catch (error) {
            toast.error(error.response?.data?.message || 'ปิดใช้งานไม่สำเร็จ');
        }
    };

    const startEdit = (column) => {
        setEditingId(column.id);
        setForm({
            name: column.name,
            description: column.description || '',
            data_type: column.data_type || 'text',
            position: positionValue(column),
        });
    };

    const activeColumns = columns.filter((c) => c.is_active);
    const inactiveColumns = columns.filter((c) => !c.is_active);

    // ตำแหน่งที่บันทึกไว้อาจไม่ตรงกับแบบฟอร์มปัจจุบัน (เช่น เปลี่ยนชื่อคอลัมน์ในไฟล์ต้นแบบ)
    // ต้องแสดงค่าเดิมไว้ให้ผู้ใช้เห็น ไม่ใช่กระโดดไปที่ตัวเลือกแรกเงียบ ๆ
    const [currentSide, currentAnchor] = splitPosition(form.position);
    const isUnknownPosition = currentSide !== null
        && !baseColumns.some((name) => name === currentAnchor);

    return (
        <div className="rounded-2xl border border-[#E6D3A3] bg-white p-5">
            <div className="mb-4">
                <h3 className="text-lg font-semibold text-gray-800">
                    คอลัมน์เพิ่มเติม
                </h3>
                <p className="mt-1 text-xs text-gray-500">
                    เพิ่มคอลัมน์ที่หน่วยงานต้องการเก็บเพิ่ม และเลือกได้ว่าจะยัดไว้ก่อนหรือหลัง
                    คอลัมน์ไหนในแบบฟอร์ม (ถ้าไม่ระบุจะต่อท้ายไฟล์)
                    แล้วอ่านค่าจากไฟล์ที่อัปโหลดมาเก็บไว้ให้
                </p>
            </div>

            <form onSubmit={handleSubmit} className="mb-5 rounded-xl bg-[#F5EEDC]/40 p-4">
                <div className="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <label
                            htmlFor="extra-column-name"
                            className="mb-1 block text-sm font-medium text-gray-700"
                        >
                            ชื่อคอลัมน์
                        </label>
                        <input
                            id="extra-column-name"
                            type="text"
                            value={form.name}
                            onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
                            placeholder="เช่น ค่าครองชีพเฉพาะหน่วย"
                            maxLength={100}
                            className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-700 focus:border-[#C5A059] focus:outline-none focus:ring-1 focus:ring-[#C5A059]"
                        />
                    </div>

                    <div>
                        <label
                            htmlFor="extra-column-type"
                            className="mb-1 block text-sm font-medium text-gray-700"
                        >
                            ชนิดข้อมูล
                        </label>
                        <select
                            id="extra-column-type"
                            value={form.data_type}
                            onChange={(e) => setForm((f) => ({ ...f, data_type: e.target.value }))}
                            className="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 focus:border-[#C5A059] focus:outline-none focus:ring-1 focus:ring-[#C5A059]"
                        >
                            {Object.entries(TYPE_LABELS).map(([value, label]) => (
                                <option key={value} value={value}>{label}</option>
                            ))}
                        </select>
                    </div>

                    <div>
                        <label
                            htmlFor="extra-column-position"
                            className="mb-1 block text-sm font-medium text-gray-700"
                        >
                            ตำแหน่งในแบบฟอร์ม
                        </label>
                        <select
                            id="extra-column-position"
                            value={form.position}
                            onChange={(e) => setForm((f) => ({ ...f, position: e.target.value }))}
                            className="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 focus:border-[#C5A059] focus:outline-none focus:ring-1 focus:ring-[#C5A059]"
                        >
                            <option value={END_OF_FILE}>ต่อท้ายไฟล์ (ท้ายสุด)</option>

                            {isUnknownPosition && (
                                <option value={form.position}>
                                    {positionLabel(form.position)} — ไม่พบในแบบฟอร์ม
                                </option>
                            )}

                            <optgroup label="วางไว้ก่อนคอลัมน์">
                                {baseColumns.map((name) => (
                                    <option key={`before:${name}`} value={`before:${name}`}>
                                        ก่อน {name}
                                    </option>
                                ))}
                            </optgroup>

                            <optgroup label="วางไว้หลังคอลัมน์">
                                {baseColumns.map((name) => (
                                    <option key={`after:${name}`} value={`after:${name}`}>
                                        หลัง {name}
                                    </option>
                                ))}
                            </optgroup>
                        </select>
                    </div>

                    <div>
                        <label
                            htmlFor="extra-column-desc"
                            className="mb-1 block text-sm font-medium text-gray-700"
                        >
                            คำอธิบาย (ไม่บังคับ)
                        </label>
                        <input
                            id="extra-column-desc"
                            type="text"
                            value={form.description}
                            onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))}
                            maxLength={255}
                            className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-700 focus:border-[#C5A059] focus:outline-none focus:ring-1 focus:ring-[#C5A059]"
                        />
                    </div>
                </div>

                <p className="mt-2 text-xs text-gray-500">{TYPE_HINTS[form.data_type]}</p>

                <div className="mt-3 flex flex-wrap gap-2">
                    <button
                        type="submit"
                        disabled={saving}
                        className="inline-flex items-center gap-1.5 rounded-lg bg-[#8B5E3C] px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-[#75503a] disabled:opacity-50"
                    >
                        {saving
                            ? <Loader2 size={15} className="animate-spin" />
                            : editingId ? <Pencil size={15} /> : <Plus size={15} />}
                        {editingId ? 'บันทึกการแก้ไข' : 'เพิ่มคอลัมน์'}
                    </button>

                    {editingId && (
                        <button
                            type="button"
                            onClick={resetForm}
                            className="inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm text-gray-500 transition-colors hover:bg-gray-50"
                        >
                            <X size={15} />
                            ยกเลิก
                        </button>
                    )}
                </div>

                {activeColumns.length > 0 && (
                    <p className="mt-3 text-xs text-amber-700">
                        หลังเพิ่มหรือย้ายตำแหน่งคอลัมน์ ผู้ใช้ต้องดาวน์โหลดแบบฟอร์มใหม่
                        จึงจะกรอกคอลัมน์นั้นได้
                    </p>
                )}
            </form>

            {loading ? (
                <p className="py-6 text-center text-sm text-gray-400">กำลังโหลดรายการคอลัมน์...</p>
            ) : columns.length === 0 ? (
                <p className="rounded-xl border border-dashed border-gray-300 py-6 text-center text-sm text-gray-400">
                    ยังไม่มีคอลัมน์เพิ่มเติม — ไฟล์ต้นแบบมีเฉพาะ 39 คอลัมน์มาตรฐาน
                </p>
            ) : (
                <ul className="space-y-2">
                    {[...activeColumns, ...inactiveColumns].map((column) => (
                        <li
                            key={column.id}
                            className={`flex flex-wrap items-center justify-between gap-2 rounded-xl border px-3 py-2 ${
                                column.is_active
                                    ? 'border-gray-200 bg-white'
                                    : 'border-gray-200 bg-gray-50'
                            }`}
                        >
                            <div className="min-w-0">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className={`text-sm font-medium ${column.is_active ? 'text-gray-800' : 'text-gray-400 line-through'}`}>
                                        {column.name}
                                    </span>
                                    <span className="rounded-full bg-[#F5EEDC] px-2 py-0.5 text-[11px] text-[#8B5E3C]">
                                        {TYPE_LABELS[column.data_type] || column.data_type}
                                    </span>
                                    <span className="text-[11px] text-gray-500">
                                        {positionLabel(positionValue(column))}
                                    </span>
                                    {!column.is_active && (
                                        <span className="text-[11px] text-gray-400">ปิดใช้งานแล้ว</span>
                                    )}
                                </div>
                                {column.description && (
                                    <p className="mt-0.5 text-xs text-gray-500">{column.description}</p>
                                )}
                            </div>

                            {column.is_active && (
                                <div className="flex shrink-0 gap-1">
                                    <button
                                        type="button"
                                        onClick={() => startEdit(column)}
                                        title="แก้ไข"
                                        className="rounded-lg p-1.5 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-700"
                                    >
                                        <Pencil size={15} />
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => handleDisable(column)}
                                        title="ปิดใช้งาน (ข้อมูลเดิมยังอยู่)"
                                        className="rounded-lg p-1.5 text-gray-400 transition-colors hover:bg-red-50 hover:text-red-600"
                                    >
                                        <Trash2 size={15} />
                                    </button>
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
};

export default ExtraColumnsPanel;