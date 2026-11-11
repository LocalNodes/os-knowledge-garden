<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_import\Kernel;

use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_import\Entity\ImportItem;
use Drupal\localnodes_import\Entity\ImportRun;
use Drupal\node\NodeInterface;
use Drupal\search_api\IndexInterface;
use Drupal\Tests\social_ai_indexing\Traits\IndexFixtureTrait;

/**
 * The S6 Source box on imported Topics (D-14, D-25, UI-SPEC Part 8 S6).
 *
 * Every imported page credits its source the way CC BY-SA 4.0 §3(a)(1) asks:
 * the site, the original address and the licence, the change note and the
 * dates. A licence is shown only when one was detected (an open key, or a
 * detected label in field_source_license_text) or typed by the organizer
 * (files, as plain text). Nothing is claimed otherwise (threat T-22-87).
 *
 * The same facts reach the assistant through the citation_metadata processor
 * (citation_source_url, citation_license, citation_attribution), and the
 * stored citation_url is root-relative (D-19).
 *
 * @group phase22
 * @group localnodes_import
 */
class ProvenanceTest extends ImportKernelTestBase {

  use IndexFixtureTrait;

  private const ADDRESS = 'https://wiki.p2pfoundation.net/Bioregionalism';

  /**
   * The group the Topics are imported into.
   */
  private GroupInterface $group;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The shipped Topic default display shows the body first (weight 0,
    // text_default). It can't be imported verbatim here: its field_files
    // formatter comes from social_core, which this stack doesn't enable. So
    // the runtime display gets the same body component, and the Source box
    // (an extra field, visible by default) is picked up with no other change.
    $this->container->get('entity_display.repository')->getViewDisplay('node', 'topic')
      ->setComponent('body', ['type' => 'text_default', 'label' => 'hidden', 'weight' => 0])
      ->save();
    $this->group = $this->createFlexibleGroup('Provenance group');
  }

  /**
   * A web Topic with an open key carries the full CC BY-SA attribution.
   */
  public function testWebTopicAttribution(): void {
    $node = $this->createWebTopic('cc_by_sa_4_0', NULL);
    $this->recordImport($node, ImportRun::KIND_WEB, ImportItem::OUTCOME_IMPORTED, '2026-11-04');

    $xpath = $this->renderFull($node);
    $box = $this->box($xpath);
    $this->assertNotNull($box, 'An imported web page has a Source box');
    $this->assertSame('Source', $this->text($xpath, ".//h2", $box), 'The box heading is "Source"');

    $line = $xpath->query(".//*[contains(concat(' ', @class, ' '), ' localnodes-import-source__attribution ')]", $box)->item(0);
    $this->assertNotNull($line, 'The attribution line is there');
    $this->assertSame('Source: P2P Foundation Wiki, ' . self::ADDRESS . ', CC BY-SA 4.0', $this->visibleText($line), 'The attribution line names site, address and licence');

    $address = $xpath->query(".//a[@href='" . self::ADDRESS . "']", $line)->item(0);
    $this->assertNotNull($address, 'The original address is a link');
    $this->assertSame(self::ADDRESS, $this->visibleText($address), 'The link text is the full address');
    $this->assertExternalLink($xpath, $address);

    $licence = $xpath->query(".//a[@href='https://creativecommons.org/licenses/by-sa/4.0/']", $line)->item(0);
    $this->assertNotNull($licence, 'The licence links to its deed');
    $this->assertSame('CC BY-SA 4.0', $this->visibleText($licence));
    $this->assertSame('Creative Commons Attribution-ShareAlike 4.0 license', $licence->getAttribute('aria-label'), 'The licence link is named with the full licence name');
    $this->assertExternalLink($xpath, $licence);

    $this->assertStringContainsString('Formatting was converted for this site. The original page may look different.', $box->textContent, 'The change note is there');
    $meta = $this->text($xpath, ".//*[contains(concat(' ', @class, ' '), ' localnodes-import-source__meta ')]", $box);
    $this->assertSame('Imported 4 November 2026', $meta, 'The meta line has the import date');
  }

