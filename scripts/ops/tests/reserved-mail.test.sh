#!/usr/bin/env bash
# Regression test: localnodes_platform must never hand mail for reserved test
# domains (RFC 2606 / RFC 6761) to the mail transport.
#
# Runs the module's real hook_mailer_post_render() and its filter class against
# minimal stand-ins for the symfony_mailer and PSR-3 types, so it needs only a
# PHP 8.3 CLI (no Drupal bootstrap, no database).
#
# Usage: bash scripts/ops/tests/reserved-mail.test.sh
#   PHP=...    PHP command to use (default: php). Example for this repo's
#              Docker-only setup:
#              PHP="docker run --rm -i -v $PWD:/repo -w /repo php:8.3.36-cli php"
#   MODULE=... path to the localnodes_platform module (default: the repo's).
set -u
cd "$(dirname "$0")/../../.."
PHP="${PHP:-php}"
MODULE="${MODULE:-html/modules/custom/localnodes_platform}"

# shellcheck disable=SC2086
$PHP -d assert.exception=1 -d zend.assertions=1 -- "$MODULE" <<'PHP'
<?php
// ---- Minimal stand-ins (only what the module touches) ----------------------
namespace Psr\Log {
  interface LoggerInterface {
    public function notice(string|\Stringable $message, array $context = []): void;
  }
}
namespace Drupal\symfony_mailer\Exception {
  class SkipMailException extends \Exception {}
}
namespace Drupal\symfony_mailer {
  interface AddressInterface { public function getEmail(); }
  interface EmailInterface {
    public function getTo(): array;
    public function getCc(): array;
    public function getBcc(): array;
    public function setAddress(string $name, $addresses);
    public function getType();
    public function getSubType();
  }
}
namespace {
  use Drupal\symfony_mailer\AddressInterface;
  use Drupal\symfony_mailer\EmailInterface;
  use Drupal\symfony_mailer\Exception\SkipMailException;
  use Psr\Log\LoggerInterface;

  final class TestAddress implements AddressInterface {
    public function __construct(private string $e) {}
    public function getEmail() { return $this->e; }
  }
  final class TestEmail implements EmailInterface {
    public array $a;
    public array $setCalls = [];
    public function __construct(array $to, array $cc = [], array $bcc = []) {
      $w = fn($l) => array_map(fn($e) => new TestAddress($e), $l);
      $this->a = ['to' => $w($to), 'cc' => $w($cc), 'bcc' => $w($bcc)];
    }
    public function getTo(): array { return $this->a['to']; }
    public function getCc(): array { return $this->a['cc']; }
    public function getBcc(): array { return $this->a['bcc']; }
    public function setAddress(string $name, $addresses, bool $legacy = FALSE) {
      $this->setCalls[] = [$name, $legacy];
      $this->a[$name] = $addresses; return $this;
    }
    public function getType() { return 'activity_send_email'; }
    public function getSubType() { return 'activity_send_email'; }
    public function emails(string $h): array { return array_map(fn($x) => $x->getEmail(), $this->a[$h]); }
  }
  final class TestLogger implements LoggerInterface {
    public array $notices = [];
    public function notice(string|\Stringable $message, array $context = []): void {
      $this->notices[] = strtr((string) $message, array_map('strval', $context));
    }
  }
  // Drupal::logger() stand-in used by the .module hook.
  final class Drupal {
    public static ?TestLogger $logger = NULL;
    public static function logger(string $channel) { return self::$logger; }
  }

  $module = $argv[1];
  $pass = 0; $fail = 0;
  $check = function (string $name, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { $pass++; echo "PASS $name\n"; }
    else { $fail++; echo "FAIL $name" . ($detail !== '' ? " ($detail)" : '') . "\n"; }
  };

  $class = "$module/src/Mail/ReservedRecipientFilter.php";
  $hookfile = "$module/localnodes_platform.module";
  $check('filter class exists', is_file($class), $class);
  $check('module file exists', is_file($hookfile), $hookfile);
  if (!is_file($class) || !is_file($hookfile)) { echo "passed $pass, failed $fail\n"; exit(1); }
  require $class;
  require $hookfile;
  $hook = 'localnodes_platform_mailer_post_render';
  $check('hook_mailer_post_render implemented', function_exists($hook));
  if (!function_exists($hook)) { echo "passed $pass, failed $fail\n"; exit(1); }
  $F = '\Drupal\localnodes_platform\Mail\ReservedRecipientFilter';

  // ---- Domain classification ----------------------------------------------
  $reserved = ['anna@localnodes.example', 'admin@example.com', 'x@example.net',
    'x@example.org', 'x@sub.example.com', 'x@EXAMPLE.COM', 'x@foo.test',
    'x@foo.invalid', 'x@foo.localhost', 'x@localhost', 'x@example',
    'x@foo.example.', ' x@foo.example '];
  foreach ($reserved as $e) $check("reserved: '$e'", $F::isReserved($e) === TRUE);
  $real = ['drupal.tom@gmail.com', 'a@localnodes.xyz', 'a@myexample.com',
    'a@example.com.au', 'a@testing.org', 'a@example.co', 'a@notexample.org',
    'a@invalid.io', 'not-an-address', ''];
  foreach ($real as $e) $check("not reserved: '$e'", $F::isReserved($e) === FALSE);

