<?php
/**
 * news.php v7
 * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
 * ✦ SQLite database (AES-256-CBC encrypted text)
 * ✦ Post images shown below caption (click to zoom)
 * ✦ Channel logos (circle) from logos/ folder
 *      → Put logos/akkhbarfori.jpg  logos/iranintltv.png  etc.
 *      → Any of: jpg  jpeg  png  webp  gif
 *      → Falls back to a coloured initial-letter circle if missing
 * ✦ All times in Tehran timezone (Asia/Tehran)
 * ✦ Feed sorted newest-first across all channels
 *
 * ── HOW TO CHANGE LIMITS ───────────────────────────────
 *  HOURS_WINDOW  → hours of news to keep & show  (default: 1)
 *  MAX_ENTRIES   → max items returned per API call (default: 800)
 *  POLL_MS       → browser poll interval in ms    (default: 2000)
 * ───────────────────────────────────────────────────────
 */

date_default_timezone_set('Asia/Tehran');

// ═══ CONFIG ═══════════════════════════════════════════════════
if (!defined('DB_FILE'))      define('DB_FILE',      __DIR__ . '/news.db');
if (!defined('ENC_KEY'))      define('ENC_KEY',      'CHANGE_THIS_ENC_KEY');
if (!defined('ENC_SALT'))     define('ENC_SALT',     'CHANGE_THIS_ENC_SALT');
if (!defined('PASS_KEY'))     define('PASS_KEY',     '1234');
if (!defined('POLL_MS'))      define('POLL_MS',      2000);
if (!defined('HOURS_WINDOW')) define('HOURS_WINDOW', 1);
if (!defined('MAX_ENTRIES'))  define('MAX_ENTRIES',  800);
if (!defined('IMG_DIR'))      define('IMG_DIR',      __DIR__ . '/images/');
if (!defined('LOGOS_DIR'))    define('LOGOS_DIR',    __DIR__ . '/logos/');
// ══════════════════════════════════════════════════════════════

$CHANNEL_NAMES = [
    'akkhbarfori'       => 'Akhbarfori | اخبار فوری',
];

// ── Channel logo map ──────────────────────────────────────────
// Scans logos/ dir and returns [handle => 'logos/file.ext']
function buildLogoMap(): array {
    $map  = [];
    $exts = ['jpg','jpeg','png','webp','gif'];
    if (!is_dir(LOGOS_DIR)) return $map;
    foreach (scandir(LOGOS_DIR) as $f) {
        $info = pathinfo($f);
        if (!isset($info['extension'])) continue;
        if (!in_array(strtolower($info['extension']), $exts)) continue;
        $map[strtolower($info['filename'])] = 'logos/' . $f;
    }
    return $map;
}