  /**
   * After a refresh the meta line carries both dates.
   */
  public function testRefreshedTopicShowsBothDates(): void {
    $node = $this->createWebTopic('cc_by_sa_4_0', NULL);
    $this->recordImport($node, ImportRun::KIND_WEB, ImportItem::OUTCOME_IMPORTED, '2026-11-04');
    $this->recordImport($node, ImportRun::KIND_WEB, ImportItem::OUTCOME_REFRESHED, '2026-11-12');

    $xpath = $this->renderFull($node);
    $meta = $this->text($xpath, ".//*[contains(concat(' ', @class, ' '), ' localnodes-import-source__meta ')]", $this->box($xpath));
    $this->assertSame('Imported 4 November 2026 · Refreshed from source 12 November 2026', $meta);
  }

  /**
   * No detected licence: the line ends after the address, no licence named.
   */
  public function testNoLicenceIsClaimed(): void {
    $node = $this->createWebTopic('not_stated', NULL);
    $this->recordImport($node, ImportRun::KIND_WEB, ImportItem::OUTCOME_IMPORTED, '2026-11-04');

    $xpath = $this->renderFull($node);
    $box = $this->box($xpath);
    $this->assertNotNull($box, 'An imported web page without a licence still has a Source box');
    $line = $xpath->query(".//*[contains(concat(' ', @class, ' '), ' localnodes-import-source__attribution ')]", $box)->item(0);
    $this->assertNotNull($line, 'The attribution line is there');
    $this->assertSame('Source: P2P Foundation Wiki, ' . self::ADDRESS, $this->visibleText($line), 'The line ends after the address');
    $this->assertSame(0, $xpath->query(".//a[contains(@href, 'creativecommons.org')]", $box)->length, 'No licence link');
    $this->assertStringNotContainsStringIgnoringCase('licen', $box->textContent, 'No licence word');
    $this->assertStringNotContainsString('Not stated', $box->textContent);
  }

  /**
   * Licence text in the DetectedLicense label format becomes a linked label.
   */
  public function testDetectedLabelInTextIsLinked(): void {
    $node = $this->createWebTopic('not_stated', 'CC BY-SA 3.0');
    $this->recordImport($node, ImportRun::KIND_WEB, ImportItem::OUTCOME_IMPORTED, '2026-11-04');

    $xpath = $this->renderFull($node);
    $box = $this->box($xpath);
    $licence = $xpath->query(".//a[@href='https://creativecommons.org/licenses/by-sa/3.0/']", $box)->item(0);
    $this->assertNotNull($licence, 'The 3.0 label links to the 3.0 deed');
    $this->assertSame('CC BY-SA 3.0', $this->visibleText($licence));
    $this->assertSame('Creative Commons Attribution-ShareAlike 3.0 license', $licence->getAttribute('aria-label'));
  }

  /**
   * Text that isn't a detected label gives no licence part on a web Topic.
   */
  public function testUnrecognisedTextOnAWebTopicIsNotShown(): void {
    foreach (['CC BY-NC 4.0', 'All rights reserved', 'https://creativecommons.org/licenses/by-sa/4.0/'] as $text) {
      $node = $this->createWebTopic('not_stated', $text);
      $this->recordImport($node, ImportRun::KIND_WEB, ImportItem::OUTCOME_IMPORTED, '2026-11-04');
      $xpath = $this->renderFull($node);
      $box = $this->box($xpath);
      $this->assertNotNull($box, "A Source box for '$text'");
      $line = $xpath->query(".//*[contains(concat(' ', @class, ' '), ' localnodes-import-source__attribution ')]", $box)->item(0);
      $this->assertNotNull($line);
      $this->assertSame('Source: P2P Foundation Wiki, ' . self::ADDRESS, $this->visibleText($line), "'$text' is not shown as a licence");
      // The report link (23-05) sits in its own paragraph and is not part of
      // the attribution; only the source address may be linked there.
      $this->assertSame(1, $xpath->query(".//a[not(ancestor::*[contains(concat(' ', @class, ' '), ' localnodes-import-source__report ')])]", $box)->length, "Only the address is linked for '$text'");
    }
  }

