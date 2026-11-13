<?php

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\localnodes_platform\Mail\NoticeMailTemplates;
use Symfony\Component\Yaml\Yaml;

/**
 * Email policy persists at install and after optional modules arrive.
 *
 * @group localnodes_platform
 */
final class NoticeMailTemplatesTest extends KernelTestBase {

  protected static $modules = ['system', 'user', 'language', 'localnodes_platform'];

  protected $strictConfigSchema = FALSE;

  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'language']);
    $this->container->get('module_handler')->loadInclude('localnodes_platform', 'install');
  }

  public function testExistingTemplatesAndIdempotence(): void {
    $before = $this->seedTemplates();
    NoticeMailTemplates::configure();
    foreach ($before as $id => $data) {
      $this->assertSame(NoticeMailTemplates::replace($id, $data), $this->config('message.template.' . $id)->getRawData());
    }
    $saved = new \ArrayObject();
    $this->container->get('event_dispatcher')->addListener(ConfigEvents::SAVE, static function (ConfigCrudEvent $event) use ($saved): void {
      $saved[] = $event->getConfig()->getName();
    });
    NoticeMailTemplates::configure();
    $this->assertSame([], $saved->getArrayCopy());
  }

  public function testOptionalTemplateAppearingLater(): void {
    NoticeMailTemplates::configure();
    $name = 'message.template.create_mention_post';
    $this->assertTrue($this->config($name)->isNew());
    $this->seedTemplates();
    localnodes_platform_modules_installed(['social_mentions'], FALSE);
    $this->assertSame(NoticeMailTemplates::replacements()['create_mention_post']['body'], $this->config($name)->get('text.2.value'));
  }

  public function testLanguageOverrideCannotRestoreThePreview(): void {
    $this->seedTemplates();
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $manager = $this->container->get('language_manager');
    $name = 'message.template.create_mention_post';
    $override = $manager->getLanguageConfigOverride('fr', $name);
    $before = [
      'text' => [
        ['value' => 'French bell [message:preview]', 'format' => 'full_html'],
        ['value' => 'French stream [message:preview]', 'format' => 'full_html'],
        ['value' => 'French mail [message:preview]', 'format' => 'full_html'],
      ],
      'third_party_settings' => ['activity_logger' => ['email_subject' => '[message:node-title]']],
    ];
    $override->setData($before)->save();
    NoticeMailTemplates::configure();
    $this->assertSame(NoticeMailTemplates::replace('create_mention_post', $before), $manager->getLanguageConfigOverride('fr', $name)->getRawData());
  }

  public function testInstallCallsTheSameHelper(): void {
    $function = new \ReflectionFunction('localnodes_platform_install');
    $body = implode('', array_slice(file($function->getFileName()), $function->getStartLine() - 1, $function->getEndLine() - $function->getStartLine() + 1));
    $this->assertStringContainsString('\\Drupal\\localnodes_platform\\Mail\\NoticeMailTemplates::configure();', $body);
  }

  private function seedTemplates(): array {
    $before = [];
    $root = DRUPAL_ROOT . '/profiles/contrib/social';
    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
      if (!preg_match('~/config/(install|optional)/message\.template\.[^/]+\.yml$~', $file->getPathname())) {
        continue;
      }
      $data = Yaml::parseFile($file->getPathname());
      if (!isset(NoticeMailTemplates::replacements()[$data['template']])) {
        continue;
      }
      unset($data['_core']);
      $this->config('message.template.' . $data['template'])->setData($data)->save();
      $before[$data['template']] = $data;
    }
    $this->assertCount(count(NoticeMailTemplates::replacements()), $before);
    return $before;
  }

}
