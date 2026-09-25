# รูปแบบไฟล์ Excel สำหรับการนำเข้าข้อมูลบุคลากร (HR Import)

## ภาพรวม

ระบบรองรับการนำเข้าข้อมูลบุคลากร 2 ประเภทในไฟล์เดียวกัน:
1. **ข้อมูลส่วนตัวพนักงาน** (Employee Personal Information)
2. **ข้อมูลการจ้างงาน/ประวัติการทำงาน** (Employment History)

ระบบจะตรวจจับประเภทข้อมูลอัตโนมัติจากชื่อ column headers

---

## 1. ข้อมูลส่วนตัวพนักงาน (Employees Sheet)

### Column Headers ที่รองรับ

| Column Name | Aliases | Type | Required | Description | Example |
|------------|---------|------|----------|-------------|---------|
| **PID** | pid, เลขบัตรประชาชน, บัตรประชาชน | String | ✅ | เลขบัตรประชาชน 13 หลัก | 1234567890123 |
| **HID** | hid, รหัส hr | String | | รหัสพนักงาน (HR ID) | EMP001 |
| **TTL** | ttl, คำนำหน้า | String | | คำนำหน้าชื่อ | นาย, นาง, นางสาว |
| **NAME** | name, ชื่อ | String | ✅ | ชื่อ | สมชาย |
| **LNAME** | lname, นามสกุล | String | ✅ | นามสกุล | ใจดี |
| **SEX** | sex, เพศ | String | | เพศ | ชาย, หญิง |
| **BLOOD** | blood, กรุ๊ปเลือด | String | | หมู่เลือด | A, B, O, AB |
| **BRON** | bron, วันเกิด | Date | | วันเกิด | 1990-01-15 |
| **TEL** | tel, โทรศัพท์ | String | | เบอร์โทรศัพท์บ้าน | 021234567 |
| **MOBILE** | mobile, มือถือ | String | | เบอร์มือถือ | 0812345678 |
| **ADDRESS1** | address1, ที่อยู่ 1 | String | | ที่อยู่บรรทัดที่ 1 | 123 ถ.สุขุมวิท |
| **ADDRESS2** | address2, ที่อยู่ 2 | String | | ที่อยู่บรรทัดที่ 2 | แขวงคลองเตย กทม 10110 |
| **ETTL** | ettl | String | | คำนำหน้าชื่อผู้ติดต่อฉุกเฉิน | นาย |
| **ENAME** | ename | String | | ชื่อผู้ติดต่อฉุกเฉิน | สมหมาย |
| **ELNAME** | elname | String | | นามสกุลผู้ติดต่อฉุกเฉิน | ใจดี |
| **STATUS** | status, สถานะ | String | | สถานะพนักงาน | active, inactive |
| **EMAIL** | email, อีเมล | String | | อีเมล | somchai@example.com |

### ตัวอย่างไฟล์

```
PID             HID     TTL     NAME    LNAME   SEX     BLOOD   BRON        TEL         MOBILE      EMAIL
1234567890123   EMP001  นาย     สมชาย   ใจดี    ชาย     O       1990-01-15  021234567   0812345678  somchai@example.com
9876543210987   EMP002  นาง     สมหญิง สวยงาม  หญิง    A       1992-05-20  025551234   0898765432  somying@example.com
```

---

## 2. ข้อมูลการจ้างงาน (Employment History Sheet)

### Column Headers ที่รองรับ

| Column Name | Aliases | Type | Required | Description | Example |
|------------|---------|------|----------|-------------|---------|
| **SER** | ser, เลขที่ | String | | เลขที่ลำดับ | 1, 2, 3 |
| **PID** | pid, เลขบัตรประชาชน | String | ✅ | เลขบัตรประชาชน (เชื่อมโยงกับพนักงาน) | 1234567890123 |
| **WID** | wid | String | | รหัสงาน | W001 |
| **EMPLOYEE** | employee, ชื่อพนักงาน | String | | ชื่อเต็มพนักงาน | นายสมชาย ใจดี |
| **WORKID** | workid | String | | รหัสงาน | WORK123 |
| **POSITION** | position, ตำแหน่ง | String | | ตำแหน่งงาน | พนักงานทั่วไป |
| **CLASS** | class, ระดับ | String | | ระดับพนักงาน | 3 |
| **CONDITIO** | conditio, เงื่อนไข | String | | เงื่อนไขการจ้าง | ประจำ, ชั่วคราว |
| **DATES** | dates, วันเริ่มงาน | Date | ✅ | วันที่เริ่มงาน | 2020-01-01 |
| **DATEE** | datee, วันสิ้นสุด | Date | | วันที่สิ้นสุดงาน (ถ้ามี) | 2025-12-31 |
| **PAYROLL** | payroll, เงินเดือน | Decimal | | เงินเดือน | 15000.00 |

### ตัวอย่างไฟล์

```
SER PID             EMPLOYEE        POSITION        CONDITIO    DATES       DATEE       PAYROLL
1   1234567890123   นายสมชาย ใจดี   พนักงานทั่วไป   ประจำ       2020-01-01              15000.00
2   1234567890123   นายสมชาย ใจดี   หัวหน้างาน      ประจำ       2022-01-01              25000.00
3   9876543210987   นางสมหญิง สวยงาม ผู้จัดการ       ประจำ       2021-06-01              35000.00
```

---

## การใช้งาน

### วิธีการจัดเตรียมไฟล์

1. **แยกเป็น 2 ไฟล์**: `employees.xlsx` และ `employment_history.xlsx`
2. **หรือรวมใน 1 ไฟล์ แต่แยก Sheet**
3. **บรรทัดแรกต้องเป็น Header**: ชื่อ column ต้องอยู่แถวแรก
4. **ข้อมูลเริ่มต้นจากแถวที่ 2**

### รูปแบบข้อมูล

- **วันที่**: รองรับทั้ง Excel date format และ text (YYYY-MM-DD)
- **เลขบัตรประชาชน**: อนุญาตให้มีขีด (-) หรือช่องว่าง ระบบจะลบออกอัตโนมัติ
- **ตัวเลข**: สามารถมีคอมม่า (,) หรือช่องว่างได้ ระบบจะลบออกอัตโนมัติ

### การ Import

1. เลือกไฟล์ Excel (.xlsx หรือ .xls)
2. ระบบจะแสดง Preview 10 แถวแรก
3. ตรวจสอบข้อมูลและคำเตือน (ถ้ามี)
4. กด Confirm เพื่อนำเข้าข้อมูล

### หมายเหตุ

- ระบบใช้ **citizen_id (PID)** เป็น key หลักในการ **upsert** ข้อมูล
  - หากมี citizen_id นี้อยู่แล้ว → **อัปเดต**
  - หากไม่มี → **สร้างใหม่**
- Employment History จะเชื่อมโยงกับ employee ผ่าน `employee_id`
- แถวที่มีข้อผิดพลาดจะถูกข้าม (skip) และบันทึกใน error log

---

## API Endpoints

### Preview Import
```
POST /api/hr/imports/preview
Content-Type: multipart/form-data
file: <Excel file>
```

### Confirm Import
```
POST /api/hr/imports
Content-Type: multipart/form-data
file: <Excel file>
```

### Import History
```
GET /api/hr/imports
```

---

**อัปเดตล่าสุด**: 2026-09-24
