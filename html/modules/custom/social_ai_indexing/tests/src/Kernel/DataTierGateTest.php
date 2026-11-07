<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Kernel;

use Drupal\ai\Event\PreGenerateResponseEvent;

/**
 * The G8 data-tier gate: warn-and-provision (AI-SPEC 1b, guardrail G8).
 *
 * Tom's decision, 2026-11-05: a status-report error plus a provisioning
 * check, no runtime block, and sites whose DEMO_MODULE names a demo module
 * (fictional content only) are exempt from both. So:
 * - settings.php derives $settings['localnodes_ai_data_tier'] ('local' on
 *   DDEV; off DDEV 'paid' only when GEMINI_KEY_TIER=paid) and
 *   $settings['localnodes_demo_module'] (DEMO_MODULE, with the entrypoint's
 *   default 'localnodes_demo').
 * - localnodes_platform_requirements('runtime') reports an error naming
 *   GEMINI_KEY_TIER unless the tier is paid or local, or the site is a demo.
 * - Nothing blocks a Gemini request at runtime.
 *
 * @group phase22
 * @group social_ai_indexing
 */
final class DataTierGateTest extends GroupAccessKernelTestBase {

  /**
   * Environment variables settings.php reads for the gate.
   */
  private const ENV = ['IS_DDEV_PROJECT', 'GEMINI_KEY_TIER', 'DEMO_MODULE'];

