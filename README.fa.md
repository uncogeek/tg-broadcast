# 📡 TelegramLive — اگریگیتور اخبار تلگرام

یک news aggregator self-hosted و real-time که کانال‌های انتخابی تلگرام را monitor می‌کند و پست‌ها را در یک live web feed نمایش می‌دهد — بدون نیاز به تلگرام یا VPN برای بیننده.

ساخته‌شده برای انتقال اخبار تلگرام به دوستان و مخاطبانی که **اینترنت آزاد ندارند**.

---

## 📸 تصاویر

> **Live Feed — صفحه `news.php`**

| نمای فید اخبار | انتخاب کانال |
|----------------|--------------|
| ![Feed](screenshots/feed.png) | ![Channels](screenshots/channels.png) |

> تصاویر را در پوشه `screenshots/` در root پروژه قرار دهید.

---

## 📱 نصب به عنوان App (PWA)

صفحه `news.php` یک **Progressive Web App** کامل است. بیننده‌ها می‌توانند آن را روی home screen گوشی‌شان نصب کنند و دقیقاً مثل یک native app استفاده کنند — بدون app store، بدون تلگرام.

**اندروید (Chrome / Edge):**
> صفحه را باز کنید ← روی **منوی ⋮** بزنید ← **"افزودن به صفحه اصلی"** ← نصب

**آیفون / آیپد (Safari):**
> صفحه را باز کنید ← دکمه **Share** (□↑) را بزنید ← **"Add to Home Screen"** ← Add

**دسکتاپ (Chrome / Edge):**
> آیکون نصب **(⊕)** در نوار آدرس را ببینید ← کلیک کنید ← Install

بعد از نصب: app در حالت fullscreen باز می‌شود، در صورت قطع اینترنت offline کار می‌کند، و در صورت تأیید کاربر، browser notification برای اخبار جدید می‌فرستد.

> ℹ️ نصب PWA نیاز به **HTTPS** دارد.

---

## ✨ قابلیت‌ها

- 🔴 **Live feed** — هر ۲۰ ثانیه تلگرام را poll می‌کند و پست‌های جدید را فوری push می‌دهد
- 🖼️ **متن + تصویر** — پست‌های caption‌دار با تصویرشان دریافت می‌شوند
- 🔐 **Encrypted storage** — متن اخبار با AES-256-CBC رمزنگاری می‌شود
- 📱 **PWA-ready** — قابل نصب روی home screen هر دستگاهی
- 🔔 **Browser notifications** — اعلان مرورگر برای اخبار جدید (opt-in)
- 🌐 **بدون نیاز به تلگرام** — بیننده فقط به یک مرورگر نیاز دارد
- 🧹 **Auto-cleanup** — پست‌های قدیمی‌تر از N ساعت به‌طور خودکار حذف می‌شوند
- 🔍 **جستجو + فیلتر کانال** — فیلتر بر اساس کانال یا کلمه کلیدی
- 🌙 **Dark UI** — رابط کاربری تیره، RTL، فارسی‌اول

---

## 🏗️ معماری (Architecture)

```
┌─────────────────────────────────────────────────────────────────┐
│                     GRABBER SERVER (Python)                      │
│                                                                  │
│   ┌────────────┐    SOCKS5     ┌───────────────────────────┐    │
│   │  Telegram  │◄─────────────│   news_grabber.py  v6.3   │    │
│   │  Channels  │   Telethon   │                           │    │
│   └────────────┘              │  Phase 1: آخرین پیام      │    │
│                               │  Phase 2: Live poll/20s   │    │
│                               │                           │    │
│                               │  profile_grabber.py       │    │
│                               │    (اختیاری، یک‌بار)      │    │
│                               │                           │    │
│                               │  config.py ← اینجا ویرایش│    │
│                               └────────────┬──────────────┘    │
└────────────────────────────────────────────┼────────────────────┘
                                             │
                           HTTPS POST  (JSON + base64 image)
                                             │
┌────────────────────────────────────────────▼────────────────────┐
│                       WEB SERVER (PHP)                           │
│                                                                  │
│   fetch.php  ← POST receiver                                     │
│   news.php   ← PWA live feed                                     │
│   news.db    ← SQLite database                                   │
└─────────────────────────────────────────────────────────────────┘
                                  ▲
                        بیننده‌ها news.php را در browser باز می‌کنند
                    (نه تلگرام لازم است، نه VPN، نه app store)
```

### جریان داده (Data flow)

| مرحله | اتفاق |
|-------|-------|
| ۱ | `news_grabber.py` فایل `config.py` را می‌خواند و با Telethon به تلگرام وصل می‌شود |
| ۲ | **Phase 1** — آخرین پیام مرتبط از هر کانال fetch می‌شود |
| ۳ | **Phase 2** — هر ۲۰ ثانیه کانال‌ها poll می‌شوند |
| ۴ | پست‌های text-only فوراً push می‌شوند |
| ۵ | پست‌های دارای تصویر ابتدا download، سپس text + base64 image با هم push می‌شوند |
| ۶ | `fetch.php` secret را validate، متن را encrypt و در SQLite insert می‌کند |
| ۷ | مرورگر هر ۲ ثانیه `?api=1` را poll کرده و کارت‌های جدید را به DOM اضافه می‌کند |

