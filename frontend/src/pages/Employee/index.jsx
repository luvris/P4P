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

    const [searchInput, setSearchInput] = useState('');
    const debouncedSearch = useDebounce(searchInput, 500);

    const {
        employees, meta, loading, error,
        stats, statsLoading,
        filters, updateFilters, setSearchFilter,
        createEmployee,
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

    const handleAddClick = useCallback(() => setDrawerOpen(true), []);
    const handleDrawerClose = useCallback(() => setDrawerOpen(false), []);

    const handleCreateEmployee = useCallback(async (payload) => {
        const result = await createEmployee(payload);
        if (result.success) {
            toast.success('เพิ่มบุคลากรสำเร็จ');
        } else {
            toast.error(result.error || 'ไม่สามารถเพิ่มบุคลากรได้');
        }
        return result;
    }, [createEmployee]);

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
            />

            <AddEmployeeDrawer
                open={drawerOpen}
                onClose={handleDrawerClose}
                lookups={lookups}
                onSubmit={handleCreateEmployee}
            />
        </div>
    );
};

export default EmployeePage;