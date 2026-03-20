"""
Telegram Text + Image Grabber  v6.3
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
FLOW:
  • Text posts     → push IMMEDIATELY
  • Image posts    → download image FIRST (background task)
                   → push TEXT + IMAGE together when ready
                   → post appears at top of feed with image

Image posts are held until image is ready so they always
arrive complete and appear at the correct position in news.

Configuration is loaded from config.py — edit that file first.
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
"""

import asyncio
import sys
import json
import logging
import re
import io
import base64
import os
from datetime import datetime, timezone, timedelta
from pathlib import Path

import aiohttp
import pytz
from telethon import TelegramClient
from telethon.network.connection import ConnectionTcpFull
from telethon.tl.types import MessageMediaPhoto

# ── Load shared config ────────────────────────────────────────────────
from config import (
    API_ID, API_HASH, PHONE,
    USE_PROXY, PROXY_HOST, PROXY_PORT, PROXY_USER, PROXY_PASS,
    CHANNELS, PUSH_ENDPOINTS,
    POLL_INTERVAL, HOURS_WINDOW, MAX_IMAGE_BYTES,
    LOG_FILE, BW_LOG_FILE, LOCK_FILE, IMAGE_DIR,
)

# ─────────────────────────────────────────────────────────────────────

TEHRAN_TZ = pytz.timezone('Asia/Tehran')
Path(IMAGE_DIR).mkdir(exist_ok=True)


def setup_logging():
    fmt     = '%(asctime)s  %(levelname)-7s  %(message)s'
    datefmt = '%Y-%m-%d %H:%M:%S'
    logging.basicConfig(
        level=logging.DEBUG, format=fmt, datefmt=datefmt,
        handlers=[
            logging.FileHandler(LOG_FILE, encoding='utf-8'),
            logging.StreamHandler(sys.stdout),
        ]
    )
    logging.getLogger('telethon').setLevel(logging.WARNING)
    logging.getLogger('aiohttp').setLevel(logging.WARNING)

log = logging.getLogger('grabber')


# ── PID Lock ──────────────────────────────────────────────────────────
def acquire_lock():
    if os.path.exists(LOCK_FILE):
        try:
            with open(LOCK_FILE, 'r') as f:
                old_pid = int(f.read().strip())
            try:
                import psutil
                if psutil.pid_exists(old_pid):
                    print(f'❌ Already running (PID {old_pid})')
                    print(f'   → Kill: kill {old_pid}  (Linux) / taskkill /F /PID {old_pid}  (Windows)')
                    sys.exit(1)
                else:
                    os.remove(LOCK_FILE)
            except ImportError:
                import time
                if os.path.getmtime(LOCK_FILE) < (time.time() - 3600):
                    os.remove(LOCK_FILE)
                else:
                    print(f'❌ Lock exists (PID {old_pid})')
                    print('   → If stale: rm grabber.pid')
                    sys.exit(1)
        except Exception:
            pass
    with open(LOCK_FILE, 'w') as f:
        f.write(str(os.getpid()))

def release_lock():
    try:
        os.remove(LOCK_FILE)
    except Exception:
        pass


# ── Bandwidth tracker ─────────────────────────────────────────────────
class BandwidthTracker:
    def __init__(self):
        self.proxy_bytes_sent     = 0
        self.proxy_bytes_received = 0
        self.messages_fetched     = 0
        self.images_downloaded    = 0
        self.start_time           = datetime.now()

    def add_message(self, text: str, img_raw_bytes: int = 0):
        overhead = 60
        payload  = len(text.encode('utf-8'))
        self.proxy_bytes_received += payload + overhead + img_raw_bytes
        self.proxy_bytes_sent     += overhead
        self.messages_fetched     += 1
        if img_raw_bytes:
            self.images_downloaded += 1

    def proxy_kb(self) -> float:
        return round((self.proxy_bytes_sent + self.proxy_bytes_received) / 1024, 2)

    def summary_line(self) -> str:
        return (f'Proxy: {self.proxy_kb()} KB  |  '
                f'Msgs: {self.messages_fetched}  |  '
                f'Imgs: {self.images_downloaded}')

    def save_report(self):
        elapsed = (datetime.now() - self.start_time).total_seconds()
        report  = {
            'session_start':     self.start_time.strftime('%Y-%m-%d %H:%M:%S'),
            'session_end':       datetime.now().strftime('%Y-%m-%d %H:%M:%S'),
            'elapsed_seconds':   round(elapsed),
            'messages_fetched':  self.messages_fetched,
            'images_downloaded': self.images_downloaded,
            'proxy_total_kb':    self.proxy_kb(),
        }
        with open(BW_LOG_FILE, 'a', encoding='utf-8') as f:
            f.write(json.dumps(report, ensure_ascii=False) + '\n')
        log.info('━' * 55)
        log.info(f'SESSION END  |  {self.summary_line()}  |  {round(elapsed)}s')
        log.info('━' * 55)


bw = BandwidthTracker()


