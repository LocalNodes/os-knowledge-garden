<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_import\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\localnodes_import\Entity\ImportRun;
use Drupal\localnodes_import\Form\UploadFilesForm;
use Drupal\taxonomy\Entity\Term;
use Drupal\Tests\localnodes_import\Kernel\Support\UploadRequestTrait;
use Drupal\user\UserInterface;

/**
 * Only the uploader's own temporary, bound files reach a run (T-22-36).
 *
 * Also the 22-07 follow-up run fields for D-25b and D-26.
 *
 * @group phase22
 * @group localnodes_import
 */
final class UploadFidBindingTest extends ImportKernelTestBase {

  use UploadRequestTrait;

  /**
   * A fid outside the tempstore list, another user's fid and a permanent fid
   * create no item; the one bound, owned, temporary fid does.
   */
  public function testSubmitAcceptsOnlyBoundFids(): void {
    $group = $this->createFlexibleGroup('Orchard group');
    $manager = $this->createVerifiedUser('orchard_organizer');
    $this->addManager($group, $manager);
    $other = $this->createVerifiedUser('pond_organizer');
    $this->addManager($group, $other);

    $bound = $this->storedFile($group->id(), 'bound.txt', $manager, FALSE);
    $unbound = $this->storedFile($group->id(), 'unbound.txt', $manager, FALSE);
    $someone_elses = $this->storedFile($group->id(), 'theirs.txt', $other, FALSE);
    $permanent = $this->storedFile($group->id(), 'permanent.txt', $manager, TRUE);

    $this->setCurrentUser($manager);
    // The server-side list: the attacker can't add to it, but a stale or
    // foreign entry must still be refused on ownership and status.
    $this->container->get('tempstore.private')->get('localnodes_import')->set('uploads:' . $group->id(), [
      (int) $bound->id() => 'Bound notes.txt',
      (int) $someone_elses->id() => 'theirs.txt',
      (int) $permanent->id() => 'permanent.txt',
    ]);

    $this->routedRequest('localnodes_import.files', $group);
    $form_state = (new FormState())->setValues([
      'fids' => implode(',', [$bound->id(), $unbound->id(), $someone_elses->id(), $permanent->id()]),
      'rights_confirmed' => 1,
    ]);
    $this->container->get('form_builder')->submitForm(UploadFilesForm::class, $form_state, $group);
    $this->assertSame([], array_map('strval', $form_state->getErrors()), 'The submission validates');

    $items = $this->container->get('entity_type.manager')->getStorage('localnodes_import_item')->loadMultiple();
    $this->assertCount(1, $items, 'Only the bound, owned, temporary file became an item');
    $item = reset($items);
    $this->assertSame((int) $bound->id(), (int) $item->get('fid')->value);
    $this->assertSame('Bound notes.txt', $item->get('source_name')->value, 'The item keeps the uploaded name');

    $file_storage = $this->container->get('entity_type.manager')->getStorage('file');
    $file_storage->resetCache();
    $this->assertTrue($file_storage->load($someone_elses->id())->isTemporary(), "Another user's file is untouched");
    $this->assertSame((int) $other->id(), (int) $file_storage->load($someone_elses->id())->getOwnerId());

    $left = $this->container->get('tempstore.private')->get('localnodes_import')->get('uploads:' . $group->id());
    $this->assertArrayNotHasKey((int) $bound->id(), $left ?? [], 'A consumed fid is dropped from the list');
  }

  /**
   * The run records who confirmed the right to share, when, and the page type.
   */
  public function testRunRightsAndPageTypeFields(): void {
    $group = $this->createFlexibleGroup('Orchard group');
    $manager = $this->createVerifiedUser('orchard_organizer');
    $this->addManager($group, $manager);
    $term = Term::create(['vid' => 'topic_types', 'name' => 'Minutes']);
    $term->save();

    $storage = $this->container->get('entity_type.manager')->getStorage('localnodes_import_run');
    $definitions = $this->container->get('entity_field.manager')->getBaseFieldDefinitions('localnodes_import_run');
    foreach (['rights_confirmed_by', 'rights_confirmed_at', 'topic_type'] as $field_name) {
      $this->assertArrayHasKey($field_name, $definitions, "ImportRun has $field_name");
    }
    $this->assertSame('user', $definitions['rights_confirmed_by']->getSetting('target_type'));
    $this->assertSame('timestamp', $definitions['rights_confirmed_at']->getType());
    $this->assertSame('taxonomy_term', $definitions['topic_type']->getSetting('target_type'));
    $this->assertSame(['topic_types' => 'topic_types'], $definitions['topic_type']->getSetting('handler_settings')['target_bundles'] ?? NULL);
    $this->assertSame(255, (int) $definitions['license']->getSetting('max_length'), 'The run licence is 255 characters wide');
    $this->assertSame('Licence', (string) $definitions['license']->getLabel());

    $run = $storage->create([
      'gid' => $group->id(),
      'uid' => $manager->id(),
      'kind' => ImportRun::KIND_FILES,
      'rights_confirmed_by' => $manager->id(),
      'rights_confirmed_at' => 1767225600,
      'topic_type' => $term->id(),
    ]);
    $run->save();
    $storage->resetCache();
    $run = $storage->load($run->id());
    $this->assertSame((int) $manager->id(), (int) $run->get('rights_confirmed_by')->target_id);
    $this->assertSame(1767225600, (int) $run->get('rights_confirmed_at')->value);
    $this->assertSame((int) $term->id(), (int) $run->get('topic_type')->target_id);
    $this->assertEmpty($run->get('license')->value, "A new run's licence is empty, not a list key");

    $long = str_repeat('CC BY 4.0 ', 25) . 'abcde';
    $this->assertSame(255, mb_strlen($long));
    $run->set('license', $long)->save();
    $storage->resetCache();
    $this->assertSame($long, $storage->load($run->id())->get('license')->value, 'A 255-character licence reads back intact');
  }

  /**
   * A real file in the group's private folder.
   */
  private function storedFile(int|string $gid, string $name, UserInterface $owner, bool $permanent): FileInterface {
    $folder = 'private://localnodes_import/' . $gid;
    $file_system = $this->container->get('file_system');
    $file_system->prepareDirectory($folder, $file_system::CREATE_DIRECTORY);
    $uri = $folder . '/' . $name;
    file_put_contents($uri, "Notes from the orchard meeting about $name.");
    $file = File::create(['uri' => $uri, 'filename' => $name, 'uid' => $owner->id()]);
    $permanent ? $file->setPermanent() : $file->setTemporary();
    $file->save();
    return $file;
  }

}
