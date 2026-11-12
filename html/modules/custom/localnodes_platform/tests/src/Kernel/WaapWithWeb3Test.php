<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\block\Entity\Block;
use Drupal\Core\Render\RenderContext;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Form\UserLoginForm;

/**
 * The web3 dependency brings the WaaP block alongside password sign-in.
 *
 * Full localnodes_web3 installation pulls in the Open Social distribution.
 * Here Drupal's real dependency graph must include the connector, then the
 * module installer installs that sign-in dependency and its parent normally.
 * The web3 hooks are loaded separately, as in SafetyHelpLinksTest. The staging
 * fresh-install check covers installation of the complete distribution.
 *
 * @group localnodes_platform
 * @group localnodes_web3
 */
final class WaapWithWeb3Test extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'field', 'block'];

  /**
   * The declared dependency installs its defaults and reaches the render tree.
   */
  public function testConnectorAndPasswordSignIn(): void {
    $this->installEntitySchema('user');
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['system', 'user']);
    $this->container->get('theme_installer')->install(['stark']);
    $this->config('system.theme')->set('default', 'stark')->save();

    // Give the upstream installer a valid account-menu region to follow.
    Block::create([
      'id' => 'stark_account_menu',
      'plugin' => 'system_menu_block:account',
      'theme' => 'stark',
      'region' => 'content',
    ])->save();

    $modules = $this->container->get('extension.list.module')->getList();
    $this->assertArrayHasKey('siwe_login_waap', $modules['localnodes_web3']->requires, 'The web3 dependency graph must enable WaaP.');
    $this->assertFalse($this->container->get('module_handler')->moduleExists('siwe_login_waap'));
    $this->assertTrue($this->container->get('module_installer')->install(['siwe_login_waap']));
    $this->enableModules(['localnodes_web3']);
    $this->assertTrue($this->container->get('module_handler')->moduleExists('siwe_login_waap'));
    $this->assertTrue($this->container->get('module_handler')->moduleExists('siwe_login'));

    // No distribution override is needed: upstream ships the single button.
    $settings = $this->config('siwe_login_waap.settings');
    $this->assertSame('staging', $settings->get('environment'));
    $this->assertSame('', $settings->get('walletconnect_project_id'));
    $this->assertFalse($settings->get('show_siwe_wallet_button'));

    // The parent install hook placed this block; the test does not place it.
    $block = Block::load('siwe_login');
    $this->assertNotNull($block);
    $this->assertTrue($block->status());
    $this->assertSame('content', $block->getRegion());
    $build = $this->container->get('entity_type.manager')->getViewBuilder('block')->view($block);
    $renderer = $this->container->get('renderer');
    $html = (string) $renderer->renderInIsolation($build);
    $this->assertSame(1, substr_count($html, 'data-siwe-login-waap'));
    $this->assertStringNotContainsString('id="siwe-login-button"', $html, 'No separate legacy wallet button.');
    $this->assertContains('siwe_login_waap/connector', $build['#attached']['library']);
    $this->assertSame(['email', 'social'], $build['#attached']['drupalSettings']['siweLoginWaap']['authenticationMethods']);
    $this->assertSame(['google'], $build['#attached']['drupalSettings']['siweLoginWaap']['allowedSocials']);
    $this->assertNotEmpty($build['#attached']['html_head'], 'The SDK import map reaches the rendered block.');

    // The connector changes the block, not the existing password form.
    $form = $this->container->get('form_builder')->getForm(UserLoginForm::class);
    $this->assertSame('textfield', $form['name']['#type']);
    $this->assertSame('password', $form['pass']['#type']);
    $this->assertSame('submit', $form['actions']['submit']['#type']);
    $password_html = $renderer->executeInRenderContext(new RenderContext(), function () use ($renderer, &$form): string {
      return (string) $renderer->render($form);
    });
    $this->assertStringContainsString('name="pass"', $password_html);
    $this->assertStringContainsString('name="name"', $password_html);
    $this->assertStringContainsString('type="submit"', $password_html);

    // A project ID unlocks the Browser Wallet choice; email/Google need none.
    $settings->set('walletconnect_project_id', 'test-project-id')->save();
    $js = siwe_login_waap_js_settings(\Drupal::config('siwe_login_waap.settings'));
    $this->assertSame(['email', 'social', 'wallet'], $js['authenticationMethods']);
    $this->assertSame('test-project-id', $js['walletConnectProjectId']);
  }

}
