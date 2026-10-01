# P4P — ภาพรวมโปรเจค (Project Overview)

## 1. ภาพรวม (Overview)

P4P เป็นระบบบริหารจัดการบุคลากรและข้อมูลการเงินของโรงพยาบาล (Hospital HR & Finance System) แบ่งเป็น Monorepo 2 ส่วน:

- `backend/` — Laravel API (PHP)
- `frontend/` — React SPA (Vite)

ระบบรองรับการนำเข้า (Import) ข้อมูลเงินเดือนจากไฟล์ Excel และการบริหารจัดการข้อมูลพนักงาน (Employee) พร้อมระบบยืนยันตัวตนแบบ Role-based

## 2. เทคโนโลยี (Tech Stack)

### Backend
| หมวดหมู่ | เทคโนโลยี |
| --- | --- |
| Framework | Laravel 13 |
| PHP | ^8.3 |
| Authentication | Laravel Sanctum ^4.0 (Bearer Token) |
| Excel Parsing | PhpOffice/PhpSpreadsheet ^5.9 |
| Testing | PHPUnit ^12 |

### Frontend
| หมวดหมู่ | เทคโนโลยี |
| --- | --- |
| Framework | React 19 |
| Build Tool | Vite 8 |
| Styling | Tailwind CSS 4 |
| HTTP Client | Axios ^1.20 |
| Routing | React Router DOM ^7 |
| UI/อื่น ๆ | lucide-react, recharts, react-hot-toast |

## 3. โครงสร้างโปรเจค (Structure)

```
P4P/
├── backend/                     # Laravel API
│   ├── app/
│   │   ├── Models/              # Eloquent Models
│   │   ├── Http/
│   │   │   ├── Controllers/Api/ # AuthController, EmployeeController, ImportController
│   │   │   ├── Middleware/      # CheckRole
│   │   │   └── Requests/        # StoreEmployeeRequest
│   │   └── Services/
│   │       ├── ImportService.php
│   │       └── Parsers/XlsxParser.php
│   ├── database/
│   │   ├── migrations/          # ตารางทั้งหมด
│   │   └── seeders/            # LookupSeeder, EmployeeFromPayrollSeeder, etc.
│   └── routes/api.php          # เส้นทาง API ทั้งหมด
├── frontend/                    # React SPA
│   └── src/
│       ├── pages/              # LoginPage, EmployeePage, ImportPage
│       ├── components/
│       │   ├── layout/         # DashboardLayout, Sidebar, Header
│       │   ├── employee/       # StatCards, FilterBar, EmployeeTable, AddEmployeeDrawer
│       │   ├── features/       # import/*, login/*
│       │   └── ui/             # Input, Button
│       ├── services/           # api.js, importService, employeeService
│       ├── hooks/              # useAuth, useEmployees, useLookups, useDebounce
│       └── App.jsx             # Routing
└── PROJECT_OVERVIEW.md          # ไฟล์นี้
```

## 4. เส้นทาง API (API Routes)

### Public
| Method | Path | คำอธิบาย |
| --- | --- | --- |
| POST | `/api/login` | เข้าสู่ระบบ (ไม่ต้อง auth) |

