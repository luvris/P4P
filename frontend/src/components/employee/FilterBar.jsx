import { memo, useRef, useEffect, useMemo } from 'react';
import { Search, Plus } from 'lucide-react';

const FilterBar = ({ filters, onChange, onAdd, lookups = {} }) => {
    const searchRef = useRef(null);

    useEffect(() => {
        if (
            searchRef.current &&
            searchRef.current.value !== (filters.search || '')
        ) {
            searchRef.current.value = filters.search || '';
        }
    }, [filters.search]);

    const handleSearchChange = (e) => {
        onChange({ search: e.target.value });
    };

    const handleDropdownChange = (key, value) => {
        onChange({ [key]: value });
    };

    // ============================================
    // คำนวณ groups + works ตาม parent ที่เลือก
    // ============================================
    const duties = lookups.duties || [];

    const availableGroups = useMemo(() => {
        if (!filters.duty_id) return [];
        const duty = duties.find((d) => String(d.id) === String(filters.duty_id));
        return duty?.groups || [];
    }, [duties, filters.duty_id]);

    const availableWorks = useMemo(() => {
        if (!filters.group_id) return [];
        const group = availableGroups.find((g) => String(g.id) === String(filters.group_id));
        return group?.works || [];
    }, [availableGroups, filters.group_id]);

    const dropdowns = [
        {
            key: 'employee_type_id',
            label: 'ประเภท',
            options: lookups.employee_types || [],
            disabled: false,
        },
        {
            key: 'duty_id',
            label: 'ภารกิจ',
            options: duties,
            disabled: false,
        },
        {
            key: 'group_id',
            label: 'กลุ่มงาน',
            options: availableGroups,
            disabled: !filters.duty_id,
            placeholder: !filters.duty_id ? 'เลือกภารกิจก่อน' : 'ทั้งหมด',
        },
        {
            key: 'work_id',
            label: 'งาน',
            options: availableWorks,
            disabled: !filters.group_id,
            placeholder: !filters.group_id ? 'เลือกกลุ่มงานก่อน' : 'ทั้งหมด',
        },
        {
            key: 'status_id',
            label: 'สถานะ',
            options: lookups.employee_statuses || [],
            disabled: false,
        },
    ];

    return (
        <div className="rounded-xl border border-gray-200 bg-white p-4 space-y-4">
            <div className="flex flex-col sm:flex-row gap-3">
                <div className="relative flex-1">
                    <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none" />
                    <input
                        ref={searchRef}
                        type="text"
                        defaultValue={filters.search || ''}
                        onChange={handleSearchChange}
                        placeholder="ค้นหาด้วยชื่อ, เลขบัตรประชาชน, ตำแหน่ง..."
                        className="w-full pl-9 pr-3 py-2.5 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-transparent placeholder:text-gray-400"
                    />
                </div>

                <button
                    type="button"
                    onClick={onAdd}
                    className="flex items-center justify-center gap-2 px-4 py-2.5 bg-amber-600 hover:bg-amber-700 text-white text-sm font-medium rounded-lg transition-colors shrink-0"
                >
                    <Plus className="w-4 h-4" />
                    เพิ่มบุคลากร
                </button>
            </div>

            <div className="flex flex-wrap gap-3">
                {dropdowns.map(({ key, label, options, disabled, placeholder }) => (
                    <select
                        key={key}
                        value={filters[key] || ''}
                        onChange={(e) => handleDropdownChange(key, e.target.value)}
                        disabled={disabled}
                        className={`min-w-[140px] flex-1 sm:flex-none px-3 py-2 text-sm border rounded-lg
                            focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-transparent
                            ${disabled
                                ? 'cursor-not-allowed bg-gray-50 text-gray-400 border-gray-200'
                                : 'cursor-pointer border-gray-300 bg-white'
                            }`}
                    >
                        <option value="">
                            {label}: {placeholder || 'ทั้งหมด'}
                        </option>
                        {options.map((opt) => (
                            <option key={opt.id} value={opt.id}>
                                {opt.name}
                            </option>
                        ))}
                    </select>
                ))}
            </div>
        </div>
    );
};

export default memo(FilterBar);