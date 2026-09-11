# WelfVita Gateway — PHP Edition

نسخه‌ی **PHP خالص** از پنل WelfVita Gateway — شامل **پنل مدیریت + ریلی واقعی** (VLESS / Trojan / Shadowsocks روی WebSocket و XHTTP packet-up / stream-up) که ترافیک واقعی عبور می‌دهد.

دو حالت اجرا دارد:

| حالت | ریلی | مناسب برای |
|---|---|---|
| **Gateway mode** (پیشنهادی — Railway/VPS/Docker) | ✅ VLESS-WS، Trojan-WS، SS-WS، XHTTP packet-up، XHTTP stream-up (+ Trojan-XHTTP) | اتصال واقعی VPN |
| **Panel-only mode** (هاست اشتراکی با mod_php/FPM) | ❌ | فقط مدیریت لینک‌ها؛ لینک‌ها به سروری با ریلی اشاره می‌کنند |

معماری، مدل داده، فرمت لینک‌ها، API و ظاهر داشبورد **یک‌به‌یک با نسخه‌ی پایتون (main v2)** حفظ شده است.

---

## ✨ ویژگی‌ها

- **مدیریت کانفیگ چندپروتکلی**: VLESS-WS، VLESS-XHTTP، Trojan-WS، Trojan-XHTTP، Shadowsocks (chacha20/aes-256-gcm)، MTProto
- **لینک اشتراک هوشمند** (`/sub/{uuid}`): مرورگر → صفحه پروفایل حرفه‌ای؛ کلاینت VPN (v2rayNG، NekoBox، Streisand، …) → فید Base64 استاندارد با هدرهای `profile-title` و `subscription-userinfo`
- **گروه اشتراک** (`/sub-group/{key}` + صفحه عمومی `/p/{key}`) با رمز اختیاری
- **سهمیه ترافیک و انقضا** برای هر کانفیگ + ریست مصرف
- **داشبورد تک‌صفحه‌ای فارسی/RTL** با تم تیره/روشن، نمودار Chart.js، مانیتور اتصالات، لاگ فعالیت
- **احراز هویت** فقط با رمز (پیش‌فرض `123456`) — سشن کوکی ۷ روزه، تغییر رمز از تنظیمات
- **بکاپ/بازیابی کامل** در قالب JSON (`kind: welfvita-backup`)
- **صفحه ورود** با همان طراحی (اسلایدشو، تم دوگانه، هویت بصری یکسان)

## 📦 نصب روی هاست اشتراکی

1. محتوای پوشه‌ی `php-panel` را در `public_html` (یا هر مسیر زیر‌دامنه‌ی دلخواه) آپلود کنید:

```
public_html/
├── index.php          ← نقطه‌ی ورود و مسیریاب
├── .htaccess          ← ریرایت همه‌ی مسیرها به index.php (Apache/LiteSpeed)
├── lib/               ← هسته‌ی برنامه (state, auth, links, subscription, …)
├── templates/         ← صفحات HTML (login, dashboard)
└── data/              ← ذخیره‌سازی وضعیت (ساخت خودکار؛ محافظت‌شده با .htaccess)
```

2. مطمئن شوید PHP نسخه **8.0 یا بالاتر** انتخاب است (در cPanel: *Select PHP Version*).
3. فایل `.htaccess` باید قابل اجرای `mod_rewrite` باشد (روی اکثر هاست‌های اشتراکی فعال است).

> **nginx؟** معادل ریرایت + مسدودسازی فایل‌های حساس (`.htaccess` روی nginx اعمال نمی‌شود، پس این را حتماً اضافه کنید):
> ```nginx
> location / { try_files $uri $uri/ /index.php?$query_string; }
> location ~ ^/(data|lib|templates)/ { deny all; }
> location ~ /\. { deny all; }
> ```

### تنظیمات اختیاری (متغیر محیطی)

| متغیر | توضیح | پیش‌فرض |
|---|---|---|
| `SECRET_KEY` | کلید امنیتی هش رمز و UUID پیش‌فرض | تولید تصادفی در `data/.welfvita_secret` |
| `ADMIN_PASSWORD` | پشتیبانی نمی‌شود — رمز پیش‌فرض `123456` است؛ بلافاصله از تنظیمات عوضش کنید | `123456` |
| `PUBLIC_DOMAIN` | دامنه‌ی عمومی برای ساخت لینک‌ها | هدر `Host` درخواست |
| `DATA_DIR` | مسیر ذخیره‌ی داده‌ها | `data/` کنار برنامه |

## 🔧 اجرای محلی (تست)

```bash
cd php-panel
php -S 127.0.0.1:8099 index.php
```

سپس `http://127.0.0.1:8099` — بازدیدکننده‌ها خودکار به `/login` می‌روند و ادمینِ لاگین‌شده به `/dashboard`. رمز پیش‌فرض: `123456`

## 🚂 دیپلوی روی Railway (اتصال واقعی)

فایل‌های `Dockerfile` و `railway.json` آماده‌اند:

1. این پوشه را روی GitHub پوش کنید → Railway → **New Project → Deploy from GitHub repo**
2. Railway خودش Dockerfile را می‌سازد و `healthcheckPath=/health` را چک می‌کند
3. **Settings → Networking → Generate Domain** (لینک‌ها با همین دامنه ساخته می‌شوند)
4. داشبورد: `https://<دامنه>/login` — رمز پیش‌فرض `123456` (فوراً عوضش کنید)
5. کانفیگ بسازید → در v2rayNG/Hiddify ایمپورت کنید — اتصال واقعی برقرار است

