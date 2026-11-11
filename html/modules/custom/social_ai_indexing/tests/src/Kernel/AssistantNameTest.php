<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Kernel;

use Drupal\block\Entity\Block;
use Drupal\Core\Form\FormState;
use Drupal\system\Form\SiteInformationForm;
use Drupal\Tests\SchemaCheckTestTrait;

/**
 * The chat header's name: "LocalNodes LiAIson" and the "AI" label.
 *
 * A small visible "AI" label, and each community can rename the assistant.
 * The default name is "LocalNodes LiAIson" on every site, because on the
 * hosted plan LocalNodes supplies the AI. It does not come from the site name.
 *
 * What is proved, on the rendered HTML of the ai_deepchat theme hook (the
 * template social_ai_indexing overrides, through the real preprocess):
 * - the visible name is the constant default, with any site name or none;
 * - the visible name is aria-hidden and a visually hidden span holds the
 *   spoken form ("LocalNodes Liaison,"), so a screen reader hears
 *   "LocalNodes Liaison, AI" and WCAG 2.5.3 (label in name) holds: the same
 *   words in the same order, differing in letter case and a comma;
 * - the "AI" label is markup in the template, never aria-hidden, so no
 *   setting can remove it (EU AI Act Art. 50);
 * - the rename setting on the Site information form, its trimming, blank,
 *   missing-key and escaping behaviour, and the cache tag that carries it;
 * - the hint on the DeepChat block's form (Q9b).
 *
 * The block plugin is not built: it needs an ai_assistant entity and the
 * runner. The theme hook is rendered directly with the values the block
 * would pass, including today's block label "AI assistant", so the test
 * proves the preprocess replaces it.
 *
 * @group phase22_1
 * @group social_ai_indexing
 */
final class AssistantNameTest extends GroupAccessKernelTestBase {

