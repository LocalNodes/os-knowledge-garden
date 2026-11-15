<?php

namespace Drupal\localnodes_demo_packs\Plugin\DemoContent;

use Drupal\localnodes_platform\SiteGuide;

/**
 * Pack how-to pages: the member guides every pack site shows.
 *
 * Each page is a Topic of the "Site guide" type, owned by a site account of
 * its own instead of a member, and visible to signed-in members only. The
 * assistant answers "how do I" questions from these pages.
 *
 * @DemoContent(
 *   id = "pack_howto",
 *   label = @Translation("Pack how-to pages"),
 *   source = "content/entity/howto.yml",
 *   entity_type = "node"
 * )
 */
class PackHowto extends PackTopic {

  /**
   * The site guide account that owns every how-to page.
   */
  const GUIDE_ACCOUNT_UUID = 'aab4c2a4-55c4-46b4-bca9-b3e2b05f58b0';

  /**
   * The "Site guide" topic type, created when the module is installed.
   *
   * The platform owns the id, because the rules about who edits a guide page
   * live there and outlast this module.
   */
  const GUIDE_TERM_UUID = SiteGuide::TERM_UUID;

  /**
   * {@inheritdoc}
   *
   * The how-to pages are the same on every pack site, so they live once in
   * the module's shared directory instead of in each pack.
   */
  protected function packProfile(): string {
    return 'shared';
  }

  /**
   * {@inheritdoc}
   *
   * The file holds each page's uuid, title, date and body. What makes it a
   * guide page is stamped here, so no page can come out owned by a member,
   * in a group or visible to anyone but members. DemoNode reads the owner
   * and the group from the item before it builds the entry, so this is the
   * place to set them.
   */
  protected function fetchData() {
    $data = parent::fetchData();
    foreach ($data as $uuid => $item) {
      if (!is_array($item)) {
        continue;
      }
      unset($item['group']);
      $item['type'] = 'topic';
      $item['langcode'] = 'en';
      $item['uid'] = self::GUIDE_ACCOUNT_UUID;
      $item['field_content_visibility'] = 'community';
      $data[$uuid] = $item;
    }
    $this->data = $data;
    return $this->data;
  }

  /**
   * {@inheritdoc}
   */
  public function createContent($generate = FALSE, $max = NULL) {
    $this->ensureGuideAccount();
    return parent::createContent($generate, $max);
  }

  /**
   * {@inheritdoc}
   */
  protected function getEntry(array $item) {
    $entry = parent::getEntry($item);
    $terms = $this->termStorage->loadByProperties(['uuid' => self::GUIDE_TERM_UUID]);
    if (!$terms) {
      throw new \RuntimeException('The Site guide topic type is missing; enable localnodes_demo_packs on a fresh site.');
    }
    $entry['field_topic_type'] = [['target_id' => current($terms)->id()]];
    return $entry;
  }

  /**
   * Creates the guide account when the site does not have it yet.
   *
   * An ordinary active member account with the authenticated role only and a
   * random password that is never shown or kept anywhere; nobody signs in as
   * it. Its address is on a reserved domain, so no mail reaches anyone.
   */
  protected function ensureGuideAccount(): void {
    $manager = \Drupal::entityTypeManager();
    $users = $manager->getStorage('user');
    if ($users->loadByProperties(['uuid' => self::GUIDE_ACCOUNT_UUID])) {
      return;
    }
    $account = $users->create([
      'uuid' => self::GUIDE_ACCOUNT_UUID,
      'name' => 'site_guide',
      'mail' => 'site-guide@guide.example',
      'init' => 'site-guide@guide.example',
      'status' => 1,
      'pass' => \Drupal::service('password_generator')->generate(32),
    ]);
    $account->enforceIsNew();
    $account->save();

    // The profile is created with the account.
    $profiles = $manager->getStorage('profile')->loadByProperties([
      'uid' => $account->id(),
      'type' => 'profile',
    ]);
    if ($profile = array_pop($profiles)) {
      $profile->set('field_profile_first_name', 'Site');
      $profile->set('field_profile_last_name', 'guide');
      $profile->save();
    }
  }

}