# ── Telegram client ───────────────────────────────────────────────────
def build_tg_client(session_name='session') -> TelegramClient:
    proxy = None
    if USE_PROXY:
        proxy = {
            'proxy_type': 'socks5', 'addr': PROXY_HOST, 'port': PROXY_PORT,
            'rdns': True, 'username': PROXY_USER, 'password': PROXY_PASS,
        }
        log.info(f'[Telegram] SOCKS5 {PROXY_HOST}:{PROXY_PORT}  user={PROXY_USER}')
    else:
        log.info('[Telegram] Direct connection (no proxy)')

    return TelegramClient(
        session_name, int(API_ID), API_HASH,
        connection=ConnectionTcpFull,
        connection_retries=5, timeout=30, retry_delay=5,
        use_ipv6=False, proxy=proxy,
    )


def build_http_session() -> aiohttp.ClientSession:
    connector = aiohttp.TCPConnector(ssl=False, limit=10)
    return aiohttp.ClientSession(connector=connector, trust_env=False)


# ── Message classification ────────────────────────────────────────────
def classify_msg(msg) -> str:
    has_text = bool(msg.text and msg.text.strip())
    if msg.media is None:
        return 'text' if has_text else 'skip'
    if isinstance(msg.media, MessageMediaPhoto):
        return 'image' if has_text else 'skip'
    return 'skip'


# ── Helpers ───────────────────────────────────────────────────────────
def get_window_start() -> datetime:
    return datetime.now(timezone.utc) - timedelta(hours=HOURS_WINDOW)

def utc_to_tehran(dt: datetime) -> str:
    return dt.replace(tzinfo=timezone.utc).astimezone(TEHRAN_TZ).strftime('%Y-%m-%d %H:%M:%S')

def clean_text(text: str) -> str:
    lines, cleaned = text.split('\n'), []
    for line in lines:
        core = re.sub(
            r'[\U0001F000-\U0001FFFF\u2600-\u26FF\u2700-\u27BF\uFE00-\uFE0F'
            r'⭐🤖📢👤🔔📣💬📰🗞\-–—•|\s\u200b-\u200f\u202a-\u202e\uFEFF]',
            '', line.strip()
        )
        if re.match(r'^@[A-Za-z0-9_]{3,}$', core):
            continue
        cleaned.append(line)
    return '\n'.join(cleaned).strip()


# ── Image download (background) ───────────────────────────────────────
async def download_photo_to_disk(client, msg, channel: str) -> Path | None:
    """Download photo from a message to IMAGE_DIR. Returns path or None."""
    try:
        filename = Path(IMAGE_DIR) / f'{channel}_{msg.id}.jpg'
        await client.download_media(msg.media, file=str(filename))
        if filename.exists() and filename.stat().st_size > 0:
            size = filename.stat().st_size
            if size > MAX_IMAGE_BYTES:
                log.warning(f'[img] {filename.name} too large ({size//1024} KB), skipping')
                filename.unlink(missing_ok=True)
                return None
            log.debug(f'[img] downloaded {filename.name}  ({size//1024} KB)')
            return filename
    except Exception as e:
        log.error(f'[img] download failed for {channel}#{msg.id}: {e}')
    return None

def image_to_base64(filepath: Path) -> str | None:
    try:
        with open(filepath, 'rb') as f:
            return base64.b64encode(f.read()).decode('ascii')
    except Exception as e:
        log.error(f'[img] base64 encode failed: {e}')
        return None


# ── Push ──────────────────────────────────────────────────────────────
async def push_to_all(http, channel, msg_id, date_str, text, image_b64=None, is_update=False) -> str:
    """Push to all endpoints. is_update=True means this is an image update."""
    results = {}
    for ep in PUSH_ENDPOINTS:
        payload = {
            'secret':  ep['secret'],
            'channel': channel,
            'msg_id':  msg_id,
            'date':    date_str,
            'text':    text,
        }
        if image_b64:
            payload['image'] = image_b64
        if is_update:
            payload['update'] = True

        try:
            async with http.post(ep['url'], json=payload, timeout=aiohttp.ClientTimeout(total=30)) as resp:
                body = await resp.text()
                ok = resp.status == 200 and 'ok' in body.lower()
                results[ep['url']] = ok
        except Exception:
            results[ep['url']] = False

    ok_count = sum(1 for v in results.values() if v)
    icon = '✓' if ok_count == len(results) else ('⚠' if ok_count else '✗')
    return f'{icon} {ok_count}/{len(results)}'


# ── Process messages ──────────────────────────────────────────────────
async def process_text_only(http, channel, msg, prefix='') -> bool:
    """Push text-only post immediately."""
    text = clean_text(msg.text)
    if not text:
        return False

    date_str = utc_to_tehran(msg.date)
    bw.add_message(text, 0)
    summary = await push_to_all(http, channel, msg.id, date_str, text, None)
    short = text[:68].replace('\n', ' ')
    log.info(f'{prefix}#{msg.id} {date_str} [txt] [{summary}]  {short}{"…" if len(text)>68 else ""}')
    return True


