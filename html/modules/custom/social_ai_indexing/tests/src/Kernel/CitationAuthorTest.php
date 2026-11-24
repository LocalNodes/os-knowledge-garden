<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Kernel;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\social_ai_indexing\Traits\AgentLoopTrait;
use Drupal\Tests\social_ai_indexing\Traits\IndexFixtureTrait;
use Drupal\Tests\social_ai_indexing\Traits\LeakFixtureTrait;
use Drupal\Tests\social_ai_indexing\Traits\RagToolTrait;
use Drupal\Tests\social_ai_indexing\Traits\StubSearchTrait;

/**
 * The assistant's Sources line names each page's author (Gate A row 6).
 *
 * Runs the group assistant end to end with a scripted model and the stub
 * search backend, so no model or AI key is needed. The author is read from
 * the page when the answer is built, as the person asking.
 *
 * Every name here is fictional.
 *
 * @group phase22
 * @group social_ai_indexing
 */
final class CitationAuthorTest extends GroupAccessKernelTestBase {

  use AgentLoopTrait;
  use IndexFixtureTrait;
  use LeakFixtureTrait;
  use RagToolTrait;
  use StubSearchTrait;

  /**
   * Author of the public page in group G.
   */
  private const WRITER = 'fictional_ana_writer';

  /**
   * Author of the reply on that page.
   */
  private const REPLIER = 'fictional_reply_writer';

  /**
   * Author of the members-only pages in groups H and S (a canary).
   */
  private const HIDDEN = 'fictional_hidden_kq4';

  /**
   * A blocked author.
   */
  private const BLOCKED = 'fictional_blocked_zx8';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'social_ai_indexing_test',
    'social_email_broadcast',
    'ai_test',
    'modeler_api',
    'ai_agents',
    // Open Social's display-name memo (social_user_user_format_name_alter)
    // and the service its actions need.
    'role_delegation',
    'social_user',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setUpAgentLoop();
    // The stub returns every page whatever the filter says, so only the
    // tool's own access check stands between a hidden page and the answer.
    $this->setStubMode('ignore');

