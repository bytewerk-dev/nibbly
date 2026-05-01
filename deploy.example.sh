#!/bin/bash

# ============================================================
# Nibbly CMS — FTPS Deployment Script (three-phase sync)
# ============================================================
#
# Synchronises a Nibbly site between your local working copy and a
# remote FTPS server using lftp. Designed to preserve data the admin
# writes server-side (inline-editor changes, image uploads, contact
# form submissions) while still treating local code as the source of
# truth for templates, JS, and CSS.
#
# WHY THREE PHASES?
#   Nibbly is a flat-file CMS. Pages live as JSON in content/, images
#   under assets/images/. Both are written by admins on the LIVE
#   server. A naive `mirror --reverse --delete` would overwrite those
#   edits with whatever older copies sit in the local repo, and would
#   delete server-uploaded images that aren't in the local checkout.
#   This script avoids that by:
#     Phase 1 — PULL newer admin-written files from the server first.
#     Phase 2 — PUSH local code (excluding admin-written paths) with
#               --delete so removed files actually disappear.
#     Phase 3 — PUSH local admin-written files only if newer (no
#               --delete), so a deliberate local edit can still ship
#               but no server file is silently dropped.
#
# PREREQUISITES:
#   - lftp must be installed:
#       macOS:  brew install lftp
#       Linux:  sudo apt-get install lftp
#
# SETUP:
#   1. Copy this file to deploy.sh:
#        cp deploy.example.sh deploy.sh
#   2. Edit deploy.sh and fill in your FTP credentials below.
#   3. deploy.sh is already in .gitignore — your credentials will
#      never be committed.
#   4. Run:  bash deploy.sh
#
# IMPORTANT — lftp script file approach:
#   This script writes lftp commands to a temporary file and runs
#   them with `lftp -f`. This is intentional. Passing lftp commands
#   via heredoc (<< EOF) or inline (-e "...") causes backslash line
#   continuations and long option flags (like --verbose, --newer,
#   --no-perms) to be misinterpreted — flags get treated as file
#   paths, resulting in "No such file or directory" errors and
#   silent misbehavior (e.g. --newer being ignored). The temp file
#   approach avoids all shell quoting/escaping issues.
#
# ============================================================

set -o pipefail

# --- Server configuration (edit these) -----------------------
SERVER="YOUR_SERVER"          # e.g. example.com or ftp.example.com
USER="YOUR_USER"              # FTP username
PASS="YOUR_PASSWORD"          # FTP password
REMOTE_DIR="/httpdocs/"       # Remote directory (document root)
# -------------------------------------------------------------

# Local directory (directory where this script lives)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

echo -e "${YELLOW}=== Deploying Nibbly (three-phase sync) ===${NC}"
echo "Local:  $SCRIPT_DIR"
echo "Remote: $SERVER:$REMOTE_DIR"
echo ""

# Check if lftp is installed
if ! command -v lftp &> /dev/null; then
    echo -e "${RED}lftp is not installed.${NC}"
    echo "Install it first:"
    echo "  macOS: brew install lftp"
    echo "  Linux: sudo apt-get install lftp"
    exit 1
fi

cd "$SCRIPT_DIR" || exit 1

# OS-junk and editor-junk excludes — applied in EVERY mirror call.
# (lftp's mirror:exclude-regex setting is unreliable in 4.9.x; keeping
# these as per-call --exclude-glob is the only robust approach.)
OS_EXCLUDES="\
  --exclude-glob .DS_Store \
  --exclude-glob ._* \
  --exclude-glob Thumbs.db \
  --exclude-glob *.tmp \
  --exclude-glob *.swp"

# Code excludes — never push or pull these.
CODE_EXCLUDES="\
  --exclude-glob .git/ \
  --exclude-glob node_modules/ \
  --exclude-glob .gitignore \
  --exclude-glob .gitattributes \
  --exclude-glob deploy.sh \
  --exclude-glob deploy.example.sh \
  --exclude-glob screenshots/ \
  --exclude-glob reference/ \
  --exclude-glob *.mjs \
  --exclude-glob *.log \
  --exclude-glob package.json \
  --exclude-glob package-lock.json"

# Paths the admin writes server-side. Excluded from the code-push
# phase, handled separately in the content-push phase with --only-newer.
ADMIN_WRITTEN_EXCLUDES="\
  --exclude-glob content/ \
  --exclude-glob assets/images/ \
  --exclude-glob backups/"

# Write lftp commands to a temp file (see IMPORTANT note above)
LFTP_SCRIPT=$(mktemp)
cat > "$LFTP_SCRIPT" << LFTP
set ftp:ssl-allow yes
set ftp:ssl-force yes
set ftp:ssl-protect-data yes
set ftp:passive-mode yes
set ssl:verify-certificate no
set mirror:use-pget-n 5
set net:max-retries 2
set net:timeout 20
open -u $USER,"$PASS" $SERVER
mkdir -p $REMOTE_DIR
cd $REMOTE_DIR

# Phase 1: PULL — newer server-side admin writes back to local.
# No --delete — we never let the server tell us to delete local files.
echo ""
echo "[Phase 1/3] Pull newer admin-written files from server..."
mirror --only-newer -v --no-perms \
  $OS_EXCLUDES \
  content/ $SCRIPT_DIR/content/
mirror --only-newer -v --no-perms \
  $OS_EXCLUDES \
  assets/images/ $SCRIPT_DIR/assets/images/

# Phase 2: PUSH — local code is the source of truth. Use --delete so
# removed templates/CSS/JS actually disappear from the server.
# Excludes admin-written paths — those are handled in Phase 3.
echo ""
echo "[Phase 2/3] Push code (templates, CSS, JS, includes/) with --delete..."
mirror --reverse --delete -v --no-perms \
  $OS_EXCLUDES \
  $CODE_EXCLUDES \
  $ADMIN_WRITTEN_EXCLUDES \
  . .

# Phase 3: PUSH — admin-written paths, only if local copy is newer.
# No --delete — never silently drop a server-side file just because
# it doesn't exist locally (e.g. an admin-uploaded image).
echo ""
echo "[Phase 3/3] Push admin-written paths only if local is newer..."
mirror --reverse --only-newer -v --no-perms \
  $OS_EXCLUDES \
  $SCRIPT_DIR/content/ content/
mirror --reverse --only-newer -v --no-perms \
  $OS_EXCLUDES \
  $SCRIPT_DIR/assets/images/ assets/images/

bye
LFTP

lftp -f "$LFTP_SCRIPT"
RESULT=$?
rm -f "$LFTP_SCRIPT"

if [ $RESULT -eq 0 ]; then
    echo ""
    echo -e "${GREEN}=== Deploy complete ===${NC}"
else
    echo ""
    echo -e "${RED}=== Deploy failed ===${NC}"
    exit 1
fi