// ── Database ──────────────────────────────────────────────────
function getDB(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('sqlite:' . DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("PRAGMA journal_mode=WAL");
    $pdo->exec("PRAGMA synchronous=NORMAL");
    $pdo->exec("CREATE TABLE IF NOT EXISTS news (
        id        INTEGER PRIMARY KEY AUTOINCREMENT,
        msg_id    INTEGER NOT NULL,
        channel   TEXT    NOT NULL COLLATE NOCASE,
        date      TEXT    NOT NULL,
        text_enc  TEXT    NOT NULL,
        image     TEXT    DEFAULT NULL,
        UNIQUE(msg_id, channel)
    )");
    try { $pdo->exec("ALTER TABLE news ADD COLUMN image TEXT DEFAULT NULL"); } catch (Exception $e) {}
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_date ON news(date DESC)");
    return $pdo;
}

// ── Encryption ────────────────────────────────────────────────
function encKey(): string {
    return hash('sha256', ENC_KEY . ':' . ENC_SALT, true);
}
function encryptText(string $text): string {
    $iv  = random_bytes(16);
    $enc = openssl_encrypt($text, 'AES-256-CBC', encKey(), OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $enc);
}
function decryptText(string $blob): string {
    $raw = base64_decode($blob);
    if (strlen($raw) < 17) return '';
    $dec = openssl_decrypt(
        substr($raw, 16), 'AES-256-CBC', encKey(), OPENSSL_RAW_DATA, substr($raw, 0, 16)
    );
    return $dec === false ? '' : $dec;
}

function getCutoff(): string {
    return date('Y-m-d H:i:s', strtotime('-' . HOURS_WINDOW . ' hours'));
}

// ── Session ───────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION['news_auth'] = true;

if (!empty($_GET['key']) && $_GET['key'] === PASS_KEY) {
    $_SESSION['news_auth'] = true;
}
$api_key_valid = (!empty($_GET['key']) && $_GET['key'] === PASS_KEY);

if (isset($_GET['logout'])) { session_destroy(); header('Location: news.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['passkey'])) {
    if ($_POST['passkey'] === PASS_KEY) {
        $_SESSION['news_auth'] = true;
        header('Location: news.php');
        exit;
    }
    $login_error = true;
}

// ═══ JSON API ════════════════════════════════════════════════
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache');
    global $CHANNEL_NAMES;

    $db        = getDB();
    $cutoff    = getCutoff();
    $since_rid = (int)($_GET['since_rowid'] ?? 0);
    $entries   = [];
    $channels  = [];
    $stats     = [];
    $logos     = buildLogoMap();

    $stmt = $db->prepare(
        "SELECT id, msg_id, channel, date, text_enc, image
         FROM news
         WHERE date >= :cutoff AND id > :since
         ORDER BY date DESC
         LIMIT :lim"
    );
    $stmt->execute([':cutoff' => $cutoff, ':since' => $since_rid, ':lim' => MAX_ENTRIES]);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $text = decryptText($row['text_enc']);
        if ($text === '') continue;

        $ch = strtolower($row['channel']);
        if (!in_array($ch, $channels)) $channels[] = $ch;
        $stats[$ch] = ($stats[$ch] ?? 0) + 1;

        // Resolve image URL (only if the file actually exists on disk)
        $img_url = null;
        if (!empty($row['image'])) {
            $fp = IMG_DIR . basename($row['image']);
            if (file_exists($fp)) {
                $img_url = 'images/' . basename($row['image']);
            }
        }

        $entries[] = [
            'rowid'   => (int)$row['id'],
            'id'      => (int)$row['msg_id'],
            'channel' => $ch,
            'date'    => $row['date'],
            'text'    => $text,
            'image'   => $img_url,
        ];
    }

    foreach (array_keys($CHANNEL_NAMES) as $ch) {
        if (!in_array($ch, $channels)) $channels[] = $ch;
    }

    $max_rid = (int)$db->query(
        "SELECT COALESCE(MAX(id),0) FROM news WHERE date >= " . $db->quote($cutoff)
    )->fetchColumn();

    $ch_names = [];
    $ch_logos = [];
    foreach ($channels as $ch) {
        $ch_names[$ch] = $CHANNEL_NAMES[$ch] ?? '';
        $ch_logos[$ch] = $logos[$ch] ?? null;
    }

    echo json_encode([
        'ok'            => true,
        'count'         => count($entries),
        'max_rowid'     => $max_rid,
        'entries'       => $entries,
        'stats'         => $stats,
        'channels'      => $channels,
        'channel_names' => $ch_names,
        'channel_logos' => $ch_logos,   // NEW ← logo URLs for each channel
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<title>اخبار زنده</title>
<link rel="manifest" href="manifest.php">
<meta name="theme-color" content="#0d1117">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0d1117;--surface:#161b22;--surface2:#21262d;
  --border:#30363d;--border2:#444c56;
  --text:#e6edf3;--muted:#8b949e;
  --accent:#58a6ff;--accent-dim:#1f3a5c;
  --green:#3fb950;--red:#f85149;
  --font:-apple-system,BlinkMacSystemFont,'Segoe UI',Tahoma,Arial,sans-serif;
  --mono:'Courier New',Courier,monospace;
  --r:8px;
}
@font-face{font-family:'vazirmatn';
  src:url('Vazirmatn-Regular.woff2') format('woff2');
  font-weight:400;font-style:normal;font-display:swap}
html,body{min-height:100vh;background:var(--bg);color:var(--text);
  font-family:vazirmatn,var(--font);font-size:14px;line-height:1.6}

/* ── Header ── */
.header{position:sticky;top:0;z-index:100;background:rgba(13,17,23,.96);
  border-bottom:1px solid var(--border);padding:0 14px;height:52px;
  display:flex;align-items:center;gap:10px}
.live-dot{width:8px;height:8px;border-radius:50%;background:#ff0404;
  flex-shrink:0;position:relative}
.live-dot::after{content:'';position:absolute;top:50%;left:50%;
  width:4px;height:4px;margin:-2px 0 0 -2px;border-radius:50%;
  background:#ff0404;animation:rippleOut 1.8s ease-out infinite}
.live-dot.off{background:var(--muted)}
.live-dot.off::after{display:none}
@keyframes rippleOut{
  0%  {transform:scale(.5);opacity:1}
  70% {transform:scale(4); opacity:.4}
  100%{transform:scale(5); opacity:0}
}
.header-title{font-size:16px;font-weight:700}
.header-ago{font-size:11px;color:var(--muted);font-family:var(--mono);white-space:nowrap}
.header-right{margin-right:auto;display:flex;align-items:center;gap:6px}
.btn{background:none;border:1px solid var(--border);border-radius:6px;color:var(--muted);
  cursor:pointer;font-size:12px;padding:4px 9px;transition:all .15s;font-family:var(--font);
  display:inline-flex;align-items:center;gap:4px;text-decoration:none;white-space:nowrap}
.btn:hover{border-color:var(--border2);color:var(--text);background:var(--surface2)}
.new-badge{display:none;background:var(--red);color:#fff;font-size:10px;
  font-family:var(--mono);font-weight:700;padding:3px 8px;border-radius:10px;
  cursor:pointer;white-space:nowrap;border:none}
.new-badge.show{display:inline-block}

/* ── Toolbar ── */
.toolbar{background:var(--surface);border-bottom:1px solid var(--border);
  padding:8px 14px;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.search-box{background:var(--bg);border:1px solid var(--border);border-radius:var(--r);
  color:var(--text);font-family:vazirmatn,var(--font);font-size:13px;
  padding:7px 11px;outline:none;transition:border-color .15s;flex:1;min-width:120px;direction:rtl}
.search-box::placeholder{color:var(--muted)}
.search-box:focus{border-color:var(--accent)}
.count-label{font-size:11px;color:var(--muted);font-family:var(--mono);white-space:nowrap}
.active-ch-wrap{width:100%;display:flex;gap:5px;flex-wrap:wrap;padding:3px 0 1px;align-items:center}
.active-ch-tag{font-size:10px;padding:2px 8px;border-radius:20px;border-width:1px;border-style:solid;
  font-weight:600;font-family:var(--mono);white-space:nowrap;cursor:pointer;transition:opacity .15s}
.active-ch-tag:hover{opacity:.7}
.all-tag{border-color:var(--green)!important;background:rgba(63,185,80,.12)!important;color:var(--green)!important}
.ch-manage-btn{font-size:10px;padding:2px 9px;border-radius:20px;
  border:1px dashed var(--border2);background:none;color:var(--muted);
  cursor:pointer;transition:all .15s;font-family:var(--font);white-space:nowrap}
.ch-manage-btn:hover{border-color:var(--accent);color:var(--accent)}

/* ── Channel Popup ── */
.ch-popup-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.72);
  z-index:300;align-items:flex-end;justify-content:center}
.ch-popup-overlay.show{display:flex}
.ch-popup{background:var(--surface);border:1px solid var(--border);
  border-radius:16px 16px 0 0;width:100%;max-width:520px;
  max-height:80vh;display:flex;flex-direction:column;
  padding-bottom:env(safe-area-inset-bottom)}
.ch-popup-header{display:flex;align-items:center;justify-content:space-between;
  padding:16px 18px 12px;border-bottom:1px solid var(--border);flex-shrink:0}
.ch-popup-title{font-size:15px;font-weight:700}
.ch-popup-actions{display:flex;gap:8px;align-items:center}
.ch-popup-close{background:none;border:none;color:var(--muted);cursor:pointer;
  font-size:20px;line-height:1;padding:0 4px}
.ch-popup-close:hover{color:var(--text)}
.ch-list{overflow-y:auto;padding:10px 14px 16px;display:flex;flex-direction:column;gap:4px}
.ch-row{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:var(--r);
  cursor:pointer;transition:background .12s;user-select:none;border:1px solid transparent}
.ch-row:hover{background:var(--surface2)}
.ch-row.selected{background:var(--accent-dim);border-color:rgba(88,166,255,.3)}
.ch-row-check{width:18px;height:18px;border-radius:4px;border:2px solid var(--border2);
  flex-shrink:0;display:flex;align-items:center;justify-content:center;transition:all .12s;font-size:11px}
.ch-row.selected .ch-row-check{background:var(--accent);border-color:var(--accent);color:#fff}
/* logo in popup rows */
.ch-row-logo{width:30px;height:30px;border-radius:50%;overflow:hidden;flex-shrink:0;
  display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;
  border:1.5px solid var(--border2);background:var(--surface2)}
.ch-row-logo img{width:100%;height:100%;object-fit:cover;display:block}
.ch-row-info{flex:1;min-width:0}
.ch-row-name{font-size:13px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ch-row-handle{font-size:11px;color:var(--muted);font-family:var(--mono)}
.ch-select-all{font-size:11px;color:var(--accent);cursor:pointer;background:none;
  border:none;padding:0;font-family:var(--font);transition:opacity .15s}
.ch-select-all:hover{opacity:.7}
.ch-popup-footer{padding:12px 14px;border-top:1px solid var(--border);flex-shrink:0}
.ch-apply-btn{width:100%;padding:10px;background:var(--accent);border:none;
  border-radius:var(--r);color:#0d1117;font-size:14px;font-weight:700;cursor:pointer;
  font-family:vazirmatn,var(--font);transition:opacity .15s}
.ch-apply-btn:hover{opacity:.85}

/* ── Feed ── */
.container{max-width:780px;margin:0 auto;padding:12px 12px 60px}
#feed{display:flex;flex-direction:column;gap:8px;font-family:vazirmatn,var(--font)}
.news-item{background:var(--surface);border:1px solid var(--border);
  border-radius:var(--r);padding:11px 14px;
  animation:slideIn .22s ease;transition:border-color .2s,transform .15s}
.news-item:hover{border-color:var(--border2);transform:translateY(-1px)}
.news-item.fresh{border-color:rgba(88,166,255,.5);
  background:linear-gradient(135deg,var(--surface) 0%,rgba(31,58,92,.2) 100%);
  animation:popIn .3s cubic-bezier(.16,1,.3,1)}
@keyframes slideIn{from{opacity:0;transform:translateY(7px)}to{opacity:1;transform:none}}
@keyframes popIn{0%{opacity:0;transform:scale(.97) translateY(5px)}100%{opacity:1;transform:none}}

/* ── Card meta row ── */
.news-meta{display:flex;align-items:center;gap:7px;margin-bottom:8px;
  font-size:11px;font-family:var(--mono);direction:ltr;flex-wrap:wrap;flex-flow:row-reverse}

/* ── Channel logo circle (card) ── */
.ch-logo{width:30px;height:30px;border-radius:50%;overflow:hidden;flex-shrink:0;
  display:inline-flex;align-items:center;justify-content:center;
  font-size:12px;font-weight:700;vertical-align:middle;
  border:1.5px solid var(--border2)}
.ch-logo img{width:100%;height:100%;object-fit:cover;display:block}

.ch-tag{background:var(--accent-dim);color:var(--accent);padding:2px 7px;
  border-radius:4px;font-weight:600;font-size:10px;letter-spacing:.1em;display:none}
.ch-name{padding:2px 7px;border-radius:4px;font-weight:600;font-size:10px;
  font-family:vazirmatn,var(--font);letter-spacing:0;white-space:nowrap}
.news-date{color:#c1c5cd;font-size:13px;white-space:nowrap}
.meta-right{margin-right:auto;display:flex;align-items:center;gap:6px}
.new-label{font-size:9px;font-weight:700;background:rgba(63,185,80,.12);color:var(--green);
  border:1px solid rgba(63,185,80,.3);padding:1px 6px;border-radius:10px}
.copy-btn{background:none;border:1px solid var(--border);border-radius:4px;
  color:var(--muted);cursor:pointer;font-size:10px;padding:2px 7px;
  transition:all .15s;font-family:var(--font)}
.copy-btn:hover{border-color:var(--border2);color:var(--text)}
.copy-btn.copied{border-color:var(--green);color:var(--green)}
.news-text{font-size:14px;line-height:1.8;color:var(--text);word-break:break-word;direction:auto}
.news-text a{color:var(--accent);text-decoration:none}
.news-text a:hover{text-decoration:underline}
.news-text strong{font-weight:700;color:#f0f6fc}
.news-text em{font-style:italic;color:#cdd9e5}
.news-text code{background:var(--surface2);border-radius:4px;padding:1px 5px;
  font-family:var(--mono);font-size:12px}

/* ── Post image ── */
.news-img-wrap{margin-top:10px;border-radius:var(--r);overflow:hidden;
  background:var(--surface2);cursor:zoom-in;line-height:0}
.news-img-wrap img{width:100%;max-height:480px;object-fit:contain;display:block;
  border-radius:var(--r);transition:opacity .25s}
.news-img-wrap img.img-loading{opacity:.35}

/* ── Lightbox ── */
.lightbox{display:none;position:fixed;inset:0;background:rgba(0,0,0,.93);
  z-index:600;align-items:center;justify-content:center;cursor:zoom-out}
.lightbox.show{display:flex}
.lb-img{max-width:96vw;max-height:94vh;border-radius:10px;
  object-fit:contain;box-shadow:0 8px 48px rgba(0,0,0,.8)}
.lb-close{position:absolute;top:14px;right:16px;background:rgba(0,0,0,.55);
  border:1px solid rgba(255,255,255,.25);border-radius:50%;
  width:36px;height:36px;color:#fff;font-size:20px;line-height:34px;
  text-align:center;cursor:pointer;transition:background .15s;user-select:none}
.lb-close:hover{background:rgba(255,255,255,.15)}

.empty{text-align:center;padding:60px 20px;color:var(--muted)}
.empty .icon{font-size:40px;margin-bottom:12px}

/* ── Stats Modal ── */
.overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);
  z-index:200;align-items:center;justify-content:center;padding:16px}
.overlay.show{display:flex}
.modal{background:var(--surface);border:1px solid var(--border);
  border-radius:12px;padding:22px 24px;width:100%;max-width:400px}
.modal-title{font-size:16px;font-weight:700;margin-bottom:14px}
.stat-row{display:flex;justify-content:space-between;align-items:center;
  padding:8px 0;border-bottom:1px solid var(--border);font-size:14px}
.stat-row:last-of-type{border-bottom:none}
.stat-ch{color:var(--accent);font-family:var(--mono);font-size:12px}
.stat-num{font-weight:700;color:var(--green);font-family:var(--mono)}
.stat-total{font-size:12px;color:var(--muted);margin-top:12px;text-align:center;
  padding-top:10px;border-top:1px solid var(--border)}
.modal-close{margin-top:16px;width:100%;background:var(--surface2);
  border:1px solid var(--border);border-radius:var(--r);color:var(--text);
  font-family:vazirmatn,var(--font);font-size:14px;padding:9px;
  cursor:pointer;transition:background .15s}
.modal-close:hover{background:var(--bg)}

/* ── Toast / Scroll-top ── */
.toast{position:fixed;bottom:20px;left:50%;transform:translateX(-50%) translateY(80px);
  background:var(--surface2);border:1px solid var(--border);border-radius:var(--r);
  padding:7px 18px;font-size:12px;font-family:var(--mono);color:var(--green);
  transition:transform .3s cubic-bezier(.16,1,.3,1),opacity .3s;
  opacity:0;z-index:999;pointer-events:none;white-space:nowrap}
.toast.show{transform:translateX(-50%) translateY(0);opacity:1}
.scroll-top{position:fixed;bottom:20px;right:14px;background:var(--surface2);
  border:1px solid var(--border);border-radius:50%;width:40px;height:40px;cursor:pointer;
  font-size:18px;display:flex;align-items:center;justify-content:center;
  transition:all .2s;opacity:0;pointer-events:none}
.scroll-top.show{opacity:1;pointer-events:all}
.scroll-top:hover{border-color:var(--accent)}

::-webkit-scrollbar{width:5px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:var(--border);border-radius:5px}
@media(max-width:520px){
  .header-title{font-size:14px}
  .header-ago{display:none}
  .news-text{font-size:13px}
  .ch-popup{max-height:88vh}
  .news-img-wrap img{max-height:300px}
}
</style>
</head>
<body>

<div class="header">
  <div class="live-dot" id="liveDot"></div>
  <span class="header-title">اخبار زنده</span>
  <span class="header-ago" id="headerAgo">در حال بارگذاری…</span>
  <div class="header-right">
    <button class="new-badge" id="newBadge" onclick="scrollToTop()"></button>
    <button class="btn" id="refreshBtn" onclick="manualRefresh()" title="بروزرسانی">↻</button>
    <button class="btn" id="notifBtn"   onclick="toggleNotif()"   title="اعلان">🔔</button>
    <button class="btn" id="installBtn" onclick="installPWA()"    style="display:none">⬇ نصب</button>
    <a href="?logout" class="btn">خروج</a>
  </div>
</div>

<div class="toolbar">
  <input type="text" class="search-box" id="searchBox" placeholder="جستجو در اخبار…" oninput="applyFilters()">
  <span class="count-label" id="countLabel">— خبر</span>
  <div class="active-ch-wrap" id="activeChWrap"></div>
</div>

<div class="container">
  <div id="feed">
    <div class="empty"><div class="icon">📡</div><p>در حال اتصال…</p></div>
  </div>
</div>

<!-- Channel Picker -->
<div class="ch-popup-overlay" id="chPopupOverlay" onclick="closeChPopup(event)">
  <div class="ch-popup">
    <div class="ch-popup-header">
      <span class="ch-popup-title">📺 انتخاب کانال‌ها</span>
      <div class="ch-popup-actions">
        <button class="ch-select-all" onclick="selectAllCh()">همه</button>
        <button class="ch-select-all" onclick="clearAllCh()" style="color:var(--muted)">هیچکدام</button>
        <button class="ch-popup-close" onclick="closeChPopup()">×</button>
      </div>
    </div>
    <div class="ch-list" id="chList"></div>
    <div class="ch-popup-footer">
      <button class="ch-apply-btn" onclick="applyChSelection()">اعمال و بستن</button>
    </div>
  </div>
</div>

<!-- Stats -->
<div class="overlay" id="statsOverlay" onclick="closeStats(event)">
  <div class="modal">
    <div class="modal-title">📊 آمار <?= HOURS_WINDOW ?> ساعت اخیر</div>
    <div id="statsBody"></div>
    <button class="modal-close" onclick="closeStats()">بستن</button>
  </div>
</div>

<!-- Image Lightbox -->
<div class="lightbox" id="lightbox" onclick="closeLightbox()">
  <div class="lb-close" onclick="closeLightbox()">×</div>
  <img class="lb-img" id="lbImg" src="" alt="">
</div>

<div class="toast" id="toast"></div>
<div class="scroll-top" id="scrollTopBtn" onclick="scrollToTop()">↑</div>

<script>
// ── Config ─────────────────────────────────────────────
var POLL_MS = <?= POLL_MS ?>;
var MAX_DOM = <?= MAX_ENTRIES ?>;
var LS_KEY  = 'news_sel_ch_v3';

// ── State ──────────────────────────────────────────────
var cardDataMap  = {};
var allEntries   = [];
var maxRowId     = 0;
var statsData    = {};
var channelNames = {};
var channelLogos = {};   // channel_handle → logo URL (or null)
var allChannels  = [];
var searchTerm   = '';
var activeChans  = [];
var pendingChans = [];
var newSinceTop  = 0;
var lastPollTs   = null;
var agoTimer     = null;
var notifEnabled = (typeof Notification !== 'undefined' && Notification.permission === 'granted');
var deferredInstallPrompt = null;

// ── localStorage ────────────────────────────────────────
function loadSavedCh() { try { var r=localStorage.getItem(LS_KEY); return r?JSON.parse(r):null; } catch(e){ return null; } }
function saveCh(arr)   { try { localStorage.setItem(LS_KEY, JSON.stringify(arr)); } catch(e){} }

// ── Channel colour (unique hue per handle) ─────────────
function chColor(ch) {
  ch = String(ch).toLowerCase();
  var h = 0;
  for (var i = 0; i < ch.length; i++) h = (ch.charCodeAt(i) + ((h << 5) - h)) | 0;
  var hue = Math.abs(h) % 360;
  if (hue >= 190 && hue <= 250) hue = (hue + 130) % 360;
  return { bg: 'hsla('+hue+',48%,20%,1)', fg: 'hsl('+hue+',75%,72%)' };
}

// ── Channel logo HTML (circle) ─────────────────────────
// Returns an HTML string for a 30×30 circle logo or initial fallback.
function chLogoHtml(ch) {
  ch = String(ch).toLowerCase();
  var logo = channelLogos[ch];
  var col  = chColor(ch);
  var name = channelNames[ch] || ch;
  // First usable character for fallback circle
  var initial = (name.replace(/[^a-zA-Z\u0600-\u06FF]/g,'').charAt(0) || ch.charAt(0)).toUpperCase();
  var baseStyle = 'width:30px;height:30px;border-radius:50%;overflow:hidden;display:inline-flex;'
    + 'align-items:center;justify-content:center;flex-shrink:0;vertical-align:middle;'
    + 'border:1.5px solid ' + col.fg + ';';
  if (logo) {
    // Use an <img>; on error swap to coloured initial
    var fbBg  = col.bg.replace(/'/g,"\\'");
    var fbFg  = col.fg.replace(/'/g,"\\'");
    return '<span class="ch-logo" style="' + baseStyle + '">'
      + '<img src="' + esc(logo) + '" alt="" loading="lazy" '
      + 'onerror="this.parentNode.style.background=\'' + fbBg + '\';'
      +          'this.parentNode.style.color=\'' + fbFg + '\';'
      +          'this.parentNode.style.fontSize=\'13px\';'
      +          'this.parentNode.style.fontWeight=\'700\';'
      +          'this.parentNode.innerHTML=\'' + initial + '\'">'
      + '</span>';
  } else {
    return '<span class="ch-logo" style="' + baseStyle
      + 'background:' + col.bg + ';color:' + col.fg + ';font-size:13px;font-weight:700">'
      + initial + '</span>';
  }
}

// ── Tehran time display ─────────────────────────────────
function getTehranToday() {
  try { return new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Tehran'}).format(new Date()); }
  catch(e) { return new Date(Date.now()+12600000).toISOString().slice(0,10); }
}
function formatTime(dateStr) {
  if (!dateStr) return '';
  var d = dateStr.slice(0,10), t = dateStr.slice(11,16);
  var today = getTehranToday();
  var ydObj = new Date(today+'T12:00:00'); ydObj.setDate(ydObj.getDate()-1);
  var yesterday = ydObj.toISOString().slice(0,10);
  if (d === today)     return t;
  if (d === yesterday) return 'دیروز ' + t;
  return d.slice(5).replace('-','/') + ' ' + t;
}

// ── Init ────────────────────────────────────────────────
function init() {
  var saved = loadSavedCh();
  if (saved !== null) activeChans = saved;

  apiFetch('news.php?api=1', function(data) {
    if (!data) return;
    channelNames = data.channel_names || {};
    channelLogos = data.channel_logos || {};
    allChannels  = data.channels      || [];
    statsData    = data.stats         || {};
    maxRowId     = data.max_rowid     || 0;
    allEntries   = data.entries       || [];
    renderActiveChBar();
    renderFeed();
    setLastPoll();
    setTimeout(function(){ poll(); setInterval(poll, POLL_MS); }, 1500);
  });
}

// ── Poll ────────────────────────────────────────────────
function poll() {
  apiFetch('news.php?api=1&since_rowid=' + maxRowId, function(data) {
    if (!data) return;
    if (data.channel_names) channelNames = data.channel_names;
    if (data.channel_logos) channelLogos = data.channel_logos;
    if (data.channels)      allChannels  = data.channels;
    if (data.stats)         statsData    = data.stats;
    if ((data.max_rowid||0) > maxRowId) maxRowId = data.max_rowid;
    setLastPoll();
    if (!data.count) return;

    var seen = {};
    for (var i=0;i<allEntries.length;i++) seen[allEntries[i].rowid]=true;
    var added = [];
    for (var j=0;j<data.entries.length;j++) {
      var e=data.entries[j];
      if (!seen[e.rowid]){ allEntries.push(e); added.push(e); seen[e.rowid]=true; }
    }
    if (!added.length) return;

    allEntries.sort(function(a,b){ return b.date>a.date?1:(b.date<a.date?-1:0); });
    if (allEntries.length > MAX_DOM*2) allEntries=allEntries.slice(0,MAX_DOM*2);

    document.getElementById('liveDot').classList.remove('off');
    var feed  = document.getElementById('feed');
    var empty = feed.querySelector('.empty');
    if (empty) empty.remove();
    var isDown = window.scrollY > 250;

    var toInsert=[];
    for (var k=0;k<added.length;k++){
      var ae=added[k];
      if (activeChans.length>0 && activeChans.indexOf(ae.channel)===-1) continue;
      if (searchTerm && ae.text.toLowerCase().indexOf(searchTerm)===-1) continue;
      if (feed.querySelector('[data-rowid="'+ae.rowid+'"]')) continue;
      toInsert.push(ae);
    }
    if (toInsert.length>0){
      toInsert.sort(function(a,b){return a.date>b.date?1:(a.date<b.date?-1:0);});
      for (var m=0;m<toInsert.length;m++) feed.insertBefore(makeCard(toInsert[m],true),feed.firstChild);
      trimDom(); updateCount();
      showToast(toInsert.length+' خبر جدید ✓');
      var newest=toInsert[toInsert.length-1];
      sendNotif(toInsert.length, newest.text, newest.channel);
      if (isDown){ newSinceTop+=toInsert.length; showNewBadge(newSinceTop); }
    }
  });
}

// ── Render full feed ─────────────────────────────────────
function renderFeed() {
  var feed  = document.getElementById('feed');
  var items = allEntries.filter(function(e){
    if (activeChans.length>0 && activeChans.indexOf(e.channel)===-1) return false;
    if (searchTerm && e.text.toLowerCase().indexOf(searchTerm)===-1) return false;
    return true;
  });
  items.sort(function(a,b){return b.date>a.date?1:(b.date<a.date?-1:0);});
  if (items.length>MAX_DOM) items=items.slice(0,MAX_DOM);
  if (!items.length){
    feed.innerHTML='<div class="empty"><div class="icon">📭</div><p>'+(searchTerm||activeChans.length>0?'نتیجه‌ای یافت نشد':'هنوز خبری ثبت نشده')+'</p></div>';
    updateCount(0); return;
  }
  feed.innerHTML='';
  for (var i=0;i<items.length;i++) feed.appendChild(makeCard(items[i],false));
  updateCount(items.length);
}

// ── Card builder ─────────────────────────────────────────
function makeCard(entry, isFresh) {
  var div = document.createElement('div');
  div.className = 'news-item' + (isFresh ? ' fresh' : '');
  div.setAttribute('data-rowid', entry.rowid);
  div.setAttribute('data-ch', entry.channel);

  cardDataMap[entry.rowid] = {
    text: entry.text, ch: entry.channel,
    name: channelNames[entry.channel]||'',
    date: formatTime(entry.date), rowid: entry.rowid
  };

  var timeStr  = formatTime(entry.date);
  var name     = channelNames[entry.channel] || '';
  var col      = chColor(entry.channel);
  var logoHtml = chLogoHtml(entry.channel);
  var nameHtml = name
    ? '<span class="ch-name" style="background:'+col.bg+';color:'+col.fg+'">'+esc(name)+'</span>'
    : '';

  // Image block — shown BELOW the text, tappable to zoom
  var imgHtml = '';
  if (entry.image) {
    imgHtml = '<div class="news-img-wrap" onclick="openLightbox(\''+esc(entry.image)+'\')">'
            + '<img src="'+esc(entry.image)+'" alt="" loading="lazy" class="img-loading" '
            + 'onload="this.classList.remove(\'img-loading\')" '
            + 'onerror="this.parentNode.style.display=\'none\'">'
            + '</div>';
  }

  div.innerHTML =
    '<div class="news-meta">' +
      logoHtml +
      nameHtml +
      '<span class="ch-tag">@'+esc(entry.channel)+'</span>' +
      '<span class="news-date">'+esc(timeStr)+'</span>' +
      '<span class="meta-right">' +
        (isFresh ? '<span class="new-label">NEW</span>' : '') +
        '<button class="copy-btn" onclick="copyCard('+entry.rowid+',this)">' +
          '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="currentColor" viewBox="0 0 16 16">' +
          '<path fill-rule="evenodd" d="M4 2a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2zm2-1a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1zM2 5a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1v-1h1v1a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h1v1z"/>' +
          '</svg>' +
        '</button>' +
      '</span>' +
    '</div>' +
    '<div class="news-text">' + formatText(entry.text) + '</div>' +
    imgHtml;

  return div;
}

// ── Text formatter ──────────────────────────────────────
function formatText(raw) {
  if (!raw) return '';
  var lines=raw.split('\n'), cleaned=[];
  for (var i=0;i<lines.length;i++){
    var core=lines[i].trim()
      .replace(/[\u200b-\u200f\u202a-\u202e\uFEFF]/g,'')
      .replace(/[\s\-\u2013\u2014\u2022|]/g,'')
      .replace(/[\uD800-\uDBFF][\uDC00-\uDFFF]/g,'')
      .replace(/[\u2600-\u26FF\u2700-\u27BF\uFE00-\uFE0F\u20D0-\u20FF]/g,'').trim();
    if (/^@[A-Za-z0-9_]{3,}$/.test(core)) continue;
    cleaned.push(lines[i]);
  }
  while (cleaned.length && !cleaned[cleaned.length-1].trim()) cleaned.pop();
  var s=cleaned.join('\n'); if (!s) return '';
  var links=[];
  s=s.replace(/\[([^\]]*)\]\((https?:\/\/[^\)\s]+)\)/g,function(_,lb,url){
    var t=lb.replace(/[\u200b-\u200f\u202a-\u202e\uFEFF]/g,'').trim();
    if (!t) return '';
    var ph='\x00L'+links.length+'\x00';
    links.push('<a href="'+esc(url)+'" target="_blank" rel="noopener">'+esc(t)+'</a>');
    return ph;
  });
  s=s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  s=s.replace(/\*\*(.+?)\*\*/gs,'<strong>$1</strong>');
  s=s.replace(/__(.+?)__/gs,'<strong>$1</strong>');
  s=s.replace(/\*([^*\n]+)\*/g,'<em>$1</em>');
  s=s.replace(/_([^_\n]+)_/g,'<em>$1</em>');
  s=s.replace(/`([^`\n]+)`/g,'<code>$1</code>');
  s=s.replace(/(https?:\/\/[^\s<>"&\x00]+)/g,function(url){
    return '<a href="'+url+'" target="_blank" rel="noopener">'+url+'</a>';
  });
  links.forEach(function(h,i){ s=s.split('\x00L'+i+'\x00').join(h); });
  return s.replace(/\n/g,'<br>');
}
function esc(s){ if(!s)return''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }

// ── Lightbox ─────────────────────────────────────────────
function openLightbox(src){
  document.getElementById('lbImg').src = src;
  document.getElementById('lightbox').classList.add('show');
  document.body.style.overflow = 'hidden';
}
function closeLightbox(){
  document.getElementById('lightbox').classList.remove('show');
  document.getElementById('lbImg').src = '';
  document.body.style.overflow = '';
}
document.addEventListener('keydown', function(e){ if(e.key==='Escape') closeLightbox(); });

// ── Active channel bar ──────────────────────────────────
function renderActiveChBar() {
  var wrap=document.getElementById('activeChWrap');
  wrap.innerHTML='';
  var btn=document.createElement('button');
  btn.className='ch-manage-btn'; btn.textContent='+ کانال‌ها'; btn.onclick=openChPopup;
  wrap.appendChild(btn);
  if (activeChans.length===0){
    var t=document.createElement('span');
    t.className='active-ch-tag all-tag'; t.textContent='همه کانال‌ها'; t.onclick=openChPopup;
    wrap.appendChild(t);
  } else {
    activeChans.forEach(function(ch){
      var c=chColor(ch), tag=document.createElement('span');
      tag.className='active-ch-tag';
      tag.style.cssText='background:'+c.bg+';border-color:'+c.fg+';color:'+c.fg;
      tag.textContent=channelNames[ch]||('@'+ch);
      tag.title='حذف از فیلتر';
      tag.onclick=(function(x){return function(){removeCh(x);};})(ch);
      wrap.appendChild(tag);
    });
  }
}
function removeCh(ch){ activeChans=activeChans.filter(function(c){return c!==ch;}); saveCh(activeChans); renderActiveChBar(); renderFeed(); }

// ── Channel Popup ─────────────────────────────────────────
function openChPopup(){ pendingChans=activeChans.slice(); buildChList(); document.getElementById('chPopupOverlay').classList.add('show'); }
function closeChPopup(e){ if(e&&e.target!==document.getElementById('chPopupOverlay'))return; document.getElementById('chPopupOverlay').classList.remove('show'); }

function buildChList(){
  var list=document.getElementById('chList');
  list.innerHTML='';
  var toShow=allChannels.slice();
  pendingChans.forEach(function(ch){ ch=ch.toLowerCase(); if(toShow.indexOf(ch)===-1) toShow.push(ch); });
  if (!toShow.length){
    list.innerHTML='<p style="color:var(--muted);font-size:13px;padding:20px;text-align:center">هنوز کانالی داده ارسال نکرده</p>';
    return;
  }
  toShow.forEach(function(ch){
    ch=ch.toLowerCase();
    var sel=pendingChans.indexOf(ch)!==-1, col=chColor(ch);
    var row=document.createElement('div');
    row.className='ch-row'+(sel?' selected':'');
    var name=channelNames[ch]||'';
    var logo=channelLogos[ch];
    var initial=((name||ch).replace(/[^a-zA-Z\u0600-\u06FF]/g,'').charAt(0)||(ch.charAt(0))).toUpperCase();

    var logoHtml;
    if (logo){
      logoHtml='<div class="ch-row-logo" style="border-color:'+col.fg+'">'
        +'<img src="'+esc(logo)+'" alt="" '
        +'onerror="this.parentNode.style.background=\''+col.bg.replace(/'/g,"\\'") +'\';'
        +         'this.parentNode.style.color=\''+col.fg.replace(/'/g,"\\'")+'\';'
        +         'this.parentNode.innerHTML=\''+initial+'\'"></div>';
    } else {
      logoHtml='<div class="ch-row-logo" style="background:'+col.bg+';color:'+col.fg+';border-color:'+col.fg+'">'+initial+'</div>';
    }
    row.innerHTML='<div class="ch-row-check">'+(sel?'✓':'')+'</div>'
      +logoHtml
      +'<div class="ch-row-info">'
        +(name?'<div class="ch-row-name" style="color:'+col.fg+'">'+esc(name)+'</div>':'')
        +'<div class="ch-row-handle">@'+esc(ch)+'</div>'
      +'</div>';
    row.onclick=function(){toggleChRow(row,ch);};
    list.appendChild(row);
  });
}

function toggleChRow(row,ch){
  ch=ch.toLowerCase();
  var idx=pendingChans.indexOf(ch);
  if (idx===-1){ pendingChans.push(ch); row.classList.add('selected'); row.querySelector('.ch-row-check').textContent='✓'; }
  else { pendingChans.splice(idx,1); row.classList.remove('selected'); row.querySelector('.ch-row-check').textContent=''; }
}
function selectAllCh(){ pendingChans=allChannels.slice(); buildChList(); }
function clearAllCh(){  pendingChans=[]; buildChList(); }
function applyChSelection(){
  activeChans=(pendingChans.length===allChannels.length)?[]:pendingChans.slice();
  saveCh(activeChans);
  document.getElementById('chPopupOverlay').classList.remove('show');
  renderActiveChBar(); renderFeed();
}
function applyFilters(){ searchTerm=document.getElementById('searchBox').value.toLowerCase().trim(); renderFeed(); }

// ── Stats ─────────────────────────────────────────────────
function openStats(){
  var keys=Object.keys(statsData), body=document.getElementById('statsBody');
  if (!keys.length){ body.innerHTML='<p style="color:var(--muted);font-size:13px;text-align:center;padding:12px 0">خبری ثبت نشده.</p>'; }
  else {
    var total=keys.reduce(function(s,k){return s+statsData[k];},0);
    body.innerHTML=keys.map(function(ch){
      return '<div class="stat-row"><span class="stat-ch">@'+esc(ch)+'</span><span class="stat-num">'+statsData[ch]+' خبر</span></div>';
    }).join('')+'<div class="stat-total">مجموع: '+total+' خبر</div>';
  }
  document.getElementById('statsOverlay').classList.add('show');
}
function closeStats(e){ if(!e||e.target===document.getElementById('statsOverlay')) document.getElementById('statsOverlay').classList.remove('show'); }

// ── Helpers ───────────────────────────────────────────────
function setLastPoll(){ lastPollTs=Date.now(); updateAgo(); clearInterval(agoTimer); agoTimer=setInterval(updateAgo,15000); }
function updateAgo(){
  if (!lastPollTs) return;
  var s=Math.round((Date.now()-lastPollTs)/1000);
  document.getElementById('headerAgo').textContent=s<60?'همین الان':s<120?'۱ دقیقه قبل':Math.floor(s/60)+' دقیقه قبل';
}
function trimDom(){ var f=document.getElementById('feed'); while(f.children.length>MAX_DOM) f.removeChild(f.lastChild); }
function updateCount(n){ document.getElementById('countLabel').textContent=(n!==undefined?n:document.querySelectorAll('.news-item').length)+' خبر'; }
function showNewBadge(n){ var el=document.getElementById('newBadge'); if(n>0){el.textContent='↑ '+n+' خبر جدید';el.classList.add('show');}else el.classList.remove('show'); }
function scrollToTop(){ window.scrollTo({top:0,behavior:'smooth'}); newSinceTop=0; showNewBadge(0); }
window.addEventListener('scroll',function(){ document.getElementById('scrollTopBtn').classList.toggle('show',window.scrollY>300); if(window.scrollY<50){newSinceTop=0;showNewBadge(0);} });
var toastTimer;
function showToast(msg){ var el=document.getElementById('toast'); el.textContent=msg; el.classList.add('show'); clearTimeout(toastTimer); toastTimer=setTimeout(function(){el.classList.remove('show');},3000); }

function apiFetch(url, cb){
  var absUrl=window.location.pathname+'?'+url+'&key=<?= htmlspecialchars(PASS_KEY, ENT_QUOTES) ?>';
  var xhr=new XMLHttpRequest();
  xhr.open('GET',absUrl,true);
  xhr.setRequestHeader('Cache-Control','no-cache');
  xhr.onload=function(){
    if (xhr.status===200){
      try{
        var data=JSON.parse(xhr.responseText);
        if (data.offline){ document.getElementById('liveDot').classList.add('off'); document.getElementById('headerAgo').textContent='آفلاین'; cb(null); return; }
        cb(data);
      }catch(e){ cb(null); }
    } else cb(null);
  };
  xhr.onerror=function(){ document.getElementById('liveDot').classList.add('off'); document.getElementById('headerAgo').textContent='خطا در اتصال'; cb(null); };
  xhr.timeout=10000;
  xhr.ontimeout=function(){ document.getElementById('liveDot').classList.add('off'); document.getElementById('headerAgo').textContent='اتصال قطع شد'; cb(null); };
  xhr.send();
}

function copyCard(rowid, btn){
  var d=cardDataMap[rowid]; if (!d) return;
  var label=d.name?d.name+' (@'+d.ch+')':'@'+d.ch;
  var str='['+label+'] ['+d.date+']\n'+d.text;
  if (navigator.clipboard&&navigator.clipboard.writeText){ navigator.clipboard.writeText(str).then(function(){flashCopied(btn);}); }
  else { var ta=document.createElement('textarea'); ta.value=str; ta.style.cssText='position:fixed;opacity:0'; document.body.appendChild(ta); ta.select(); try{document.execCommand('copy');flashCopied(btn);}catch(e){} document.body.removeChild(ta); }
}
function flashCopied(btn){ if(!btn)return; btn.textContent='✓ کپی شد'; btn.classList.add('copied'); showToast('متن کپی شد ✓'); setTimeout(function(){btn.textContent='📋 کپی';btn.classList.remove('copied');},2000); }
function manualRefresh(){ var btn=document.getElementById('refreshBtn'); btn.style.opacity='0.5'; btn.style.pointerEvents='none'; poll(); setTimeout(function(){btn.style.opacity='';btn.style.pointerEvents='';},1500); }
function toggleNotif(){
  if (!('Notification'in window)){showToast('مرورگر اعلان پشتیبانی نمیکند');return;}
  if (Notification.permission==='granted'){notifEnabled=!notifEnabled;updateNotifBtn();showToast(notifEnabled?'اعلان فعال شد 🔔':'اعلان غیرفعال شد 🔕');}
  else{Notification.requestPermission().then(function(p){notifEnabled=(p==='granted');updateNotifBtn();showToast(notifEnabled?'اعلان فعال شد 🔔':'دسترسی داده نشد');});}
}
function updateNotifBtn(){ var b=document.getElementById('notifBtn'); if(!b)return; b.textContent=notifEnabled?'🔔':'🔕'; b.style.color=notifEnabled?'var(--green)':''; }
function sendNotif(count,text,ch){
  if (!notifEnabled||Notification.permission!=='granted') return;
  var name=channelNames[ch]||('@'+ch);
  try{ var n=new Notification(name+' — '+count+' خبر جدید',{body:String(text).replace(/<[^>]+>/g,'').slice(0,100),icon:'icon-192.png',tag:'news-live',renotify:true}); n.onclick=function(){window.focus();n.close();}; }catch(e){}
}
window.addEventListener('beforeinstallprompt',function(e){e.preventDefault();deferredInstallPrompt=e;var b=document.getElementById('installBtn');if(b)b.style.display='';});
window.addEventListener('appinstalled',function(){deferredInstallPrompt=null;var b=document.getElementById('installBtn');if(b)b.style.display='none';});
function installPWA(){ if(!deferredInstallPrompt){showToast('از منوی مرورگر نصب کنید');return;} deferredInstallPrompt.prompt(); deferredInstallPrompt.userChoice.then(function(r){if(r.outcome==='accepted')showToast('نصب شد ✓');deferredInstallPrompt=null;document.getElementById('installBtn').style.display='none';}); }

if ('serviceWorker' in navigator && window.self===window.top) {
  navigator.serviceWorker.register('/news/sw.js',{scope:'/news/'})
    .then(function(r){console.log('✓ SW:',r.scope);})
    .catch(function(e){console.error('✗ SW:',e);});
}

updateNotifBtn();
init();
</script>
</body>
</html>