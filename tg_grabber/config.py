"""
config.py — Shared configuration for news_grabber.py and profile_grabber.py
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Edit this file before running either script.
DO NOT commit this file to Git if you fill in real credentials.
Add it to .gitignore, or keep credentials in environment variables.
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
"""

# ═══════════════════════════════════════════════════════════════════════
#  TELEGRAM API CREDENTIALS
#  Get yours at: https://my.telegram.org → API development tools
# ═══════════════════════════════════════════════════════════════════════

API_ID   = 'YOUR_API_ID'          # e.g. '12345678'
API_HASH = 'YOUR_API_HASH'        # e.g. 'abcdef1234567890abcdef1234567890'
PHONE    = 'YOUR_PHONE_NUMBER'    # e.g. '+98912XXXXXXX'  (with country code)

# ═══════════════════════════════════════════════════════════════════════
#  SOCKS5 PROXY
#  If your server can reach Telegram directly, set USE_PROXY = False
# ═══════════════════════════════════════════════════════════════════════

USE_PROXY  = True
PROXY_HOST = '127.0.0.1'
PROXY_PORT = 1071
PROXY_USER = '1'
PROXY_PASS = '1'

# ═══════════════════════════════════════════════════════════════════════
#  CHANNELS TO MONITOR
#  Add or remove Telegram channel usernames (without @)
# ═══════════════════════════════════════════════════════════════════════

CHANNELS = [
    'channel_handle_1',
    'channel_handle_2',
    'channel_handle_3',
    # add more...
]

# ═══════════════════════════════════════════════════════════════════════
#  PUSH ENDPOINTS  (news_grabber.py only)
#  URL of your fetch.php + the matching secret key
#  You can add multiple endpoints to push to more than one server
# ═══════════════════════════════════════════════════════════════════════

PUSH_ENDPOINTS = [
    {
        'url':    'https://yoursite.com/news/fetch.php',
        'secret': 'CHANGE_THIS_SECRET_KEY',   # must match SECRET in fetch.php
    },
    # {
    #     'url':    'https://mirror.example.com/news/fetch.php',
    #     'secret': 'ANOTHER_SECRET_KEY',
    # },
]

# ═══════════════════════════════════════════════════════════════════════
#  NEWS GRABBER SETTINGS  (news_grabber.py)
# ═══════════════════════════════════════════════════════════════════════

POLL_INTERVAL   = 20             # seconds between live polls
HOURS_WINDOW    = 1              # ignore messages older than this (hours)
MAX_IMAGE_BYTES = 1 * 1024 * 1024  # skip images larger than this (1 MB)

# ── File paths ─────────────────────────────────────────────────────────
LOG_FILE     = 'grabber.log'
BW_LOG_FILE  = 'bandwidth.log'
LOCK_FILE    = 'grabber.pid'
IMAGE_DIR    = 'images'          # local temp cache for downloaded images

# ═══════════════════════════════════════════════════════════════════════
#  PROFILE GRABBER SETTINGS  (profile_grabber.py)
# ═══════════════════════════════════════════════════════════════════════

PROFILE_OUTPUT_FOLDER = 'profile_channels'
PROFILE_LOG_FILE      = 'profile_grabber.log'
