<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_openrouter\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\key\Entity\Key;
use Symfony\Component\Yaml\Yaml;

/**
 * Both AI choices install with their provider, models and vector size.
 *
 * Assumption-delta invariant for ONB-07 (the site's AI choice is set once at
 * install): a default site keeps Gemini for every chat user and for
 * embeddings; an OpenRouter site (localnodes_openrouter enabled, as the
 * entrypoint does when AI_PROVIDER=openrouter) uses OpenRouter for all of
 * them with the fixed models of the 23-06 ruling, and no Gemini default is
 * left for images, speech or vision (D-21: an OpenRouter site's content must
 * not go to Gemini). The chat users are the default providers, the group
 * assistant, the citation support check and the search server's chat model.
 *
 * localnodes_platform itself can't be installed in a Kernel test (it pulls
 * in the Open Social profile). Its shipped config objects are seeded from
 * its config/install YAML (and social_ai_indexing's), and its
 * _localnodes_platform_configure_ai_defaults() is called from its .install
 * file, loaded by path. localnodes_openrouter is then enabled, its config
 * installed and localnodes_openrouter_install() called, which is what a
 * module install runs.
 *
 * Also covers the status report line (orchestrator ruling of 2026-11-08):
 * an OpenRouter model in use with no ZDR-only pin shows as a warning.
 *
 * @group localnodes_openrouter
 * @group phase23
 */
