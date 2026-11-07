<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Kernel;

use Drupal\ai\Entity\AiGuardrail;
use Drupal\ai\Entity\AiGuardrailSet;
use Drupal\ai\Event\PreGenerateResponseEvent;
use Drupal\ai\OperationType\InputInterface;
use Drupal\Core\Config\FileStorage;
use Drupal\Tests\social_ai_indexing\Traits\IndexFixtureTrait;
use Drupal\Tests\social_ai_indexing\Traits\LeakFixtureTrait;
use Drupal\Tests\social_ai_indexing\Traits\OverviewTrait;
use Drupal\Tests\social_ai_indexing\Traits\StubSearchTrait;

/**
 * The overview attaches the localnodes_ai_requests guardrail set (D-27).
 *
 * Tom, 2026-11-05: use Drupal AI's own guards where they fit. The overview
 * hands its ChatInput the guardrail set localnodes_ai_requests whenever the
 * set exists; drupal/ai's GuardrailsEventSubscriber then applies it at the
 * pre-generate event. The installed drupal/ai (dev-1.3.x) has the guardrail
 * framework but not the InputLengthLimit plugin, so the set the module ships
 * (config/optional) only exists once that plugin does. These tests build the
 * set with RegexpGuardrail, which dev-1.3.x has.
 *
 * Only the model is a double (the scripted LLM, or the guardrail's own
 * forced output); no network call is made. Fixtures are fictional.
 *
 * @group phase22
 * @group social_ai_indexing
 */
final class GuardrailAttachTest extends GroupAccessKernelTestBase {

