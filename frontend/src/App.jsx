import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import LoginPage from './pages/LoginPage';
import ImportPage from './pages/ImportPage';
import EmployeePage from './pages/Employee';
import ReserveFundPage from './pages/ReserveFund';
import SalaryAdjustmentPage from './pages/SalaryAdjustment';
import HrImportPage from './pages/HrImportPage';
import ClaimListPage from './pages/TravelExpenseClaim/ClaimListPage';
import ClaimFormPage from './pages/TravelExpenseClaim/ClaimFormPage';
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

        {/* Finance — จัดทำใบเบิกค่าใช้จ่ายเดินทางไปราชการ */}
        <Route
          path="/finance/travel-expense-claims"
          element={
            <ProtectedRoute allowedRoles={['admin', 'finance']}>
              <DashboardLayout title="จัดทำใบเบิกค่าใช้จ่าย">
                <ClaimListPage />
              </DashboardLayout>
            </ProtectedRoute>
          }
        />
        <Route
          path="/finance/travel-expense-claims/create"
          element={
            <ProtectedRoute allowedRoles={['admin', 'finance']}>
              <DashboardLayout title="สร้างใบเบิกค่าใช้จ่าย">
                <ClaimFormPage mode="edit" />
              </DashboardLayout>
            </ProtectedRoute>
          }
        />
        <Route
          path="/finance/travel-expense-claims/:id"
          element={
            <ProtectedRoute allowedRoles={['admin', 'finance']}>
              <DashboardLayout title="รายละเอียดใบเบิกค่าใช้จ่าย">
                <ClaimFormPage mode="view" />
              </DashboardLayout>
            </ProtectedRoute>
          }
        />
        {/* แก้ไขได้เฉพาะเอกสารร่าง — backend บังคับด้วย is_editable */}
        <Route
          path="/finance/travel-expense-claims/:id/edit"
          element={
            <ProtectedRoute allowedRoles={['admin', 'finance']}>
              <DashboardLayout title="แก้ไขใบเบิกค่าใช้จ่าย">
                <ClaimFormPage mode="edit" />
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