  /**
   * A file Topic: uploaded file line, credit, licence as typed, no note.
   */
  public function testFileTopic(): void {
    $file = $this->createFile('garden-minutes.pdf');
    $node = $this->createFileTopic($file, 'Garden Committee, 2024', 'CC BY 4.0, from the author');
    $this->recordImport($node, ImportRun::KIND_FILES, ImportItem::OUTCOME_IMPORTED, '2026-11-04', (int) $file->id());

    $xpath = $this->renderFull($node);
    $box = $this->box($xpath);
    $this->assertNotNull($box, 'An imported file has a Source box');
    $this->assertSame('Source', $this->text($xpath, './/h2', $box));

    $line = $xpath->query(".//*[contains(concat(' ', @class, ' '), ' localnodes-import-source__attribution ')]", $box)->item(0);
    $this->assertSame('Source: uploaded file garden-minutes.pdf', $this->visibleText($line));
    $download = $xpath->query('.//a', $line)->item(0);
    $this->assertNotNull($download, 'The file name links to the download');
    $this->assertSame('garden-minutes.pdf', $this->visibleText($download));
    $this->assertSame($this->container->get('file_url_generator')->generateString($file->getFileUri()), $download->getAttribute('href'));

    $this->assertSame('Credit: Garden Committee, 2024', $this->text($xpath, ".//*[contains(concat(' ', @class, ' '), ' localnodes-import-source__credit ')]", $box));
    $licence = $xpath->query(".//*[contains(concat(' ', @class, ' '), ' localnodes-import-source__licence ')]", $box)->item(0);
    $this->assertNotNull($licence, 'The typed licence is shown');
    $this->assertSame('Licence: CC BY 4.0, from the author', $this->visibleText($licence));
    $this->assertSame(0, $xpath->query('.//a', $licence)->length, 'The typed licence is never linked');
    $this->assertStringNotContainsString('Formatting was converted', $box->textContent, 'No change note on a file');
    $this->assertSame('Imported 4 November 2026', $this->text($xpath, ".//*[contains(concat(' ', @class, ' '), ' localnodes-import-source__meta ')]", $box));
  }

  /**
   * A file Topic without licence text or credit hides those lines.
   */
  public function testFileTopicWithoutLicenceOrCredit(): void {
    $file = $this->createFile('notes.docx');
    $node = $this->createFileTopic($file, NULL, NULL);
    $this->recordImport($node, ImportRun::KIND_FILES, ImportItem::OUTCOME_NOT_SEARCHABLE, '2026-11-04', (int) $file->id());

    $xpath = $this->renderFull($node);
    $box = $this->box($xpath);
    $this->assertNotNull($box);
    $this->assertSame(0, $xpath->query(".//*[contains(concat(' ', @class, ' '), ' localnodes-import-source__licence ')]", $box)->length, 'No licence line');
    $this->assertSame(0, $xpath->query(".//*[contains(concat(' ', @class, ' '), ' localnodes-import-source__credit ')]", $box)->length, 'No credit line');
    $this->assertStringNotContainsStringIgnoringCase('licen', $box->textContent);
  }

  /**
   * Organizer licence text is escaped, never markup.
   */
  public function testFileLicenceTextIsEscaped(): void {
    $file = $this->createFile('flyer.pdf');
    $node = $this->createFileTopic($file, NULL, '<script>alert(1)</script> CC BY 4.0');
    $this->recordImport($node, ImportRun::KIND_FILES, ImportItem::OUTCOME_IMPORTED, '2026-11-04', (int) $file->id());

    $html = $this->renderFullHtml($node);
    $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; CC BY 4.0', $html, 'The licence text shows as text');
    $this->assertStringNotContainsString('<script>alert(1)', $html, 'No script element');
  }

