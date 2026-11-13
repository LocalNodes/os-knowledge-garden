<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_web3\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * The distribution puts in its public-record notice.
 *
 * group_treasury ships group_treasury.public_notice empty, which shows no
 * notice and asks for no tick. localnodes_web3_install() sets the LocalNodes
 * wording at install. As in GovernancePlatformNameTest, the .install file is
 * loaded and its helper is called directly.
 *
 * @group localnodes_web3
 */
final class PublicNoticeDefaultTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'options',
    'text',
    'filter',
    'siwe_login',
    'safe_smart_accounts',
    'entity',
    'flexible_permissions',
    'group',
    'group_treasury',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['group_treasury']);
    require_once dirname(__DIR__, 3) . '/localnodes_web3.install';
  }

  /**
   * The empty notice group_treasury ships gets the LocalNodes wording.
   */
  public function testEmptyNoticeGetsTheLocalNodesWording(): void {
    $notice = $this->config('group_treasury.public_notice');
    $this->assertFalse($notice->isNew(), 'group_treasury ships the setting.');
    $this->assertSame('', $notice->get('text'), 'Empty as group_treasury ships it.');

    _localnodes_web3_set_public_notice();
    _localnodes_web3_set_public_notice();

    // Read what was saved, not the config factory's cached copy.
    $stored = $this->container->get('config.storage')->read('group_treasury.public_notice');
    $this->assertSame(LOCALNODES_WEB3_PUBLIC_NOTICE, array_intersect_key($stored, LOCALNODES_WEB3_PUBLIC_NOTICE));
    $this->assertStringContainsString('cannot be erased', $stored['text']);
    $this->assertSame(40, str_word_count(str_replace("'", '', $stored['text'])), 'The short notice has 40 words.');
    $this->assertSame('I have read what becomes public and what cannot be erased.', $stored['label']);
  }

  /**
   * A notice the site already wrote, even in part, is kept.
   */
  public function testSitesOwnNoticeIsKept(): void {
    $this->config('group_treasury.public_notice')->set('label', 'I understand.')->save();

    _localnodes_web3_set_public_notice();

    $stored = $this->container->get('config.storage')->read('group_treasury.public_notice');
    $this->assertSame('', $stored['text']);
    $this->assertSame('', $stored['details']);
    $this->assertSame('I understand.', $stored['label']);
  }

  /**
   * Without the setting (a group_treasury before the notice) nothing is made.
   */
  public function testNothingWrittenWithoutTheSetting(): void {
    $this->config('group_treasury.public_notice')->delete();

    _localnodes_web3_set_public_notice();

    $this->assertFalse($this->container->get('config.storage')->exists('group_treasury.public_notice'), 'No notice setting was created.');
  }

}
