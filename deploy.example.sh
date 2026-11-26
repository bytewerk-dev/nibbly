#!/bin/bash
# Copy to deploy.sh or supply these values in the environment. REMOTE_DIR is an
# existing webroot on the FTP server. See AI-AGENT-GUIDE.md, "Publication and recovery".
set -euo pipefail
SERVER=${NIBBLY_FTP_SERVER:-YOUR_SERVER}
FTP_USER=${NIBBLY_FTP_USER:-YOUR_USER}
export LFTP_PASSWORD=${NIBBLY_FTP_PASSWORD:-YOUR_PASSWORD}
REMOTE_DIR=${NIBBLY_FTP_DIR:-/httpdocs/}
mode=${1:---preview}
seed=${2:-}
if [[ $# -gt 2 || ( $mode != --preview && $mode != --apply ) || ( -n $seed && $seed != --seed ) ]]; then
    echo "Usage: bash deploy.sh [--preview | --apply] [--seed]" >&2
    exit 2
fi

root=$(cd "$(dirname "$0")" && pwd -P)
stage=$(mktemp -d)
trap 'rm -rf -- "$stage"' EXIT
php "$root/cli/package.php" build "$stage/package"
cd "$stage/package"
if [[ $SERVER == YOUR_SERVER || $FTP_USER == YOUR_USER || $LFTP_PASSWORD == YOUR_PASSWORD ]]; then
    echo "Set the FTP server, username and password before running the remote preview or upload." >&2
    exit 2
fi

server_pattern='^([A-Za-z0-9.-]+|\[[0-9A-Fa-f:]+\])(:[0-9]+)?$'
if [[ ! $SERVER =~ $server_pattern || $REMOTE_DIR != /* ]]; then
    echo "Use an FTP hostname (with optional port) and an absolute remote directory." >&2
    exit 2
fi
# Encode connection values before placing them in the lftp command language.
url=$(php -r 'echo "ftp://", rawurlencode($argv[1]), "@", $argv[2], implode("/", array_map("rawurlencode", explode("/", $argv[3])));' -- "$FTP_USER" "$SERVER" "$REMOTE_DIR")
connect() {
    lftp --norc -f /dev/stdin
}
# Inspect the target before upload. Legacy files are only reported. Passwords
# are passed via the environment and TLS verification uses the host trust store.
settings='set cmd:fail-exit yes
set ftp:ssl-force yes
set ftp:ssl-protect-data yes
set ssl:verify-certificate yes
set ftp:list-options -a
set net:max-retries 2
set net:timeout 20'
if [[ $mode == --preview ]]; then
    echo "Remote local-only and unclassified paths (review existing files separately):"
    connect <<LFTP | php "$root/cli/package.php" remote
$settings
open --env-password $url
find .
bye
LFTP
    option=--dry-run
else
    option=
fi

# Only the managed tree may replace existing files. Seed files supplement an
# installation, including all four media libraries. No remote deletions or pulls.
seed_command=
if [[ $seed == --seed ]]; then
    seed_command="mirror --reverse --only-missing --no-perms --verbose $option seed/ ."
fi
connect <<LFTP
$settings
open --env-password $url
mirror --reverse --no-perms --verbose $option webroot/ .
$seed_command
bye
LFTP
