<?php
/**
 * fetch.php v5 — Telegram News Receiver (text + async images)
 * ────────────────────────────────────────────────────────────
 * ✦ Handles 2-phase delivery from grabber v6.2:
 *    1. Initial POST: text only (fast)
 *    2. Update POST: add image when ready (background)
 * ✦ Stores news in SQLite with AES-256-CBC encrypted text
 * ✦ Images stored in /images/ subfolder
 * ✦ Old image files cleaned up with old DB rows
 * ✦ All dates in Tehran timezone (Asia/Tehran)
 *
 * ── LIMITS ──────────────────────────────────────────────────
 *  KEEP_HOURS  → delete entries older than N hours (default 6)
 *  SECRET      → must match grabber.py PUSH_ENDPOINTS secret
 *  MAX_IMG_B   → max accepted image size in bytes (default 3 MB)
 * ────────────────────────────────────────────────────────────
 */

date_default_timezone_set('Asia/Tehran');

// ── CONFIG ────────────────────────────────────────────────────
define('SECRET',     'CHANGE_THIS_SECRET_KEY');   // Must match grabber.py
define('DB_FILE',    __DIR__ . '/news.db');
define('ENC_KEY',    'CHANGE_THIS_ENC_KEY');      // Must match news.php
define('ENC_SALT',   'CHANGE_THIS_ENC_SALT');     // Must match news.php
define('KEEP_HOURS', 6);                          // Hours of data to retain
define('IMG_DIR',    __DIR__ . '/images/');       // Image storage directory
define('MAX_IMG_B',  3 * 1024 * 1024);            // 3 MB max image size
// ─────────────────────────────────────────────────────────────

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status'=>'error','msg'=>'POST only']);
    exit;
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!$data) { 
    http_response_code(400); 
    echo json_encode(['status'=>'error','msg'=>'Invalid JSON']); 
    exit; 
}

if (($data['secret'] ?? '') !== SECRET) { 
    http_response_code(403); 
    echo json_encode(['status'=>'error','msg'=>'Forbidden']); 
    exit; 
}

// Check if this is an UPDATE (image addition) or new INSERT
$is_update = !empty($data['update']);

// ═══════════════════════════════════════════════════════════════
//  UPDATE MODE: Add image to existing entry
// ═══════════════════════════════════════════════════════════════
if ($is_update) {
    foreach (['channel','msg_id'] as $f) {
        if (empty($data[$f])) { 
            http_response_code(400); 
            echo json_encode(['status'=>'error','msg'=>"Missing: $f"]); 
            exit; 
        }
    }

    $channel = preg_replace('/[^a-zA-Z0-9_\-]/', '', $data['channel']);
    $msg_id  = (int)$data['msg_id'];

    // Process image
    $image_filename = null;
    if (!empty($data['image'])) {
        $img_b64 = $data['image'];
        if (preg_match('/^data:image\/\w+;base64,/', $img_b64)) {
            $img_b64 = preg_replace('/^data:image\/\w+;base64,/', '', $img_b64);
        }

        $raw_img = base64_decode($img_b64, true);

        if ($raw_img !== false && strlen($raw_img) >= 64 && strlen($raw_img) <= MAX_IMG_B) {
            $magic   = substr($raw_img, 0, 4);
            $is_jpeg = (substr($magic, 0, 2) === "\xFF\xD8");
            $is_png  = ($magic === "\x89PNG");

            if ($is_jpeg || $is_png) {
                if (!is_dir(IMG_DIR)) {
                    mkdir(IMG_DIR, 0755, true);
                }
                $ext      = $is_jpeg ? 'jpg' : 'png';
                $filename = strtolower($channel) . '_' . $msg_id . '.' . $ext;
                $filepath = IMG_DIR . $filename;

                if (!file_exists($filepath)) {
                    file_put_contents($filepath, $raw_img);
                }
                $image_filename = $filename;
            }
        }
    }

    if (!$image_filename) {
        echo json_encode(['status'=>'ok','msg'=>'No valid image in update']);
        exit;
    }

    // Update existing row
    $db = getDB();
    try {
        $stmt = $db->prepare("UPDATE news SET image = ? WHERE msg_id = ? AND channel = ?");
        $stmt->execute([$image_filename, $msg_id, $channel]);
        $updated = $stmt->rowCount();
        
        if ($updated) {
            echo json_encode([
                'status' => 'ok',
                'msg'    => 'Image added',
                'action' => 'updated',
                'image'  => $image_filename
            ]);
        } else {
            echo json_encode([
                'status' => 'ok',
                'msg'    => 'Entry not found (maybe already deleted)',
                'action' => 'skipped'
            ]);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['status'=>'error','msg'=>'DB error: ' . $e->getMessage()]);
    }
    exit;
}

