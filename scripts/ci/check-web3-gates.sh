#!/usr/bin/env bash
#
# LocalNodes web3 gate: no mocks and no literal chain data in fresh3 custom code
# (Phase 21: D-07, D-14; success criteria 4 and 6).
#
# Usage:
#   bash scripts/ci/check-web3-gates.sh            # scans <repo>/html/modules/custom
#   bash scripts/ci/check-web3-gates.sh <root>     # scans another tree (planted proofs)
#
# Every chain id, RPC URL, contract address, explorer link and network name
# belongs in the network registry (safe_network config entities). In fresh3 the
# only registry files are localnodes_web3's config/install
# safe_smart_accounts.network.*.yml, so those are the allowlist. The module
# repos (safe_smart_accounts, group_treasury) are gitignored here; they gate
# themselves with PHPUnit guards (21-26, 21-27).
#
# Scanned: *.php *.module *.install *.inc *.yml *.js *.twig *.sh under <root>.
# Skipped: any path containing /tests/, /node_modules/, /vendor/ or /libraries/;
#          *.map and *.md files; files named safe_smart_accounts.network.*.yml.
#
# Pattern classes (one pass each; comments are scanned too). These regexes are
# kept identical, by copy, with the module guards' NoMockNoLiteralTest
# (21-26/21-27 say so in their headers): change one, change all three.
#   mock          mock_mode|isMockMode|getMock[A-Z]|calculateMock|\bcrc32\s*\(|
#                 bin2hex(random_bytes(20|32))
#   address       0x + 40 hex not followed by more hex, except exactly the zero
#                 address and Safe's owner-list sentinel 0x...01
#   chain id      distinctive decimal ids (with an optional BigInt n), their hex
#                 forms, eip155:<n>, and small ids next to a chain-id key
#   host          RPC, explorer and Safe service hostnames
#   network name  sepolia, hardhat, mainnet, goerli, holesky (case-insensitive)
#
# Exact-line allowlist: see ALLOW_LINES in the perl below.
#
# Output: one line per hit, "file:line: class: matched text".
# Exit status: 0 clean, 1 at least one violation, 2 usage error.
#
# Prerequisites: bash, find, perl (present on macOS and ubuntu-latest).
#
set -euo pipefail

if [ "$#" -gt 1 ]; then
  echo "usage: $0 [root]" >&2
  exit 2
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

if [ "$#" -eq 1 ]; then
  ROOT="$1"
else
  cd "$REPO_ROOT"
  ROOT="html/modules/custom"
fi

if [ ! -d "$ROOT" ]; then
  echo "check-web3-gates: no such directory: $ROOT" >&2
  exit 2
fi
if ! command -v perl >/dev/null 2>&1; then
  echo "check-web3-gates: perl is required" >&2
  exit 2
fi

set +e
find "$ROOT" -type f \
  \( -name '*.php' -o -name '*.module' -o -name '*.install' -o -name '*.inc' \
     -o -name '*.yml' -o -name '*.js' -o -name '*.twig' -o -name '*.sh' \) \
  -not -path '*/tests/*' -not -path '*/node_modules/*' -not -path '*/vendor/*' \
  -not -path '*/libraries/*' -not -name '*.map' -not -name '*.md' \
  -not -name 'safe_smart_accounts.network.*.yml' -print0 \
| perl -e '
use strict;
use warnings;

my @classes = (
  ["mock", qr/mock_mode|isMockMode|getMock[A-Z]|calculateMock|\bcrc32\s*\(|bin2hex\(\s*random_bytes\(\s*(?:20|32)\s*\)\s*\)/],
  ["address", qr/0x[0-9a-fA-F]{40}(?![0-9a-fA-F])/],
  ["chain id", qr/\b(?:11155111|84532|8453|31337|42161|421614|11155420|80002|10200|17000)n?\b|\b0x(?:aa36a7|14a34|2105|7a69)\b|eip155:\d+|(?:chain[_-]?id|chainId|CHAIN_ID)\W{0,6}(?:1|10|56|100|137|250|324|1101)\b/],
  ["host", qr/api\.pocket\.network|publicnode\.com|sepolia\.base\.org|rpc\.sepolia\.org|etherscan\.io|basescan\.org|blockscout\.com|https?:\/\/safe-transaction-[a-z0-9-]+\.safe\.global|api\.safe\.global/],
  ["network name", qr/\b(?:sepolia|hardhat|mainnet|goerli|holesky)\b/i],
);

# Addresses allowed by exact value (21-RESEARCH Pitfall 8).
my %allow_address = map { $_ => 1 } (
  "0x0000000000000000000000000000000000000000",
  "0x0000000000000000000000000000000000000001",
);

# Exact-line allowlist: file basename => full line content (indentation included).
my %allow_lines = (
  # Phase 19 boundary; revisit after 19 closes. The siwe_login RPC default
  # set by localnodes_web3_install(); siwe_login chain settings are Phase 19s.
  "localnodes_web3.install" => [
    q{    $siwe->set(\x27ethereum_provider_url\x27, \x27https://eth.api.pocket.network\x27)->save(TRUE);},
  ],
);
for my $base (keys %allow_lines) {
  $allow_lines{$base} = [ map { s/\\x27/\x27/gr } @{ $allow_lines{$base} } ];
}

local $/ = "\0";
my @files = sort map { chomp; $_ } <STDIN>;
my $hits = 0;
for my $file (@files) {
  open(my $fh, "<", $file) or do { print STDERR "check-web3-gates: cannot read $file\n"; exit 2; };
  local $/ = "\n";
  (my $base = $file) =~ s{.*/}{};
  my %allowed = map { $_ => 1 } @{ $allow_lines{$base} || [] };
  my $n = 0;
  while (my $line = <$fh>) {
    $n++;
    (my $text = $line) =~ s/\r?\n\z//;
    next if $allowed{$text};
    for my $c (@classes) {
      my ($name, $re) = @$c;
      my $found;
      # A fresh copy per class, so one class leaves no match position behind.
      my $scan = $text;
      while ($scan =~ /$re/g) {
        my $m = $&;
        next if $name eq "address" && $allow_address{$m};
        $found = $m;
        last;
      }
      if (defined $found) {
        print "$file:$n: $name: $found\n";
        $hits++;
      }
    }
  }
  close($fh);
}
exit($hits ? 1 : 0);
'
status=$?
set -e

case "$status" in
  0) echo "check-web3-gates: clean ($ROOT)" >&2 ;;
  1) echo "check-web3-gates: violations found ($ROOT). Move chain data into a safe_smart_accounts.network.*.yml registry entry." >&2 ;;
  *) echo "check-web3-gates: error ($status)" >&2; status=2 ;;
esac
exit "$status"
