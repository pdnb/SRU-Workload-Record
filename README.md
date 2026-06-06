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

## Deploy บน Laravel Cloud

แอปนี้รองรับ [Laravel Cloud](https://cloud.laravel.com) — แพลตฟอร์มโฮสติ้งที่จัดการโดยทีม Laravel ไม่ต้องดูแลเซิร์ฟเวอร์เอง

### ขั้นตอนเริ่มต้น

**ผ่าน Dashboard**

1. สมัครและเข้าสู่ระบบที่ [cloud.laravel.com](https://cloud.laravel.com)
2. สร้าง Application ใหม่ แล้วเชื่อม GitHub/GitLab/Bitbucket
3. เลือก repository นี้และ branch ที่ต้องการ deploy (เช่น `main`)
4. เพิ่ม resources ที่จำเป็น (ดูด้านล่าง)
5. ตั้งค่า Build / Deploy commands และ environment variables
6. กด Deploy — ระบบจะ build Docker image, compile assets, migrate และเปิดใช้งานพร้อม SSL

**ผ่าน CLI** (ทางเลือก)

```bash
composer global require laravel/cloud-cli
cloud auth
cloud ship    # deploy ครั้งแรก — ตั้งค่า repo และ environment
cloud deploy  # deploy ครั้งถัดไป
```

เอกสารเพิ่มเติม: [Environments](https://cloud.laravel.com/docs/environments) · [Deployments](https://cloud.laravel.com/docs/deployments)

### Resources ที่แนะนำ

| Resource | ใช้สำหรับ |
|----------|-----------|
| **Database** (MySQL หรือ Postgres) | แทน SQLite — เก็บ users, บันทึกภาระงาน, session, cache, queue |
| **Object Storage** | ไฟล์หลักฐานภาระงาน (`work-evidence/`) — filesystem บน Cloud เป็น ephemeral |
| **Worker** (ถ้าใช้ queue) | ประมวลผล background jobs (`QUEUE_CONNECTION=database`) |

> **หมายเหตุเรื่องไฟล์หลักฐาน:** แอปเก็บไฟล์บน disk `public` (local) ซึ่งไม่ persist บน Laravel Cloud ควร attach Object Storage แล้วตั้งค่า disk `public` ให้ใช้ driver `s3` พร้อม credentials ที่ Cloud inject ให้อัตโนมัติ

### Build commands

ตั้งค่าใน Environment → Settings → Deployments:

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan optimize
```

- ใช้ Node **22** (ตรงกับ CI ของโปรเจกต์)
- ใช้ PHP **8.2** ขึ้นไป
- อย่ารัน `php artisan storage:link` — ใช้ Object Storage แทน local disk

### Deploy commands

```bash
php artisan migrate --force
```

- อย่ารัน `php artisan optimize:clear` หรือ `php artisan queue:restart` — Cloud จัดการให้อัตโนมัติ
- รัน `php artisan db:seed --force` เฉพาะ environment ทดสอบ/staging เท่านั้น ไม่ใช่ production

### Environment variables (production)

ตั้งค่าใน Environment → Settings → Environment variables:

```env
APP_NAME="SRU Workload Record"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-app.laravel.cloud
APP_LOCALE=th
APP_FALLBACK_LOCALE=en

SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database

# UApp OAuth — อัปเดต redirect ให้ตรง URL production
UAPP_OAUTH_CLIENT_ID=
UAPP_OAUTH_CLIENT_SECRET=
UAPP_OAUTH_REDIRECT_URI="${APP_URL}/auth/uapp/callback"
UAPP_OAUTH_AUTHORIZE_URL=
UAPP_OAUTH_TOKEN_URL=
UAPP_OAUTH_USERINFO_URL=
UAPP_EMAIL_DOMAIN=sru.ac.th
```

เมื่อ attach Database resource แล้ว Laravel Cloud จะ inject ค่า `DB_*` ให้อัตโนมัติ ค่า custom ที่ตั้งเองจะ override ค่า auto-inject

### Deploy อัตโนมัติ

- **Push to deploy** — เปิดอยู่โดย default; push ไป branch ที่ผูกกับ environment จะ trigger deploy ใหม่
- **Deploy hook** — เปิดใน Settings → Deployments แล้ว POST ไปยัง URL ที่ได้รับ (เหมาะกับ CI/CD)

ตัวอย่าง GitHub Actions:

```yaml
name: Deploy to Laravel Cloud

on:
  push:
    branches: [main]

jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - name: Deploy
        run: curl -X POST "${{ secrets.LARAVEL_CLOUD_DEPLOY_HOOK }}?commit_hash=${{ github.sha }}"
```

เพิ่ม `LARAVEL_CLOUD_DEPLOY_HOOK` เป็น repository secret ก่อนใช้งาน

### Custom domain

หลัง deploy สำเร็จ environment จะได้โดเมน `*.laravel.cloud` ฟรี จากนั้นสามารถผูก custom domain (เช่น `workload.sru.ac.th`) ได้จาก Environment → Domains — Cloud จัดการ DNS verification และ SSL ให้

อย่าลืมอัปเดต `APP_URL`, `UAPP_OAUTH_REDIRECT_URI` และ callback URL ที่ลงทะเบียนกับ UApp OAuth ให้ตรงกับโดเมน production

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