  // ---- Hook behaviour -------------------------------------------------------
  // 1. Every recipient reserved: the send is skipped and logged at notice.
  Drupal::$logger = new TestLogger();
  $m = new TestEmail(['anna@localnodes.example']);
  $skipped = FALSE;
  try { $hook($m); } catch (SkipMailException $e) { $skipped = TRUE; }
  $check('all-reserved mail is skipped (SkipMailException)', $skipped);
  $check('skip is logged once at notice', count(Drupal::$logger->notices) === 1,
    json_encode(Drupal::$logger->notices));
  $check('notice names the dropped address',
    str_contains(Drupal::$logger->notices[0] ?? '', 'anna@localnodes.example'),
    Drupal::$logger->notices[0] ?? '');

  // 2. Mixed To: real recipient kept, reserved one removed, mail still sent.
  Drupal::$logger = new TestLogger();
  $m = new TestEmail(['drupal.tom@gmail.com', 'bob@localnodes.example'],
    ['c@example.org', 'c@localnodes.xyz'], ['b@foo.test']);
  $threw = FALSE;
  try { $hook($m); } catch (\Throwable $e) { $threw = get_class($e) . ': ' . $e->getMessage(); }
  $check('mixed mail is not skipped', $threw === FALSE, (string) $threw);
  $check('real To kept, reserved To dropped', $m->emails('to') === ['drupal.tom@gmail.com'], json_encode($m->emails('to')));
  $check('reserved Cc dropped', $m->emails('cc') === ['c@localnodes.xyz'], json_encode($m->emails('cc')));
  $check('reserved Bcc dropped', $m->emails('bcc') === [], json_encode($m->emails('bcc')));
  $check('To rewritten via the legacy (late) path', in_array(['to', TRUE], $m->setCalls, TRUE), json_encode($m->setCalls));
  $check('mixed drop logged once at notice', count(Drupal::$logger->notices) === 1, json_encode(Drupal::$logger->notices));

  // 3. Real-only mail: untouched, nothing logged.
  Drupal::$logger = new TestLogger();
  $m = new TestEmail(['drupal.tom@gmail.com'], ['a@localnodes.xyz']);
  $hook($m);
  $check('real-only mail untouched', $m->setCalls === [] && $m->emails('to') === ['drupal.tom@gmail.com'], json_encode($m->setCalls));
  $check('real-only mail logs nothing', Drupal::$logger->notices === []);

  // 4. Reserved only in Cc: Cc cleaned, real To still sent.
  Drupal::$logger = new TestLogger();
  $m = new TestEmail(['drupal.tom@gmail.com'], ['c@example.com']);
  $hook($m);
  $check('reserved Cc-only dropped, To kept', $m->emails('cc') === [] && $m->emails('to') === ['drupal.tom@gmail.com']);

  // 5. Reserved To but a real Cc: still sent, to the Cc only (Codex review on #32).
  Drupal::$logger = new TestLogger();
  $m = new TestEmail(['anna@localnodes.example'], ['drupal.tom@gmail.com']);
  $threw = FALSE;
  try { $hook($m); } catch (\Throwable $e) { $threw = get_class($e); }
  $check('reserved To + real Cc is not skipped', $threw === FALSE, (string) $threw);
  $check('  ...To emptied, real Cc kept', $m->emails('to') === [] && $m->emails('cc') === ['drupal.tom@gmail.com'], json_encode([$m->emails('to'), $m->emails('cc')]));
  $check('  ...drop logged once at notice', count(Drupal::$logger->notices) === 1);

  // 6. Reserved To but a real Bcc: still sent, to the Bcc only.
  Drupal::$logger = new TestLogger();
  $m = new TestEmail(['anna@localnodes.example'], ['c@example.org'], ['drupal.tom@gmail.com']);
  $threw = FALSE;
  try { $hook($m); } catch (\Throwable $e) { $threw = get_class($e); }
  $check('reserved To + reserved Cc + real Bcc is not skipped', $threw === FALSE, (string) $threw);
  $check('  ...To and Cc emptied, real Bcc kept', $m->emails('to') === [] && $m->emails('cc') === [] && $m->emails('bcc') === ['drupal.tom@gmail.com'], json_encode([$m->emails('to'), $m->emails('cc'), $m->emails('bcc')]));

  // 7. Everything reserved across To, Cc and Bcc: skipped.
  Drupal::$logger = new TestLogger();
  $m = new TestEmail(['a@localnodes.example'], ['c@example.org'], ['b@foo.test']);
  $skipped = FALSE;
  try { $hook($m); } catch (SkipMailException $e) { $skipped = TRUE; }
  $check('reserved To + Cc + Bcc is skipped', $skipped);

  echo "passed $pass, failed $fail\n";
  exit($fail ? 1 : 0);
}
PHP
