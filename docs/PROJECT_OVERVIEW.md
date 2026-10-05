# P4P — ภาพรวมโปรเจค (Project Overview)

> 🔒 **นโยบายข้อมูลส่วนบุคคล (PDPA)**
> เอกสารทุกไฟล์ใน `docs/` ห้ามมีชื่อ-สกุลจริง เลขบัตรประชาชน
> เลขที่บัญชี เงินเดือน หรือตำแหน่งของบุคคลจริง — ใช้ค่าสมมติเท่านั้น
> ตัวอย่างการนำเข้าที่อ้างอิงในเอกสารเป็นข้อมูลสมมติทั้งหมด
> (เอกสารประกอบทั้งหมดของโปรเจครวมไว้ที่ `docs/` ที่เดียว)

## 1. ภาพรวม (Overview)

P4P เป็นระบบบริหารจัดการบุคลากรและข้อมูลการเงินของโรงพยาบาล (Hospital HR & Finance System) แบ่งเป็น Monorepo 2 ส่วน:

- `backend/` — Laravel API (PHP)
- `frontend/` — React SPA (Vite)

ระบบรองรับการนำเข้า (Import) ข้อมูลจาก **ไฟล์เงินเดือนรูปแบบใหม่ (39 คอลัมน์)** ไฟล์เดียวกันนี้ถูกใช้ได้ 2 ที่:
นำเข้าทะเบียนบุคลากร (`/api/hr/imports`) และนำเข้าเงินเดือน (`/api/finance/imports`)
พร้อมระบบยืนยันตัวตนแบบ Role-based
(ดูรายละเอียดที่ [HR_IMPORT_GUIDE.md](./HR_IMPORT_GUIDE.md) และ [EXCEL_TO_DATABASE_MAPPING.md](./EXCEL_TO_DATABASE_MAPPING.md))

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
│   │       ├── ImportService.php                     # นำเข้า payroll
│   │       ├── NewFormatEmployeeImportService.php    # นำเข้าทะเบียนบุคลากร
│   │       ├── DutyAssignmentImportService.php       # นำเข้าภารกิจ/กลุ่มงาน/งาน
│   │       └── Parsers/
│   │           ├── NewFormatPayrollParser.php        # รูปแบบใหม่ 39 คอลัมน์
│   │           ├── XlsxParser.php                    # รูปแบบเดิม (fallback)
│   │           └── DutyAssignmentXlsxParser.php
│   ├── database/
│   │   ├── migrations/          # ตารางทั้งหมด
│   │   └── seeders/            # LookupSeeder, EmployeeFromPayrollSeeder, etc.
│   ├── scripts/                # สคริปต์ช่วยตรวจสอบบนเครื่องนักพัฒนา (ดู scripts/README.md)
│   └── routes/api.php          # เส้นทาง API ทั้งหมด
├── frontend/                    # React SPA
│   └── src/
│       ├── pages/              # LoginPage, EmployeePage, HrImportPage, ImportPage
│       ├── components/
│       │   ├── layout/         # DashboardLayout, Sidebar, Header
│       │   ├── employee/       # StatCards, FilterBar, EmployeeTable, AddEmployeeDrawer
│       │   ├── features/       # import/*, login/*
│       │   └── ui/             # Input, Button
│       ├── services/           # api.js, importService, hrImportService, employeeService
│       ├── hooks/              # useAuth, useEmployees, useLookups, useDebounce
│       └── App.jsx             # Routing
└── docs/                       # เอกสารทั้งหมดของโปรเจค (แหล่งเดียว)
    ├── PROJECT_OVERVIEW.md      # ไฟล์นี้
    ├── HR_IMPORT_GUIDE.md      # คู่มือนำเข้าทะเบียนบุคลากร (ไฟล์เงินเดือนรูปแบบใหม่)
    ├── EXCEL_TO_DATABASE_MAPPING.md  # ตารางแมปคอลัมน์ Excel → ฐานข้อมูล
    └── SECURITY_AUDIT.md       # รายงานตรวจสอบความปลอดภัย (มีข้อมูลอ่อนไหว — ห้ามส่งต่อภายนอก)
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
| POST | `/api/hr/imports/preview` | `auth:sanctum` + `role:admin,hr` | ดูตัวอย่างทะเบียนบุคลากรจากไฟล์เงินเดือนรูปแบบใหม่ (10 คนแรกของงวดล่าสุด) |
| POST | `/api/hr/imports` | `auth:sanctum` + `role:admin,hr` | นำเข้าทะเบียนบุคลากร (upsert ตามเลขบัตรประชาชน) |
| GET | `/api/hr/imports` | `auth:sanctum` + `role:admin,hr` | ประวัติการนำเข้าบุคลากร |
| POST | `/api/hr/duty-assignment-imports/preview` | `auth:sanctum` + `role:admin,hr` | ดูตัวอย่างการเชื่อมภารกิจ/กลุ่มงาน/งาน (PID) |
| POST | `/api/hr/duty-assignment-imports` | `auth:sanctum` + `role:admin,hr` | นำเข้าภารกิจ/กลุ่มงาน/งาน |
| GET | `/api/hr/duty-assignment-imports` | `auth:sanctum` + `role:admin,hr` | ประวัติการนำเข้าภารกิจ |
| GET | `/api/reserve-fund` | `auth:sanctum` | สรุปเงินสำรอง (ฐาน = `payrolls.total_income`) |
| GET | `/api/reserve-fund/annual` | `auth:sanctum` | สรุปเงินสำรองรายปีงบประมาณ |
| POST | `/api/finance/imports` | `auth:sanctum` + `role:admin,finance` | นำเข้าไฟล์เงินเดือน (รองรับรูปแบบใหม่/เดิมอัตโนมัติ) |
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
- **payrolls** — ข้อมูลเงินเดือน 1 แถว = 1 คน × 1 งวด (เชื่อมกับ employees ผ่าน `citizen_id` ไม่ใช่ `id`) มีคอลัมน์รูปแบบใหม่ครบ เช่น `fiscal_year`, `period_month`, `seq_number`, `total_direct_income`, `total_indirect_income`, `total_income` (ฐานคำนวณเงินสำรอง)
  คอลัมน์ฝั่งบุคลากรที่เพิ่มจากไฟล์รูปแบบใหม่: `bank_account_2`, `latest_salary`, `latest_period_year`, `latest_period_month`
