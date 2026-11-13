<?php

namespace Drupal\Tests\localnodes_platform\Unit;

use Drupal\activity_creator\Plugin\ActivityDestinationManager;
use Drupal\activity_creator\ActivityFactory;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Utility\Token;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Config\MemoryStorage;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\language\Config\LanguageConfigOverride;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Drupal\message\Entity\Message;
use Drupal\message\Entity\MessageTemplate;
use Symfony\Component\Yaml\Yaml;
use Drupal\localnodes_platform\Mail\NoticeMailTemplates;
use Drupal\localnodes_platform\EventSubscriber\NoticeMailConfigSubscriber;
use Drupal\Tests\UnitTestCase;

/**
 * Notice mail never inherits a preview from its bell paragraphs.
 *
 * @group localnodes_platform
 */
final class NoticeMailTemplatesTest extends UnitTestCase {

  /**
   * Existing bell paragraphs, text formats and unrelated settings survive.
   */
  public function testOnlyEmailFieldsChange(): void {
    foreach (NoticeMailTemplates::replacements() as $id => $replacement) {
      $before = $this->template();
      $after = NoticeMailTemplates::replace($id, $before);
      $this->assertSame($before['text'][0], $after['text'][0]);
      $this->assertSame($before['text'][1], $after['text'][1]);
      $this->assertSame($before['text'][2]['format'], $after['text'][2]['format']);
      $this->assertSame($replacement['body'], $after['text'][2]['value']);
      $this->assertSame($replacement['subject'], $after['third_party_settings']['activity_logger']['email_subject']);
      $this->assertSame($before['third_party_settings']['activity_logger']['activity_destinations'], $after['third_party_settings']['activity_logger']['activity_destinations']);
      $this->assertSame($after, NoticeMailTemplates::replace($id, $after));
    }
  }

  /**
   * A template with only a bell gets an explicit email paragraph at delta 2.
   */
  public function testMissingEmailParagraphIsAdded(): void {
    $before = $this->template();
    unset($before['text'][1], $before['text'][2]);
    $after = NoticeMailTemplates::replace('create_mention_post', $before);
    $this->assertSame($before['text'][0], $after['text'][0]);
    $this->assertArrayNotHasKey(1, $after['text']);
    $this->assertNotEmpty($after['text'][2]['value']);
    $this->assertSame('basic_html', $after['text'][2]['format']);
  }

  /**
   * An unrelated template is left completely unchanged.
   */
  public function testUnclassifiedTemplateIsUntouched(): void {
    $this->assertSame($this->template(), NoticeMailTemplates::replace('an_invitation', $this->template()));
  }

  /**
   * Real config writes also cover late installs and existing language data.
   */
  public function testConfigureWritesExistingConfigAndLanguageOverrides(): void {
    $storage = new MemoryStorage();
    $events = new EventDispatcher();
    $typed = $this->createMock(TypedConfigManagerInterface::class);
    $factory = new ConfigFactory($storage, $events, $typed);
    $manager = $this->createMock(ConfigurableLanguageManagerInterface::class);
    $manager->method('getLanguageConfigOverride')->willReturnCallback(static function ($langcode, $name) use ($storage, $typed, $events) {
      $collection = $storage->createCollection('language.' . $langcode);
      $override = new LanguageConfigOverride($name, $collection, $typed, $events);
      if ($data = $collection->read($name)) {
        $override->initWithData($data);
      }
      return $override;
    });
    $container = new ContainerBuilder();
    $container->set('config.factory', $factory);
    $container->set('config.storage', $storage);
    $container->set('language_manager', $manager);
    $container->set('cache_tags.invalidator', $this->createMock(CacheTagsInvalidatorInterface::class));
    \Drupal::setContainer($container);
    NoticeMailTemplates::configure();
    $this->assertSame([], $storage->listAll());
    $name = 'message.template.create_mention_post';
    $before = $this->template();
    $factory->getEditable($name)->setData($before)->save();
    $french = $storage->createCollection('language.fr');
    $french->write($name, $before);
    $french->write('message.template.an_invitation', $before);
    $saved = [];
    $events->addListener('config.save', static function ($event) use (&$saved): void {
      $saved[] = $event->getConfig()->getName();
    });
    NoticeMailTemplates::configure();
    $expected = NoticeMailTemplates::replace('create_mention_post', $before);
    $this->assertSame($expected, $storage->read($name));
    $this->assertSame($expected, $french->read($name));
    $this->assertSame($before, $french->read('message.template.an_invitation'));
    $this->assertSame([$name], $storage->listAll());
    $this->assertSame([$name], $saved);
    NoticeMailTemplates::configure();
    $this->assertSame([$name], $saved, 'An unchanged template is not saved twice.');
    // A later catalog import uses the same configuration save events.
    $events->addSubscriber(new NoticeMailConfigSubscriber());
    foreach (['ar', 'ca', 'de', 'es', 'eu', 'fr', 'gl', 'it', 'ja', 'nl', 'pt_BR', 'uk', 'zh_Hans'] as $langcode) {
      $translated = $before;
      $translated['third_party_settings']['activity_logger']['email_subject'] = 'Safe translated subject ' . $langcode;
      $override = $manager->getLanguageConfigOverride($langcode, $name);
      $override->setData($translated)->save();
      $after_import = $storage->createCollection('language.' . $langcode)->read($name);
      $this->assertSame($expected['text'][2], $after_import['text'][2]);
      $this->assertSame($translated['text'][0], $after_import['text'][0]);
      $this->assertSame($translated['text'][1], $after_import['text'][1]);
      $this->assertSame($translated['third_party_settings'], $after_import['third_party_settings']);
    }
    $partial = ['third_party_settings' => ['activity_logger' => ['email_subject' => 'Safe subject only']]];
    $override->setData($partial)->save();
    $this->assertSame($partial, $storage->createCollection('language.zh_Hans')->read($name), 'A subject-only override must not shadow both translated bell paragraphs with a delta-2-only array.');
    $factory->getEditable($name)->setData($before)->save();
    $this->assertSame($expected, $storage->read($name), 'Default-language locale writes are protected too.');

  }

