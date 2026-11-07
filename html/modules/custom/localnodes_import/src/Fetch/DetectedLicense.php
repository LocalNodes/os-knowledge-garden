<?php

declare(strict_types=1);

namespace Drupal\localnodes_import\Fetch;

/**
 * An open Creative Commons licence a site states for its pages (D-25a).
 *
 * The licence is recognised from an address only: a MediaWiki's siteinfo
 * rightsinfo URL, or a page's rel=license link or CC badge. Only CC BY,
 * CC BY-SA and CC0 count as open (D-25c). NC and ND variants, other hosts
 * and other schemes are not recognised, so the import records no licence.
 *
 * The label, name and deed address are built from this class's own map and
 * the matched version, never from the page's wording, so a page can't get
 * its own text shown as a licence (threat T-22-85). A site can still claim a
 * licence it doesn't hold; the steward's rights confirmation (D-25b) and the
 * kept source link cover that.
 *
 * The label format ("CC BY 4.0", "CC BY-SA 3.0", "CC0 1.0") is the contract
 * the Source box (22-20) reads back through fromLabel().
 */
final readonly class DetectedLicense {

  /**
   * The licence families recognised, by their path on creativecommons.org.
   *
   * Each has its label prefix, its full name and the versions that exist.
   */
  private const FAMILIES = [
    'licenses/by' => [
      'label' => 'CC BY',
      'name' => 'Creative Commons Attribution %s license',
      'versions' => ['1.0', '2.0', '2.5', '3.0', '4.0'],
    ],
    'licenses/by-sa' => [
      'label' => 'CC BY-SA',
      'name' => 'Creative Commons Attribution-ShareAlike %s license',
      'versions' => ['1.0', '2.0', '2.5', '3.0', '4.0'],
    ],
    'publicdomain/zero' => [
      'label' => 'CC0',
      'name' => 'CC0 %s Universal public domain dedication',
      'versions' => ['1.0'],
    ],
  ];

  /**
   * Field_source_license list keys, for the versions that have one.
   */
  private const KEYS = [
    'licenses/by 4.0' => 'cc_by_4_0',
    'licenses/by-sa 4.0' => 'cc_by_sa_4_0',
    'publicdomain/zero 1.0' => 'cc0_1_0',
  ];

  /**
   * Builds a licence. Use fromUrl() or fromLabel().
   *
   * @param string|null $key
   *   The field_source_license key (cc_by_4_0, cc_by_sa_4_0, cc0_1_0), or
   *   NULL for an open version that has no key (for example CC BY-SA 3.0).
   * @param string $label
   *   The short label, for example "CC BY-SA 4.0".
   * @param string $name
   *   The full name, for example "Creative Commons Attribution-ShareAlike
   *   4.0 license".
   * @param string $url
   *   The canonical deed address, https://creativecommons.org/.../.
   */
  private function __construct(
    public ?string $key,
    public string $label,
    public string $name,
    public string $url,
  ) {}

  /**
   * Recognises an open CC licence from its address.
   *
   * Accepted: http(s) on creativecommons.org or www.creativecommons.org, no
   * user or port, path /licenses/by/‹v›/, /licenses/by-sa/‹v›/ or
   * /publicdomain/zero/1.0/, optionally followed by a deed or legalcode
   * segment (deed.en, legalcode, legalcode.fr). A query or fragment is
   * ignored.
   *
   * @return self|null
   *   The licence, or NULL for anything else (NC, ND, GFDL, other hosts,
   *   other schemes, unknown versions, relative addresses).
   */
  public static function fromUrl(string $url): ?self {
    $parts = parse_url(trim($url));
    if ($parts === FALSE || !isset($parts['scheme'], $parts['host'], $parts['path'])) {
      return NULL;
    }
    if (!in_array(strtolower($parts['scheme']), ['http', 'https'], TRUE)
      || !in_array(strtolower($parts['host']), ['creativecommons.org', 'www.creativecommons.org'], TRUE)
      || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
      return NULL;
    }
    $pattern = '#^/(licenses/by|licenses/by-sa|publicdomain/zero)/(\d\.\d)(?:/(?:(?:deed|legalcode)(?:\.[a-z]{2,3}(?:[-_][a-z0-9]{2,8})*)?/?)?)?$#i';
    if (!preg_match($pattern, $parts['path'], $match)) {
      return NULL;
    }
    return self::build(strtolower($match[1]), $match[2]);
  }

  /**
   * The licence a label made by this class stands for.
   *
   * @return self|null
   *   The licence, or NULL when the label isn't one fromUrl() produces.
   */
  public static function fromLabel(string $label): ?self {
    foreach (self::FAMILIES as $family => $definition) {
      foreach ($definition['versions'] as $version) {
        if ($label === $definition['label'] . ' ' . $version) {
          return self::build($family, $version);
        }
      }
    }
    return NULL;
  }

  /**
   * Builds a licence from a family path and a version, or NULL.
   */
  private static function build(string $family, string $version): ?self {
    $definition = self::FAMILIES[$family] ?? NULL;
    if ($definition === NULL || !in_array($version, $definition['versions'], TRUE)) {
      return NULL;
    }
    return new self(
      self::KEYS["$family $version"] ?? NULL,
      $definition['label'] . ' ' . $version,
      sprintf($definition['name'], $version),
      "https://creativecommons.org/$family/$version/",
    );
  }

}
