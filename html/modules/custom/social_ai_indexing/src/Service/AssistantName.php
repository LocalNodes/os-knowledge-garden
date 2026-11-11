<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * The chat assistant's name, as people see it and as a screen reader hears it.
 *
 * Each community can rename the assistant. The default is "LocalNodes
 * LiAIson" on every site, because on the hosted plan LocalNodes supplies the
 * AI. The default is a constant. It does not read the site name.
 *
 * The stylized "LiAIson" may be spelled out letter by letter, so the default
 * has a second form for screen readers, "LocalNodes Liaison". The words and
 * their order are the same, so WCAG 2.5.3 (label in name) holds. A custom
 * name is shown and spoken as typed.
 *
 * The "AI" label is not here: it is text in ai-deepchat.html.twig, so no
 * setting can remove it (EU AI Act Art. 50).
 *
 * The product name is not passed through t(): it is the same in every
 * language.
 */
final class AssistantName {

  /**
   * The default name as people see it.
   */
  public const DEFAULT_VISIBLE = 'LocalNodes LiAIson';

  /**
   * The default name as a screen reader should say it.
   */
  public const DEFAULT_SPOKEN = 'LocalNodes Liaison';

  /**
   * The settings object that holds the rename.
   */
  private const SETTINGS = 'social_ai_indexing.settings';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * The name people see in the chat header.
   */
  public function label(): string {
    return $this->custom() ?? self::DEFAULT_VISIBLE;
  }

  /**
   * The name a screen reader says (without the AI label).
   */
  public function spokenLabel(): string {
    return $this->custom() ?? self::DEFAULT_SPOKEN;
  }

  /**
   * The name a community set on Site information, or NULL for the default.
   *
   * A site installed before the setting existed has no key; that counts as
   * empty. The value is used as typed, with spaces at the ends removed.
   */
  private function custom(): ?string {
    $name = $this->configFactory->get(self::SETTINGS)->get('assistant_name');
    $name = is_string($name) ? trim($name) : '';
    return $name === '' ? NULL : $name;
  }

  /**
   * Cache tags of what the name depends on.
   *
   * @return string[]
   *   The rename setting; the default needs no other.
   */
  public function cacheTags(): array {
    return $this->configFactory->get(self::SETTINGS)->getCacheTags();
  }

}
