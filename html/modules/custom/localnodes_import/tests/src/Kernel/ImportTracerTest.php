<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_import\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\FileInterface;
use Drupal\group\Entity\GroupRole;
use Drupal\localnodes_import\Controller\RunReportController;
use Drupal\localnodes_import\Entity\ImportItem;
use Drupal\localnodes_import\Entity\ImportRun;
use Drupal\localnodes_import\Form\UploadFilesForm;
use Drupal\node\NodeInterface;
use Drupal\Tests\social_ai_indexing\Traits\IndexFixtureTrait;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * The importer's tracer slice: install, then one file end to end.
 *
 * @group phase22
 * @group localnodes_import
 */
final class ImportTracerTest extends ImportKernelTestBase {

  use IndexFixtureTrait;

  /**
   * A fixture PDF with a text layer (22-04 manifest: expect imported).
   */
  private const FIXTURE = 'pdf-01-tool-share-rules.pdf';

  /**
   * Enabling the module installs the log entities, settings and the grant.
   */
  public function testInstall(): void {
    $schema = $this->container->get('database')->schema();
    $this->assertTrue($schema->tableExists('localnodes_import_run'), 'Run table installed with the module');
    $this->assertTrue($schema->tableExists('localnodes_import_item'), 'Item table installed with the module');

    $settings = $this->config('localnodes_import.settings');
    $this->assertSame(20, $settings->get('max_file_mb'));
    $this->assertSame(50, $settings->get('max_urls'));
    $this->assertSame('pdf docx doc odt txt md', $settings->get('allowed_extensions'));

    $manager = GroupRole::load('flexible_group-group_manager');
    $this->assertTrue($manager->hasPermission('import content into group'), 'Group managers get the import permission at install');
    $member = GroupRole::load('flexible_group-member');
    $this->assertFalse($member->hasPermission('import content into group'), 'Ordinary members do not');
    $this->assertTrue(GroupRole::load('flexible_group-sm-insider')->isAdmin(), 'Site managers pass as group admins');

    // Item table indexes (D-21): (gid, outcome) from ImportItemStorageSchema,
    // (run) from the entity_reference field schema.
    $this->assertTrue($schema->indexExists('localnodes_import_item', 'localnodes_import_item__gid_outcome'), 'Index on (gid, outcome)');
    $this->assertTrue($schema->indexExists('localnodes_import_item', 'localnodes_import_item_field__run__target_id'), 'Index on (run)');

    // The three Topic source fields (D-14).
    $expected_types = [
      'field_source_url' => 'link',
      'field_source_license' => 'list_string',
      'field_source_attribution' => 'string',
    ];
    foreach ($expected_types as $field_name => $type) {
      $storage = FieldStorageConfig::loadByName('node', $field_name);
      $this->assertNotNull($storage, "$field_name storage exists");
      $this->assertSame($type, $storage->getType(), "$field_name is a $type field");
      $this->assertSame(1, $storage->getCardinality(), "$field_name holds one value");
      $field = FieldConfig::loadByName('node', 'topic', $field_name);
      $this->assertNotNull($field, "$field_name is on Topic");
      $this->assertFalse($field->isRequired(), "$field_name is optional");
    }
    $url_field = FieldConfig::loadByName('node', 'topic', 'field_source_url');
    $this->assertSame(16, (int) $url_field->getSetting('link_type'), 'External addresses only');
    $this->assertSame(0, (int) $url_field->getSetting('title'), 'No link text');
    $this->assertSame([
      'not_stated' => 'Not stated',
      'cc_by_4_0' => 'CC BY 4.0',
      'cc_by_sa_4_0' => 'CC BY-SA 4.0',
      'cc0_1_0' => 'CC0 (no rights reserved)',
      'all_rights_reserved' => 'All rights reserved',
    ], FieldStorageConfig::loadByName('node', 'field_source_license')->getSetting('allowed_values'));
    $this->assertSame(255, (int) FieldStorageConfig::loadByName('node', 'field_source_attribution')->getSetting('max_length'));

    // field_files accepts odt and md, without losing what social_topic ships.
    $extensions = explode(' ', FieldConfig::loadByName('node', 'topic', 'field_files')->getSetting('file_extensions'));
    foreach (['odt', 'md', 'pdf', 'docx', 'doc', 'txt'] as $extension) {
      $this->assertContains($extension, $extensions);
    }
    $this->assertSame(count($extensions), count(array_unique($extensions)), 'No duplicate extensions');

    // The raw fields stay off the Topic displays (the Source box renders
    // them in 22-20).
    $repository = $this->container->get('entity_display.repository');
    foreach (['default', 'search_index'] as $view_mode) {
      $display = $repository->getViewDisplay('node', 'topic', $view_mode);
      foreach (array_keys($expected_types) as $field_name) {
        $this->assertNull($display->getComponent($field_name), "$field_name is not shown in the $view_mode view display");
      }
    }
    $form_display = $repository->getFormDisplay('node', 'topic', 'default');
    foreach (array_keys($expected_types) as $field_name) {
      $this->assertNull($form_display->getComponent($field_name), "$field_name is not on the Topic form");
    }
  }