- **imports** — ประวัติ/สถานะการนำเข้า

### ตาราง Lookup
`prefixes`, `employee_types`, `positions`, `duties`, `groups`, `works`, `departments`, `employee_statuses`

### ความสัมพันธ์ที่สำคัญ
- `payrolls` ↔ `employees` ผ่าน `citizen_id`
- `groups` มี `works` อยู่ภายใต้ (hierarchy: duties → groups → works)
- `employees` อ้างอิง lookup หลายตารางผ่าน foreign keys

## 6. Flow การนำเข้าข้อมูล (Import Flow)

### 6.1 นำเข้าเงินเดือน (`POST /api/finance/imports`)

1. Frontend อัปโหลดไฟล์ Excel (timeout 180 วินาที เพราะไฟล์จริงใช้เวลา 10–20 วิ)
2. `ImportController` เรียก `ImportService`
3. `ImportService::resolveParser()` เลือก `NewFormatPayrollParser` ถ้าไฟล์มีคอลัมน์
   ลำดับที่/ปี/เดือน + คอลัมน์รายรับหลัก มิฉะนั้นใช้ `XlsxParser` (รูปแบบเดิม)
4. parser อ่านทุกแถวพร้อมงวดของแถวนั้น (`fiscal_year`/`period_month`/`period_year`)
5. ตรวจข้อมูลซ้ำด้วย `citizen_id + fiscal_year + period_month` (รวมงวด!) แล้วข้ามแถวที่ซ้ำ
6. บันทึกภายใน `DB::transaction`
7. อัปเดตสถานะ Import: `processing` → `completed` / `failed`

### 6.2 นำเข้าทะเบียนบุคลากร (`POST /api/hr/imports`)

1. ใช้ `NewFormatPayrollParser` ตัวเดียวกันอ่านไฟล์ (ถ้าไม่ใช่รูปแบบใหม่ → 422)
2. `NewFormatEmployeeImportService::parse()` ยุบหลายงวดเหลือ **งวดล่าสุดต่อ `id card`**
   แถวไหนไม่มีเลขบัตร → ข้ามพร้อมคำเตือน
3. `preview()` คืน 10 แถวแรก + จำนวน `new_count` / `update_count`
4. `import()` upsert ตาราง `employees` ตาม `citizen_id` ใน `DB::transaction`
   สร้าง `positions` ใหม่อัตโนมัติถ้าชื่อตำแหน่งยังไม่มี
5. ตอบกลับ `summary { inserted, updated, skipped, errors, total }`

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
| `/hr/import` | HrImportPage | admin, hr |
| `/finance/import` | ImportPage | admin, finance |

หน้า `/hr/import` มี 2 ประเภทการนำเข้า:
- **นำเข้าข้อมูลบุคลากร** — ไฟล์เงินเดือนรูปแบบใหม่ → ทะเบียน `employees`
- **นำเข้าข้อมูลการอยู่ภารกิจของบุคลากร** — ไฟล์ `PID | DUTY | PARTY | AGENCIES` → ภารกิจ/กลุ่มงาน/งาน

## 8. Flow การยืนยันตัวตน (Auth Flow)

1. ผู้ใช้กรอก username/password ที่ LoginPage
2. ส่ง `POST /api/login` → ได้รับ Bearer token
3. Token เก็บใน `localStorage`
4. Request interceptor เพิ่ม `Authorization: Bearer <token>`
5. เมื่อ token หมดอายุ (401) → ล้าง token + redirect ไป `/login`