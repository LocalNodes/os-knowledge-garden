<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_consensus\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Form\FormState;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\localnodes_consensus\Traits\ConsensusPersonasTrait;
use Drupal\user\UserInterface;

/**
 * The group choice on a content form checks the visitor's access to the group.
 *
 * A group the person may not see is answered exactly as a missing number. A
 * group the person may see is answered as before: a member choosing their
 * members-only group, and anyone choosing an ordinary open group.
 *
 * @group localnodes_consensus
 * @group phase22_functional
 */
final class GroupChoiceChecksAccessTest extends BrowserTestBase {

  use ConsensusPersonasTrait;

  /**
   * {@inheritdoc}
   */
  protected $profile = 'social';

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'socialblue';

  /**
   * {@inheritdoc}
   */
  protected $strictConfigSchema = FALSE;

  /**
   * A number no group has.
   */
  private const MISSING = 99999;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->setUpConsensusSite();
  }

  /**
   * Opens a content form, chooses a group on it, and returns the answer.
   *
   * @return list<array<string, mixed>>
   *   The answer's commands, without the ones that differ on every request.
   */
  private function chooseGroup(string $type, int|string $group_id): array {
    $this->drupalGet("/node/add/$type");
    $this->assertSession()->statusCodeEquals(200);
    $page = $this->getSession()->getPage();
    $form = $page->find('css', "form#node-$type-form");
    $this->assertNotNull($form, "the $type form");
    $select = $form->find('css', 'select[data-drupal-selector="edit-groups"]');
    $this->assertNotNull($select, "the group choice on the $type form");
    $fields = [
      $select->getAttribute('name') => (string) $group_id,
      '_triggering_element_name' => 'groups',
    ];
    foreach (['form_build_id', 'form_token', 'form_id'] as $name) {
      $fields[$name] = (string) $form->find('css', "input[name=\"$name\"]")->getValue();
    }
    $action = $this->getAbsoluteUrl((string) $form->getAttribute('action'));
    $url = $action . (str_contains($action, '?') ? '&' : '?') . 'ajax_form=1&_wrapper_format=drupal_ajax';
    $client = $this->getSession()->getDriver()->getClient();
    $client->request('POST', $url, $fields, [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    $this->assertSame(200, $client->getInternalResponse()->getStatusCode(), "the answer to choosing $group_id on the $type form");
    $commands = Json::decode($client->getInternalResponse()->getContent());
    $this->assertIsArray($commands);
    return array_values(array_filter($commands, static fn (array $command): bool => !in_array($command['command'] ?? '', ['update_build_id', 'settings'], TRUE)));
  }

  /**
   * The visibility choices an answer turns on and off.
   *
   * @return array<string, string>
   *   Visibility => 'on' or 'off', for each one the answer changes.
   */
  private function choices(array $commands): array {
    $choices = [];
    foreach ($commands as $command) {
      if (($command['command'] ?? '') !== 'invoke' || !preg_match('/^#edit-field-content-visibility-(.+)$/', (string) $command['selector'], $match)) {
        continue;
      }
      if ($command['method'] === 'removeAttr' && $command['args'] === ['disabled']) {
        $choices[$match[1]] = 'on';
      }
      if ($command['method'] === 'prop' && $command['args'] === ['disabled', 'disabled']) {
        $choices[$match[1]] = 'off';
      }
    }
    ksort($choices);
    return $choices;
  }

  /**
   * Chooses each group as one person, on the topic and the event form.
   *
   * @return array<string, array<string, list<array<string, mixed>>>>
   *   Form type => group key => the answer's commands.
   */
  private function answers(UserInterface $account, array $group_ids): array {
    return $this->asPersona($account, function () use ($group_ids): array {
      $answers = [];
      foreach (['topic', 'event'] as $type) {
        foreach ($group_ids as $key => $id) {
          $answers[$type][$key] = $this->chooseGroup($type, $id);
        }
      }
      return $answers;
    });
  }

  /**
   * A members-only group is answered to someone outside it as a missing number.
   */
  public function testGroupChoiceChecksAccess(): void {
    $open = $this->groups['C'];
    $members_only = $this->groups['B'];
    $all = ['group' => 'on', 'community' => 'on', 'public' => 'on'];
    ksort($all);
    $only_group = ['community' => 'off', 'group' => 'on', 'public' => 'off'];

    // A member of the members-only group: as before.
    $member = $this->answers($this->people['B_member'], ['members_only' => $members_only->id(), 'open' => $open->id()]);
    // Someone outside it, signed in, a member of another group.
    $outside = $this->answers($this->people['A_member'], ['members_only' => $members_only->id(), 'open' => $open->id(), 'own_open' => $this->groups['A']->id(), 'missing' => self::MISSING]);

    foreach (['topic', 'event'] as $type) {
      // Controls: an ordinary open group, their own open group, and the
      // member's own members-only group.
      $this->assertSame($all, $this->choices($member[$type]['open']), "$type form, member, open group");
      $this->assertSame($all, $this->choices($outside[$type]['open']), "$type form, outside, open group");
      $this->assertSame($all, $this->choices($outside[$type]['own_open']), "$type form, outside, own open group");
      $this->assertSame($only_group, $this->choices($member[$type]['members_only']), "$type form, member, own members-only group");
      // A missing number changes no choice.
      $this->assertSame([], $this->choices($outside[$type]['missing']), "$type form, outside, missing number");
      // The members-only group, to someone outside it: the same answer.
      $this->assertSame($outside[$type]['missing'], $outside[$type]['members_only'], "$type form, outside, members-only group answered as a missing number");
    }
  }

  /**
   * The visibility choices the form itself offers after a group is chosen.
   *
   * @return array<string, string>
   *   Visibility => 'on' or 'off'.
   */
  private function formChoices(UserInterface $account, string $type, int|string $group_id): array {
    $this->container->get('current_user')->setAccount($account);
    $node = $this->container->get('entity_type.manager')->getStorage('node')->create(['type' => $type, 'uid' => $account->id()]);
    $form_object = $this->container->get('entity_type.manager')->getFormObject('node', 'default')->setEntity($node);
    $form_state = (new FormState())->setFormObject($form_object);
    $form_state->setValue('groups', [['target_id' => $group_id]]);
    $form_state->setUserInput(['_triggering_element_name' => 'groups']);
    $element = ['public' => [], 'community' => [], 'group' => []];
    $context = ['items' => $node->get('field_content_visibility')];
    social_group_field_widget_single_element_form_alter($element, $form_state, $context);
    $choices = [];
    foreach (['public', 'community', 'group'] as $visibility) {
      $choices[$visibility] = empty($element[$visibility]['#disabled']) ? 'on' : 'off';
    }
    return $choices;
  }

  /**
   * The form's own choices follow the same rule as its answer.
   */
  public function testFormChoicesCheckAccess(): void {
    $members_only = $this->groups['B']->id();
    foreach (['topic', 'event'] as $type) {
      // Controls: the member's own members-only group, and an open group.
      $this->assertSame(['public' => 'off', 'community' => 'off', 'group' => 'on'], $this->formChoices($this->people['B_member'], $type, $members_only), "$type form, member, own members-only group");
      $this->assertSame(['public' => 'on', 'community' => 'on', 'group' => 'on'], $this->formChoices($this->people['A_member'], $type, $this->groups['A']->id()), "$type form, outside, own open group");
      // The members-only group, to someone outside it: as a missing number.
      $this->assertSame($this->formChoices($this->people['A_member'], $type, self::MISSING), $this->formChoices($this->people['A_member'], $type, $members_only), "$type form, outside, members-only group as a missing number");
    }
  }

}
