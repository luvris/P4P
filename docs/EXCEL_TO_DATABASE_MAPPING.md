# 📋 Excel Columns → Database Schema Mapping

## ตารางเปรียบเทียบแบบละเอียด

### 🟢 ข้อมูลส่วนตัวพนักงาน (employees table)

| # | Excel Column | Database Column | Type | คำอธิบาย |
|---|--------------|-----------------|------|----------|
| 1 | **PID** | `employee_id` | varchar | รหัสพนักงานภายใน (ไม่ใช่เลขบัตรประชาชน) |
| 2 | **HID** | `citizen_id` | varchar(13) | เลขบัตรประชาชน 13 หลัก |
| 3 | **TTL** | `prefix_id` | bigint | คำนำหน้าชื่อไทย → แปลงเป็น ID (นาย→1, นาง→2) |
| 4 | **NAME** | `first_name` | varchar | ชื่อภาษาไทย |
| 5 | **LNAME** | `last_name` | varchar | นามสกุลภาษาไทย |
| 6 | **SEX** | `sex` | enum('M','F','O') | เพศ (ชาย→M, หญิง→F) |
| 7 | **BLOOD** | `blood_type` | varchar | กรุ๊ปเลือด (A, B, AB, O) |
| 8 | **BRON** | `birth_date` | date | วันเกิด |
| 9 | **TEL** | `tel` | varchar(20) | เบอร์โทรศัพท์ |
| 10 | **MOBILE** | `mobile` | varchar(20) | เบอร์มือถือ |
| 11 | **ADDRESS1** | `address1` | text | ที่อยู่ 1 |
| 12 | **ADDRESS2** | `address2` | text | ที่อยู่ 2 |
| 13 | **ETTL** | `english_prefix` | varchar | คำนำหน้าชื่ออังกฤษ (Mr., Mrs., Ms.) |
| 14 | **ENAME** | `english_first_name` | varchar | ชื่อภาษาอังกฤษ |
| 15 | **ELNAME** | `english_last_name` | varchar | นามสกุลภาษาอังกฤษ |
| 16 | **STATUS** | `status_id` | bigint | สถานะ → แปลงเป็น ID (ทำงาน→1, ลาออก→3) |
| 17 | **LEAVES_BY** | `leaves_by` | varchar | - |
| 18 | **FINGER** | `finger` | varchar | ข้อมูลลายนิ้วมือ |
| 19 | **EMAIL** | `email` | varchar | อีเมล |
| 20 | **TOKENLINE** | `line_token` | varchar | LINE Token |

---

### 🟡 ข้อมูลประวัติการทำงาน (employment_histories table)

| # | Excel Column | Database Column | Type | คำอธิบาย |
|---|--------------|-----------------|------|----------|
| 21 | **SER** | `serial_number` | varchar | เลขที่ประวัติ (unique ต่อ employee) |
| 22 | **PID** (ซ้ำ) | `employee_id` | bigint | รหัสพนักงาน → ใช้เชื่อมกับ employees.id |
| 23 | **WID** | `wid` | varchar | - |
| 24 | **EMPLOYEE** | ✅ `employee_type_id` | bigint | **ประเภทบุคลากร** (ข้าราชการ, พนักงานราชการ) |
| 25 | **WORKID** | `work_id` | bigint | - |
| 26 | **MANAGE** | `manager` | varchar | ชื่อผู้จัดการ/หัวหน้า |
| 27 | **POSITION** | ✅ `position_id` | bigint | **ตำแหน่งงาน** (พยาบาล, แพทย์) |
| 28 | **CLASS** | `class` | varchar | ระดับ/ชั้น |
| 29 | **CONDITION** | `condition` | varchar | เงื่อนไข |
| 30 | **DATES** | `start_date` | date | วันที่เริ่มงาน/เริ่มตำแหน่ง |
| 31 | **DATEE** | `end_date` | date | วันที่สิ้นสุด/ย้ายตำแหน่ง |
| 32 | **EXP** | `experience` | varchar | ประสบการณ์/หมายเหตุ ✅ **ใช้เช็ค "ตำแหน่งปัจจุบัน"** |
| 33 | **MARK** | `mark` | text | หมายเหตุ ✅ **ใช้เช็ค "ลาออก"** |
| 34 | **PAYROLL** | `payroll` | decimal(10,2) | เงินเดือน |
| 35 | **DATEDIREC** | `appointment_date` | date | วันที่ในคำสั่งแต่งตั้ง |
| 36 | **CODEDIREC** | `appointment_code` | varchar | เลขที่คำสั่งแต่งตั้ง |

---

## 🔄 การแปลงข้อมูลอัตโนมัติ

### 1️⃣ TTL (คำนำหน้าไทย) → prefix_id
```
"นาย" → ค้นหาใน prefixes table → prefix_id = 1
"นาง" → prefix_id = 2
"นางสาว" → prefix_id = 3
```

### 2️⃣ EMPLOYEE (ประเภทบุคลากร) → employee_type_id
```
"ข้าราชการ" → employee_types table → id = 1
"พนักงานราชการ" → id = 2
"พนักงานกระทรวงสาธารณสุข" → id = 6
ถ้าไม่มี → สร้างใหม่อัตโนมัติ
```

### 3️⃣ POSITION (ตำแหน่ง) → position_id
```
"พยาบาลวิชาชีพ" → positions table → id = X
"นายแพทย์" → id = Y
ถ้าไม่มี → สร้างใหม่อัตโนมัติ
```

### 4️⃣ STATUS (สถานะ) → status_id
```
"ทำงาน" → "ปฏิบัติงานอยู่" → id = 1
"ออกจากงาน" → "ลาออก" → id = 3
```

### 5️⃣ PID (ปรากฏ 2 ครั้ง)
- **ครั้งที่ 1 (คอลัมน์ 1):** employees.employee_id
- **ครั้งที่ 2 (คอลัมน์ 22):** ใช้หา employees.id แล้วเก็บเป็น employment_histories.employee_id

---

## ⚠️ หมายเหตุสำคัญ

1. **PID ซ้ำ 2 ครั้ง** - ครั้งที่ 1 เป็นข้อมูลพนักงาน, ครั้งที่ 2 เชื่อมประวัติ
2. **EXP column** - ใช้ตรวจหา "ตำแหน่งปัจจุบัน" เพื่อกำหนดว่าประวัติไหนเป็นปัจจุบัน
3. **MARK column** - ใช้ตรวจหา "ลาออก" เพื่อกำหนดสถานะ
4. **แถวเดียวใน Excel** = ข้อมูลพนักงาน 1 คน + ประวัติ 1 รายการ
5. **พนักงาน 1 คนอาจมีหลายแถว** = หลายประวัติ (เลื่อนขั้น, ย้ายตำแหน่ง)

---

## 📊 ตัวอย่างข้อมูล

```
PID=184 | HID=1234567890123 | TTL=นางสาว | NAME=สมหญิง | LNAME=ใจดี
ETTL=Ms. | ENAME=Somying | ELNAME=Jaidee
SER=2005 | EMPLOYEE=ข้าราชการ | POSITION=พยาบาลวิชาชีพ | EXP=ตำแหน่งปัจจุบัน

→ สร้าง employees (id=100)
→ สร้าง employment_histories (employee_id=100)
→ อัพเดต employees: employee_type_id, position_id จากประวัติที่มี "ตำแหน่งปัจจุบัน"
```
