<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Kernel;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\profile\Entity\Profile;
use Drupal\profile\Entity\ProfileType;
use Drupal\Tests\social_ai_indexing\Traits\AgentLoopTrait;
use Drupal\Tests\social_ai_indexing\Traits\IndexFixtureTrait;
use Drupal\Tests\social_ai_indexing\Traits\LeakFixtureTrait;
use Drupal\Tests\social_ai_indexing\Traits\RagToolTrait;
use Drupal\Tests\social_ai_indexing\Traits\StubSearchTrait;

/**
 * An author who hides their real name is not named by it in a later answer.
 *
 * Uses Open Social's own profile privacy code (social_profile and
 * social_profile_privacy), which keeps each author's hidden-field list in a
 * drupal_static keyed by the author. In one long-running process (a queue
 * worker, the answer checker) a list worked out while the name was public
 * would still be used after the author hid it. No static reset between the
 * two questions here, on purpose.
 *
 * Only the profile type, the two name fields, the form display's name group
 * and the privacy settings are made here: the privacy code reads just
 * those. Every name is fictional.
 *
 * @group phase22
 * @group social_ai_indexing
 */
final class CitationAuthorPrivacyChangeTest extends GroupAccessKernelTestBase {

  use AgentLoopTrait;
  use IndexFixtureTrait;
  use LeakFixtureTrait;
  use RagToolTrait;
  use StubSearchTrait;

  /**
   * The author's username, shown when the real name is hidden.
   */
  private const HANDLE = 'fictional_pv_writer';

  /**
   * The author's real last name (a canary).
   */
  private const REAL_LAST = 'Quillbrightmoss';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'social_ai_indexing_test',
    'social_email_broadcast',
    'ai_test',
    'modeler_api',
    'ai_agents',
    'role_delegation',
    'social_user',
    // Open Social's profile names and their privacy settings.
    'profile',
    'field_group',
    'social_profile',
    'social_profile_privacy',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('profile');

    ProfileType::create(['id' => 'profile', 'label' => 'Profile', 'registration' => FALSE])->save();
    foreach (['field_profile_first_name', 'field_profile_last_name'] as $field) {
      FieldStorageConfig::create(['field_name' => $field, 'entity_type' => 'profile', 'type' => 'string'])->save();
      FieldConfig::create(['field_name' => $field, 'entity_type' => 'profile', 'bundle' => 'profile', 'label' => $field])->save();
    }
    EntityFormDisplay::create([
      'targetEntityType' => 'profile',
      'bundle' => 'profile',
      'mode' => 'default',
      'status' => TRUE,
    ])->setThirdPartySetting('field_group', 'group_profile_names_image', [
      'children' => ['field_profile_first_name', 'field_profile_last_name'],
      'label' => 'Names',
      'region' => 'content',
      'parent_name' => '',
      'weight' => 0,
      'format_type' => 'fieldset',
      'format_settings' => [],
    ])->save();
    // Each member chooses whether their name fields are shown (CONFIGURABLE).
    $this->config('social_profile_privacy.settings')->set('fields', [
      'field_profile_first_name' => 1,
      'field_profile_last_name' => 1,
    ])->save();

    $this->setUpAgentLoop();
    $this->setStubMode('ignore');

    $writer = $this->createPersona(self::HANDLE);
    $this->fixtures['G-PUB']->set('uid', $writer->id())->save();
    $profile = \Drupal::entityTypeManager()->getStorage('profile')->loadByUser($writer, 'profile')
      ?? Profile::create(['type' => 'profile', 'uid' => $writer->id()]);
    $profile->set('field_profile_first_name', 'Ottoline');
    $profile->set('field_profile_last_name', self::REAL_LAST);
    $profile->save();
    $this->setNameShown((int) $writer->id(), TRUE);
  }

  /**
   * Hiding the name takes effect for the next asker in the same process.
   */
  public function testHiddenNameIsNotUsedAfterTheAuthorHidesIt(): void {
    $question = 'What do the notices say?';

    // While the name is public, the real name is shown (the real code path
    // gives a real name, so the check below can fail).
    $run = $this->askAssistant($this->personas['P-MEM'], $this->scopeValue('G'), $question, $this->citeEverythingScript($question));
    $this->assertSame([], $run['violations']);
    $this->assertStringContainsString('[Fictional G public notice, by Ottoline ' . self::REAL_LAST . '](', $this->shown($run['answer']), 'Control: the public real name is shown.');

    // The author hides both name fields (another request; nothing in this
    // process is told).
    $this->setNameShown((int) $this->fixtures['G-PUB']->getOwnerId(), FALSE);

    foreach (['P-MEM', 'P-ANON'] as $persona) {
      $run = $this->askAssistant($this->personas[$persona], $this->scopeValue('G'), $question, $this->citeEverythingScript($question));
      $this->assertSame([], $run['violations'], $persona);
      $shown = $this->shown($run['answer']);
      $this->assertStringContainsString('[Fictional G public notice, by ' . self::HANDLE . '](', $shown, $persona . ' sees the username.');
      $this->assertStringNotContainsString(self::REAL_LAST, $shown, $persona);
      foreach ($run['sources'] as $source) {
        $this->assertStringNotContainsString(self::REAL_LAST, $source->author, $persona);
      }
    }
  }

  /**
   * Sets the author's own choice for both name fields (user data).
   */
  private function setNameShown(int $uid, bool $shown): void {
    \Drupal::service('user.data')->set('social_profile_privacy', $uid, 'fields', [
      'field_profile_first_name' => (int) $shown,
      'field_profile_last_name' => (int) $shown,
    ]);
  }

  /**
   * The answer as a reader sees it, with markdown's entity encoding undone.
   */
  private function shown(string $answer): string {
    return html_entity_decode($answer, ENT_QUOTES | ENT_HTML5, 'UTF-8');
  }

}