  /**
   * Environment values before the test, restored in tearDown().
   *
   * @var array<string, string|false>
   */
  private array $savedEnv = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    foreach (self::ENV as $name) {
      $this->savedEnv[$name] = getenv($name);
    }
    require_once $this->root . '/core/includes/install.inc';
    $path = $this->container->get('extension.list.module')->getPath('localnodes_platform');
    require_once $this->root . '/' . $path . '/localnodes_platform.install';
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach ($this->savedEnv as $name => $value) {
      putenv($value === FALSE ? $name : "$name=$value");
    }
    parent::tearDown();
  }

  /**
   * Runs settings.php with the given environment and returns $settings.
   *
   * @param array<string, string|null> $env
   *   Variable => value; NULL unsets it.
   */
  private function settingsFor(array $env): array {
    foreach (self::ENV as $name) {
      $value = $env[$name] ?? NULL;
      putenv($value === NULL ? $name : "$name=$value");
    }
    $file = $this->root . '/sites/default/settings.php';
    return (static function (string $file): array {
      $app_root = '/tmp';
      $site_path = 'sites/default';
      $databases = [];
      $settings = [];
      $config = [];
      include $file;
      return $settings;
    })($file);
  }

  /**
   * Returns the runtime requirement for a tier and demo setting.
   *
   * @param string|null $tier
   *   The localnodes_ai_data_tier setting; NULL leaves it unset.
   * @param string|null $demo
   *   The localnodes_demo_module setting; NULL leaves it unset.
   */
  private function requirementFor(?string $tier, ?string $demo): array {
    $this->assertTrue(function_exists('localnodes_platform_requirements'), 'localnodes_platform implements hook_requirements().');
    $this->setSetting('localnodes_ai_data_tier', $tier);
    $this->setSetting('localnodes_demo_module', $demo);
    $requirements = localnodes_platform_requirements('runtime');
    $this->assertArrayHasKey('localnodes_ai_data_tier', $requirements);
    return $requirements['localnodes_ai_data_tier'];
  }

  /**
   * settings.php: DDEV is 'local'; off DDEV only an explicit 'paid' counts.
   */
  public function testSettingsDeriveTheTier(): void {
    $this->assertSame('local', $this->settingsFor(['IS_DDEV_PROJECT' => 'true'])['localnodes_ai_data_tier'] ?? NULL, 'DDEV is local.');
    $this->assertSame('local', $this->settingsFor(['IS_DDEV_PROJECT' => 'true', 'GEMINI_KEY_TIER' => 'free'])['localnodes_ai_data_tier'] ?? NULL, 'DDEV stays local whatever the key.');
    $this->assertSame('paid', $this->settingsFor(['GEMINI_KEY_TIER' => 'paid'])['localnodes_ai_data_tier'] ?? NULL);
    $this->assertSame('paid', $this->settingsFor(['GEMINI_KEY_TIER' => ' PAID '])['localnodes_ai_data_tier'] ?? NULL, 'Case and spaces are ignored.');
    $this->assertSame('unpaid', $this->settingsFor(['GEMINI_KEY_TIER' => 'free'])['localnodes_ai_data_tier'] ?? NULL);
    $this->assertSame('unpaid', $this->settingsFor(['GEMINI_KEY_TIER' => 'local'])['localnodes_ai_data_tier'] ?? NULL, "Off DDEV, 'local' can't be declared.");
    $this->assertSame('', $this->settingsFor([])['localnodes_ai_data_tier'] ?? NULL, 'Unset off DDEV is empty.');
  }

  /**
   * settings.php: the demo setting reads DEMO_MODULE like the entrypoint.
   */
  public function testSettingsReadDemoModuleLikeTheEntrypoint(): void {
    // docker/entrypoint.sh: DEMO_MODULE="${DEMO_MODULE:-localnodes_demo}".
    $this->assertSame('localnodes_demo', $this->settingsFor([])['localnodes_demo_module'] ?? NULL, 'Unset counts as the default demo.');
    $this->assertSame('localnodes_demo', $this->settingsFor(['DEMO_MODULE' => ''])['localnodes_demo_module'] ?? NULL, 'Empty counts as the default demo.');
    $this->assertSame('boulder_demo', $this->settingsFor(['DEMO_MODULE' => 'boulder_demo'])['localnodes_demo_module'] ?? NULL);
    $this->assertSame('none', $this->settingsFor(['DEMO_MODULE' => 'none'])['localnodes_demo_module'] ?? NULL);
  }

  /**
   * A real (non-demo) site without a paid key gets a status-report error.
   */
  public function testNonDemoSiteWithoutPaidKeyIsAnError(): void {
    foreach (['', 'unpaid', NULL] as $tier) {
      $requirement = $this->requirementFor($tier, 'none');
      $this->assertSame(REQUIREMENT_ERROR, $requirement['severity'], 'Tier ' . var_export($tier, TRUE) . ' on a non-demo site is an error.');
      $this->assertStringContainsString('GEMINI_KEY_TIER', (string) $requirement['description']);
    }
  }

  /**
   * A paid key, or local DDEV, is OK.
   */
  public function testPaidOrLocalIsOk(): void {
    foreach (['paid', 'local'] as $tier) {
      foreach (['none', 'localnodes_demo'] as $demo) {
        $this->assertSame(REQUIREMENT_OK, $this->requirementFor($tier, $demo)['severity'], "Tier $tier with DEMO_MODULE $demo is OK.");
      }
    }
  }

  /**
   * A demo site is exempt: never an error, whatever the key.
   */
  public function testDemoSiteIsExempt(): void {
    foreach (['localnodes_demo', 'boulder_demo', 'portland_demo'] as $demo) {
      foreach (['', 'unpaid'] as $tier) {
        $requirement = $this->requirementFor($tier, $demo);
        $this->assertNotSame(REQUIREMENT_ERROR, $requirement['severity'], "DEMO_MODULE $demo is exempt.");
        $this->assertSame(REQUIREMENT_INFO, $requirement['severity'], 'The exemption is shown, not hidden.');
      }
    }
  }

  /**
   * Without the demo setting at all, nothing is exempt (fail closed).
   */
  public function testMissingDemoSettingIsNotExempt(): void {
    $this->assertSame(REQUIREMENT_ERROR, $this->requirementFor('', NULL)['severity']);
    $this->assertSame(REQUIREMENT_ERROR, $this->requirementFor('', '')['severity']);
  }

  /**
   * The requirement is a runtime check only.
   */
  public function testOnlyTheRuntimePhaseReports(): void {
    $this->assertTrue(function_exists('localnodes_platform_requirements'), 'localnodes_platform implements hook_requirements().');
    $this->setSetting('localnodes_ai_data_tier', '');
    $this->setSetting('localnodes_demo_module', 'none');
    $this->assertSame([], localnodes_platform_requirements('install'));
    $this->assertSame([], localnodes_platform_requirements('update'));
  }

  /**
   * Warn-and-provision: no runtime block, even on an undeclared tier.
   */
  public function testNoRuntimeBlock(): void {
    $this->setSetting('localnodes_ai_data_tier', '');
    $this->setSetting('localnodes_demo_module', 'none');
    foreach (['chat', 'embeddings'] as $operation) {
      $event = new PreGenerateResponseEvent(
        requestThreadId: 'test-thread',
        providerId: 'gemini',
        operationType: $operation,
        configuration: [],
        input: NULL,
        modelId: 'models/gemini-3-flash-preview',
        tags: [$operation, 'ai_agents_group_assistant'],
      );
      // An exception here would be a runtime block on embeddings.
      $this->container->get('event_dispatcher')->dispatch($event, PreGenerateResponseEvent::EVENT_NAME);
      $this->assertNull($event->getForcedOutputObject(), "No short-circuit on $operation.");
    }
  }

}
