import { memo, useRef, useEffect } from 'react';
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

    const dropdowns = [
        { key: 'employee_type_id', label: 'ประเภท', options: lookups.employee_types || [] },
        { key: 'position_id', label: 'ตำแหน่ง', options: lookups.positions || [] },
        { key: 'duty_id', label: 'ภารกิจ', options: lookups.duties || [] },
        { key: 'group_id', label: 'กลุ่มงาน', options: lookups.groups || [] },
        { key: 'work_id', label: 'งาน', options: lookups.works || [] },
        { key: 'status_id', label: 'สถานะ', options: lookups.employee_statuses || [] },
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
                {dropdowns.map(({ key, label, options }) => (
                    <select
                        key={key}
                        value={filters[key] || ''}
                        onChange={(e) => handleDropdownChange(key, e.target.value)}
                        className="min-w-[140px] flex-1 sm:flex-none px-3 py-2 text-sm border border-gray-300 rounded-lg bg-white focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-transparent cursor-pointer"
                    >
                        <option value="">{label}: ทั้งหมด</option>
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