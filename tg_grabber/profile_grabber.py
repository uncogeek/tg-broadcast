"""
Telegram Channel Profile Image Grabber
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Downloads profile pictures of all channels defined in config.py
to the folder set in PROFILE_OUTPUT_FOLDER.
One image per channel, runs once, then exits.

⚠️  Uses existing 'session.session' file — NO new authentication required
    Run news_grabber.py first to create the session.

Configuration is loaded from config.py — edit that file first.
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
"""

import asyncio
import logging
import sys
from pathlib import Path

from telethon import TelegramClient
from telethon.network.connection import ConnectionTcpFull

# ── Load shared config ────────────────────────────────────────────────
from config import (
    API_ID, API_HASH,
    USE_PROXY, PROXY_HOST, PROXY_PORT, PROXY_USER, PROXY_PASS,
    CHANNELS,
    PROFILE_OUTPUT_FOLDER, PROFILE_LOG_FILE,
)

# ─────────────────────────────────────────────────────────────────────


def setup_logging():
    fmt     = '%(asctime)s  %(levelname)-7s  %(message)s'
    datefmt = '%Y-%m-%d %H:%M:%S'
    logging.basicConfig(
        level=logging.INFO, format=fmt, datefmt=datefmt,
        handlers=[
            logging.FileHandler(PROFILE_LOG_FILE, encoding='utf-8'),
            logging.StreamHandler(sys.stdout),
        ]
    )
    logging.getLogger('telethon').setLevel(logging.WARNING)


log = logging.getLogger('profile_grabber')


def build_tg_client() -> TelegramClient:
    """Uses existing 'session.session' file — no phone auth if already logged in."""
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
        'session',
        int(API_ID), API_HASH,
        connection=ConnectionTcpFull,
        connection_retries=5, timeout=30, retry_delay=5,
        use_ipv6=False, proxy=proxy,
    )


async def download_profile_pic(client: TelegramClient, channel_username: str, out_dir: Path) -> bool:
    """
    Download channel profile picture. Returns True if successful.
    Saves as: {channel_username}.jpg
    """
    try:
        entity = await client.get_entity(channel_username)

        if not entity.photo:
            log.warning(f'  @{channel_username} — no profile picture')
            return False

        filename = out_dir / f'{channel_username}.jpg'

        await client.download_profile_photo(
            entity,
            file=str(filename),
            download_big=True
        )

        if filename.exists():
            size_kb = filename.stat().st_size // 1024
            log.info(f'  ✓ @{channel_username} — {size_kb} KB → {filename.name}')
            return True
        else:
            log.warning(f'  @{channel_username} — download failed (file not created)')
            return False

    except Exception as e:
        log.error(f'  ✗ @{channel_username} — {type(e).__name__}: {e}')
        return False


async def run():
    log.info('═' * 60)
    log.info('Telegram Channel Profile Image Grabber')
    log.info(f'  Channels : {len(CHANNELS)}')
    log.info(f'  Output   : {PROFILE_OUTPUT_FOLDER}/')
    log.info('═' * 60)

    session_file = Path('session.session')
    if not session_file.exists():
        log.error('❌ session.session NOT FOUND!')
        log.error('   Run news_grabber.py first to create the session.')
        return

    out_dir = Path(PROFILE_OUTPUT_FOLDER)
    out_dir.mkdir(exist_ok=True)
    log.info(f'Output folder: {out_dir.absolute()}\n')

    success_count = 0

    try:
        async with build_tg_client() as client:
            await client.connect()

            if not await client.is_user_authorized():
                log.error('❌ Session expired or invalid!')
                log.error('   Run news_grabber.py to re-authenticate.')
                return

            me = await client.get_me()
            log.info(f'Signed in as: @{me.username or me.first_name}\n')

            for i, channel in enumerate(CHANNELS, 1):
                log.info(f'[{i}/{len(CHANNELS)}] Downloading @{channel}...')
                ok = await download_profile_pic(client, channel, out_dir)
                if ok:
                    success_count += 1
                await asyncio.sleep(0.5)

    except KeyboardInterrupt:
        log.info('\nStopped by user.')
    except Exception as e:
        log.error(f'Fatal: {e}', exc_info=True)

    log.info('━' * 60)
    log.info(f'Done!  {success_count}/{len(CHANNELS)} profiles downloaded.')
    log.info(f'Saved in: {out_dir.absolute()}')
    log.info('━' * 60)


if __name__ == '__main__':
    setup_logging()
    if sys.platform == 'win32':
        asyncio.set_event_loop_policy(asyncio.WindowsSelectorEventLoopPolicy())
    asyncio.run(run())
