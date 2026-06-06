# SRU Workload Record

ระบบบันทึกภาระงานสำหรับบุคลากรมหาวิทยาลัยราชภัฏสุราษฎร์ธานี (SRU) — บันทึกผลงานตามโครงสร้างภาระงาน PMS รายรอบประเมิน ส่งให้หัวหน้างานตรวจสอบ และสรุปผลสำหรับงานบุคคล

## คุณสมบัติ

| บทบาท | ความสามารถ |
|--------|------------|
| **บุคลากร** (`staff`) | สร้าง/แก้ไขบันทึกภาระงาน 3 ขั้นตอน แนบหลักฐาน (URL หรือไฟล์) พิมพ์บันทึก |
| **หัวหน้างาน** (`supervisor`) | คิวตรวจสอบบันทึกในแผนก อนุมัติ/ส่งกลับแก้ไข |
| **งานบุคคล** (`hr`) | แดชบอร์ดสรุปภาระงาน ส่งออก Excel |
| **ผู้ดูแลระบบ** (`admin`) | จัดการรอบประเมินและโครงสร้างภาระงาน (คัดลอก/โคลน/นำเข้าจาก PMS HTML) |

## เทคโนโลยี

- PHP 8.2+, Laravel 12
- Livewire 4, Livewire Volt, Flux UI
- Tailwind CSS 4, Vite, Chart.js
- Laravel Socialite (UApp OAuth)
- Maatwebsite Excel (ส่งออกรายงาน)
- SQLite (พัฒนา) / MySQL (production)

## ความต้องการของระบบ

- PHP 8.2 ขึ้นไป พร้อม extensions ที่ Laravel ต้องการ
- Composer
- Node.js 22+ และ npm
- [Laravel Herd](https://herd.laravel.com) หรือ web server ที่รองรับ PHP

## การติดตั้ง

```bash
# โคลนและติดตั้ง dependencies
composer install
npm install

# ตั้งค่า environment
cp .env.example .env
php artisan key:generate

# สร้างฐานข้อมูล (SQLite เป็นค่าเริ่มต้น)
touch database/database.sqlite

# migrate และ seed ข้อมูลทดสอบ
php artisan migrate --seed

# build assets
npm run build
```

เปิดแอปที่ URL ของโปรเจกต์ (เช่น `http://sru-workload-record.test` บน Herd) แล้วไปที่ `/login`

## การพัฒนา

รัน server, queue, log tail และ Vite พร้อมกัน:

```bash
composer dev
```

หรือแยกรัน:

```bash
php artisan serve
npm run dev
```

## การทดสอบ

```bash
npm run build
./vendor/bin/phpunit
```

CI รันบน GitHub Actions เมื่อ push/PR ไปยัง `main` หรือ `develop`

## บัญชีทดสอบ (หลัง seed)

รหัสผ่านทุกบัญชี: `password`

| อีเมล | บทบาท |
|-------|--------|
| `admin@university.ac.th` | ผู้ดูแลระบบ |
| `supervisor@university.ac.th` | หัวหน้างาน |
| `staff1@university.ac.th` – `staff3@university.ac.th` | บุคลากร |

Seeder สร้างปีงบประมาณ 2568–2569 พร้อมโครงสร้างภาระงาน PMS (เจ้าหน้าที่คอมพิวเตอร์) และรอบประเมินที่เปิดใช้งาน

## การเข้าสู่ระบบด้วย UApp OAuth

ตั้งค่าใน `.env` เพื่อแสดงปุ่ม "เข้าสู่ระบบด้วยบัญชีมหาวิทยาลัย" บนหน้า login:

```env
UAPP_OAUTH_CLIENT_ID=
UAPP_OAUTH_CLIENT_SECRET=
UAPP_OAUTH_REDIRECT_URI="${APP_URL}/auth/uapp/callback"
UAPP_OAUTH_AUTHORIZE_URL=
UAPP_OAUTH_TOKEN_URL=
UAPP_OAUTH_USERINFO_URL=
UAPP_EMAIL_DOMAIN=sru.ac.th
```

ระบบอนุญาตเฉพาะอีเมล `@sru.ac.th` (หรือโดเมนที่กำหนดใน `UAPP_EMAIL_DOMAIN`) ผู้ใช้ใหม่จะถูกสร้าง/อัปเดตอัตโนมัติจาก OAuth โดยคงบทบาทเดิมไว้

## โครงสร้างโปรเจกต์ (สรุป)

```
app/
  Actions/          # โคลน/คัดลอกโครงสร้างภาระงาน, นำเข้า PMS HTML
  Enums/            # UserRole, WorkRecordStatus, PeriodStatus
  Http/Controllers/ # OAuth, export, พิมพ์, ดาวน์โหลดหลักฐาน
  Models/           # WorkRecord, WorkEntry, Workload*, EvaluationPeriod, ...
resources/views/pages/
  staff/            # รายการบันทึก, ฟอร์มบันทึกภาระงาน
  supervisor/       # คิวตรวจสอบ
  hr/               # แดชบอร์ด
  admin/            # จัดการรอบประเมินและโครงสร้างภาระงาน
routes/web.php      # เส้นทางตามบทบาท
```

## ใบอนุญาต

MIT