async def process_with_image_async(client, http, channel, msg, prefix='') -> bool:
    """
    Download image in background first.
    Push TEXT + IMAGE together as a single INSERT when ready.
    Falls back to text-only if download fails.
    """
    text = clean_text(msg.text)
    if not text:
        return False

    date_str = utc_to_tehran(msg.date)
    short = text[:60].replace('\n', ' ')

    async def download_and_push():
        try:
            filepath  = await download_photo_to_disk(client, msg, channel)
            image_b64 = image_to_base64(filepath) if filepath else None

            bw.add_message(text, 0)
            summary = await push_to_all(
                http, channel, msg.id, date_str, text,
                image_b64=image_b64,
                is_update=False,
            )
            tag = '[cap+img]' if image_b64 else '[cap-noimag]'
            log.info(f'{prefix}#{msg.id} {date_str} {tag} [{summary}]  {short}{"…" if len(text)>60 else ""}')

        except Exception as e:
            log.error(f'{prefix}#{msg.id} [img] background task failed: {e}')
            try:
                bw.add_message(text, 0)
                summary = await push_to_all(http, channel, msg.id, date_str, text)
                log.info(f'{prefix}#{msg.id} {date_str} [cap-fallback] [{summary}]  {short}')
            except Exception:
                pass

    asyncio.create_task(download_and_push())
    return True


# ── Main ──────────────────────────────────────────────────────────────
async def run():
    acquire_lock()

    log.info('═' * 55)
    log.info('Telegram Grabber v6.3')
    log.info(f'  TEXT posts   → push IMMEDIATELY')
    log.info(f'  IMAGE posts  → download first, then push text+image together')
    log.info(f'  Poll every   {POLL_INTERVAL}s  |  Window: {HOURS_WINDOW}h')
    log.info('═' * 55)

    since_utc = get_window_start()
    log.info(f'Window start: {utc_to_tehran(since_utc)} Tehran')

    try:
        async with build_tg_client() as client:
            await client.start(phone=PHONE)
            me = await client.get_me()
            log.info(f'Signed in: @{me.username or me.first_name}')

            async with build_http_session() as http:

                # ── Phase 1: Last message per channel ──
                log.info('━' * 55)
                log.info('[Phase 1] Catching up — last message per channel')
                last_ids = {}

                for channel in CHANNELS:
                    log.info(f'  ▶ @{channel}')
                    last_id, found = 0, False

                    async for msg in client.iter_messages(channel, limit=20):
                        if msg.id > last_id:
                            last_id = msg.id

                        msg_utc = msg.date.replace(tzinfo=timezone.utc) if msg.date else None
                        if msg_utc and msg_utc < since_utc:
                            break

                        msg_type = classify_msg(msg)
                        if msg_type == 'skip':
                            continue

                        if msg_type == 'text':
                            ok = await process_text_only(http, channel, msg, '    ')
                        elif msg_type == 'image':
                            ok = await process_with_image_async(client, http, channel, msg, '    ')

                        if ok:
                            found = True
                            break

                    if last_id == 0:
                        try:
                            latest = await client.get_messages(channel, limit=1)
                            if latest:
                                last_id = latest[0].id
                        except Exception:
                            pass

                    last_ids[channel] = last_id
                    log.info(f'  {"✓" if found else "○"}  |  {bw.summary_line()}')

                # ── Phase 2: Live monitoring ──
                log.info('━' * 55)
                log.info(f'[Phase 2] Live monitoring every {POLL_INTERVAL}s — press Ctrl+C to stop')

                while True:
                    await asyncio.sleep(POLL_INTERVAL)
                    ts = datetime.now(TEHRAN_TZ).strftime('%H:%M:%S')
                    window_utc = get_window_start()

                    for channel in CHANNELS:
                        min_id = last_ids.get(channel, 0)

                        async for msg in client.iter_messages(channel, min_id=min_id, limit=10):
                            if msg.id <= min_id:
                                continue
                            if msg.id > last_ids.get(channel, 0):
                                last_ids[channel] = msg.id

                            msg_utc = msg.date.replace(tzinfo=timezone.utc) if msg.date else None
                            if msg_utc and msg_utc < window_utc:
                                continue

                            msg_type = classify_msg(msg)
                            if msg_type == 'skip':
                                continue

                            prefix = f'[{ts}] 🆕 @{channel} '

                            if msg_type == 'text':
                                await process_text_only(http, channel, msg, prefix)
                            elif msg_type == 'image':
                                await process_with_image_async(client, http, channel, msg, prefix)

                            break  # one new post per channel per poll

    except KeyboardInterrupt:
        log.info('Stopped by user.')
    except Exception as e:
        log.error(f'Fatal: {e}', exc_info=True)
    finally:
        bw.save_report()
        release_lock()
        log.info('Bye! 👋')


if __name__ == '__main__':
    setup_logging()
    if sys.platform == 'win32':
        asyncio.set_event_loop_policy(asyncio.WindowsSelectorEventLoopPolicy())
    asyncio.run(run())
