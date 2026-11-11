<?php

declare(strict_types=1);

// Run the actual requirement hook without Drupal, a database or a provider call.
// Usage: php scripts/ops/tests/ai-data-tier.test.php
namespace Drupal\Core\Site {
  final class Settings {
    public static array $values = [];

    public static function get(string $name, mixed $default = NULL): mixed {
      return self::$values[$name] ?? $default;
    }
  }
}

namespace {
  const REQUIREMENT_INFO = -1;
  const REQUIREMENT_OK = 0;
  const REQUIREMENT_ERROR = 2;

  function t(string $text, array $arguments = []): string {
    return strtr($text, $arguments);
  }

  final class Drupal {
    public static function state(): object {
      return new class {
        public function get(string $name, mixed $default = NULL): mixed {
          return $default;
        }
      };
    }
  }

  require __DIR__ . '/../../../html/modules/custom/localnodes_platform/localnodes_platform.install';
  $original = [];
  foreach (['GEMINI_API_KEY', 'OPENROUTER_API_KEY'] as $name) {
    $original[$name] = getenv($name);
  }
  $passed = 0;
  $failed = 0;
  $check = static function (string $name, bool $condition) use (&$passed, &$failed): void {
    if ($condition) {
      $passed++;
      echo "PASS $name\n";
    }
    else {
      $failed++;
      echo "FAIL $name\n";
    }
  };
  $requirement = static function (?string $tier, ?string $demo = 'none'): array {
    \Drupal\Core\Site\Settings::$values = ['localnodes_ai_data_tier' => $tier, 'localnodes_demo_module' => $demo];
    return localnodes_platform_requirements('runtime')['localnodes_ai_data_tier'];
  };

  try {
    putenv('OPENROUTER_API_KEY');
    foreach ([FALSE, ''] as $key) {
      putenv($key === FALSE ? 'GEMINI_API_KEY' : "GEMINI_API_KEY=$key");
      foreach (['', 'unpaid', 'paid', 'local'] as $tier) {
        $row = $requirement($tier);
        $check('no key is informational, tier=' . $tier, $row['severity'] === REQUIREMENT_INFO && $row['value'] === 'The assistant is not set up');
      }
    }
    putenv('OPENROUTER_API_KEY=fixture-openrouter-key');
    $row = $requirement('');
    $check('OpenRouter key does not imply the assistant is missing', $row['severity'] === REQUIREMENT_INFO && $row['value'] === 'No Gemini key set');

    putenv('OPENROUTER_API_KEY');
    putenv('GEMINI_API_KEY=fixture-gemini-key');
    $originalError = "This site sends members' posts and documents to Gemini, but GEMINI_KEY_TIER isn't set to 'paid'. Google may use content sent with an unpaid key and have people review it, and its terms allow only paid keys for users in the EEA, Switzerland and the UK. Use a key from a Google Cloud project with billing turned on, then set GEMINI_KEY_TIER=paid for this instance and redeploy.";
    foreach (['', 'unpaid', NULL] as $tier) {
      $row = $requirement($tier);
      $check('key without paid declaration keeps the error', $row['severity'] === REQUIREMENT_ERROR && $row['value'] === 'No paid key declared' && $row['description'] === $originalError);
    }
    foreach (['paid', 'local'] as $tier) {
      $check('declared tier remains OK: ' . $tier, $requirement($tier)['severity'] === REQUIREMENT_OK);
    }
    $check('demo exemption remains informational with a key', $requirement('', 'localnodes_demo')['severity'] === REQUIREMENT_INFO);
    $check('install phase has no runtime requirement', localnodes_platform_requirements('install') === []);
  }
  finally {
    foreach ($original as $name => $value) {
      putenv($value === FALSE ? $name : "$name=$value");
    }
  }
  echo "passed: $passed  failed: $failed\n";
  exit($failed === 0 ? 0 : 1);
}