    $this->setOwner('G-PUB', $this->createPersona(self::WRITER));
    $this->setOwner('G-PUB-COMMENT', $this->createPersona(self::REPLIER));
    $hidden = $this->createPersona(self::HIDDEN);
    $this->setOwner('H-MO', $hidden);
    $this->setOwner('S-MO', $hidden);
    $blocked = $this->createPersona(self::BLOCKED);
    $blocked->block()->save();
    $this->setOwner('G-COM', $blocked);
    // A removed account's pages go to the anonymous user (uid 0).
    $this->fixtures['H-PUB']->setOwnerId(0)->save();
  }

  /**
   * A member's page is cited as "<title>, by <author>", still linked.
   */
  public function testSourceLineNamesTheAuthor(): void {
    $run = $this->askAssistant($this->personas['P-MEM'], $this->scopeValue('G'), 'What do the notices say?', $this->citeEverythingScript('What do the notices say?'));

    $this->assertTrue($run['rewritten']);
    $this->assertSame([], $run['violations']);
    $url = $this->fixtures['G-PUB']->toUrl()->toString();
    $this->assertMatchesRegularExpression('#^- \[\d+\] \[Fictional G public notice, by ' . self::WRITER . '\]\(' . preg_quote($url, '#') . '\)$#m', $this->shown($run['answer']));
    // A reply names the person who wrote the reply.
    $this->assertStringContainsString('[Comment on Fictional G public notice, by ' . self::REPLIER . '](', $this->shown($run['answer']));
  }

  /**
   * No name for a blocked or removed author; the page is still cited.
   */
  public function testBlockedOrRemovedAuthorIsNotNamed(): void {
    $run = $this->askAssistant($this->personas['P-MEM'], $this->scopeValue('G'), 'What do the notices say?', $this->citeEverythingScript('What do the notices say?'));

    $this->assertSame([], $run['violations']);
    $this->assertStringContainsString('[Fictional G community notice](', $this->shown($run['answer']));
    $this->assertStringContainsString('[Fictional H public notice](', $this->shown($run['answer']));
    $this->assertStringNotContainsString(self::BLOCKED, $this->shown($run['answer']));
    $this->assertStringNotContainsString('Anonymous', $this->shown($run['answer']));
  }

  /**
   * Someone who can't see a page never learns its author's name.
   *
   * P-MEM is not in group H or the secret group S, so H-MO and S-MO are
   * hidden from them, in group G's assistant and site-wide. Their author's
   * name must not reach the answer, the model or the source list.
   */
  public function testHiddenPageAuthorNeverReachesTheAsker(): void {
    foreach ([$this->scopeValue('G'), NULL] as $scope) {
      foreach ([$this->personas['P-MEM'], $this->personas['P-ANON']] as $asker) {
        drupal_static_reset();
        $run = $this->askAssistant($asker, $scope, 'What are the members notes?', $this->citeEverythingScript('What are the members notes?'));
        $where = $this->describe($asker, $scope);

        $this->assertSame([], $run['violations'], $where);
        $this->assertStringNotContainsString(self::HIDDEN, $this->shown($run['answer']), $where);
        foreach ($run['inputs'] as $input) {
          $this->assertStringNotContainsString(self::HIDDEN, $input, $where);
        }
        foreach ($run['sources'] as $source) {
          $this->assertNotContains($source->key(), ['node:' . $this->fixtures['H-MO']->id(), 'node:' . $this->fixtures['S-MO']->id()], $where);
          $this->assertStringNotContainsString(self::HIDDEN, $source->author, $where);
        }
      }
    }

    // Control: a member of H sees the page and its author inside H.
    drupal_static_reset();
    $run = $this->askAssistant($this->personas['P-MEMH'], $this->scopeValue('H'), 'What are the members notes?', $this->citeEverythingScript('What are the members notes?'));
    $this->assertSame([], $run['violations']);
    $this->assertStringContainsString('[Fictional H members notes, by ' . self::HIDDEN . '](', $this->shown($run['answer']));
  }

  /**
   * A name shown only to some viewers is never reused for another asker.
   *
   * Open Social's social_user_user_format_name_alter() keeps each account's
   * display name in a drupal_static keyed by the account alone, so in one
   * long-running process (a queue worker, the verification runner) the name
   * worked out for one asker would be served to the next. No static reset
   * between askers here, on purpose.
   */
  public function testPrivateNameIsNotReusedForAnotherAsker(): void {
    $writer = $this->fixtures['G-PUB']->getOwner();
    \Drupal::state()->set('social_ai_indexing_test.private_name', [(int) $writer->id() => 'Zelda Privaterealname']);
    \Drupal::state()->set('social_ai_indexing_test.private_name_viewers', [(int) $this->personas['P-MEMH']->id()]);
    drupal_static_reset();

    $allowed = $this->askAssistant($this->personas['P-MEMH'], $this->scopeValue('G'), 'What do the notices say?', $this->citeEverythingScript('What do the notices say?'));
    $this->assertStringContainsString('[Fictional G public notice, by Zelda Privaterealname](', $this->shown($allowed['answer']), 'The allowed viewer sees the private name.');

    foreach (['P-MEM', 'P-ANON'] as $persona) {
      $run = $this->askAssistant($this->personas[$persona], $this->scopeValue('G'), 'What do the notices say?', $this->citeEverythingScript('What do the notices say?'));
      $this->assertSame([], $run['violations'], $persona);
      $this->assertStringContainsString('[Fictional G public notice, by ' . self::WRITER . '](', $this->shown($run['answer']), $persona . ' sees the public handle.');
      $this->assertStringNotContainsString('Privaterealname', $this->shown($run['answer']), $persona);
      foreach ($run['sources'] as $source) {
        $this->assertStringNotContainsString('Privaterealname', $source->author, $persona);
      }
    }
  }

  /**
   * Sets a fixture's owner.
   */
  private function setOwner(string $fixture, AccountInterface $account): void {
    $entity = $this->fixtures[$fixture];
    assert($entity instanceof ContentEntityInterface);
    $entity->set('uid', $account->id())->save();
  }

  /**
   * The answer as a reader sees it, with markdown's entity encoding undone.
   *
   * The Sources link text is encoded for markdown ("_" becomes "&#95;"), so
   * the raw answer never holds a name like "fictional_hidden_kq4" even when
   * it shows one. Checking the raw text would let a shown name pass a "not
   * shown" check; every check here reads the decoded text instead.
   */
  private function shown(string $answer): string {
    return html_entity_decode($answer, ENT_QUOTES | ENT_HTML5, 'UTF-8');
  }

  /**
   * A label for assertion messages.
   */
  private function describe(AccountInterface $asker, ?string $scope): string {
    return ($asker->isAnonymous() ? 'signed out' : $asker->getAccountName()) . ($scope === NULL ? ', site-wide' : ', in group ' . $scope);
  }

}