final class ProviderChoiceTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'key',
    'ai',
    'ai_assistant_api',
    'search_api',
    'ai_search',
    'ai_vdb_provider_qdrant',
    'gemini_provider',
    'ai_usage_limits',
    'ai_provider_openrouter',
  ];

  /**
   * social_ai_indexing isn't enabled here (it needs Open Social).
   *
   * @var string[]
   */
  protected static $configSchemaCheckerExclusions = [
    'social_ai_indexing.settings',
  ];

  /**
   * The chat operation types that follow the site's chat model.
   */
  private const CHAT_OPERATIONS = [
    'chat',
    'chat_with_complex_json',
    'chat_with_structured_response',
    'chat_with_tools',
  ];

  /**
   * Gemini-only operation types an OpenRouter site must not keep.
   */
  private const GEMINI_ONLY_OPERATIONS = [
    'chat_with_image_vision',
    'speech_to_text',
    'text_to_image',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'ai', 'ai_usage_limits']);
    $this->seedPlatformDefaults();
  }

  /**
   * A default site uses Gemini everywhere, with 3072-number vectors.
   */
  public function testDefaultVariantIsGemini(): void {
    $defaults = $this->config('ai.settings')->get('default_providers');
    foreach (self::CHAT_OPERATIONS as $operation) {
      $this->assertSame(['provider_id' => 'gemini', 'model_id' => 'models/gemini-3.8-flash'], $defaults[$operation], $operation);
    }
    $this->assertSame(['provider_id' => 'gemini', 'model_id' => 'models/gemini-embedding-2'], $defaults['embeddings']);

    $assistant = $this->config('ai_assistant_api.ai_assistant.group_assistant');
    $this->assertSame('gemini', $assistant->get('llm_provider'));
    $this->assertSame('models/gemini-3.8-flash', $assistant->get('llm_model'));

    $support = $this->config('social_ai_indexing.settings');
    $this->assertSame('gemini', $support->get('support_check.provider'));
    $this->assertSame('models/gemini-3.8-flash', $support->get('support_check.model'));

    $backend = $this->config('search_api.server.ai_knowledge_garden')->get('backend_config');
    $this->assertSame('gemini__models/gemini-3.8-flash', $backend['chat_model']);
    $this->assertSame('gemini__models/gemini-embedding-2', $backend['embeddings_engine']);
    $this->assertTrue($backend['embeddings_engine_configuration']['set_dimensions']);
    $this->assertSame(3072, (int) $backend['embeddings_engine_configuration']['dimensions']);

    $limits = $this->config('ai_usage_limits.settings');
    $this->assertSame(30000000, $limits->get('providers.gemini.total_token_usage'), 'The D-27 ceiling is on Gemini.');
    $this->assertNull($limits->get('providers.openrouter'));

    $this->assertFalse($this->container->get('module_handler')->moduleExists('localnodes_openrouter'));
  }

  /**
   * An OpenRouter site uses OpenRouter everywhere, with the fixed models.
   */
  public function testOpenRouterVariantIsOpenRouterEverywhere(): void {
    $this->installOpenRouter();
    $settings = $this->config('localnodes_openrouter.settings');
    // 23-06 ruling as amended 2026-11-08: mistral primary; the backup is
    // glm-5.3, the one model that passed the wider gate (orchestrator's
    // model-gate answer (B), 2026-11-08), replacing gpt-oss-120b.
    $chat = 'mistralai/mistral-small-2603';
    $backup = 'z-ai/glm-5.3';
    $embed = 'openai/text-embedding-3-large';
    $this->assertSame($chat, $settings->get('chat_model'));
    $this->assertSame($backup, $settings->get('backup_chat_model'));
    $this->assertSame($embed, $settings->get('embedding_model'));
    $this->assertSame(3072, $settings->get('vector_size'));

    $defaults = $this->config('ai.settings')->get('default_providers');
    foreach (self::CHAT_OPERATIONS as $operation) {
      $this->assertSame(['provider_id' => 'openrouter', 'model_id' => $chat], $defaults[$operation], $operation);
    }
    $this->assertSame(['provider_id' => 'openrouter', 'model_id' => $embed], $defaults['embeddings']);
    foreach (self::GEMINI_ONLY_OPERATIONS as $operation) {
      $this->assertArrayNotHasKey($operation, $defaults, "No Gemini default is left for $operation.");
    }
    foreach ($defaults as $operation => $default) {
      $this->assertNotSame('gemini', $default['provider_id'] ?? NULL, "$operation does not point at Gemini.");
    }

    $assistant = $this->config('ai_assistant_api.ai_assistant.group_assistant');
    $this->assertSame('openrouter', $assistant->get('llm_provider'));
    $this->assertSame($chat, $assistant->get('llm_model'));

    $support = $this->config('social_ai_indexing.settings');
    $this->assertSame('openrouter', $support->get('support_check.provider'));
    $this->assertSame($chat, $support->get('support_check.model'));
    $this->assertTrue($support->get('support_check.assistant'), 'Other support_check settings are kept.');

    $backend = $this->config('search_api.server.ai_knowledge_garden')->get('backend_config');
    $this->assertSame('openrouter__' . $chat, $backend['chat_model']);
    $this->assertSame('openrouter__' . $embed, $backend['embeddings_engine']);
    $this->assertTrue($backend['embeddings_engine_configuration']['set_dimensions']);
    $this->assertSame(3072, $backend['embeddings_engine_configuration']['dimensions']);
    $this->assertSame('qdrant', $backend['database'], 'Other backend settings are kept.');

    $openrouter = $this->config('ai_provider_openrouter.settings');
    $this->assertSame('openrouter_api_key', $openrouter->get('api_key'));
    $this->assertSame([$chat, $backup, $embed], $openrouter->get('enabled_models'));
    $this->assertFalse($openrouter->get('streaming'));
    $this->assertNull($openrouter->get('base_url'), 'base_url is left to settings.php (OPENROUTER_BASE_URL).');

    // D-27: the site-wide token ceiling covers OpenRouter too. Without an
    // entry, ai_usage_limits refuses every call once OpenRouter has usage.
    $limits = $this->config('ai_usage_limits.settings');
    $this->assertSame($limits->get('providers.gemini'), $limits->get('providers.openrouter'));
    $this->assertTrue($limits->get('providers.openrouter.enable_limits'));
    $this->assertSame(30000000, $limits->get('providers.openrouter.total_token_usage'));

    $key = Key::load('openrouter_api_key');
    $this->assertNotNull($key);
    $this->assertSame('env', $key->getKeyProvider()->getPluginId());
    $this->assertSame('OPENROUTER_API_KEY', $key->get('key_provider_settings')['env_variable']);
  }

  /**
   * Running the install helper again changes nothing.
   */
  public function testInstallHelperIsIdempotent(): void {
    $this->installOpenRouter();
    $names = [
      'ai.settings',
      'ai_assistant_api.ai_assistant.group_assistant',
      'social_ai_indexing.settings',
      'search_api.server.ai_knowledge_garden',
      'ai_provider_openrouter.settings',
      'ai_usage_limits.settings',
    ];
    $before = array_map(fn (string $name): array => $this->config($name)->getRawData(), $names);
    _localnodes_openrouter_configure_ai();
    $after = array_map(fn (string $name): array => $this->config($name)->getRawData(), $names);
    $this->assertSame($before, $after);
  }

  /**
   * Values an operator set before the install are left as they were.
   */
  public function testOperatorValuesAreLeftAlone(): void {
    $this->config('ai_assistant_api.ai_assistant.group_assistant')
      ->set('llm_model', 'models/operator-picked-model')
      ->save();
    $this->config('social_ai_indexing.settings')
      ->set('support_check.provider', 'anthropic')
      ->set('support_check.model', 'claude-operator')
      ->save();
    $this->config('ai_provider_openrouter.settings')
      ->set('api_key', 'operator_key')
      ->save();
    $operator_ceiling = [
      'enable_limits' => TRUE,
      'input_token_usage' => 1000,
      'output_token_usage' => 1000,
      'total_token_usage' => 1000,
      'cached_token_usage' => 1000,
      'reasoning_token_usage' => 1000,
    ];
    $this->config('ai_usage_limits.settings')->set('providers.openrouter', $operator_ceiling)->save();

    $this->installOpenRouter();

    $assistant = $this->config('ai_assistant_api.ai_assistant.group_assistant');
    $this->assertSame('models/operator-picked-model', $assistant->get('llm_model'));
    $this->assertSame('gemini', $assistant->get('llm_provider'), 'Provider and model change together or not at all.');
    $support = $this->config('social_ai_indexing.settings');
    $this->assertSame('anthropic', $support->get('support_check.provider'));
    $this->assertSame('claude-operator', $support->get('support_check.model'));
    $this->assertSame('operator_key', $this->config('ai_provider_openrouter.settings')->get('api_key'));
    $this->assertSame($operator_ceiling, $this->config('ai_usage_limits.settings')->get('providers.openrouter'));
  }

  /**
   * The status report warns about an OpenRouter model with no pin.
   */
  public function testStatusReportWarnsAboutUnpinnedModel(): void {
    // The status report page loads install.inc (REQUIREMENT_* constants).
    require_once DRUPAL_ROOT . '/core/includes/install.inc';
    $this->installOpenRouter();

    $requirement = $this->pinRequirement();
    $this->assertSame(REQUIREMENT_OK, $requirement['severity'], 'Every fixed model is pinned.');

    $this->config('ai_assistant_api.ai_assistant.group_assistant')
      ->set('llm_model', 'example/unpinned-model-7b')
      ->save();
    $requirement = $this->pinRequirement();
    $this->assertSame(REQUIREMENT_WARNING, $requirement['severity']);
    $this->assertStringContainsString('example/unpinned-model-7b', (string) $requirement['description']);

    // A pin entry with nothing in it counts as no pin.
    $pins = $this->config('localnodes_openrouter.settings')->get('provider_pins');
    $pins[] = ['model' => 'example/unpinned-model-7b', 'only' => [], 'ignore' => []];
    $this->config('localnodes_openrouter.settings')->set('provider_pins', $pins)->save();
    $this->assertSame(REQUIREMENT_WARNING, $this->pinRequirement()['severity']);

    // Only the runtime phase reports.
    $this->assertSame([], localnodes_openrouter_requirements('install'));
  }

  /**
   * Returns the pin line of localnodes_openrouter's runtime requirements.
   *
   * @return array<string, mixed>
   *   The requirement.
   */
  private function pinRequirement(): array {
    $requirements = localnodes_openrouter_requirements('runtime');
    $this->assertArrayHasKey('localnodes_openrouter_pins', $requirements);
    return $requirements['localnodes_openrouter_pins'];
  }

  /**
   * Seeds the AI config a default LocalNodes site ships with.
   */
  private function seedPlatformDefaults(): void {
    $list = $this->container->get('extension.list.module');
    $platform = DRUPAL_ROOT . '/' . $list->getPath('localnodes_platform');
    $indexing = DRUPAL_ROOT . '/' . $list->getPath('social_ai_indexing');
    $seed = [
      'ai_assistant_api.ai_assistant.group_assistant' => "$platform/config/install/ai_assistant_api.ai_assistant.group_assistant.yml",
      'search_api.server.ai_knowledge_garden' => "$platform/config/install/search_api.server.ai_knowledge_garden.yml",
      'social_ai_indexing.settings' => "$indexing/config/install/social_ai_indexing.settings.yml",
    ];
    foreach ($seed as $name => $file) {
      $this->config($name)->setData(Yaml::parseFile($file))->save();
    }
    require_once "$platform/localnodes_platform.install";
    _localnodes_platform_configure_ai_defaults();
    _localnodes_platform_configure_ai_usage_limits();
  }

  /**
   * Installs localnodes_openrouter the way a module install does.
   */
  private function installOpenRouter(): void {
    $this->enableModules(['localnodes_openrouter']);
    $this->installConfig(['localnodes_openrouter']);
    require_once DRUPAL_ROOT . '/' . $this->container->get('extension.list.module')->getPath('localnodes_openrouter') . '/localnodes_openrouter.install';
    localnodes_openrouter_install();
  }

}
