#!/usr/bin/env bash
# Regression test: the chatbot's citation links must survive drupal/ai's
# HostnameFilter (drupal/ai >= 1.4).
#
# drupal/ai 1.4 strips every Markdown link in an AI answer whose host is not in
# ai.settings:allowed_hosts, keeping only the link text. The default list is
# empty, so on Boulder (8a8fdc8) every "[Title](https://boulder.localnodes.xyz/node/N)"
# source link was removed (dblog: "Removed disallowed Markdown link with URL").
# settings.php must therefore allow the instance's own host.
#
# Loads the real settings.php with a controlled environment and checks the
# resulting $config. Needs only a PHP 8.3 CLI.
#
# Usage: bash scripts/ops/tests/ai-allowed-hosts.test.sh
#   PHP=...       PHP command (default: php). Docker-only example:
#                 PHP="docker run --rm -i -v $PWD:/repo -w /repo php:8.3.36-cli php"
#                 Variables are set with putenv() inside PHP, so this works the
#                 same with a local php or a container.
#   SETTINGS=...  settings.php to test (default: the repo's).
set -u
cd "$(dirname "$0")/../../.."
PHP="${PHP:-php}"
SETTINGS="${SETTINGS:-html/sites/default/settings.php}"

PASS=0; FAIL=0
check() {  # check NAME EXPECTED ACTUAL
  if [ "$2" = "$3" ]; then PASS=$((PASS + 1)); echo "PASS $1"
  else FAIL=$((FAIL + 1)); echo "FAIL $1 (expected $2, got $3)"; fi
}

# run "VAR=value ..." -> prints json_encode of $config['ai.settings']['allowed_hosts'] or "unset"
run() {
  # shellcheck disable=SC2086
  $PHP -- "$SETTINGS" "$@" <<'PHP'
<?php
$settings_file = $argv[1];
foreach (['SERVICE_FQDN_OPENSOCIAL', 'IS_DDEV_PROJECT', 'DDEV_HOSTNAME', 'DRUPAL_REVERSE_PROXY'] as $k) putenv($k);
foreach (array_slice($argv, 2) as $kv) putenv($kv);
$app_root = getcwd() . '/html'; $site_path = 'sites/default';
$settings = []; $config = []; $databases = [];
// settings.ddev.php is DDEV-generated and absent here; settings.php guards it.
require $settings_file;
echo array_key_exists('ai.settings', $config) && array_key_exists('allowed_hosts', $config['ai.settings'])
  ? json_encode($config['ai.settings']['allowed_hosts']) : 'unset';
PHP
}

check "instance host is allowed" '["boulder.localnodes.xyz"]' "$(run SERVICE_FQDN_OPENSOCIAL=boulder.localnodes.xyz)"
check "scheme is stripped" '["cascadia.localnodes.xyz"]' "$(run SERVICE_FQDN_OPENSOCIAL=https://cascadia.localnodes.xyz)"
check "no FQDN: ai.settings left alone" 'unset' "$(run)"
check "DDEV hostnames are allowed in DDEV" '["fresh3.ddev.site","alt.ddev.site"]' "$(run IS_DDEV_PROJECT=true DDEV_HOSTNAME=fresh3.ddev.site,alt.ddev.site)"
check "DDEV_HOSTNAME ignored outside DDEV" 'unset' "$(run DDEV_HOSTNAME=fresh3.ddev.site)"
check "port and path dropped" '["boulder.localnodes.xyz"]' "$(run SERVICE_FQDN_OPENSOCIAL=https://boulder.localnodes.xyz:8443/sub/path)"
check "host lowercased" '["boulder.localnodes.xyz"]' "$(run SERVICE_FQDN_OPENSOCIAL=Boulder.LocalNodes.xyz)"
check "comma-separated FQDNs all allowed" '["a.localnodes.xyz","b.localnodes.xyz"]' "$(run SERVICE_FQDN_OPENSOCIAL=https://a.localnodes.xyz,b.localnodes.xyz)"
check "FQDN plus DDEV, deduplicated" '["x.ddev.site"]' "$(run SERVICE_FQDN_OPENSOCIAL=x.ddev.site IS_DDEV_PROJECT=true DDEV_HOSTNAME=x.ddev.site)"
check "garbage entry ignored" '["ok.localnodes.xyz"]' "$(run 'SERVICE_FQDN_OPENSOCIAL=https://,ok.localnodes.xyz')"