  /**
   * A Topic that wasn't imported, or a non-full view, has no Source box.
   */
  public function testNoBoxWhenNotImportedOrNotFull(): void {
    $plain = $this->createGroupTopic($this->group, 'Hand-written notes', '<p>Our notes.</p>', 'public');
    $this->assertNull($this->box($this->renderFull($plain)), 'A Topic that was not imported has no Source box');

    // A failed or duplicate row naming the Topic doesn't make it imported.
    $this->recordImport($plain, ImportRun::KIND_WEB, ImportItem::OUTCOME_DUPLICATE, '2026-11-04');
    $this->assertNull($this->box($this->renderFull($plain)), 'A duplicate row does not add a Source box');

    $node = $this->createWebTopic('cc_by_sa_4_0', NULL);
    $this->recordImport($node, ImportRun::KIND_WEB, ImportItem::OUTCOME_IMPORTED, '2026-11-04');
    $build = $this->container->get('entity_type.manager')->getViewBuilder('node')->view($node, 'teaser');
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->assertStringNotContainsString('localnodes-import-source', $html, 'The teaser has no Source box');
  }

  /**
   * The box adds its cache metadata to the Topic's, never replaces it.
   *
   * Replacing it dropped the node's own tags and the initial cache contexts,
   * and the page render cache then refused the build ("missing: theme").
   */
  public function testCacheMetadataIsMerged(): void {
    $node = $this->createWebTopic('cc_by_sa_4_0', NULL);
    $this->recordImport($node, ImportRun::KIND_WEB, ImportItem::OUTCOME_IMPORTED, '2026-11-04');
    $view_builder = $this->container->get('entity_type.manager')->getViewBuilder('node');
    $build = $view_builder->view($node, 'full');
    $contexts = $build['#cache']['contexts'] ?? [];
    $build = $view_builder->build($build);

    $tags = $build['#cache']['tags'] ?? [];
    $this->assertContains('node:' . $node->id(), $tags, "The Topic's own tag is kept");
    $this->assertContains('localnodes_import_item_list', $tags, 'A new import or refresh row invalidates the box');
    foreach ($contexts as $context) {
      $this->assertContains($context, $build['#cache']['contexts'] ?? [], "Initial cache context $context is kept");
    }
    $this->assertSame(['entity_view', 'node', (string) $node->id(), 'full'], $build['#cache']['keys'] ?? NULL, 'The render cache keys are kept');

    // Rendering through the render cache (as a page does) succeeds.
    $html = (string) $this->container->get('renderer')->renderRoot($build);
    $this->assertStringContainsString('localnodes-import-source', $html);
  }

  /**
   * Imported Topics carry the class that scopes the blockquote styles.
   */
  public function testImportedBodyIsMarkedForQuoteStyles(): void {
    $node = $this->createWebTopic('cc_by_sa_4_0', NULL);
    $this->recordImport($node, ImportRun::KIND_WEB, ImportItem::OUTCOME_IMPORTED, '2026-11-04');
    $xpath = $this->renderFull($node);
    $this->assertSame(1, $xpath->query("//*[contains(concat(' ', @class, ' '), ' localnodes-import-imported ')]")->length, 'The imported Topic is marked');
    $this->assertSame(1, $xpath->query("//*[contains(concat(' ', @class, ' '), ' localnodes-import-imported ')]//blockquote")->length, 'The quote sits inside the marked Topic');
    $this->assertSame(1, $xpath->query("//blockquote/following::*[contains(concat(' ', @class, ' '), ' localnodes-import-source ')]")->length, 'The Source box comes after the page text');
  }

  /**
   * The processor gives the assistant the source, licence and credit.
   */
  public function testCitationMetadataCarriesProvenance(): void {
    $index = $this->createShippedIndex('social_posts', $this->createTestSearchServer());
    foreach (['citation_source_url', 'citation_license', 'citation_attribution'] as $field) {
      $this->assertNotNull($index->getField($field), "The shipped social_posts index has $field");
    }

    $web = $this->createWebTopic('cc_by_sa_4_0', NULL);
    $values = $this->citationValues($index, $web);
    $this->assertSame([self::ADDRESS], $values['citation_source_url']);
    $this->assertSame(['CC BY-SA 4.0'], $values['citation_license']);
    $this->assertSame(['P2P Foundation Wiki'], $values['citation_attribution']);
    $this->assertSame([$web->toUrl()->toString()], $values['citation_url'], 'citation_url is the root-relative page address');
    $this->assertStringStartsWith('/', $values['citation_url'][0]);
    $this->assertStringStartsNotWith('//', $values['citation_url'][0]);

    $label = $this->createWebTopic('not_stated', 'CC BY-SA 3.0');
    $this->assertSame(['CC BY-SA 3.0'], $this->citationValues($index, $label)['citation_license'], 'A detected label in the text is the licence');

    $none = $this->createWebTopic('not_stated', NULL);
    $this->assertSame([], $this->citationValues($index, $none)['citation_license'], 'No licence: empty, never "Not stated"');

    $file = $this->createFileTopic($this->createFile('rules.pdf'), 'Garden Committee, 2024', 'CC BY 4.0, from the author');
    $values = $this->citationValues($index, $file);
    $this->assertSame(['CC BY 4.0, from the author'], $values['citation_license'], 'A file gives its licence text as typed');
    $this->assertSame(['Garden Committee, 2024'], $values['citation_attribution']);
    $this->assertSame([], $values['citation_source_url']);
  }

