<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The community site's footer line.
 *
 * Tom (2026-11-06, card 11): "‹community› · a community on LocalNodes · Open
 * source", with the small node mark before "LocalNodes" and "Open source"
 * linking to the code. Community-first, so communities are less likely to
 * remove it on reflex. It replaces Open Social's "#MadeToShare by Open Social"
 * block (social_footer's socialblue_footer_powered), which
 * _localnodes_platform_configure_branding() switches off.
 *
 * "Site terms" follows "Open source", linking to /terms (23-26, ONB-05;
 * 23-UI-SPEC section 9 and "Footers (T-5)").
 *
 * "Hacked or phished?" follows "Site terms", linking to the help page at
 * /help/hacked-or-phished (22.1-15, Tom's ruling of 2026-11-09).
 *
 * The community's name is read from system.site at render time, so renaming
 * the site changes the footer without a config edit. The cache tag
 * config:system.site invalidates the rendered block on a rename.
 *
 * @Block(
 *   id = "localnodes_footer",
 *   admin_label = @Translation("LocalNodes footer")
 * )
 */
final class LocalNodesFooterBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Where "Open source" points unless the block is configured otherwise.
   */
  public const DEFAULT_REPOSITORY_URL = 'https://github.com/LocalNodes/os-knowledge-garden';

  /**
   * Creates the block.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin id.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Extension\ModuleExtensionList $moduleList
   *   The module extension list.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $fileUrlGenerator
   *   The file URL generator.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ModuleExtensionList $moduleList,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
      $container->get('extension.list.module'),
      $container->get('file_url_generator'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return ['repository_url' => self::DEFAULT_REPOSITORY_URL];
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    // The link is a plain http(s) address or the default; a stored value with
    // any other scheme (javascript:, data:) is not used.
    $url = (string) ($this->configuration['repository_url'] ?? '');
    if (!preg_match('#^https?://#i', $url)) {
      $url = self::DEFAULT_REPOSITORY_URL;
    }
    $mark = $this->moduleList->getPath('localnodes_platform') . '/images/localnodes-mark-reversed.svg';
    $terms = Url::fromRoute('localnodes_platform.terms')->toString(TRUE);
    $help = Url::fromRoute('localnodes_platform.safety_help')->toString(TRUE);
    $build = [
      '#theme' => 'localnodes_footer',
      '#site_name' => (string) $this->configFactory->get('system.site')->get('name'),
      '#repository_url' => $url,
      '#mark_url' => $this->fileUrlGenerator->generateString($mark),
      '#terms_url' => $terms->getGeneratedUrl(),
      '#help_url' => $help->getGeneratedUrl(),
      '#cache' => ['tags' => ['config:system.site']],
    ];
    CacheableMetadata::createFromRenderArray($build)->merge(CacheableMetadata::createFromObject($terms))->merge(CacheableMetadata::createFromObject($help))->applyTo($build);
    return $build;
  }

}