  use SchemaCheckTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'block',
  ];

  /**
   * The help text under the field.
   */
  private const FIELD_HELP = 'The name at the top of the chat. Leave it empty to use "LocalNodes LiAIson". An "AI" label always shows next to it.';

  /**
   * The line under the DeepChat block's Title field (Q9b).
   */
  private const BLOCK_HELP = 'The name at the top of the chat is set on Site information (Assistant name).';

  /**
   * The default, as people see it and as a screen reader hears it.
   */
  private const DEFAULT_VISIBLE = 'LocalNodes LiAIson';
  private const DEFAULT_SPOKEN = 'LocalNodes Liaison';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The shipped settings, as a fresh install has them (with the new key).
    $this->installShippedConfig('social_ai_indexing', ['social_ai_indexing.settings']);
    $this->container->get('theme_installer')->install(['stark']);
    $this->config('system.theme')->set('default', 'stark')->save();
    $this->container->get('theme.manager')->resetActiveTheme();
  }

  /**
   * Renders the chat as the block would.
   *
   * @return array{0: \DOMXPath, 1: array, 2: string}
   *   The parsed HTML, the render array after bubbling and the raw HTML.
   */
  private function renderChat(): array {
    $build = [
      '#theme' => 'ai_deepchat',
      '#settings' => [
        'label' => 'AI assistant',
        'placement' => 'bottom-right',
        'width' => '400px',
        'height' => '500px',
      ],
      '#deepchat_settings' => [],
      '#current_theme' => 'chatbot-stark',
    ];
    $html = (string) $this->container->get('renderer')->renderRoot($build);
    $dom = new \DOMDocument();
    $previous = libxml_use_internal_errors(TRUE);
    $dom->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>');
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    return [new \DOMXPath($dom), $build, $html];
  }

  /**
   * The header element of the rendered chat.
   */
  private function header(\DOMXPath $xpath): \DOMElement {
    $nodes = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " ai-deepchat--header ")]');
    $this->assertSame(1, $nodes->length, 'Exactly one chat header is rendered.');
    return $nodes->item(0);
  }

  /**
   * What a screen reader reads in a node: aria-hidden subtrees left out.
   */
  private function spokenText(\DOMNode $node): string {
    $text = '';
    foreach ($node->childNodes as $child) {
      if ($child instanceof \DOMElement) {
        if ($child->getAttribute('aria-hidden') === 'true') {
          continue;
        }
        $text .= ' ' . $this->spokenText($child) . ' ';
      }
      elseif ($child instanceof \DOMText) {
        $text .= $child->textContent;
      }
    }
    return trim((string) preg_replace('/\s+/', ' ', $text));
  }

  /**
   * The text of the first element with the class inside the header.
   */
  private function classText(\DOMXPath $xpath, string $class): ?string {
    $nodes = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")]', $this->header($xpath));
    return $nodes->length > 0 ? trim((string) preg_replace('/\s+/', ' ', $nodes->item(0)->textContent)) : NULL;
  }

  /**
   * Asserts the header for a name: visible, spoken and the AI label.
   */
  private function assertHeader(\DOMXPath $xpath, string $visible, string $spoken): void {
    $this->assertSame($visible, $this->classText($xpath, 'ln-assistant-name'));
    $name = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " ln-assistant-name ")]', $this->header($xpath))->item(0);
    $this->assertSame('true', $name->getAttribute('aria-hidden'), 'The visible name is hidden from screen readers.');

    $hidden = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " visually-hidden ")]', $this->header($xpath));
    $this->assertSame(1, $hidden->length, 'One visually hidden span holds the spoken name.');
    $this->assertSame($spoken . ',', trim((string) preg_replace('/\s+/', ' ', $hidden->item(0)->textContent)));

    $this->assertSame('AI', $this->classText($xpath, 'ln-ai-label'));
    $label = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " ln-ai-label ")]', $this->header($xpath))->item(0);
    $this->assertNotSame('true', $label->getAttribute('aria-hidden'), 'The AI label is never hidden from screen readers.');
    $this->assertSame(0, $xpath->query('ancestor::*[@aria-hidden="true"]', $label)->length);

    $this->assertSame($spoken . ', AI', $this->spokenText($this->header($xpath)));
  }

  /**
   * The default shows on every site, whatever the site is called.
   */
  public function testDefaultNameIgnoresSiteName(): void {
    $this->config('system.site')->set('name', 'Bread & Roses Co-op')->save();
    [$xpath] = $this->renderChat();
    $this->assertHeader($xpath, self::DEFAULT_VISIBLE, self::DEFAULT_SPOKEN);
    $header_text = $this->header($xpath)->textContent;
    $this->assertStringNotContainsString('Bread', $header_text);
    $this->assertStringNotContainsString('Roses', $header_text);
    $this->assertStringNotContainsString('AI assistant', $header_text, "The block's own label no longer reaches the header.");
  }

  /**
   * A site with no name gets the same default.
   */
  public function testDefaultNameWithEmptySiteName(): void {
    $this->config('system.site')->set('name', '')->save();
    [$xpath] = $this->renderChat();
    $this->assertHeader($xpath, self::DEFAULT_VISIBLE, self::DEFAULT_SPOKEN);
  }

  /**
   * The rendered chat carries the settings cache tag, so a rename shows.
   */
  public function testRenderedChatBubblesTheSettingsCacheTag(): void {
    [, $build] = $this->renderChat();
    $this->assertContains('config:social_ai_indexing.settings', $build['#cache']['tags'] ?? []);
  }

  /**
   * Submits the Site information form the way an admin would.
   *
   * @param string $assistant_name
   *   The value typed in the Assistant name field.
   */
  private function submitSiteInformation(string $assistant_name): FormState {
    $form_state = new FormState();
    $form_state->setValues([
      'site_name' => 'Bread & Roses Co-op',
      'site_slogan' => '',
      'site_mail' => 'organizer@breadandroses.example',
      'site_frontpage' => '/user/login',
      'site_403' => '',
      'site_404' => '',
      'assistant_name' => $assistant_name,
    ]);
    $this->container->get('form_builder')->submitForm(SiteInformationForm::class, $form_state);
    return $form_state;
  }

  /**
   * The stored rename, read fresh.
   */
  private function storedName(): mixed {
    return $this->container->get('config.factory')->getEditable('social_ai_indexing.settings')->get('assistant_name');
  }

  /**
   * A renamed assistant shows the typed name, trimmed, with the AI label.
   */
  public function testRenameThroughSiteInformationForm(): void {
    $form_state = $this->submitSiteInformation('  Repair Collective Assistant  ');
    $this->assertSame([], $form_state->getErrors());
    $this->assertSame('Repair Collective Assistant', $this->storedName());

    [$xpath] = $this->renderChat();
    $this->assertHeader($xpath, 'Repair Collective Assistant', 'Repair Collective Assistant');
  }

  /**
   * The typed case is kept: no Title Case, no upper-casing.
   */
  public function testTypedCaseIsKept(): void {
    $this->submitSiteInformation('repair collective helper');
    [$xpath] = $this->renderChat();
    $this->assertHeader($xpath, 'repair collective helper', 'repair collective helper');
  }

  /**
   * Only spaces store an empty string, and the default comes back.
   */
  public function testBlankRenameBringsBackTheDefault(): void {
    $this->submitSiteInformation('Repair Collective Assistant');
    $form_state = $this->submitSiteInformation('   ');
    $this->assertSame([], $form_state->getErrors());
    $this->assertSame('', $this->storedName());

    [$xpath] = $this->renderChat();
    $this->assertHeader($xpath, self::DEFAULT_VISIBLE, self::DEFAULT_SPOKEN);
  }

  /**
   * A site installed before the setting existed has no key and still works.
   */
  public function testMissingKeyShowsTheDefault(): void {
    $this->config('social_ai_indexing.settings')->clear('assistant_name')->save();
    $this->assertArrayNotHasKey('assistant_name', $this->config('social_ai_indexing.settings')->getRawData());

    [$xpath] = $this->renderChat();
    $this->assertHeader($xpath, self::DEFAULT_VISIBLE, self::DEFAULT_SPOKEN);
  }

  /**
   * A fresh install gets the key from config/install, and it is empty.
   */
  public function testShippedSettingsHoldAnEmptyKey(): void {
    $raw = $this->config('social_ai_indexing.settings')->getRawData();
    $this->assertArrayHasKey('assistant_name', $raw);
    $this->assertSame('', $raw['assistant_name']);
    $this->assertConfigSchemaByName('social_ai_indexing.settings');
  }

  /**
   * Markup and an ampersand show as plain text, escaped once.
   */
  public function testTypedMarkupIsEscapedOnce(): void {
    $typed = '<b>Sam</b> & Co';
    $this->config('social_ai_indexing.settings')->set('assistant_name', $typed)->save();

    [$xpath, , $html] = $this->renderChat();
    $this->assertHeader($xpath, $typed, $typed);
    $this->assertSame(0, $xpath->query('.//b', $this->header($xpath))->length, 'No element is created from the typed name.');

    $this->assertStringContainsString('&lt;b&gt;Sam&lt;/b&gt; &amp; Co', $html);
    $this->assertStringNotContainsString('&amp;lt;', $html, 'Escaped once, not twice.');
  }

  /**
   * The field sits directly under Site name, with the approved help.
   */
  public function testSiteInformationFormHasTheField(): void {
    $this->config('social_ai_indexing.settings')->set('assistant_name', 'Repair Collective Assistant')->save();
    $form = $this->container->get('form_builder')->getForm(SiteInformationForm::class);

    $children = array_values(array_filter(array_keys($form['site_information']), static fn ($key) => !str_starts_with((string) $key, '#')));
    $position = array_search('site_name', $children, TRUE);
    $this->assertNotFalse($position);
    $this->assertSame('assistant_name', $children[$position + 1] ?? NULL, 'The field is directly under Site name.');

    $field = $form['site_information']['assistant_name'];
    $this->assertSame('textfield', $field['#type']);
    $this->assertSame('Assistant name', (string) $field['#title']);
    $this->assertSame(40, $field['#maxlength']);
    $this->assertSame('Repair Collective Assistant', $field['#default_value']);
    $this->assertSame(self::FIELD_HELP, (string) $field['#description']);
  }

  /**
   * A 41-character name is refused, and nothing is saved.
   */
  public function testTooLongNameIsRefused(): void {
    $form_state = $this->submitSiteInformation(str_repeat('a', 41));
    $errors = $form_state->getErrors();
    $this->assertArrayHasKey('assistant_name', $errors);
    $this->assertSame('', $this->storedName());

    // Exactly 40 is fine.
    $form_state = $this->submitSiteInformation(str_repeat('a', 40));
    $this->assertSame([], $form_state->getErrors());
    $this->assertSame(str_repeat('a', 40), $this->storedName());
  }

  /**
   * The DeepChat block's form points at Site information; others do not.
   */
  public function testBlockFormHintsAtSiteInformation(): void {
    $hint = function (string $plugin): ?string {
      $block = Block::create(['id' => 'x_' . $plugin, 'theme' => 'stark', 'region' => 'content', 'plugin' => $plugin]);
      $form_object = $this->container->get('entity_type.manager')->getFormObject('block', 'default');
      $form_object->setEntity($block);
      $form_state = new FormState();
      $form_state->setFormState([]);
      $form_state->setFormObject($form_object);
      $form = ['settings' => ['label' => ['#type' => 'textfield', '#title' => 'Title']]];
      $form_id = 'block_form';
      $this->container->get('module_handler')->alter('form_block_form', $form, $form_state, $form_id);
      $description = $form['settings']['label']['#description'] ?? NULL;
      if ($description === NULL) {
        return NULL;
      }
      $text = is_array($description) ? (string) $this->container->get('renderer')->renderRoot($description) : (string) $description;
      return trim(strip_tags($text));
    };

    $this->assertSame(self::BLOCK_HELP, $hint('ai_deepchat_block'));
    $this->assertNull($hint('system_main_block'));
  }

}