  /**
   * A Topic without the source fields gives empty values and no error.
   */
  public function testCitationMetadataWithoutSourceFields(): void {
    $index = $this->createShippedIndex('social_posts', $this->createTestSearchServer());
    $plain = $this->createGroupTopic($this->group, 'Hand-written notes', '<p>Our notes.</p>', 'public');
    $values = $this->citationValues($index, $plain);
    $this->assertSame([], $values['citation_source_url']);
    $this->assertSame([], $values['citation_license']);
    $this->assertSame([], $values['citation_attribution']);
    $this->assertSame(['Hand-written notes'], $values['citation_title']);
    $this->assertStringStartsWith('/', $values['citation_url'][0]);
  }

  /**
   * Runs the shipped index's processors on a Topic.
   *
   * @return array<string, array>
   *   Field values by citation field name.
   */
  private function citationValues(IndexInterface $index, NodeInterface $node): array {
    $node = $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($node->id());
    $item_id = 'entity:node/' . $node->id() . ':' . $node->language()->getId();
    $item = $this->container->get('search_api.fields_helper')->createItemFromObject($index, $node->getTypedData(), $item_id);
    $this->assertShippedProcessorLoaded($index, 'citation_metadata');
    // Extracting the fields runs the index's processors, citation_metadata
    // among them, exactly as indexing does.
    $fields = $item->getFields();
    $values = [];
    foreach (['citation_url', 'citation_title', 'citation_type', 'citation_source_url', 'citation_license', 'citation_attribution'] as $name) {
      $field = $fields[$name] ?? NULL;
      $values[$name] = $field ? array_values(array_map('strval', $field->getValues())) : [];
    }
    return $values;
  }

  /**
   * Creates an imported web Topic with the source fields set.
   */
  private function createWebTopic(string $license, ?string $license_text, string $address = self::ADDRESS): NodeInterface {
    $node = $this->createGroupTopic($this->group, 'Bioregionalism', '', 'public');
    $node->set('body', [
      'value' => '<blockquote><p>A distinct area.</p></blockquote><p>(Source: Peter Berg)</p>',
      'format' => 'imported_html',
    ]);
    $node->set('field_source_url', ['uri' => $address]);
    $node->set('field_source_license', $license);
    $node->set('field_source_license_text', $license_text);
    $node->set('field_source_attribution', 'P2P Foundation Wiki');
    $node->save();
    return $node;
  }

  /**
   * Creates an imported file Topic with the file attached.
   */
  private function createFileTopic(FileInterface $file, ?string $credit, ?string $license_text): NodeInterface {
    $node = $this->createGroupTopic($this->group, 'Garden minutes', '', 'group');
    $node->set('field_files', [['target_id' => $file->id(), 'display' => 1]]);
    $node->set('field_source_license', 'not_stated');
    $node->set('field_source_license_text', $license_text);
    $node->set('field_source_attribution', $credit);
    $node->save();
    return $node;
  }

  /**
   * Creates a permanent private file.
   */
  private function createFile(string $name): FileInterface {
    $uri = "private://$name";
    file_put_contents($uri, 'file body');
    $file = File::create([
      'uri' => $uri,
      'filename' => $name,
      'uid' => $this->siteOwner->id(),
      'status' => FileInterface::STATUS_PERMANENT,
    ]);
    $file->save();
    return $file;
  }