> معماری Railway: یک کانتینر، یک پورت عمومی. `gateway.php` (Workerman) روی `$PORT` گوش می‌دهد؛ مسیرهای ریلی (`/ws/*`، `/*http-siz10/*`) را خودش سرو می‌کند و بقیه‌ی مسیرها (پنل وب) را به سرور داخلی `php -S` پروکسی می‌کند.

## ☁️ دیپلوی روی Wasmer Edge (کانتینر Docker — پنل + ریلی کامل)

Wasmer از کانتینرهای استاندارد Linux پشتیبانی می‌کند، پس **همان Dockerfile ریلی** روی آن اجرا می‌شود:

```bash
wasmer login
cd php-panel
wasmer deploy --registry-push --publish-app
```

- `app.yaml` به صورت کانتینری تنظیم شده: WebSocket فعال، outbound network باز، Volume روی `data/`
- Wasmer TLS 443 را در لبه ترمینیت می‌کند و به پورت داخلی 8080 می‌فرستد — پورت عمومی جدا لازم نیست
- دامنه‌ی لینک‌ها خودکار از هدر Host ساخته می‌شود (`PUBLIC_DOMAIN` لازم نیست)
- ⚠️ اگر ایمیج را از registry دیگری می‌دهید، در `app.yaml` مقدار `package:` را به آن تغییر دهید

## 📋 خلاصه: کجا اتصال واقعی دارم؟

| پلتفرم | پنل | ریلی (اتصال واقعی) |
|---|---|---|
| **Railway** | ✅ | ✅ |
| **Wasmer Edge (کانتینر Docker)** | ✅ | ✅ |
| Wasmer Edge (PHP-WASM قدیمی) | ✅ | ❌ |
| هاست اشتراکی (cPanel و…) | ✅ | ❌ (پروسه‌ی دائمی ندارند) |

## 🗺️ نگاشت مسیرها با نسخه‌ی پایتون

| مسیر | توضیح | وضعیت در PHP |
|---|---|---|
| `/` | ریشه → هدایت خودکار به `/login` یا `/dashboard` | ✅ |
| `/login`, `/dashboard` | صفحات HTML | ✅ |
| `/api/login|logout|me|change-password` | احراز هویت | ✅ |
| `POST/GET /api/links` | ساخت/لیست کانفیگ | ✅ |
| `PATCH/DELETE /api/links/{uid}` | ویرایش/حذف/ریست | ✅ |
| `/api/subs...` | گروه اشتراک | ✅ |
| `/sub/{uuid}`, `/sub-all`, `/sub-group/{key}` | فید اشتراک | ✅ |
| `/p/{key}`, `/api/public/...` | صفحات و داده عمومی | ✅ |
| `/stats`, `/api/activity`, `/api/connections`, `/api/system` | آمار | ✅ |
| `/api/backup/export|import` | بکاپ | ✅ |
| `/api/settings/logging` | تنظیمات | ✅ |
| `WS /ws/{uuid}`, `/trojan-ws`, `/ss-ws` | ریلی پروتکل‌ها | ✅ در Gateway mode (gateway.php) |
| `GET/POST /*http-siz10/...` | ریلی XHTTP (packet-up / stream-up) | ✅ در Gateway mode |
| `/api/tunnel/...`, `/api/nodes/...`, `/api/bot-tcp-proxy/...` | تونل/نود/Railway | ❌ مختص زیرساخت Railway پایتون |

> **نکته‌ی مهم درباره‌ی ریلی:** ریلی به پروسه‌ی دائمی نیاز دارد؛ در Gateway mode همین پوشه با `php gateway/gateway.php start` (Workerman) اجرا می‌شود و همه‌ی ترنسپورت‌ها را سرو می‌کند. در هاست اشتراکی (PHP-FPM/mod_php) پروسه‌ی دائمی ندارید؛ در آن حالت فقط پنل فعال است و لینک‌ها باید به سروری با ریلی (Railway/VPS) اشاره کنند.

## 📂 ساختار کد

```
php-panel/
├── index.php              # مسیریاب متمرکز پنل (معادل روت‌های main.py)
├── gateway/
│   ├── gateway.php        # ریلی Workerman: WS + XHTTP + پروکسی پنل (Gateway mode)
│   ├── proto.php          # پارس هدر VLESS/Trojan + AEAD شادوساکس
│   └── state_bridge.php   # پل مشترک state پنل (لینک/سهمیه/اتصالات)
├── lib/
│   ├── state.php          # وضعیت JSON + ذخیره اتمیک
│   ├── auth.php           # سشن کوکی + هش sha256(pw+secret)
│   ├── links.php          # مدل لینک + generate_share_link (فرمت یکسان URIها)
│   ├── subs.php           # گروه اشتراک
│   ├── subscription.php   # فید /sub, /sub-group, /api/public
│   ├── subpage.php        # صفحه پروفایل اشتراک
│   ├── stats.php          # شمارنده ترافیک/لاگ/اتصالات
│   ├── http.php           # پاسخ‌های JSON/HTML + client_ip
│   └── router.php         # هندلرهای API
├── templates/
│   ├── login.php
│   ├── dashboard.php
│   └── dashboard_js.php
└── data/                  # welfvita_state.json و ران‌تایم (خودکار ساخته می‌شود)
```

## 🔐 نکات امنیتی

- هش رمز: `sha256(password + SECRET_KEY)` — دقیقاً مثل نسخه پایتون.
- فایل‌های `data/` با `.htaccess` از وب غیرقابل دسترس‌اند.
- همه‌ی خروجی‌های HTML با `htmlspecialchars` فرار داده می‌شوند؛ خروجی JSON با `JSON_UNESCAPED_UNICODE`.
- اکستنشن‌های اختیاری: `openssl` (برای آینده)، `mbstring` (برچینش فارسی) — `curl` لازم نیست.
