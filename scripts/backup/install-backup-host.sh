#!/usr/bin/env bash
#
# Installs the nightly backup on a Docker host. Run as root, from a checkout:
#   sudo bash scripts/backup/install-backup-host.sh
#
# It installs the AWS command line tool (a pinned version, its signature
# checked against the AWS CLI Team key shipped beside this script), the
# backup scripts, and the systemd units. It enables nothing: each site gets
# a config file and its own timer afterwards (see backup.conf.example).
# Running it again updates the scripts and units in place.

set -euo pipefail

AWS_CLI_VERSION=2.38.6
# The AWS CLI Team signing key, as published in the AWS CLI install guide.
AWS_CLI_KEY_FPR=FB5DB77FD5C118B80511ADA8A6310ACC4672475C

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LIB=/usr/local/lib/localnodes-backup

[ "$(id -u)" = 0 ] || { echo "run as root" >&2; exit 2; }
[ "$(uname -m)" = x86_64 ] || { echo "this installer knows the x86_64 AWS CLI only" >&2; exit 2; }

need=()
for c in curl unzip gpg jq openssl flock sha256sum docker; do
  command -v "$c" >/dev/null || need+=("$c")
done
if [ "${#need[@]}" -gt 0 ]; then
  echo "missing: ${need[*]}" >&2
  echo "on Debian or Ubuntu: apt-get install -y curl unzip gnupg jq openssl util-linux (docker comes from Docker's own packages)" >&2
  exit 2
fi

# --- the AWS command line tool ---------------------------------------------------
if /usr/local/bin/aws --version 2>/dev/null | grep -q "^aws-cli/$AWS_CLI_VERSION "; then
  echo "aws $AWS_CLI_VERSION already installed"
else
  work="$(mktemp -d)"
  trap 'rm -rf "$work"' EXIT
  url="https://awscli.amazonaws.com/awscli-exe-linux-x86_64-$AWS_CLI_VERSION.zip"
  curl -sSf -o "$work/cli.zip" "$url"
  curl -sSf -o "$work/cli.sig" "$url.sig"
  mkdir -m 700 "$work/gpg"
  gpg --homedir "$work/gpg" --batch --quiet --import "$HERE/aws-cli-team.asc" 2>/dev/null
  # VALIDSIG carries the primary key's fingerprint last. The copy of the key
  # on public keyservers shows an older expiry date than AWS's current one,
  # so the check is the signature and the fingerprint, not the expiry.
  if ! gpg --homedir "$work/gpg" --batch --status-fd 1 --verify "$work/cli.sig" "$work/cli.zip" 2>/dev/null \
      | awk -v f="$AWS_CLI_KEY_FPR" '$2 == "VALIDSIG" && $NF == f {ok=1} END {exit !ok}'; then
    echo "the AWS CLI download does not carry a valid AWS CLI Team signature; nothing installed" >&2
    exit 1
  fi
  gpgconf --homedir "$work/gpg" --kill all >/dev/null 2>&1 || true
  unzip -q "$work/cli.zip" -d "$work"
  "$work/aws/install" --bin-dir /usr/local/bin --install-dir /usr/local/aws-cli --update
  /usr/local/bin/aws --version
fi

# --- the scripts and units ---------------------------------------------------------
install -d -m 755 "$LIB"
install -m 755 "$HERE/nightly-backup.sh" "$LIB/nightly-backup.sh"
install -m 644 "$HERE/backup-lib.sh" "$LIB/backup-lib.sh"
[ -f "$HERE/restore-site.sh" ] && install -m 755 "$HERE/restore-site.sh" "$LIB/restore-site.sh"
ln -sfn "$LIB/nightly-backup.sh" /usr/local/sbin/localnodes-backup
[ -f "$LIB/restore-site.sh" ] && ln -sfn "$LIB/restore-site.sh" /usr/local/sbin/localnodes-restore
install -m 644 "$HERE/systemd/localnodes-backup@.service" /etc/systemd/system/
install -m 644 "$HERE/systemd/localnodes-backup@.timer" /etc/systemd/system/
install -d -m 700 /etc/localnodes-backup /var/lib/localnodes-backup
install -m 600 "$HERE/backup.conf.example" /etc/localnodes-backup/example.conf.sample
systemctl daemon-reload

echo "installed. Next, per site: write /etc/localnodes-backup/<name>.conf (mode 600),"
echo "run 'localnodes-backup /etc/localnodes-backup/<name>.conf' once by hand,"
echo "then: systemctl enable --now localnodes-backup@<name>.timer"