// ═══════════════════════════════════════════════════════════════
//  INSERT MODE: New message (text, optional image)
// ═══════════════════════════════════════════════════════════════
foreach (['channel','msg_id','date','text'] as $f) {
    if (empty($data[$f])) { 
        http_response_code(400); 
        echo json_encode(['status'=>'error','msg'=>"Missing: $f"]); 
        exit; 
    }
}

// Sanitise core fields
$text    = trim(strip_tags($data['text']));
$channel = preg_replace('/[^a-zA-Z0-9_\-]/', '', $data['channel']);
$msg_id  = (int)$data['msg_id'];

if (!$text) { 
    echo json_encode(['status'=>'ok','msg'=>'Empty text, skipped']); 
    exit; 
}

// Date (grabber sends Tehran time directly)
$stored_date = trim($data['date']);

// Reject entries older than KEEP_HOURS
$cutoff_ts = time() - (KEEP_HOURS * 3600);
$entry_ts  = strtotime($stored_date);
if ($entry_ts !== false && $entry_ts < $cutoff_ts) {
    echo json_encode(['status'=>'ok','msg'=>'Too old, skipped']);
    exit;
}

// ── Image handling (optional in INSERT) ────────────────────────
$image_filename = null;

if (!empty($data['image'])) {
    $img_b64 = $data['image'];
    if (preg_match('/^data:image\/\w+;base64,/', $img_b64)) {
        $img_b64 = preg_replace('/^data:image\/\w+;base64,/', '', $img_b64);
    }

    $raw_img = base64_decode($img_b64, true);

    if ($raw_img !== false && strlen($raw_img) >= 64 && strlen($raw_img) <= MAX_IMG_B) {
        $magic   = substr($raw_img, 0, 4);
        $is_jpeg = (substr($magic, 0, 2) === "\xFF\xD8");
        $is_png  = ($magic === "\x89PNG");

        if ($is_jpeg || $is_png) {
            if (!is_dir(IMG_DIR)) {
                mkdir(IMG_DIR, 0755, true);
            }
            $ext      = $is_jpeg ? 'jpg' : 'png';
            $filename = strtolower($channel) . '_' . $msg_id . '.' . $ext;
            $filepath = IMG_DIR . $filename;

            if (!file_exists($filepath)) {
                file_put_contents($filepath, $raw_img);
            }
            $image_filename = $filename;
        }
    }
}

// ── Database ───────────────────────────────────────────────────
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
    // Safe migration: add image column if missing
    try { 
        $pdo->exec("ALTER TABLE news ADD COLUMN image TEXT DEFAULT NULL"); 
    } catch (Exception $e) {}
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_date ON news(date DESC)");
    return $pdo;
}

// ── Encryption ─────────────────────────────────────────────────
function encKey(): string {
    return hash('sha256', ENC_KEY . ':' . ENC_SALT, true);
}
function encryptText(string $text): string {
    $iv  = random_bytes(16);
    $enc = openssl_encrypt($text, 'AES-256-CBC', encKey(), OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $enc);
}

$db = getDB();

// ── Duplicate check ────────────────────────────────────────────
$dup = $db->prepare("SELECT id FROM news WHERE msg_id=? AND channel=? LIMIT 1");
$dup->execute([$msg_id, $channel]);
if ($dup->fetch()) {
    echo json_encode(['status'=>'ok','msg'=>'Duplicate, skipped']);
    exit;
}

// ── Periodic cleanup (~every 30th insert) ─────────────────────
if (rand(1, 30) === 1) {
    $cutoff_str = date('Y-m-d H:i:s', $cutoff_ts);
    // Delete old image files from disk
    try {
        $old = $db->query(
            "SELECT image FROM news WHERE date < " . $db->quote($cutoff_str) . " AND image IS NOT NULL"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($old as $img_file) {
            $fp = IMG_DIR . basename((string)$img_file);
            if (file_exists($fp)) @unlink($fp);
        }
    } catch (Exception $e) {}
    $db->exec("DELETE FROM news WHERE date < " . $db->quote($cutoff_str));
    $db->exec("PRAGMA wal_checkpoint(TRUNCATE)");
}

// ── Insert ─────────────────────────────────────────────────────
try {
    $enc  = encryptText($text);
    $stmt = $db->prepare(
        "INSERT OR IGNORE INTO news (msg_id, channel, date, text_enc, image) VALUES (?,?,?,?,?)"
    );
    $stmt->execute([$msg_id, $channel, $stored_date, $enc, $image_filename]);
    $inserted = $stmt->rowCount();
    
    if ($inserted) {
        echo json_encode([
            'status' => 'ok',
            'msg'    => 'Saved',
            'action' => 'inserted',
            'id'     => $msg_id,
            'image'  => $image_filename
        ]);
    } else {
        echo json_encode(['status'=>'ok','msg'=>'Duplicate (race), skipped']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status'=>'error','msg'=>'DB error: ' . $e->getMessage()]);
}
?>
