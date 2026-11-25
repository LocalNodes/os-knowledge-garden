<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Plugin\Field\FieldFormatter;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\localnodes_platform\Access\DepartedAuthorAccess;
use Drupal\social_post\Plugin\Field\FieldFormatter\CommentPostFormatter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** Removes write controls while keeping the actual post thread formatter. */
final class RetainedPostCommentFormatter extends CommentPostFormatter {

  protected DepartedAuthorAccess $departedAuthors;

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $formatter = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $formatter->departedAuthors = $container->get('localnodes_platform.departed_author_access');
    return $formatter;
  }

  public function viewElements(FieldItemListInterface $items, $langcode) {
    $output = parent::viewElements($items, $langcode);
    if ($this->departedAuthors->deniesParticipation($items->getEntity(), $this->currentUser)) {
      $output[0]['comment_form'] = [];
    }
    $this->departedAuthors->accessMetadata($items->getEntity(), $this->currentUser)->merge(CacheableMetadata::createFromRenderArray($output))->applyTo($output);
    return $output;
  }

}
