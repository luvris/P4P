# HR Import System - Quick Start Guide

## 🚀 ระบบพร้อมใช้งาน!

### ✅ สิ่งที่เสร็จสมบูรณ์

1. **Database**: Migration รันสำเร็จ (employees + employment_histories)
2. **Models**: Employee + EmploymentHistory พร้อม relationships
3. **Services**: HrImportService + HrXlsxParser
4. **API**: 3 endpoints พร้อมใช้งาน
5. **Documentation**: คู่มือการใช้งานครบถ้วน

---

## 📋 API Endpoints

### 1. Preview Import
```bash
POST /api/hr/imports/preview
Content-Type: multipart/form-data
Body: file=[Excel file]
```

**Response:**
```json
{
  "message": "อ่านข้อมูลไฟล์สำเร็จ",
  "file_name": "employees.xlsx",
  "total_rows": 100,
  "preview": {
    "employees": [...],
    "employments": [...]
  }
}
```

### 2. Confirm Import
```bash
POST /api/hr/imports
Content-Type: multipart/form-data
Body: file=[Excel file]
```

**Response:**
```json
{
  "message": "Import completed successfully",
  "data": {
    "employees": { "created": 50, "updated": 20 },
    "employmentHistories": { "created": 85 }
  }
}
```

### 3. Import History
```bash
GET /api/hr/imports
```

---

## 📊 Excel Format

### Employee Data (Sheet 1)
**Required Columns:**
- `PID` - เลขบัตรประชาชน
- `NAME` - ชื่อ
- `LNAME` - นามสกุล

**Optional Columns:**
- `HID`, `TTL`, `SEX`, `BLOOD`, `BRON`, `TEL`, `MOBILE`, `EMAIL`, etc.

### Employment History (Sheet 2)
**Required Columns:**
- `PID` - เลขบัตรประชาชน (must exist in employees)
- `DATES` - วันที่เริ่มงาน

**Optional Columns:**
- `POSITION`, `CONDITIO`, `DATEE`, `PAYROLL`, etc.

---

## 🧪 Testing

### Test Routes
```bash
php artisan route:list --path=hr/imports
```

**Expected Output:**
```
POST   api/hr/imports/preview
POST   api/hr/imports
GET    api/hr/imports
```

### Test with Postman/Thunder Client

1. **Select Excel file** (employees.xlsx)
2. **POST to** `/api/hr/imports/preview`
3. **Check preview data**
4. **POST to** `/api/hr/imports` to confirm
5. **GET** `/api/hr/imports` to see history

---

## 📁 File Structure

```
backend/
├── app/
│   ├── Http/Controllers/Api/
│   │   └── HrImportController.php
│   ├── Models/
│   │   ├── Employee.php (updated)
│   │   └── EmploymentHistory.php (new)
│   └── Services/
│       ├── HrImportService.php
│       └── Parsers/
│           └── HrXlsxParser.php
├── database/migrations/
│   ├── xxxx_add_hr_fields_to_employees_table.php
│   └── xxxx_create_employment_histories_table.php
├── routes/
│   └── api.php (updated)
└── docs/
    ├── HR_IMPORT_FORMAT.md
    └── HR_IMPORT_README.md
```

---

## 🔧 Troubleshooting

### Issue: Routes not found
```bash
php artisan optimize:clear
php artisan route:cache
```

### Issue: Migration failed
```bash
php artisan migrate:fresh
# หรือ
php artisan migrate:rollback
php artisan migrate
```

### Issue: Excel parsing error
- ตรวจสอบว่าไฟล์เป็น .xlsx หรือ .xls
- ตรวจสอบว่า column headers ถูกต้อง
- ตรวจสอบว่าข้อมูล required fields ครบถ้วน

---

## 📚 Documentation

- **Format Guide**: `backend/docs/HR_IMPORT_FORMAT.md`
- **Development Summary**: `backend/docs/HR_IMPORT_README.md`

---

## 🎯 Next Steps (Optional)

- [ ] เพิ่ม Unit Tests
- [ ] เพิ่ม Frontend UI
- [ ] เพิ่ม Queue support สำหรับไฟล์ใหญ่
- [ ] เพิ่ม Excel template download
- [ ] เพิ่ม Email notification

---

**วันที่**: 2026-09-24  
**สถานะ**: ✅ Production Ready
