#!/bin/bash

# ============================================================
# Nibbly CMS — FTPS Deployment Script
# ============================================================
#
# Deploys your Nibbly site to a remote server via FTPS (FTP over SSL).
# Uses lftp's mirror mode to synchronize files, uploading only what
# has changed and optionally deleting files on the server that no
# longer exist locally.
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
#   3. deploy.sh is already in .gitignore — your credentials
#      will never be committed.
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

echo -e "${YELLOW}=== Deploying Nibbly ===${NC}"
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

echo -e "${CYAN}Uploading files (mirror with delete)...${NC}"
echo ""

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
mirror --reverse --delete -v --no-perms \
  --exclude-glob .git/ \
  --exclude-glob node_modules/ \
  --exclude-glob .gitignore \
  --exclude-glob .gitattributes \
  --exclude-glob .DS_Store \
  --exclude-glob deploy.sh \
  --exclude-glob deploy.example.sh \
  --exclude-glob screenshots/ \
  --exclude-glob reference/ \
  --exclude-glob '*.mjs' \
  --exclude-glob '*.log' \
  --exclude-glob '*.tmp' \
  --exclude-glob '*.swp' \
  --exclude-glob package.json \
  --exclude-glob package-lock.json \
  . .
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
