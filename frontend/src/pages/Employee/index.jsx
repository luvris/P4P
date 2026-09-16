import { useState, useEffect, useCallback, useMemo } from 'react';
import toast from 'react-hot-toast';

import useEmployees from '../../hooks/useEmployees';
import useLookups from '../../hooks/useLookups';
import useDebounce from '../../hooks/useDebounce';

import StatCards from '../../components/employee/StatCards';
import FilterBar from '../../components/employee/FilterBar';
import EmployeeTable from '../../components/employee/EmployeeTable';
import AddEmployeeDrawer from '../../components/employee/AddEmployeeDrawer';

const EmployeePage = () => {
    const [drawerOpen, setDrawerOpen] = useState(false);
    const [editingEmployee, setEditingEmployee] = useState(null);

    const [searchInput, setSearchInput] = useState('');
    const debouncedSearch = useDebounce(searchInput, 500);

    const {
        employees, meta, loading, error,
        stats, statsLoading,
        filters, updateFilters, setSearchFilter,
        createEmployee,
        updateEmployee,
    } = useEmployees();

    const { lookups } = useLookups();

    useEffect(() => {
        setSearchFilter(debouncedSearch);
    }, [debouncedSearch, setSearchFilter]);

    const handleFilterChange = useCallback((newFilters) => {
        if ('search' in newFilters) {
            setSearchInput(newFilters.search);
        } else {
            updateFilters(newFilters, true);
        }
    }, [updateFilters]);

    const handlePageChange = useCallback((page) => {
        updateFilters({ page }, false);
    }, [updateFilters]);

    const handlePerPageChange = useCallback((perPage) => {
        updateFilters({ per_page: perPage, page: 1 }, false);
    }, [updateFilters]);

    const handleAddClick = useCallback(() => {
        setEditingEmployee(null);
        setDrawerOpen(true);
    }, []);

    const handleEditClick = useCallback((emp) => {
        setEditingEmployee(emp);
        setDrawerOpen(true);
    }, []);

    const handleDrawerClose = useCallback(() => {
        setDrawerOpen(false);
        setEditingEmployee(null);
    }, []);

    const handleCreateEmployee = useCallback(async (payload) => {
        const result = await createEmployee(payload);
        if (result.success) {
            toast.success('เพิ่มบุคลากรสำเร็จ');
        } else {
            toast.error(result.error || 'ไม่สามารถเพิ่มบุคลากรได้');
        }
        return result;
    }, [createEmployee]);

    const handleUpdateEmployee = useCallback(async (payload) => {
        if (!editingEmployee) return { success: false, error: 'ไม่พบข้อมูลบุคลากรที่จะแก้ไข' };
        const result = await updateEmployee(editingEmployee.id, payload);
        if (result.success) {
            toast.success('บันทึกข้อมูลสำเร็จ');
        } else {
            toast.error(result.error || 'ไม่สามารถบันทึกข้อมูลได้');
        }
        return result;
    }, [editingEmployee, updateEmployee]);

    const filterBarFilters = useMemo(
        () => ({ ...filters, search: searchInput }),
        [filters, searchInput]
    );

    return (
        <div className="space-y-4">
            {error && (
                <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                    {error}
                </div>
            )}

            <StatCards stats={stats} loading={statsLoading} />

            <FilterBar
                filters={filterBarFilters}
                onChange={handleFilterChange}
                onAdd={handleAddClick}
                lookups={lookups}
            />

            <EmployeeTable
                employees={employees}
                meta={meta}
                loading={loading}
                onPageChange={handlePageChange}
                onPerPageChange={handlePerPageChange}
                onEdit={handleEditClick}
            />

            <AddEmployeeDrawer
                open={drawerOpen}
                onClose={handleDrawerClose}
                lookups={lookups}
                onSubmit={editingEmployee ? handleUpdateEmployee : handleCreateEmployee}
                employee={editingEmployee}
            />
        </div>
    );
};

export default EmployeePage;