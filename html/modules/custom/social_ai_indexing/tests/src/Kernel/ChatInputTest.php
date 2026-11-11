<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Kernel;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Routing\RouteObjectInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * What the chat input says, and what it tells people about the AI service.
 *
 * In a group the input reads "Ask about documents in ‹group›"; elsewhere
 * "Ask about pages on this site". A line under the input, always shown,
 * says the question and passages from pages the person can see are sent to
 * the AI service.
 *
 * The theme hook is rendered as the block would render it, with the style
 * file's own textInput settings, through the real preprocess.
 *
 * @group social_ai_indexing
 */
final class ChatInputTest extends GroupAccessKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'block',
  ];

  private const DISCLOSURE = 'Your question, and passages from pages you can see, are sent to the AI service to write the answer.';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installShippedConfig('social_ai_indexing', ['social_ai_indexing.settings']);
    $this->container->get('theme_installer')->install(['stark']);
    $this->config('system.theme')->set('default', 'stark')->save();
    $this->container->get('theme.manager')->resetActiveTheme();
  }

  /**
   * Outside a group: the site placeholder; the style file's look is kept.
   */
  public function testSitePlaceholder(): void {
    $text_input = $this->textInput($this->renderChat());
    $this->assertSame('Ask about pages on this site', $text_input['placeholder']['text'] ?? NULL);
    $this->assertSame('var(--ln-color-text-muted)', $text_input['placeholder']['style']['color'] ?? NULL, 'The placeholder keeps its colour');
    $this->assertSame('6px', $text_input['styles']['container']['borderRadius'] ?? NULL, 'The input keeps its look');
  }

  /**
   * On a group's page: the group's name in the placeholder.
   */
  public function testGroupPlaceholder(): void {
    $this->visitGroup('Bread & Roses Pantry');
    $text_input = $this->textInput($this->renderChat());
    $this->assertSame('Ask about documents in Bread & Roses Pantry', $text_input['placeholder']['text'] ?? NULL);
  }

  /**
   * A group name with quotes and tags stays text inside the attribute.
   *
   * The settings are printed unescaped in a single-quoted attribute, so the
   * JSON itself must carry no quote, tag or ampersand.
   */
  public function testGroupNameCannotBreakOutOfTheAttribute(): void {
    $name = "Rosa's <b onmouseover=\"x()\">Pantry</b> & 'co'";
    $this->visitGroup($name);
    $html = $this->renderChat();
    $this->assertSame('Ask about documents in ' . $name, $this->textInput($html)['placeholder']['text'] ?? NULL);
    $xpath = $this->xpathOf($html);
    $this->assertSame(0, $xpath->query('//b')->length, 'No element came from the group name');
    $this->assertSame(0, $xpath->query('//*[@onmouseover]')->length);
  }

  /**
   * Puts the request on a group's page.
   */
  private function visitGroup(string $label): void {
    $group = $this->createFlexibleGroup($label);
    $request = Request::create('/group/' . $group->id());
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, 'entity.group.canonical');
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, new Route('/group/{group}', [], [], ['parameters' => ['group' => ['type' => 'entity:group']]]));
    $request->attributes->set('group', $group);
    $this->container->get('request_stack')->push($request);
    $this->container->get('current_route_match')->resetRouteMatch();
  }

  /**
   * The AI service line shows under the input, in plain sight.
   */
  public function testTheAiServiceLineIsUnderTheInput(): void {
    $html = $this->renderChat();
    $xpath = $this->xpathOf($html);
    $lines = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " ln-chat-disclosure ")]');
    $this->assertSame(1, $lines->length, 'One AI service line');
    $line = $lines->item(0);
    $this->assertSame(self::DISCLOSURE, trim($line->textContent));
    $this->assertFalse($line->hasAttribute('hidden'));
    $this->assertNotSame('true', $line->getAttribute('aria-hidden'));
    $this->assertStringNotContainsString('visually-hidden', $line->getAttribute('class'));
    $previous = $line->previousSibling;
    while ($previous !== NULL && !$previous instanceof \DOMElement) {
      $previous = $previous->previousSibling;
    }
    $this->assertSame('deep-chat', $previous?->nodeName, 'The line comes right after the chat input');
  }

  /**
   * Renders the chat as the block would, with the style file's settings.
   */
  private function renderChat(): string {
    $build = [
      '#theme' => 'ai_deepchat',
      '#settings' => [
        'label' => 'AI assistant',
        'placement' => 'bottom-right',
        'width' => '400px',
        'height' => '500px',
      ],
      '#deepchat_settings' => [
        'textInput' => Json::encode([
          'placeholder' => ['text' => 'Ask a question', 'style' => ['color' => 'var(--ln-color-text-muted)']],
          'styles' => ['container' => ['borderRadius' => '6px']],
        ]),
      ],
      '#current_theme' => 'chatbot-stark',
    ];
    return (string) $this->container->get('renderer')->renderRoot($build);
  }

  /**
   * The deep-chat element's textInput attribute, decoded.
   */
  private function textInput(string $html): array {
    $nodes = $this->xpathOf($html)->query('//deep-chat');
    $this->assertSame(1, $nodes->length);
    $value = json_decode($nodes->item(0)->getAttribute('textInput') ?: $nodes->item(0)->getAttribute('textinput'), TRUE);
    $this->assertIsArray($value, 'textInput is JSON');
    return $value;
  }

  /**
   * Parses rendered HTML.
   */
  private function xpathOf(string $html): \DOMXPath {
    $dom = new \DOMDocument();
    $previous = libxml_use_internal_errors(TRUE);
    $dom->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>');
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    return new \DOMXPath($dom);
  }

}
