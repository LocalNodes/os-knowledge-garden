<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_web3\Unit;

use Drupal\Component\Serialization\Yaml;
use PHPUnit\Framework\TestCase;

/**
 * What WEB3_ENABLED=true turns on, through localnodes_web3's dependencies.
 *
 * The entrypoint enables localnodes_web3 alone when WEB3_ENABLED=true, so
 * everything a web3 site needs comes in through its .info.yml. The Votes tab
 * lives in safe_governance's submodule safe_governance_group, so
 * localnodes_web3 depends on it too. The WaaP connector is also a dependency,
 * so a fresh web3 site offers its Sign in button without a manual enable step.
 * Export confirmation also requires the platform parent's owner-access service.
 *
 * Full dependency installation pulls in Open Social. This pins the complete
 * declaration; WaapWithWeb3Test checks the sign-in dependency and rendering.
 *
 * @group localnodes_web3
 */
final class ModuleDependenciesTest extends TestCase {

  /**
   * The modules a web3 site needs, as project:module.
   */
  private const EXPECTED = [
    'localnodes_platform:localnodes_platform',
    'siwe_login:siwe_login',
    'siwe_login:siwe_login_waap',
    'safe_smart_accounts:safe_smart_accounts',
    'safe_governance:safe_governance',
    'safe_governance:safe_governance_group',
    'gas_sponsor:gas_sponsor',
    'group_treasury:group_treasury',
    'group_treasury:social_group_treasury',
  ];

  /**
   * WEB3_ENABLED alone gives a site the Votes tab.
   */
  public function testVotesTabComesWithWeb3(): void {
    $this->assertContains('safe_governance:safe_governance_group', $this->dependencies(), 'localnodes_web3 must enable safe_governance_group, the Votes tab (25-27 Q3).');
  }

  /**
   * WEB3_ENABLED also brings in the email/Google sign-in connector.
   */
  public function testWaapComesWithWeb3(): void {
    $this->assertContains('siwe_login:siwe_login_waap', $this->dependencies());
  }

  /**
   * The platform parent and every web3 module are declared, each once.
   */
  public function testDependenciesAreExactlyTheWeb3Stack(): void {
    $dependencies = $this->dependencies();
    $this->assertSame(array_unique($dependencies), $dependencies, 'No dependency is listed twice.');
    $this->assertEqualsCanonicalizing(self::EXPECTED, $dependencies);
  }

  /**
   * The dependencies localnodes_web3.info.yml declares.
   *
   * @return string[]
   *   The project:module entries.
   */
  private function dependencies(): array {
    $info = Yaml::decode((string) file_get_contents(dirname(__DIR__, 3) . '/localnodes_web3.info.yml'));
    $this->assertIsArray($info);
    $this->assertIsArray($info['dependencies'] ?? NULL);
    return array_values($info['dependencies']);
  }

}