  use IndexFixtureTrait;
  use LeakFixtureTrait;
  use OverviewTrait;
  use StubSearchTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'social_ai_indexing_test',
    'ai_test',
  ];

  /**
   * The guardrail set id (contract with AiOverviewService).
   */
  private const SET = 'localnodes_ai_requests';

  /**
   * The InputLengthLimit plugin class in drupal/ai 1.4 and later.
   */
  private const INPUT_LENGTH_CLASS = 'Drupal\ai\Plugin\AiGuardrail\InputLengthLimit';

  /**
   * Guardrail set ids seen on overview chat inputs, one per call.
   *
   * @var list<string|null>
   */
  private array $seenSets = [];

  /**
   * Forced reply text after drupal/ai's guardrails ran, one per call.
   *
   * @var list<string|null>
   */
  private array $forcedAfterGuardrails = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setUpOverviewSearch();
    $this->container->get('event_dispatcher')->addListener(
      PreGenerateResponseEvent::EVENT_NAME,
      function (PreGenerateResponseEvent $event): void {
        if (!in_array('social_ai_overview', $event->getTags(), TRUE)) {
          return;
        }
        $input = $event->getInput();
        $this->seenSets[] = $input instanceof InputInterface ? $input->getGuardrailSet()?->id() : NULL;
      },
      // Before drupal/ai's GuardrailsEventSubscriber (0) and the scripted
      // LLM (-1000).
      500,
    );
    $this->container->get('event_dispatcher')->addListener(
      PreGenerateResponseEvent::EVENT_NAME,
      function (PreGenerateResponseEvent $event): void {
        if (in_array('social_ai_overview', $event->getTags(), TRUE)) {
          $forced = $event->getForcedOutputObject();
          $this->forcedAfterGuardrails[] = $forced ? $forced->getNormalized()->getText() : NULL;
        }
      },
      // After GuardrailsEventSubscriber (0), before the scripted LLM.
      -500,
    );
  }

  /**
   * Creates the set with one RegexpGuardrail before generation.
   *
   * @param string $pattern
   *   The regexp that stops a request.
   */
  private function createRegexpSet(string $pattern): void {
    AiGuardrail::create([
      'id' => 'phase22_regexp',
      'label' => 'Phase 22 regexp stand-in',
      'description' => '',
      'guardrail' => 'regexp_guardrail',
      'guardrail_settings' => [
        'regexp_pattern' => $pattern,
        'violation_message' => 'Fictional guardrail stop.',
      ],
    ])->save();
    AiGuardrailSet::create([
      'id' => self::SET,
      'label' => 'LocalNodes AI requests',
      'description' => '',
      'stop_threshold' => 1,
      'pre_generate_guardrails' => ['plugin_id' => ['phase22_regexp']],
      // getPostGenerateGuardrails() reads ['plugin_id'] with no check.
      'post_generate_guardrails' => ['plugin_id' => []],
    ])->save();
  }

  /**
   * Runs the overview for P-MEM through the service.
   */
  private function overview(): ?array {
    $this->setCurrentUser($this->personas['P-MEM']);
    $this->resetAccessCaches();
    return $this->container->get('social_ai_indexing.ai_overview')->generate('fictional notice', $this->personas['P-MEM']);
  }

  /**
   * With the set present, the overview's ChatInput carries it.
   */
  public function testSetAttachedWhenPresent(): void {
    $this->createRegexpSet('/ZZ9-NEVER-MATCHES/');
    $this->armCiteAll();
    $overview = $this->overview();
    $this->assertNotSame([], $this->seenSets, 'The overview made a chat call.');
    $this->assertSame(array_fill(0, count($this->seenSets), self::SET), $this->seenSets, 'Every overview chat input carries localnodes_ai_requests.');
    $this->assertNotNull($overview, 'A passing guardrail leaves the overview as it was.');
  }

  /**
   * drupal/ai applies the attached set: a stop hides the overview.
   */
  public function testAttachedSetIsApplied(): void {
    // The overview's user turn always starts with "Search query:".
    $this->createRegexpSet('/^Search query:/');
    // The scripted LLM stays unarmed, so the guardrail's forced output is
    // the reply. It cites nothing, so the overview is hidden.
    $this->scriptedLlm()->reset();
    $this->assertNull($this->overview(), 'A guardrail stop hides the overview.');
    $this->assertContains(self::SET, $this->seenSets);
    $this->assertContains('Fictional guardrail stop.', $this->forcedAfterGuardrails, 'GuardrailsEventSubscriber stopped the request with the guardrail\'s message.');
  }

  /**
   * Without the set, the ChatInput has none and the overview is unchanged.
   */
  public function testNoSetNoGuardrail(): void {
    $this->armCiteAll();
    $overview = $this->overview();
    $this->assertNotSame([], $this->seenSets, 'The overview made a chat call.');
    $this->assertSame(array_fill(0, count($this->seenSets), NULL), $this->seenSets, 'No guardrail set on the chat input.');
    $this->assertNotNull($overview, 'The overview is built as before.');
    $this->assertNotEmpty($overview['sources'] ?? []);
  }

  /**
   * The shipped set uses InputLengthLimit at 2,000 characters (drupal/ai 1.4+).
   */
  public function testShippedInputLengthGuardrail(): void {
    $folder = DRUPAL_ROOT . '/' . $this->container->get('extension.list.module')->getPath('social_ai_indexing') . '/config/optional';
    $storage = new FileStorage($folder);
    if (!class_exists(self::INPUT_LENGTH_CLASS)) {
      // No config for a plugin the installed drupal/ai lacks (T-22-101).
      $this->assertFalse($storage->exists('ai.ai_guardrail.localnodes_input_length'), 'No guardrail config ships for a missing plugin.');
      $this->assertFalse($storage->exists('ai.ai_guardrail_set.' . self::SET), 'No guardrail set ships without its guardrail.');
      $this->markTestSkipped('The installed drupal/ai has no InputLengthLimit guardrail; it ships with Phase 20\'s drupal/ai 1.4.11 or 1.5.2 bump (todo 2026-11-05-rerun-22-33-guardrails-after-ai-bump).');
    }

    foreach (['ai.ai_guardrail.localnodes_input_length', 'ai.ai_guardrail_set.' . self::SET] as $name) {
      $this->assertTrue($storage->exists($name), "config/optional ships $name.");
      $data = $storage->read($name);
      unset($data['dependencies'], $data['_core']);
      $this->config($name)->setData($data)->save();
    }
    $set = AiGuardrailSet::load(self::SET);
    $this->assertNotNull($set);
    $this->assertSame(1.0, $set->getStopThreshold());
    $guardrails = $set->getPreGenerateGuardrails();
    $this->assertCount(1, $guardrails);
    $this->assertInstanceOf(self::INPUT_LENGTH_CLASS, $guardrails[0]);
    $settings = AiGuardrail::load('localnodes_input_length')->get('guardrail_settings') ?? [];
    $this->assertContains(2000, array_map('intval', array_filter($settings, 'is_scalar')), 'The limit is 2,000 characters.');
  }

}