  /**
   * Records a finished run with one item row for the Topic, dated at noon.
   */
  private function recordImport(NodeInterface $node, string $kind, string $outcome, string $date, ?int $fid = NULL): void {
    $time = (new \DateTimeImmutable("$date 12:00:00", new \DateTimeZone(date_default_timezone_get())))->getTimestamp();
    $manager = $this->container->get('entity_type.manager');
    $run = $manager->getStorage('localnodes_import_run')->create([
      'gid' => $this->group->id(),
      'uid' => $this->siteOwner->id(),
      'kind' => $kind,
      'status' => ImportRun::STATUS_FINISHED,
      'created' => $time,
    ]);
    $run->save();
    $item = $manager->getStorage('localnodes_import_item')->create([
      'run' => $run->id(),
      'gid' => $this->group->id(),
      'source_name' => $kind === ImportRun::KIND_WEB ? self::ADDRESS : 'upload',
      'outcome' => $outcome,
      'nid' => $node->id(),
      'fid' => $fid,
      'created' => $time,
    ]);
    $item->save();
  }

  /**
   * Renders a Topic's full view as HTML.
   */
  private function renderFullHtml(NodeInterface $node): string {
    $manager = $this->container->get('entity_type.manager');
    $manager->getStorage('node')->resetCache();
    $node = $manager->getStorage('node')->load($node->id());
    $build = $manager->getViewBuilder('node')->view($node, 'full');
    return (string) $this->container->get('renderer')->renderInIsolation($build);
  }

  /**
   * Renders a Topic's full view, ready for XPath.
   */
  private function renderFull(NodeInterface $node): \DOMXPath {
    $document = new \DOMDocument();
    libxml_use_internal_errors(TRUE);
    $document->loadHTML('<?xml encoding="UTF-8"><html><body>' . $this->renderFullHtml($node) . '</body></html>');
    libxml_clear_errors();
    return new \DOMXPath($document);
  }

  /**
   * The Source box element, or NULL.
   */
  private function box(\DOMXPath $xpath): ?\DOMElement {
    $node = $xpath->query("//*[contains(concat(' ', @class, ' '), ' localnodes-import-source ')]")->item(0);
    return $node instanceof \DOMElement ? $node : NULL;
  }

  /**
   * Whitespace-normalised text of the first match under a context node.
   */
  private function text(\DOMXPath $xpath, string $query, ?\DOMNode $context = NULL): ?string {
    $node = $xpath->query($query, $context)->item(0);
    return $node === NULL ? NULL : $this->normalise($node->textContent);
  }

  /**
   * Text a sighted reader sees: without visually hidden spans.
   */
  private function visibleText(\DOMNode $node): string {
    $copy = $node->cloneNode(TRUE);
    $xpath = new \DOMXPath($node->ownerDocument);
    foreach (iterator_to_array($xpath->query(".//*[contains(concat(' ', @class, ' '), ' visually-hidden ')]", $copy)) as $hidden) {
      $hidden->parentNode->removeChild($hidden);
    }
    return $this->normalise($copy->textContent);
  }

  /**
   * Collapses whitespace.
   */
  private function normalise(string $text): string {
    return trim((string) preg_replace('/\s+/u', ' ', $text));
  }

  /**
   * External link: icon hidden from screen readers, "(opens another site)".
   */
  private function assertExternalLink(\DOMXPath $xpath, \DOMElement $link): void {
    $this->assertSame(1, $xpath->query(".//svg[@aria-hidden='true']", $link)->length, 'The external icon is hidden from screen readers');
    $hidden = $xpath->query(".//*[contains(concat(' ', @class, ' '), ' visually-hidden ')]", $link)->item(0);
    $this->assertNotNull($hidden, 'Screen readers hear that the link opens another site');
    $this->assertSame('(opens another site)', $this->normalise($hidden->textContent));
    if ($link->hasAttribute('aria-label')) {
      $this->assertSame($hidden->getAttribute('id'), $link->getAttribute('aria-describedby'), 'With an aria-label the note is the link description');
    }
    $this->assertFalse($link->hasAttribute('target'), 'Same tab');
  }

}
