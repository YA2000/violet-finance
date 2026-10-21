# Violet Finance — دليل التنصيب على السيرفر (CloudPanel + Ubuntu 24.04)

النظام مبني بـ **Laravel 13 + PHP 8.3 + MySQL**، ومجلد `vendor` موجود جاهز ضمن الملف المضغوط، فما بتحتاج تشغّل `composer`.

---

## 1) تجهيز أدوات السيرفر (مرة وحدة فقط)

ادخل على السيرفر بـ SSH كـ root وشغّل:

```bash
# بعد ما ترفع الملفات (الخطوة 3) أو انسخ السكربت لحالو
bash /home/<site-user>/htdocs/<domain>/deploy/install-server-tools.sh
```

السكربت بيثبت:
- **wkhtmltopdf 0.12.6.1 (patched qt)** في `/usr/local/bin/wkhtmltopdf` — هاد المحرك يلي بيطلع الـ PDF بنفس شكل الإكسل بالضبط.
- **qpdf + poppler-utils** — لدمج المطبوعة مع المرفقات بملف نهائي واحد.

> تأكد بعدها: `wkhtmltopdf --version` لازم يطلع فيه `(with patched qt)`.

---

## 2) إنشاء الموقع وقاعدة البيانات في CloudPanel

1. **Sites → Add Site → Create a PHP Site**
   - Application: **Laravel 11** (أو Generic PHP)
   - Domain: مثلاً `finance.technologytime.org`
   - PHP Version: **8.3**
   - CloudPanel بيعمل مستخدم للموقع (Site User) — احفظ اسمو وكلمة سرّو.
2. من صفحة الموقع → **Settings → Root Directory** خلّيه:
   `htdocs/finance.technologytime.org/public`
   (مهم جداً: الجذر لازم يكون مجلد `public` مو مجلد المشروع).
3. **Databases → Add Database**
   - Database name: `violet_finance`
   - User: `violet_finance` + كلمة سر قوية.
4. **SSL/TLS → New Let's Encrypt Certificate** للدومين.
5. من **PHP Settings** للموقع: خلّي `upload_max_filesize` و `post_max_size` على الأقل **20M** (المرفقات حدها الافتراضي 10 MB وبتقدر تغيّرو من الإعدادات).

> إضافات PHP المطلوبة (موجودة افتراضياً بـ CloudPanel): `pdo_mysql, mbstring, xml, zip, fileinfo, curl, gd`.

---

## 3) رفع الملفات

ارفع محتوى الملف المضغوط `violet-finance` لمجلد الموقع:
`/home/<site-user>/htdocs/finance.technologytime.org/`

(من File Manager بـ CloudPanel أو بـ `scp`/SFTP بمستخدم الموقع). بعد الرفع لازم يكون عندك مباشرة داخل المجلد: `app/ public/ vendor/ artisan ...`

---

## 4) ملف الإعدادات `.env`

```bash
cd /home/<site-user>/htdocs/finance.technologytime.org
cp .env.example .env
nano .env
```

عدّل هالقيم:

| المفتاح | القيمة |
|---|---|
| `APP_URL` | `https://finance.technologytime.org` |
| `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | بيانات قاعدة البيانات من الخطوة 2 |
| `ADMIN_EMAIL` / `ADMIN_PASSWORD` | حساب الأدمن الأول |
| `MAIL_*` | إذا بدك إيميلات للموقّعين وإرسال الـ Uploader. خلّي `MAIL_MAILER=log` لإيقاف الإيميلات |

---

## 5) التشغيل لأول مرة

شغّل كـ **مستخدم الموقع** (مو root):

```bash
su - <site-user>
cd htdocs/finance.technologytime.org
bash deploy/after-upload.sh --first-time
```

هاد السكربت بيعمل: مفتاح التطبيق، إنشاء الجداول، تعبئة البيانات الأساسية (المشاريع 294، الحسابات 403، بنود الموازنة 5494، الأفراد 1048، الموردين 739، أرقام الفورمات، سلاسل التواقيع، نصوص المطبوعات)، وإنشاء حساب الأدمن، وتسريع الكاش.

> ⚠️ `--first-time` بس بأول تنصيب. بالتحديثات القادمة شغّل `bash deploy/after-upload.sh` بدون الخيار.

افتح الموقع وادخل بحساب الأدمن، وغيّر كلمة السر فوراً من **My signature → Change password**.

---

## 6) أول خطوات بعد الدخول (أدمن)

1. **Users → New user**: اعمل حساب لكل موظف، واربطه بسجلّه من *Individuals Data* (ضروري ليظهر كموقّع)، واختار الدور: Employee أو Administrator.
2. كل موظف بيضيف توقيعو من **My signature** (رسم أو رفع صورة) — أو الأدمن بيضيفلو من صفحة المستخدم.
3. **Forms management**: لكل فورم:
   - *Signature chain*: ترتيب التواقيع (المعدّ ← التدقيق الداخلي ← مسؤول المشروع ← آمر الصرف ← الدفع)، الموقّع الافتراضي، الإلزامي، إظهار التاريخ.
   - *Entry fields*: تغيير أسماء الحقول أو إخفاؤها.
   - *Additional fields*: إضافة حقول جديدة (نص، رقم، تاريخ، نعم/لا، قائمة)، وتختار إذا بتنطبع.
   - *Printouts*: تعديل الهيدر والفوتر واللوغو وكل نص مطبوع بالصفحة، مع معاينة PDF مباشرة.
4. **Form numbers**: رقم القالب، رقم الإصدار، تاريخ الإصدار — أي تعديل بيظهر فوراً بكل الـ PDF.
5. **Settings**: اسم المنظمة (إنكليزي/تركي)، اللوغو، حجم المرفقات، إيميلات الـ Uploader، عدّاد أرقام العمليات لكل سنة.
6. **Master data**: المشاريع (الرقم الجديد بيتقترح تلقائياً بالتسلسل وما بيقبل رقم مكرر)، الحسابات، بنود الموازنة، مراكز التكلفة، الأفراد، الموردين، القوائم المنسدلة، أنواع العمليات.

---

## 7) الصلاحيات

| | Administrator | Employee |
|---|---|---|
| تعبئة الفورمات وحجز الأرقام | ✅ | ✅ |
| طباعة / ملف نهائي / Uploader | ✅ | ✅ (بعد الإرسال) |
| التوقيع عند دوره | ✅ | ✅ |
| تعديل المسودة | كل المسودات | مسوداته فقط |
| رؤية كل الأرقام المحجوزة | ✅ | ✅ (عرض فقط — ما بيقدر يلغي أرقام غيره) |
| إعادة للمسودة / إدارة الفورمات / البيانات / المستخدمين / الإعدادات | ✅ | ❌ |
| تغيير صورة التوقيع | لأي مستخدم | لنفسه فقط |

---

## 8) النسخ الاحتياطي

- قاعدة البيانات: من CloudPanel → **Databases → Backups** أو `mysqldump`.
- المرفقات: مجلد `storage/app/private/attachments` (لازم ينضاف للنسخة الاحتياطية).

## 9) التحديث لاحقاً

ارفع الملفات الجديدة فوق القديمة **بدون** ما تمسح `.env` ولا `storage/`، وبعدين:

```bash
bash deploy/after-upload.sh
```

## 10) حل المشاكل

| المشكلة | الحل |
|---|---|
| صفحة 500 | شوف `storage/logs/laravel-*.log` — غالباً صلاحيات: `chmod -R ug+rwX storage bootstrap/cache` |
| الـ PDF ما بيطلع | تأكد من `wkhtmltopdf --version` و `WKHTMLTOPDF_BIN` بالـ `.env`، وبعد أي تعديل بـ `.env` شغّل `php artisan config:cache` |
| الملف النهائي (مع المرفقات) ما بيندمج | `apt install qpdf poppler-utils` |
| رفع مرفق كبير بيفشل | ارفع `upload_max_filesize` و `post_max_size` من PHP Settings بالموقع |
| صفحة "The page expired" | انتهت الجلسة — حدّث الصفحة وأعد المحاولة |