  public function testBracketedStaticSubjectIsPreserved(): void {
    $before = $this->template();
    $before['third_party_settings']['activity_logger']['email_subject'] = '[Notifications] A safe fixed subject';
    $after = NoticeMailTemplates::replace('create_mention_post', $before);
    $this->assertSame($before['third_party_settings']['activity_logger']['email_subject'], $after['third_party_settings']['activity_logger']['email_subject']);
  }

  public function testExactApprovedTextsAndGroupedContexts(): void {
    $fixture = json_decode(file_get_contents(dirname(__DIR__, 2) . '/fixtures/notice-mail-texts.json'), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertSame($fixture, NoticeMailTemplates::replacements());
    $grouped = [];
    foreach ($fixture as $id => $replacement) {
      if (str_contains($replacement['body'], '[message:gtitle]')) {
        $grouped[] = $id;
      }
    }
    $this->assertSame(['create_content_in_joined_group', 'join_to_group', 'approve_request_join_group', 'request_to_join_a_group'], $grouped);
  }

  /**
   * Every shipped template targeting email must have an explicit policy.
   */
  public function testAllShippedEmailTemplatesAreClassified(): void {
    $email_ids = [];
    foreach ($this->shippedTemplates() as $data) {
      if (!empty($data['third_party_settings']['activity_logger']['activity_destinations']['email'])) {
        $email_ids[] = $data['template'];
      }
    }
    sort($email_ids);
    $classified = array_keys(NoticeMailTemplates::replacements());
    sort($classified);
    $this->assertCount(23, $email_ids);
    $this->assertSame($email_ids, $classified, 'A new email template needs an explicit content policy.');
  }

  /**
   * Actual activity rendering retains identities and rejects content tokens.
   *
   * The token boundary is supplied with canaries so each content-bearing
   * substitution is observable, including a subject-only task token.
   *
   * @dataProvider replacementIds
   */
  public function testRenderedEmailHasNoMemberContent(string $id, string $langcode): void {
    $templates = $this->shippedTemplates();
    $before = $templates[$id];
    if ($langcode !== 'en') {
      $before['third_party_settings']['activity_logger']['email_subject'] = $langcode . ': ' . $before['third_party_settings']['activity_logger']['email_subject'];
    }
    $tokens = [
      '[message:author:display-name]' => 'ActorName730',
      '[message:revision_author:display-name]' => 'ActorName730',
      '[message:gtitle]' => 'GroupName642',
      '[message:gurl]' => 'https://site.test/group/5',
      '[message:cta_button]' => '<a href="https://site.test/notifications">Open notice</a>',
      '[site:base-url]' => 'https://site.test/community',
      '[message:recipient-user:display-name]' => 'ActorName730',
      '[social_event:enrolled_user]' => 'ActorName730',
      '[site:name]' => 'CommunityName912',
    ];
    $canaries = ['PostWords329', 'CommentWords631', 'DocTitle297', 'DocBody384', 'ProfileWords478', 'TaskSubject817', 'PrivateMessage246', 'EventLocation712'];
    foreach ($templates as $template) {
      preg_match_all('/\[[^\[\]]+\]/', json_encode($template), $matches);
      foreach ($matches[0] as $token) {
        $tokens[$token] ??= implode(' ', $canaries);
      }
    }
    $render = function (array $data) use ($tokens, $langcode): array {
      $template = $this->getMockBuilder(MessageTemplate::class)->disableOriginalConstructor()->onlyMethods(['get', 'getSetting', 'getThirdPartySettings'])->getMock();
      $template->method('get')->with('text')->willReturn($langcode === 'en' ? $data['text'] : [['value' => 'Wrong base-language fallback']]);
      $template->method('getSetting')->willReturn(['token replace' => TRUE, 'clear' => FALSE]);
      $template->method('getThirdPartySettings')->with('activity_logger')->willReturn($data['third_party_settings']['activity_logger']);
      $message = $this->getMockBuilder(Message::class)->disableOriginalConstructor()->onlyMethods(['getTemplate', 'getArguments'])->getMock();
      $message->method('getTemplate')->willReturn($template);
      $message->method('getArguments')->willReturn([]);
      $token_service = $this->createMock(Token::class);
      $token_service->method('replace')->willReturnCallback(static fn($text) => strtr($text, $tokens));
      $override = new LanguageConfigOverride('message.template.notice', new MemoryStorage('language.' . $langcode), $this->createMock(TypedConfigManagerInterface::class), new EventDispatcher());
      $override->initWithData($data);
      $language_manager = $this->createMock(ConfigurableLanguageManagerInterface::class);
      $language_manager->method('getLanguageConfigOverride')->willReturn($override);
      $factory = new ActivityFactory(
        $this->createMock(ActivityDestinationManager::class),
        $this->createMock(EntityTypeManagerInterface::class),
        $this->createMock(Connection::class),
        $language_manager,
        $token_service,
        $this->createMock(ModuleHandlerInterface::class),
      );
      return [$factory->getMessageText($message, $langcode), $factory->getMessageSubject($message, $langcode)];
    };
    [$old] = $render($before);
    [$text, $subject] = $render(NoticeMailTemplates::replace($id, $before));
    $this->assertSame($old[0], $text[0]);
    if (isset($old[1])) {
      $this->assertSame($old[1], $text[1]);
    }
    $email = $text[2] . ($subject['#markup'] ?? '');
    foreach ($canaries as $canary) {
      $this->assertStringNotContainsString($canary, $email);
    }
    $this->assertDoesNotMatchRegularExpression('/\[[^\[\]]+:[^\]]+\]/', $email, 'No unresolved tokens in the email.');
    // Automated background tasks have no member actor.
    if (!in_array($id, ['background_process_finished', 'user_was_enrolled_to_event', 'approve_request_join_group'], TRUE)) {
      $this->assertStringContainsString('ActorName730', $text[2]);
    }
    if (str_contains(NoticeMailTemplates::replacements()[$id]['body'], '[message:gtitle]')) {
      $this->assertStringContainsString('GroupName642', $text[2]);
    }
    $this->assertSame(1, substr_count($text[2], '<a '), 'One action link.');
    $this->assertStringContainsString('<a href="https://site.test/community/notifications">Open your notifications.</a>', $text[2]);
    $this->assertSame($before['third_party_settings']['activity_logger']['email_subject'], $subject['#markup']);
  }

  public function replacementIds(): array {
    $cases = [];
    foreach (array_keys(NoticeMailTemplates::replacements()) as $id) {
      foreach (['en', 'ar', 'ca', 'de', 'es', 'eu', 'fr', 'gl', 'it', 'ja', 'nl', 'pt_BR', 'uk', 'zh_Hans'] as $langcode) {
        $cases[$id . ':' . $langcode] = [$id, $langcode];
      }
    }
    return $cases;
  }

  private function shippedTemplates(): array {
    $templates = [];
    foreach (['profiles/contrib/social', 'modules/custom'] as $root) {
      $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root . '/' . $root, \FilesystemIterator::SKIP_DOTS));
      foreach ($iterator as $file) {
        $path = $file->getPathname();
        if (!preg_match('~/config/(install|optional)/message\.template\.[^/]+\.yml$~', $path)) {
          continue;
        }
        $data = Yaml::parseFile($path);
        $templates[$data['template']] = $data;
      }
    }
    return $templates;
  }

  private function template(): array {
    return [
      'text' => [
        ['value' => '<p>Original bell [message:preview]</p>', 'format' => 'full_html'],
        ['value' => '<p>Original stream [message:node-title]</p>', 'format' => 'basic_html'],
        ['value' => '<p>Unsafe email [message:preview]</p>', 'format' => 'full_html'],
      ],
      'third_party_settings' => ['activity_logger' => [
        'email_subject' => '[queue_storage_entity:mail_task_subject]',
        'activity_destinations' => ['email' => 'email', 'notifications' => 'notifications'],
      ]],
    ];
  }

}
