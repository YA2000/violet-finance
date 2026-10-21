# تحديث Violet Finance من GitHub

من هلأ ما عاد في ملفات zip. أنا برفع كل نسخة جديدة على GitHub، وإنت بتحدّث السيرفر بأمر واحد.

| الأمر | شو بيعمل |
|---|---|
| `violet status` | بيورجيك آخر نسخة على GitHub، وشو شغّال على الموقع التجريبي والحقيقي |
| `violet update test` | بينزّل آخر نسخة على **الموقع التجريبي** |
| `violet promote` | بينقل للموقع **الحقيقي** نفس النسخة يلي جرّبتها عالتجريبي |
| `violet rollback live` | بيرجّع الموقع الحقيقي متل ما كان قبل آخر تحديث (الكود + قاعدة البيانات) |
| `violet sync-test` | بينسخ بيانات الموقع الحقيقي للتجريبي، لحتى تجرّب على بيانات حقيقية |

كل تحديث بياخد قبلو نسخة احتياطية كاملة بـ `/root/violet-backups`، وبيحتفظ بآخر 10.

> ⚠️ لا تشغّل `db:seed` أبداً على السيرفر.

---

## أولاً: التجهيز (مرة وحدة بس)

كل الأوامر كـ **root** عن طريق SSH.

### 1) مفتاح للسيرفر يقرأ من GitHub

```bash
apt-get install -y git
ssh-keygen -t ed25519 -f /root/.ssh/violet_deploy -N "" -C "violet-server"
cat /root/.ssh/violet_deploy.pub
```

انسخ السطر يلي طلع (بيبلّش بـ `ssh-ed25519`)، وبعدين:

- افتح https://github.com/YA2000/violet-finance/settings/keys
- اكبس **Add deploy key**
- Title: `contabo-server`، والصق المفتاح بـ Key
- **لا تعلّم** على Allow write access (السيرفر بيقرأ بس)
- اكبس **Add key**

### 2) ربط المفتاح وسحب الكود

```bash
cat >> /root/.ssh/config <<'EOF'
Host github-violet
  HostName github.com
  User git
  IdentityFile /root/.ssh/violet_deploy
  IdentitiesOnly yes
EOF
chmod 600 /root/.ssh/config
ssh -o StrictHostKeyChecking=accept-new -T github-violet ; true
git clone git@github-violet:YA2000/violet-finance.git /opt/violet-src
```

آخر أمر لازم يخلص بدون `Permission denied`.

### 3) تركيب أمر violet

```bash
bash /opt/violet-src/deploy/server-setup.sh
```

رح يسألك كم سؤال. اكبس **Enter** لتقبل الجواب يلي بين الأقواس:

- Live site folder: `/home/violet/htdocs/finance.technologytime.org`
- Live site user: `violet`
- Test site folder: اكتب `-` هلأ (منعملو بالخطوة الجاية)

جرّب:

```bash
violet status
```

### 4) الموقع التجريبي (مستحسن كتير)

**بـ CloudPanel:**

1. **Sites → Add Site → Create a PHP Site**: الدومين `test.technologytime.org`، و PHP **8.3**، ومستخدم الموقع مثلاً `violet-test`.
2. **Settings → Root Directory**: `htdocs/test.technologytime.org/public`
3. **Databases → Add Database**: الاسم `violet-test` والمستخدم `violet-test`، مع كلمة سر.
4. ضيف `test` بـ Cloudflare متل ما عملت لـ `finance`، وطلّع شهادة SSL متل ما عملت للموقع الحقيقي.

**بالـ SSH:**

```bash
T=/home/violet-test/htdocs/test.technologytime.org
cp /home/violet/htdocs/finance.technologytime.org/.env $T/.env
nano $T/.env
```

بالملف غيّر هدول الأسطر بس، واحفظ بـ `Ctrl+O` ثم `Enter` ثم `Ctrl+X`:

```
APP_URL=https://test.technologytime.org
DB_DATABASE=violet-test
DB_USERNAME=violet-test
DB_PASSWORD=كلمة سر قاعدة البيانات التجريبية
```

بعدين:

```bash
chown violet-test:violet-test $T/.env
bash /opt/violet-src/deploy/server-setup.sh     # جاوب نفس الأجوبة، وهالمرة اكتب مجلد ومستخدم الموقع التجريبي
violet update test                              # بينزّل الكود على الموقع التجريبي
violet sync-test                                # بينسخ البيانات الحقيقية إلو
```

افتح https://test.technologytime.org وادخل بنفس حسابك.

---

## ثانياً: كل ما قلتلك "في نسخة جديدة"

```bash
violet update test     # 1. نزّلها عالتجريبي
                       # 2. جرّب عالموقع التجريبي
violet promote         # 3. إذا تمام، انقلها للحقيقي
```

إذا صار شي غلط بالموقع الحقيقي بعد التحديث:

```bash
violet rollback live   # بيسألك تكتب YES
```

> الـ rollback بيرجّع قاعدة البيانات متل ما كانت قبل التحديث، فأي شي انكتب بالموقع بعد التحديث بيروح. لهيك اعمل `promote` بوقت هادي، وجرّب مباشرة بعدو.

إذا بدك تجرّب على بيانات جديدة: `violet sync-test`. هاد بيمسح بيانات التجريبي وبيحط مكانها نسخة من الحقيقي.

---

## إذا علق شي

- ابعتلي آخر 30 سطر طلعو بالشاشة.
- سجل أغلاط الموقع: `tail -50 /home/violet/htdocs/finance.technologytime.org/storage/logs/laravel.log`
- النسخ الاحتياطية: `ls /root/violet-backups`