---

## 📁 ساختار فایل‌ها

```
github/
├── README.md
├── README.fa.md
│
├── tg_grabber/                 ← روی Python server اجرا می‌شود
│   ├── config.py               ← ✏️  همه تنظیمات اینجاست — اول اینجا را ویرایش کن
│   ├── news_grabber.py         ← grabber اصلی (v6.3)
│   ├── profile_grabber.py      ← یک‌بار اجرا: دانلود لوگوی کانال‌ها
│   ├── session.session         ← فایل session تلگرام (به .gitignore اضافه کن)
│   ├── grabber.log             ← log اجرا
│   ├── bandwidth.log           ← آمار bandwidth
│   ├── grabber.pid             ← lock file
│   └── images/                 ← کش local تصاویر (موقت)
│
└── web/                        ← روی PHP web host آپلود می‌شود
    ├── news.php                ← frontend فید زنده + API
    ├── fetch.php               ← POST receiver از grabber
    ├── manifest.php            ← PWA manifest
    ├── sw.js                   ← Service Worker
    ├── Vazirmatn-Regular.woff2 ← فونت وزیرمتن
    ├── icon-192.png            ← آیکون PWA (شما تهیه کنید)
    ├── icon-512.png            ← آیکون PWA (شما تهیه کنید)
    ├── news.db                 ← SQLite database (auto-created)
    ├── images/                 ← تصاویر پست‌ها (auto-managed)
    └── logos/                  ← لوگوی کانال‌ها (شما تهیه کنید)
```

---

## ⚙️ راهنمای نصب

### پیش‌نیازها (Prerequisites)

| بخش | نیازمندی |
|-----|---------|
| Grabber server | Python **3.9+** |
| Web host | PHP **8.0+**، SQLite3 فعال، extension ‏`openssl` |
| تلگرام | یک اکانت تلگرام برای خواندن کانال‌ها |
| (اختیاری) | SOCKS5 proxy اگر server شما دسترسی مستقیم به تلگرام ندارد |

---

### بخش اول — Web Server (PHP)

#### ۱.۱ آپلود فایل‌ها

محتوای `web/` را روی host آپلود کنید، مثلاً در `/public_html/news/`.

#### ۱.۲ تنظیم permission‌ها

```bash
chmod 755 /public_html/news/
chmod 644 /public_html/news/*.php /public_html/news/sw.js
mkdir -p /public_html/news/images /public_html/news/logos
chmod 755 /public_html/news/images /public_html/news/logos
```

#### ۱.۳ تنظیم `fetch.php`

```php
define('SECRET',   'your-long-random-secret');   // باید با config.py یکی باشد
define('ENC_KEY',  'your-encryption-key');        // باید با news.php یکی باشد
define('ENC_SALT', 'your-encryption-salt');       // باید با news.php یکی باشد
define('KEEP_HOURS', 6);
```

#### ۱.۴ تنظیم `news.php`

```php
define('ENC_KEY',      'your-encryption-key');    // مثل fetch.php
define('ENC_SALT',     'your-encryption-salt');   // مثل fetch.php
define('PASS_KEY',     'your-viewer-password');   // پسورد ورود (اختیاری)
define('POLL_MS',      2000);
define('HOURS_WINDOW', 1);
```

آرایه `$CHANNEL_NAMES` را با کانال‌ها و نام‌های خود update کنید.

#### ۱.۵ لوگوی کانال‌ها (اختیاری)

```
web/logos/iranintltv.jpg
web/logos/vahidheadline.png
```

فرمت‌های `.jpg .jpeg .png .webp .gif` قبول می‌شوند.

#### ۱.۶ آیکون PWA

فایل‌های `icon-192.png` و `icon-512.png` را در پوشه `web/` قرار دهید.

---

### بخش دوم — Grabber Server (Python)

#### ۲.۱ نسخه Python

```bash
python3 --version   # باید 3.9 یا بالاتر باشد
```

#### ۲.۲ نصب dependency‌ها

```bash
pip install telethon aiohttp pytz "python-socks[asyncio]" pillow psutil
```

| Package | کاربرد |
|---------|--------|
| `telethon` | Telegram MTProto client |
| `aiohttp` | Async HTTP — POST به `fetch.php` |
| `pytz` | تبدیل timezone (UTC به تهران) |
| `python-socks[asyncio]` | پشتیبانی SOCKS5 proxy |
| `pillow` | پردازش تصویر برای دانلود عکس |
| `psutil` | بررسی PID برای lock file guard |

> **Virtual environment (توصیه می‌شود):**
> ```bash
> python3 -m venv venv
> source venv/bin/activate        # Linux / macOS
> venv\Scripts\activate.bat       # Windows
> pip install telethon aiohttp pytz "python-socks[asyncio]" pillow psutil
> ```