  /**
   * One PDF: form, batch, Topic in the group, tracked not indexed, report.
   */
  public function testOneFileEndToEnd(): void {
    $server = $this->createTestSearchServer();
    $this->createShippedIndex('social_posts', $server);
    $this->createShippedIndex('social_comments', $server);

    $group = $this->createFlexibleGroup('Orchard group');
    $organizer = $this->createVerifiedUser('orchard_organizer');
    $this->addManager($group, $organizer);
    $this->setCurrentUser($organizer);

    // A same-named file is already in the group's folder, so core stores the
    // upload as ..._0.pdf; the title must never come from the stored name.
    $folder = 'private://localnodes_import/' . $group->id();
    $file_system = $this->container->get('file_system');
    $file_system->prepareDirectory($folder, $file_system::CREATE_DIRECTORY);
    file_put_contents($folder . '/' . self::FIXTURE, 'earlier upload');

    // The upload, as PHP hands it to Drupal: a temporary copy of the fixture.
    $fixture = dirname(__DIR__, 2) . '/fixtures/import/files/' . self::FIXTURE;
    $this->assertFileExists($fixture);
    $upload_path = tempnam(sys_get_temp_dir(), 'lnimp');
    copy($fixture, $upload_path);
    $request = Request::create('/group/' . $group->id() . '/import/files', 'POST');
    $request->files->set('files', ['files' => [new UploadedFile($upload_path, self::FIXTURE, 'application/pdf', UPLOAD_ERR_OK, TRUE)]]);
    $request->setSession(new Session(new MockArraySessionStorage()));
    // Route attributes as the router sets them; the batch takes its source
    // URL from the current route.
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, 'localnodes_import.files');
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $this->container->get('router.route_provider')->getRouteByName('localnodes_import.files'));
    $request->attributes->set('group', $group);
    $request->attributes->set('_raw_variables', new ParameterBag(['group' => $group->id()]));
    $this->container->get('request_stack')->push($request);

    // A programmed submission runs the batch to completion, non-progressively.
    // 'files' present in the input, as in a browser POST, so the file
    // element's value callback picks up the request's uploads.
    // The rights box ticked, as the form requires (D-25b, 22-14).
    $form_state = (new FormState())->setValues(['files' => [], 'rights_confirmed' => 1]);
    $this->container->get('form_builder')->submitForm(UploadFilesForm::class, $form_state, $group);
    $this->assertSame([], array_map('strval', $form_state->getErrors()), 'The upload validates');
    // A browser submission runs the batch progressively, and BatchStorage
    // serializes $form_state. Symfony UploadedFile (an SplFileInfo) refuses
    // serialization, so the submitted form state must not hold one (found
    // by a real no-JS upload on DDEV: HTTP 500 in BatchStorage::create()).
    $this->assertIsString(serialize($form_state), 'The submitted form state can be stored with the batch');

    // One run, finished, with one item.
    $runs = $this->container->get('entity_type.manager')->getStorage('localnodes_import_run')->loadMultiple();
    $this->assertCount(1, $runs);
    /** @var \Drupal\localnodes_import\Entity\ImportRun $run */
    $run = reset($runs);
    $this->assertSame(ImportRun::STATUS_FINISHED, $run->get('status')->value);
    $this->assertSame((int) $group->id(), $run->getGroupId());
    $this->assertSame((int) $organizer->id(), (int) $run->getOwnerId());
    $items = $this->container->get('entity_type.manager')->getStorage('localnodes_import_item')->loadMultiple();
    $this->assertCount(1, $items);
    /** @var \Drupal\localnodes_import\Entity\ImportItem $item */
    $item = reset($items);
    $this->assertSame(ImportItem::OUTCOME_IMPORTED, $item->get('outcome')->value, 'Item outcome: ' . $item->get('outcome')->value . ' / ' . $item->get('reason')->value);
    $this->assertSame(self::FIXTURE, $item->get('source_name')->value);

    // One Topic in the group, with the file attached.
    $relationships = $group->getRelationships('group_node:topic');
    $this->assertCount(1, $relationships, 'One group_node:topic relationship');
    $node = reset($relationships)->getEntity();
    $this->assertInstanceOf(NodeInterface::class, $node);
    $this->assertSame((int) $node->id(), (int) $item->get('nid')->target_id);
    // D-16 (22-13): the PDF's own Title wins over the file name.
    $this->assertSame('Tool Share Rules', $node->label());
    $this->assertTrue($node->isPublished());
    $this->assertSame('group', $node->get('field_content_visibility')->value);
    $this->assertNotEmpty($node->get('field_topic_type')->target_id, 'Required topic type is set');
    $file = $node->get('field_files')->entity;
    $this->assertInstanceOf(FileInterface::class, $file);
    $this->assertSame((int) $item->get('fid')->value, (int) $file->id());
    $this->assertTrue($file->isPermanent(), 'The attached file is permanent');
    $this->assertSame($folder . '/pdf-01-tool-share-rules_0.pdf', $file->getFileUri(), 'Core renamed the stored file');

    // Tracked for social_posts but not indexed: search_api_item status 1.
    $search_item_id = 'entity:node/' . $node->id() . ':' . $node->language()->getId();
    $this->assertSame($search_item_id, $item->get('search_item_id')->value);
    $status = $this->container->get('database')->select('search_api_item', 's')
      ->fields('s', ['status'])
      ->condition('index_id', 'social_posts')
      ->condition('item_id', $search_item_id)
      ->execute()
      ->fetchField();
    $this->assertSame('1', (string) $status, 'The Topic is tracked, waiting for the background worker');

    // index_directly is on in the shipped config, yet nothing was queued
    // for post-request indexing: batch tracking deferred it. Post-request
    // indexing runs at kernel terminate, which a Kernel test never reaches,
    // so the queue itself is the evidence.
    $post_request = $this->container->get('search_api.post_request_indexing');
    $operations = (new \ReflectionProperty($post_request, 'operations'))->getValue($post_request);
    $this->assertArrayNotHasKey('social_posts', $operations, 'No post-request indexing was registered for social_posts');
    $this->assertArrayNotHasKey('social_comments', $operations, 'No post-request indexing was registered for social_comments');

    // The report shows one row, the Topic title linked.
    $controller = $this->container->get('class_resolver')->getInstanceFromDefinition(RunReportController::class);
    $build = $controller->view($group, $run);
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->assertStringContainsString('Tool Share Rules', $html);
    $this->assertStringContainsString('/node/' . $node->id(), $html);
    $this->assertStringContainsString('Imported', $html);
    $this->assertSame(1, substr_count($html, 'localnodes-import-report__row '), 'One report row');
  }

}
