# SRU Workload Record

ระบบบันทึกภาระงานสำหรับบุคลากรมหาวิทยาลัยราชภัฏสุราษฎร์ธานี (SRU) — บันทึกผลงานตามโครงสร้างภาระงาน PMS รายรอบประเมิน และสรุปผลสำหรับงานบุคคล

## คุณสมบัติ

| บทบาท | ความสามารถ |
|--------|------------|
| **บุคลากร** (`staff`) | สร้าง/แก้ไขบันทึกภาระงาน 3 ขั้นตอน แนบหลักฐาน (URL หรือไฟล์) ส่งบันทึก พิมพ์บันทึก |
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

## Deploy บน HestiaCP

สำหรับ VPS/เซิร์ฟเวอร์ที่ใช้ [HestiaCP](https://hestiacp.com) (nginx + PHP-FPM + MariaDB/MySQL)

### ความต้องการบนเซิร์ฟเวอร์

| รายการ | ค่าที่แนะนำ |
|--------|-------------|
| PHP | 8.2+ (เลือก template PHP 8.2/8.3 ใน HestiaCP) |
| Extensions | `bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `mbstring`, `openssl`, `pdo`, `pdo_mysql`, `tokenizer`, `xml`, `zip` |
| Composer | ติดตั้ง global สำหรับ user ที่ deploy |
| Node.js | 22+ (สำหรับ build assets บนเซิร์ฟเวอร์) — หรือ build ใน CI แล้ว deploy `public/build` มาพร้อมกัน |
| Database | MariaDB/MySQL สร้างจาก HestiaCP → Databases |
| Upload limit | ≥ 12M (`upload_max_filesize`, `post_max_size`) — แอปรับไฟล์หลักฐานสูงสุด 10 MB |

### ติดตั้งครั้งแรก

**1. สร้าง Web Domain ใน HestiaCP**

- WEB → Add Web Domain → ใส่โดเมน (เช่น `workload.sru.ac.th`)
- เปิด SSL (Let's Encrypt) หลัง DNS ชี้มาที่เซิร์ฟเวอร์แล้ว
- สร้าง Database + user จาก Databases แล้วจดชื่อ/รหัสผ่าน

**2. Clone โปรเจกต์**

SSH เข้าเป็น user ของโดเมน (เช่น `admin`):

```bash
DOMAIN=workload.sru.ac.th
WEB_ROOT="/home/$(whoami)/web/${DOMAIN}"

cd "$WEB_ROOT"
rm -rf public_html   # โฟลเดอร์ default ของ HestiaCP — ไม่ใช้สำหรับ Laravel
git clone https://github.com/YOUR_ORG/sru-workload-record.git .
```

**3. ตั้ง document root ให้ชี้ `public/`**

```bash
# รันจาก root หรือ user ที่มีสิทธิ์ HestiaCP CLI
v-change-web-domain-docroot "$(whoami)" "$DOMAIN" public
```

**4. ตั้งค่า `.env`**

```bash
cp .env.example .env
php artisan key:generate
nano .env   # หรือ editor อื่น
```

ค่าสำคัญสำหรับ production:

```env
APP_NAME="SRU Workload Record"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://workload.sru.ac.th
APP_LOCALE=th

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=admin_workload
DB_USERNAME=admin_workload
DB_PASSWORD=your-db-password

SESSION_DRIVER=database
QUEUE_CONNECTION=sync
CACHE_STORE=database

UAPP_OAUTH_CLIENT_ID=
UAPP_OAUTH_CLIENT_SECRET=
UAPP_OAUTH_REDIRECT_URI="${APP_URL}/auth/uapp/callback"
UAPP_OAUTH_AUTHORIZE_URL=
UAPP_OAUTH_TOKEN_URL=
UAPP_OAUTH_USERINFO_URL=
UAPP_EMAIL_DOMAIN=sru.ac.th
```

> ใช้ `QUEUE_CONNECTION=sync` บน shared hosting — แอปนี้ยังไม่มี background jobs ที่ต้อง worker

**5. Build และ migrate**

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build    # ข้ามได้ถ้า build ใน CI แล้ว
php artisan migrate --force
php artisan storage:link
php artisan optimize
```

**6. สิทธิ์โฟลเดอร์**

```bash
chmod -R ug+rwx storage bootstrap/cache
```

PHP-FPM รันเป็น user เดียวกับเจ้าของไฟล์ — ไม่ต้อง `chown www-data` บน HestiaCP

**7. Cron (Laravel Scheduler)**

HestiaCP → CRON → Add job:

```cron
* * * * * cd /home/USER/web/workload.sru.ac.th && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

แทน `USER` และ path ให้ตรงกับเซิร์ฟเวอร์จริง

### Deploy ครั้งถัดไป

ใช้สคริปต์ใน repo:

```bash
chmod +x deploy/hestiacp-deploy.sh
./deploy/hestiacp-deploy.sh
```

ตัวแปรที่ปรับได้:

| ตัวแปร | ค่าเริ่มต้น | ความหมาย |
|--------|-------------|----------|
| `APP_DIR` | โฟลเดอร์โปรเจกต์ | path Laravel root |
| `DEPLOY_BRANCH` | `main` | branch ที่ pull |
| `BUILD_ASSETS` | `auto` | `yes` / `no` / `auto` (build เมื่อมี npm) |

ตัวอย่าง deploy โดยไม่ build บนเซิร์ฟเวอร์ (assets build ใน CI แล้ว):

```bash
BUILD_ASSETS=no ./deploy/hestiacp-deploy.sh
```

### PHP upload limit (ไฟล์หลักฐาน)

HestiaCP → WEB → เลือกโดเมน → Edit → PHP → ตั้งค่า (หรือแก้ `php.ini` ของ domain):

```ini
upload_max_filesize = 12M
post_max_size = 14M
```

### Deploy อัตโนมัติ (ทางเลือก)

เพิ่ม SSH key ของ CI แล้วรัน deploy script หลัง push:

```yaml
# .github/workflows/deploy-hestiacp.yml (ตัวอย่าง — สร้างเมื่อพร้อม)
- name: Deploy
  run: ssh user@server 'cd /home/user/web/workload.sru.ac.th && ./deploy/hestiacp-deploy.sh'
```

เก็บ credentials เป็น GitHub Secrets (`HESTIACP_SSH_HOST`, `HESTIACP_SSH_USER`, `HESTIACP_SSH_KEY`)

### Checklist ก่อนเปิด production

- [ ] `APP_DEBUG=false`, `APP_ENV=production`
- [ ] SSL เปิดใช้งานแล้ว, `APP_URL` เป็น `https://`
- [ ] UApp OAuth redirect URI ลงทะเบียนตรง `https://your-domain/auth/uapp/callback`
- [ ] `php artisan migrate --force` รันสำเร็จ
- [ ] `storage:link` สร้าง symlink แล้ว (ไฟล์หลักฐานอยู่ `storage/app/public/work-evidence/`)
- [ ] ทดสอบ login, อัปโหลดหลักฐาน, ส่งออก Excel
- [ ] **อย่า** รัน `db:seed` บน production (มีบัญชีทดสอบรหัส `password`)

## บัญชีทดสอบ (หลัง seed)

รหัสผ่านทุกบัญชี: `password`

| อีเมล | บทบาท |
|-------|--------|
| `admin@sru.ac.th` | ผู้ดูแลระบบ |
| `staff@sru.ac.th` | บุคลากร |

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
  hr/               # แดชบอร์ด
  admin/            # จัดการรอบประเมินและโครงสร้างภาระงาน
routes/web.php      # เส้นทางตามบทบาท
```

## ใบอนุญาต

MIT