#### ۲.۳ دریافت Telegram API credentials

1. به [https://my.telegram.org](https://my.telegram.org) بروید
2. روی **"API development tools"** کلیک کنید → یک application بسازید
3. مقادیر `api_id` و `api_hash` را کپی کنید

#### ۲.۴ ویرایش `config.py`

فایل `tg_grabber/config.py` را باز کنید و **همه بخش‌ها** را پر کنید:

```python
# ── Credentials ───────────────────────────────────────────────
API_ID   = 'YOUR_API_ID'
API_HASH = 'YOUR_API_HASH'
PHONE    = '+98912XXXXXXX'

# ── Proxy (اگر نیاز ندارید: USE_PROXY = False) ───────────────
USE_PROXY  = True
PROXY_HOST = '127.0.0.1'
PROXY_PORT = 1071

# ── Channels ──────────────────────────────────────────────────
CHANNELS = ['channel1', 'channel2', ...]

# ── Push destination ──────────────────────────────────────────
PUSH_ENDPOINTS = [
    {'url': 'https://yoursite.com/news/fetch.php', 'secret': 'your-secret'},
]
```

#### ۲.۵ اولین اجرا — احراز هویت تلگرام

```bash
cd tg_grabber
python3 news_grabber.py
```

در اولین اجرا Telethon شماره تلفن و کد تأیید تلگرام را می‌خواهد. این فرآیند `session.session` را می‌سازد. تمام اجراهای بعدی بدون login استفاده می‌کنند.

> ⚠️ **هرگز `session.session` را به Git commit نکنید.**

#### ۲.۶ اجرای دائمی

**Linux — systemd:**

```ini
# /etc/systemd/system/tgrabber.service
[Unit]
Description=Telegram News Grabber
After=network.target

[Service]
WorkingDirectory=/opt/tgrabber
ExecStart=/usr/bin/python3 news_grabber.py
Restart=on-failure
RestartSec=10

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable --now tgrabber
sudo journalctl -u tgrabber -f
```

**Linux — screen:**
```bash
screen -S grabber
python3 news_grabber.py
# Ctrl+A, D برای detach
```

**Windows:**
```batch
start /B pythonw news_grabber.py
```

---

### بخش سوم — لوگوی کانال‌ها (اختیاری، یک‌بار)

```bash
cd tg_grabber
python3 profile_grabber.py
# → در profile_channels/ ذخیره می‌شود
# سپس آپلود کنید: profile_channels/*.jpg  →  web/logos/
```

---

## 🔑 امنیت

### ⚠️ اطلاعات حساس — قبل از push به GitHub حتماً حذف کنید

فایل‌های Python ممکن است حاوی credential واقعی باشند. **قبل از commit همه اینها را بررسی کنید:**

| فایل | متغیر | چیست |
|------|-------|------|
| `config.py` | `API_ID` | شناسه app تلگرام شما — مثل password |
| `config.py` | `API_HASH` | secret app تلگرام — مثل password |
| `config.py` | `PHONE` | شماره تلفن شخصی شما |
| `config.py` | `PUSH_ENDPOINTS[*].secret` | کلید مشترک با server |
| `fetch.php` | `SECRET` | باید با بالایی یکی باشد |
| `news.php` | `ENC_KEY` / `ENC_SALT` | کلیدهای رمزنگاری اخبار |

همه را با مقادیر placeholder مثل `'YOUR_API_ID'` جایگزین کنید.

### `.gitignore` — به root مخزن اضافه کنید

```gitignore
# session تلگرام — دسترسی کامل read به اکانت شما می‌دهد
session.session

# فایل‌های runtime
grabber.pid
grabber.log
bandwidth.log
profile_grabber.log

# کش local تصاویر
images/
profile_channels/
```

### سایر نکات

- از دسترسی مستقیم HTTP به `news.db` جلوگیری کنید:
  ```apache
  <Files "news.db">
      Require all denied
  </Files>
  ```
- `fetch.php` را در سطح nginx یا Apache rate-limit کنید.
- HTTPS برای کار کردن PWA install و service worker الزامی است.

---

## ⚡ چک‌لیست سریع

```
[ ] config.py      — API_ID، API_HASH، PHONE، CHANNELS، PUSH_ENDPOINTS پر شده‌اند
[ ] fetch.php      — SECRET، ENC_KEY، ENC_SALT تنظیم شده‌اند
[ ] news.php       — ENC_KEY، ENC_SALT با fetch.php یکی هستند
[ ] Web server     — images/ و logos/ writable هستند
[ ] Web server     — HTTPS فعال است
[ ] PWA icons      — icon-192.png و icon-512.png در web/ هستند
[ ] .gitignore     — session.session حذف شده
[ ] GitHub         — هیچ API_ID، API_HASH، یا PHONE واقعی commit نشده
```

---

## 📄 لایسنس

MIT — آزاد برای استفاده، تغییر، و self-host.
