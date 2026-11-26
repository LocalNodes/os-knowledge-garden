<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_consensus\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Tests\UnitTestCase;

/**
 * A recorded decision can never reach a payment path.
 *
 * Static, and shown to catch a planted fault: the fixture, a bridge listening for
 * the feed's close event and calling a payment-enqueue stub, must be
 * rejected first; then the production services, routes, source, module
 * file and entity fields must pass. Nothing here calls a real payment
 * system.
 *
 * @group localnodes_consensus
 */
final class NoPaymentBridgeTest extends UnitTestCase {

  /**
   * Service ids, classes or service arguments that would reach money.
   */
  protected const SERVICE_PATTERN = '/payment|payout|enqueue|relayer|treasury|governance|wallet|safe_|gas_sponsor|siwe|localnodes_web3|^@?queue/i';

  /**
   * Source tokens that would reach money or another money module.
   */
  protected const SOURCE_TOKENS = [
    'GovernanceProposal',
    'SafeGovernance',
    'safe_governance',
    'safe_smart_accounts',
    'group_treasury',
    'gas_sponsor',
    'localnodes_web3',
    'siwe_login',
    'QueueFactory',
    'QueueInterface',
    "get('queue",
    'enqueue',
    'relayer',
    'calldata',
  ];

  /**
   * Field-name fragments a payment payload would need.
   */
  protected const FIELD_PATTERN = '/recipient|payee|asset|address|calldata|queue|relayer|wallet|token|chain|tx_|transaction/i';

  /**
   * The module root.
   */
  protected function root(): string {
    return dirname(__DIR__, 3);
  }

  /**
   * The planted bridge is rejected; this proves the scan can fail.
   */
  public function testPlantedBridgeIsRejected(): void {
    $fixture = $this->root() . '/tests/fixtures/payment_bridge/localnodes_consensus.services.yml';
    $violations = $this->serviceViolations(Yaml::decode((string) file_get_contents($fixture)));
    $this->assertContains('localnodes_consensus.payment_bridge: id or class', $violations);
    $this->assertContains('localnodes_consensus.payment_bridge: argument @localnodes_web3.payment_enqueue_stub', $violations);
    $this->assertContains('localnodes_consensus.payment_bridge: argument @queue', $violations);
    $this->assertContains('localnodes_consensus.payment_bridge: listens for localnodes_proposal_feed.closed', $violations);
    // The fixture's legitimate service alone passes.
    $this->assertNotContains('localnodes_consensus.decision_rules: id or class', $violations);

    $planted = "<?php\nclass Bridge { public function enqueuePayment() { \\Drupal::service('localnodes_web3.relayer'); } }\n";
    $this->assertNotSame([], $this->sourceViolations(['planted.php' => $planted]));
  }

  /**
   * Production services, routes, source and fields pass.
   */
  public function testProductionHasNoPaymentPath(): void {
    $root = $this->root();
    $services = Yaml::decode((string) file_get_contents("$root/localnodes_consensus.services.yml"));
    $this->assertNotEmpty($services['services']);
    $this->assertSame([], $this->serviceViolations($services));

    $info = Yaml::decode((string) file_get_contents("$root/localnodes_consensus.info.yml"));
    foreach ($info['dependencies'] ?? [] as $dependency) {
      $this->assertDoesNotMatchRegularExpression(self::SERVICE_PATTERN, $dependency, "Dependency $dependency");
    }
    if (is_file("$root/localnodes_consensus.routing.yml")) {
      $routing = (string) file_get_contents("$root/localnodes_consensus.routing.yml");
      $this->assertDoesNotMatchRegularExpression('/payment|payout|enqueue|relayer|treasury|wallet/i', $routing);
    }

    $sources = [];
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/src", \FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
      if ($file->getExtension() === 'php') {
        $sources[substr($file->getPathname(), strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
      }
    }
    $sources['localnodes_consensus.module'] = (string) file_get_contents("$root/localnodes_consensus.module");
    $this->assertArrayHasKey('src/Service/RoundLifecycle.php', $sources);
    $this->assertSame([], $this->sourceViolations($sources));

    foreach (['src/Entity/ConsensusRound.php', 'src/Entity/ConsensusResponse.php'] as $entity) {
      preg_match_all("/\\\$fields\\['([a-z_]+)'\\]/", $sources[$entity], $matches);
      $this->assertNotEmpty($matches[1], $entity);
      foreach ($matches[1] as $field) {
        $this->assertDoesNotMatchRegularExpression(self::FIELD_PATTERN, $field, "$entity field $field");
      }
    }
  }

  /**
   * What in a services file could reach money.
   *
   * @return string[]
   *   One line per finding.
   */
  protected function serviceViolations(array $yaml): array {
    $found = [];
    foreach ($yaml['services'] ?? [] as $id => $service) {
      if (!is_array($service)) {
        continue;
      }
      if (preg_match(self::SERVICE_PATTERN, $id . ' ' . ($service['class'] ?? ''))) {
        $found[] = "$id: id or class";
      }
      foreach ($service['arguments'] ?? [] as $argument) {
        if (is_string($argument) && str_starts_with($argument, '@') && preg_match(self::SERVICE_PATTERN, ltrim($argument, '@?'))) {
          $found[] = "$id: argument $argument";
        }
      }
      foreach ($service['tags'] ?? [] as $tag) {
        $event = (string) ($tag['event'] ?? '');
        if (str_starts_with($event, 'localnodes_proposal_feed.')) {
          $found[] = "$id: listens for $event";
        }
      }
    }
    return $found;
  }

  /**
   * What in PHP source could reach money, or listen for the feed's events.
   *
   * @param array<string, string> $sources
   *   Sources keyed by path.
   *
   * @return string[]
   *   One line per finding.
   */
  protected function sourceViolations(array $sources): array {
    $found = [];
    foreach ($sources as $path => $source) {
      foreach (self::SOURCE_TOKENS as $token) {
        if (stripos($source, $token) !== FALSE) {
          $found[] = "$path: $token";
        }
      }
      if (str_contains($source, 'EventSubscriberInterface') && str_contains($source, 'ProposalFeedEvents')) {
        $found[] = "$path: listens for the feed's events";
      }
    }
    return $found;
  }

}
