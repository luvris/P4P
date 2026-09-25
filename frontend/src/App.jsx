import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import LoginPage from './pages/LoginPage';
import ImportPage from './pages/ImportPage';
import EmployeePage from './pages/Employee';
import ReserveFundPage from './pages/ReserveFund';
import SalaryAdjustmentPage from './pages/SalaryAdjustment';
import HrImportPage from './pages/HrImportPage';
import ProtectedRoute from './components/ProtectedRoute';
import DashboardLayout from './components/layout/DashboardLayout';

function App() {
  return (
    <BrowserRouter>
      <Routes>
        {/* Public */}
        <Route path="/" element={<LoginPage />} />

        {/* HR — บริหารงานบุคคล */}
        <Route
          path="/hr"
          element={
            <ProtectedRoute allowedRoles={['admin', 'hr']}>
              <DashboardLayout title="บริหารงานบุคคล">
                <EmployeePage />
              </DashboardLayout>
            </ProtectedRoute>
          }
        />

        {/* HR — คำนวณเงินสำรอง 3% */}
        <Route
          path="/hr/reserve-fund"
          element={
            <ProtectedRoute allowedRoles={['admin', 'hr']}>
              <DashboardLayout title="คำนวณเงินสำรอง 3%">
                <ReserveFundPage />
              </DashboardLayout>
            </ProtectedRoute>
          }
        />

        {/* HR — ปรับฐานเงินเดือน */}
        <Route
          path="/hr/salary-adjustments"
          element={
            <ProtectedRoute allowedRoles={['admin', 'hr']}>
              <DashboardLayout title="ปรับฐานเงินเดือน">
                <SalaryAdjustmentPage />
              </DashboardLayout>
            </ProtectedRoute>
          }
        />

        {/* HR — นำเข้าข้อมูลบุคลากร */}
        <Route
          path="/hr/import"
          element={
            <ProtectedRoute allowedRoles={['admin', 'hr']}>
              <DashboardLayout title="นำเข้าข้อมูลบุคลากร">
                <HrImportPage />
              </DashboardLayout>
            </ProtectedRoute>
          }
        />

        {/* Finance — นำเข้าข้อมูลการเงิน */}
        <Route
          path="/finance/import"
          element={
            <ProtectedRoute allowedRoles={['admin', 'finance']}>
              <DashboardLayout title="นำเข้าข้อมูลการเงิน">
                <ImportPage />
              </DashboardLayout>
            </ProtectedRoute>
          }
        />

        {/* Fallback */}
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </BrowserRouter>
  );
}

export default App;