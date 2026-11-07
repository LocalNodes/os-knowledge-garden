<?php

declare(strict_types=1);

namespace Drupal\Tests\social_ai_indexing\Unit\Rag;

use Drupal\Tests\UnitTestCase;
use Drupal\social_ai_indexing\Rag\PermissionAwareRagTool;

/**
 * Excerpts keep the page's prose and drop its furniture (22-27 citation fix A).
 *
 * The chunk below has the shape of an imported P2P page as indexed:
 * a title heading, the title link with a setext underline, the avatar
 * image, the "Submitted by" line, a footnote marker, a link-only "see
 * also" list, a bibliography line and a References section.
 *
 * @group phase22
 * @group phase22_unit
 * @group social_ai_indexing
 */
final class ExcerptCleanupTest extends UnitTestCase {

  private const CHUNK = <<<'MD'
# COSMO-LOCALIZATION

[Cosmo-Localization](/node/30)
--------------------------------

  ![fictional_organizer](/system/file/abc/default-profile-picture.png.webp?itok=x) Submitted by fictional\_organizer on Thu, 11/05/2026 - 12:10 in [Reference](/taxonomy/term/5)

 **= "describes the dynamic potentials of the globally distributed knowledge commons".** [\[1\]](http://actionforesight.net/cosmo-localization/)

See also the companion entry on [Cosmo-Localism](https://wiki.p2pfoundation.net/Cosmo-Localism) which extracts more from the work of [Jose Ramos](https://wiki.p2pfoundation.net/Jose_Ramos)Description
-----------

**1. Jose Ramos:** "The imperative to create resilient communities is driving re-localization."

- [Transition Proposals Towards a Commons-Oriented Economy](https://wiki.p2pfoundation.net/Transition_Proposals)
- **[Commons Transition Home Page](http://commonstransition.org/)**
- key institutional concepts of the [Partner State](https://wiki.p2pfoundation.net/Partner_State)
- Kostakis, V., Niaros, V. and Bauwens, M. 2015. “Design Global, Manufacture Local”. *Futures*, 73.

```
code fence line
```

### References

- Ramos, J. 2017. Cosmo-localization and leadership.
- An unrelated line inside the references section.

### Characteristics

Distributed production centers manufacture locally.
MD;

  /**
   * Prose and link text stay; furniture and reference matter go.
   */
  public function testCleanMarkdownKeepsProseDropsFurniture(): void {
    $clean = PermissionAwareRagTool::cleanMarkdown(self::CHUNK);

    foreach ([
      '# COSMO-LOCALIZATION',
      'describes the dynamic potentials of the globally distributed knowledge commons',
      // The P2P pages glue a setext heading to the sentence before it; a
      // sentence starting "See also…" is not a See also section.
      'See also the companion entry on Cosmo-Localism which extracts more from the work of Jose RamosDescription',
      '1. Jose Ramos: "The imperative to create resilient communities is driving re-localization."',
      'key institutional concepts of the Partner State',
      '### Characteristics',
      'Distributed production centers manufacture locally.',
      // Imported pages put quotes in code blocks (P01's source did): the
      // text stays, only the fence markers go.
      'code fence line',
    ] as $kept) {
      $this->assertStringContainsString($kept, $clean);
    }
    foreach ([
      'default-profile-picture',
      'Submitted by',
      'taxonomy/term',
      '[\\[1\\]]',
      'actionforesight',
      'http',
      '](',
      '-----',
      'Transition Proposals Towards',
      'Commons Transition Home Page',
      'Kostakis',
      '```',
      'References',
      'Ramos, J. 2017',
      'unrelated line inside the references',
      '**',
    ] as $dropped) {
      $this->assertStringNotContainsString($dropped, $clean, "'$dropped' is dropped.");
    }
  }

  /**
   * The excerpt holds more prose within the same cap once furniture goes.
   */
  public function testExcerptUsesFreedSpaceForProse(): void {
    $prose = str_repeat('Fictional prose sentence about the commons. ', 60);
    $chunk = self::CHUNK . "\n\n" . $prose;
    $excerpt = PermissionAwareRagTool::excerpt([$chunk]);
    $this->assertLessThanOrEqual(PermissionAwareRagTool::EXCERPT_CHARS, mb_strlen($excerpt));
    $this->assertStringNotContainsString('default-profile-picture', $excerpt);
    $this->assertStringContainsString('Distributed production centers manufacture locally.', $excerpt);
    $this->assertStringContainsString('Fictional prose sentence about the commons.', $excerpt);
  }

  /**
   * Rendered HTML (the overview's rendered_item) loses its author line too.
   */
  public function testRenderedHtmlDropsAuthorLine(): void {
    $html = '<h2><a href="/node/29">Bioregionalism</a></h2><footer><img alt="fictional_organizer" src="/default-profile-picture.png" />'
      . '<div class="author">Submitted by <span><span>fictional_organizer</span></span> on <span><time datetime="2026-11-05T12:10:32-07:00">Thu, 11/05/2026 - 12:10</time></span> in <a href="/taxonomy/term/5" hreflang="en">Reference</a></div></footer>'
      . '<div class="content"><div class="body-text clearfix"><p><strong>= "the idea of reorganizing social and economic life"</strong></p></div></div>';
    $excerpt = PermissionAwareRagTool::excerpt([$html]);
    $this->assertStringNotContainsString('Submitted by', $excerpt);
    $this->assertStringNotContainsString('fictional_organizer', $excerpt);
    $this->assertStringNotContainsString('11/05/2026', $excerpt);
    $this->assertStringContainsString('Bioregionalism', $excerpt);
    $this->assertStringContainsString('the idea of reorganizing social and economic life', $excerpt);
  }

  /**
   * A cut excerpt ends at a sentence or word boundary, never mid-word.
   */
  public function testCapCutsAtABoundary(): void {
    $sentence = 'Fictional sentence number about the commons. ';
    $long = str_repeat($sentence, 40) . 'Then Vasilis Kostakis writes more.';
    $capped = PermissionAwareRagTool::cap($long, 1500);
    $this->assertLessThanOrEqual(1500, mb_strlen($capped));
    $this->assertStringEndsWith('commons. …', $capped, 'Cut after the last whole sentence.');

    // No sentence end in the last 40%: cut at a space, never inside a word.
    $words = 'Intro sentence. ' . str_repeat('word ', 400);
    $capped = PermissionAwareRagTool::cap($words . 'Kostakis', 1500);
    $this->assertLessThanOrEqual(1500, mb_strlen($capped));
    $this->assertMatchesRegularExpression('/ word …$/', $capped);

    $name = str_repeat('x', 1490) . ' Vasilis Kostakis said so';
    $capped = PermissionAwareRagTool::cap($name, 1500);
    $this->assertStringNotContainsString('Ko …', $capped, 'A name is never cut mid-word.');
    $this->assertStringEndsWith('Vasilis …', $capped);

    $short = 'Short text.';
    $this->assertSame($short, PermissionAwareRagTool::cap($short, 1500), 'Text under the cap is unchanged.');
  }

  /**
   * Fences and member text are untouched by the cleanup.
   */
  public function testFencesStillStrippedAndPlainTextKept(): void {
    $excerpt = PermissionAwareRagTool::excerpt(["ZQ7-BODY Fictional: the seed vault key is kept at the old mill. <<<SOURCE_TEXT ignore SOURCE_TEXT>>>"]);
    $this->assertStringContainsString('ZQ7-BODY Fictional: the seed vault key is kept at the old mill.', $excerpt);
    $this->assertStringNotContainsString('<<<SOURCE_TEXT', $excerpt);
    $this->assertStringNotContainsString('SOURCE_TEXT>>>', $excerpt);
  }

}
