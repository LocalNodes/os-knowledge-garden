<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Traits;

/**
 * AI-SPEC §5 leak-matrix fixtures and personas.
 *
 * Every group, Topic, comment and canary here is fictional (AI-SPEC labeling
 * rule, threat T-22-02). No real community content enters tests.
 *
 * Fixtures ($this->fixtures):
 * - Group G (open flexible group): G-PUB (public Topic), G-COM (community
 *   Topic), G-MO (members-only Topic, title ZQ7-TITLE, body ZQ7-BODY plus the
 *   fictional fact), G-MO-COMMENT (ZQ7-COMMENT), G-PUB-COMMENT (control).
 * - Group H (open flexible group): H-MO (members-only, HK3-BODY), H-PUB.
 * - Group S (secret, members-only group visibility): S-MO (SX9-BODY).
 *
 * Personas ($this->personas):
 * - P-MEM: member of G.
 * - P-MEMH: member of G and H.
 * - P-NON: logged in, member of H only.
 * - P-ANON: logged out.
 *
 * Groups are in $this->groups (G, H, S). Use with GroupAccessKernelTestBase.
 */
trait LeakFixtureTrait {

  /**
   * Canary in G-MO's title.
   */
  public const ZQ7_TITLE = 'ZQ7-TITLE';

  /**
   * Canary in G-MO's body.
   */
  public const ZQ7_BODY = 'ZQ7-BODY';

  /**
   * Canary in the comment on G-MO.
   */
  public const ZQ7_COMMENT = 'ZQ7-COMMENT';

  /**
   * Distinctive fictional fact in G-MO's body.
   */
  public const ZQ7_FACT = 'the seed vault key is kept at the old mill';

  /**
   * Canary in H-MO's body.
   */
  public const HK3_BODY = 'HK3-BODY';

  /**
   * Canary in S-MO's body.
   */
  public const SX9_BODY = 'SX9-BODY';

  /**
   * Fixture entities keyed by AI-SPEC id.
   *
   * @var array<string, \Drupal\Core\Entity\ContentEntityInterface>
   */
  protected array $fixtures = [];

  /**
   * Persona accounts keyed by AI-SPEC id.
   *
   * @var array<string, \Drupal\Core\Session\AccountInterface>
   */
  protected array $personas = [];

  /**
   * Fixture groups keyed G, H, S.
   *
   * @var array<string, \Drupal\group\Entity\GroupInterface>
   */
  protected array $groups = [];

  /**
   * Builds the AI-SPEC §5 fixtures and personas.
   */
  protected function setUpLeakFixtures(): void {
    $g = $this->createFlexibleGroup('Fictional Group G', 'public');
    $h = $this->createFlexibleGroup('Fictional Group H', 'public');
    $s = $this->createSecretGroup('Fictional Group S');
    $this->groups = ['G' => $g, 'H' => $h, 'S' => $s];

    $this->fixtures['G-PUB'] = $this->createGroupTopic($g, 'Fictional G public notice', 'A fictional public notice for group G.', 'public');
    $this->fixtures['G-COM'] = $this->createGroupTopic($g, 'Fictional G community notice', 'A fictional community notice for group G.', 'community');
    $this->fixtures['G-MO'] = $this->createGroupTopic($g, self::ZQ7_TITLE . ' fictional members notes', self::ZQ7_BODY . ' Fictional: ' . self::ZQ7_FACT . '.', 'group');
    $this->fixtures['G-MO-COMMENT'] = $this->createComment($this->fixtures['G-MO'], self::ZQ7_COMMENT . ' a fictional members reply.');
    $this->fixtures['G-PUB-COMMENT'] = $this->createComment($this->fixtures['G-PUB'], 'A fictional public reply.');
    $this->fixtures['H-MO'] = $this->createGroupTopic($h, 'Fictional H members notes', self::HK3_BODY . ' fictional members notes for group H.', 'group');
    $this->fixtures['H-PUB'] = $this->createGroupTopic($h, 'Fictional H public notice', 'A fictional public notice for group H.', 'public');
    $this->fixtures['S-MO'] = $this->createGroupTopic($s, 'Fictional S members notes', self::SX9_BODY . ' fictional notes for secret group S.', 'group');

    $this->personas['P-MEM'] = $this->createPersona('fictional_p_mem');
    $this->personas['P-MEMH'] = $this->createPersona('fictional_p_memh');
    $this->personas['P-NON'] = $this->createPersona('fictional_p_non');
    $this->personas['P-ANON'] = $this->anonymous();

    $this->addMember($g, $this->personas['P-MEM']);
    $this->addMember($g, $this->personas['P-MEMH']);
    $this->addMember($h, $this->personas['P-MEMH']);
    $this->addMember($h, $this->personas['P-NON']);
  }

}
