<?php

namespace Drupal\localnodes_platform\EventSubscriber;

use Drupal\Core\Config\ConfigCollectionEvents;
use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\localnodes_platform\Mail\NoticeMailTemplates;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Keeps later locale imports from restoring content-bearing email previews.
 */
final class NoticeMailConfigSubscriber implements EventSubscriberInterface {

  public static function getSubscribedEvents(): array {
    return [
      ConfigEvents::SAVE => 'onSave',
      ConfigCollectionEvents::SAVE_IN_COLLECTION => 'onSave',
    ];
  }

  public function onSave(ConfigCrudEvent $event): void {
    $config = $event->getConfig();
    $prefix = 'message.template.';
    if (!str_starts_with($config->getName(), $prefix)) {
      return;
    }
    $id = substr($config->getName(), strlen($prefix));
    if (!isset(NoticeMailTemplates::replacements()[$id])) {
      return;
    }
    $language = str_starts_with($config->getStorage()->getCollectionName(), 'language.');
    $before = $config->getRawData();
    $after = NoticeMailTemplates::replace($id, $before, $language);
    // The nested save dispatches once more, then finds identical data.
    if ($after !== $before) {
      $config->setData($after)->save();
    }
  }

}