### Authenticated
| Method | Path | Middleware | คำอธิบาย |
| --- | --- | --- | --- |
| POST | `/api/logout` | `auth:sanctum` | ออกจากระบบ |
| GET | `/api/me` | `auth:sanctum` | ข้อมูลผู้ใช้ปัจจุบัน |
| GET | `/api/profile` | `auth:sanctum` | ข้อมูลโปรไฟล์ของผู้ใช้ที่ล็อกอินอยู่ |
| PUT | `/api/profile` | `auth:sanctum` | แก้ไขชื่อ/อีเมลของตัวเอง |
| PUT | `/api/profile/password` | `auth:sanctum` | เปลี่ยนรหัสผ่าน (ยืนยันรหัสผ่านเดิม) |
| GET | `/api/hr/employees` | `auth:sanctum` + `role:admin,hr` | รายชื่อพนักงาน (search/filter/pagination) |
| POST | `/api/hr/employees` | `auth:sanctum` + `role:admin,hr` | เพิ่มพนักงาน |
| GET | `/api/hr/employees/stats` | `auth:sanctum` + `role:admin,hr` | สถิติพนักงาน |
| GET | `/api/hr/lookups` | `auth:sanctum` + `role:admin,hr` | ข้อมูลตัวเลือก (lookups) |
| POST | `/api/finance/imports` | `auth:sanctum` + `role:admin,finance` | นำเข้าไฟล์ Excel |
| GET | `/api/finance/imports` | `auth:sanctum` + `role:admin,finance` | รายการนำเข้า |
| GET | `/api/finance/imports/{import}` | `auth:sanctum` + `role:admin,finance` | รายละเอียดการนำเข้า |

### บทบาทผู้ใช้ (Roles)
- `admin` — เข้าถึงทุกส่วน
- `hr` — ดูแลพนักงาน (hr/employees)
- `finance` — ดูแลการนำเข้าข้อมูลการเงิน (finance/imports)

## 5. โครงสร้างฐานข้อมูล (Database Schema)

### ตารางหลัก
- **users** — ผู้ใช้ระบบ (`username`, `role`, password)
- **employees** — ข้อมูลพนักงาน (`citizen_id` เป็น unique natural key, softDeletes, audit created_by/updated_by)
- **payrolls** — ข้อมูลเงินเดือน (เชื่อมกับ employees ผ่าน `citizen_id` ไม่ใช่ `id`)
- **imports** — ประวัติ/สถานะการนำเข้า

### ตาราง Lookup
`prefixes`, `employee_types`, `positions`, `duties`, `groups`, `works`, `departments`, `employee_statuses`

### ความสัมพันธ์ที่สำคัญ
- `payrolls` ↔ `employees` ผ่าน `citizen_id`
- `groups` มี `works` อยู่ภายใต้ (hierarchy: duties → groups → works)
- `employees` อ้างอิง lookup หลายตารางผ่าน foreign keys

## 6. Flow การนำเข้าข้อมูล (Import Flow)

1. Frontend อัปโหลดไฟล์ Excel ผ่าน `POST /api/finance/imports`
2. `ImportController` เรียก `ImportService`
3. `ImportService::getParser()` ใช้ `match()` เลือก parser ตาม extension
4. `XlsxParser` ใช้ `$columnMap` (0-indexed, 30 คอลัมน์) map คอลัมน์ Excel กับฟิลด์ payroll
5. ตรวจสอบข้อมูลซ้ำผ่าน `Payroll` (first_name + last_name + bank_account)
6. บันทึกภายใน `DB::transaction`
7. อัปเดตสถานะ Import: `processing` → `completed` / `failed`

## 7. Flow ฝั่ง Frontend

- `api.js` — axios instance + interceptors (เพิ่ม Bearer token, จัดการ 401/403/network error)
- Token เก็บใน `localStorage`
- `ProtectedRoute` — ตรวจสอบสิทธิ์ตาม `allowedRoles`
- `DashboardLayout` — layout หลักพร้อม Sidebar + Header

### Routes
| Path | Page | Access |
| --- | --- | --- |
| `/` | LoginPage | Public |
| `/hr` | EmployeePage | admin, hr |
| `/finance/import` | ImportPage | admin, finance |

## 8. Flow การยืนยันตัวตน (Auth Flow)

1. ผู้ใช้กรอก username/password ที่ LoginPage
2. ส่ง `POST /api/login` → ได้รับ Bearer token
3. Token เก็บใน `localStorage`
4. Request interceptor เพิ่ม `Authorization: Bearer <token>`
5. เมื่อ token หมดอายุ (401) → ล้าง token + redirect ไป `/login`