<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform;

/**
 * The name of the first group everyone is in: "<Community name> Commons".
 *
 * The one place the rule lives. A community template names that group
 * "{commons}", and a site without a template gets the same name. A name that
 * already ends in "Commons", in any case, gets no second one. The organizer
 * can rename the group afterwards like any group; nothing here looks at it
 * again.
 */
final class CommonsName {

  /**
   * The word added after the community's name.
   */
  public const WORD = 'Commons';

  /**
   * The longest name a group label holds.
   */
  public const MAX_LENGTH = 255;

  /**
   * The group's name for a community's name.
   *
   * @param string $site
   *   The community's name. Outer spaces are dropped.
   *
   * @return string
   *   The name with " Commons" added, unless it already ends in "commons"
   *   (any case). A name too long for a group label is cut first, so the
   *   word is never the part that is lost.
   */
  public static function of(string $site): string {
    $site = trim($site);
    $word = mb_strtolower(self::WORD);
    if (mb_strlen($site) <= self::MAX_LENGTH && mb_strtolower(mb_substr($site, -mb_strlen($word))) === $word) {
      return $site;
    }
    $site = rtrim(mb_substr($site, 0, self::MAX_LENGTH - mb_strlen(' ' . self::WORD)));
    return $site . ' ' . self::WORD;
  }

}