# ---- Round trip through drupal/ai's real HostnameFilter (optional) ---------
# Needs the built image, which carries drupal/ai and Drupal core. Set
#   IMAGE_PHP="docker run --rm -i -v $PWD:/repo -w /repo --entrypoint php ghcr.io/localnodes/os-knowledge-garden:<sha7>"
# settings.php -> Drupal's ConfigFactory ($GLOBALS['config'] overrides) ->
# HostnameFilter::filterText(): the own-host link must survive, others must not.
if [ -n "${IMAGE_PHP:-}" ]; then
  RT="$($IMAGE_PHP -- "$SETTINGS" <<'PHP'
<?php
$root = '/var/www/html';
$loader = require "$root/vendor/autoload.php";
$loader->addPsr4('Drupal\\ai\\', "$root/html/modules/contrib/ai/src");
$loader->addPsr4('Drupal\\Core\\', "$root/html/core/lib/Drupal/Core");
$loader->addPsr4('Drupal\\Component\\', "$root/html/core/lib/Drupal/Component");
new \Drupal\Core\Site\Settings([]);
// Minimal stand-in for typed config (unused on reads), generated from the interface.
$iface = new ReflectionClass(\Drupal\Core\Config\TypedConfigManagerInterface::class);
$methods = [];
foreach ($iface->getMethods() as $m) {
  $params = [];
  foreach ($m->getParameters() as $p) {
    $s = ($p->hasType() ? $p->getType() . ' ' : '') . ($p->isPassedByReference() ? '&' : '') . '$' . $p->getName();
    if ($p->isDefaultValueAvailable()) $s .= ' = ' . var_export($p->getDefaultValue(), TRUE);
    $params[] = $s;
  }
  $rt = $m->hasReturnType() ? ': ' . $m->getReturnType() : '';
  $body = ($m->hasReturnType() && (string) $m->getReturnType() === 'void') ? '' : 'throw new \LogicException("unused");';
  $methods[] = ($m->isStatic() ? 'public static' : 'public') . " function {$m->getName()}(" . implode(', ', $params) . ")$rt { $body }";
}
eval('final class StubTyped implements \Drupal\Core\Config\TypedConfigManagerInterface {' . implode("\n", $methods) . '}');

foreach (['SERVICE_FQDN_OPENSOCIAL', 'IS_DDEV_PROJECT', 'DDEV_HOSTNAME', 'DRUPAL_REVERSE_PROXY'] as $k) putenv($k);
putenv('SERVICE_FQDN_OPENSOCIAL=boulder.localnodes.xyz');
$app_root = "$root/html"; $site_path = 'sites/default';
$settings = []; $config = []; $databases = [];
require $argv[1];
$GLOBALS['config'] = $config;

$dispatcher = new \Symfony\Component\EventDispatcher\EventDispatcher();
$factory = new \Drupal\Core\Config\ConfigFactory(new \Drupal\Core\Config\MemoryStorage(), $dispatcher, new StubTyped());
$loggers = new \Drupal\Core\Logger\LoggerChannelFactory(new \Symfony\Component\HttpFoundation\RequestStack(), new \Drupal\Core\Session\AnonymousUserSession());
$filter = new \Drupal\ai\Service\HostnameFilter($loggers, $factory);
$md = "- [1] [Own](https://boulder.localnodes.xyz/node/17)\n- [2] [Other](https://other-site.org/x)\n";
$out = $filter->filterText($md);
echo (str_contains($out, '[Own](https://boulder.localnodes.xyz/node/17)') ? 'own=kept' : 'own=stripped')
  . ' ' . (str_contains($out, 'other-site.org') ? 'other=kept' : 'other=stripped') . "\n";
PHP
)"
  check "real HostnameFilter round trip" 'own=kept other=stripped' "$(printf '%s' "$RT" | tail -1)"
else
  echo "SKIP real HostnameFilter round trip (set IMAGE_PHP to run it)"
fi

echo "passed $PASS, failed $FAIL"
[ "$FAIL" -eq 0 ]
