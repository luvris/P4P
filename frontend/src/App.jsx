import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import LoginPage from './pages/LoginPage';
import ImportPage from './pages/ImportPage';
import EmployeePage from './pages/Employee';
import ReserveFundPage from './pages/ReserveFund';
import SalaryAdjustmentPage from './pages/SalaryAdjustment';
import HrImportPage from './pages/HrImportPage';
import ProtectedRoute from './components/ProtectedRoute';
import DashboardLayout from './components/layout/DashboardLayout';
import { FiscalYearProvider } from './contexts/FiscalYearProvider';

function App() {
  return (
    <FiscalYearProvider>
      <BrowserRouter>
      <Routes>
        {/* Public */}
        <Route path="/" element={<LoginPage />} />

        {/* Dashboard — หน้าหลักหลัง login: บริหารงานบุคคล / รายชื่อบุคลากร
            finance เข้าดูได้แบบ read-only (ปุ่มเพิ่ม/แก้ไขถูกซ่อน) */}
        <Route
          path="/dashboard"
          element={
            <ProtectedRoute allowedRoles={['admin', 'hr', 'finance']}>
              <DashboardLayout title="รายชื่อบุคลากร">
                <EmployeePage />
              </DashboardLayout>
            </ProtectedRoute>
          }
        />

        {/* HR — บริหารงานบุคคล (route เดิม คงไว้ไม่ให้ลิงก์เดิมพัง) */}
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

        {/* HR — คำนวณเงินสำรอง (ระบุเปอร์เซ็นต์เอง) */}
        <Route
          path="/hr/reserve-fund"
          element={
            <ProtectedRoute allowedRoles={['admin', 'hr']}>
              <DashboardLayout title="คำนวณเงินสำรอง">
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
    </FiscalYearProvider>
  );
}

export default App;