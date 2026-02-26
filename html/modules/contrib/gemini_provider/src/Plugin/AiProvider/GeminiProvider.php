<?php

declare(strict_types=1);

namespace Drupal\gemini_provider\Plugin\AiProvider;

use Drupal\ai\Attribute\AiProvider;
use Drupal\ai\Base\AiProviderClientBase;
use Drupal\ai\Dto\TokenUsageDto;
use Drupal\ai\Exception\AiResponseErrorException;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatInterface;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai\OperationType\Chat\Tools\ToolsFunctionOutput;
use Drupal\ai\OperationType\Embeddings\EmbeddingsInput;
use Drupal\ai\OperationType\Embeddings\EmbeddingsInterface;
use Drupal\ai\OperationType\Embeddings\EmbeddingsOutput;
use Drupal\ai\OperationType\GenericType\ImageFile;
use Drupal\ai\OperationType\TextToImage\TextToImageInput;
use Drupal\ai\OperationType\TextToImage\TextToImageInterface;
use Drupal\ai\OperationType\TextToImage\TextToImageOutput;
use Drupal\ai\OperationType\SpeechToText\SpeechToTextInput;
use Drupal\ai\OperationType\SpeechToText\SpeechToTextInterface;
use Drupal\ai\OperationType\SpeechToText\SpeechToTextOutput;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallPluginManager;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Executable\ExecutableInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\gemini_provider\GeminiChatMessageIterator;
use Gemini\Contracts\Arrayable;
use Gemini\Data\Blob;
use Gemini\Data\Content;
use Gemini\Data\DataFormat;
use Gemini\Data\FunctionDeclaration;
use Gemini\Data\GenerationConfig;
use Gemini\Data\ImageConfig;
use Gemini\Data\Part;
use Gemini\Data\Schema;
use Gemini\Data\Tool;
use Gemini\Enums\DataType;
use Gemini\Enums\ResponseMimeType;
use Gemini\Enums\ResponseModality;
use Gemini\Enums\Role;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Plugin implementation of the Google's Gemini.
 */
#[AiProvider(
  id: 'gemini',
  label: new TranslatableMarkup('Gemini')
)]
class GeminiProvider extends AiProviderClientBase implements ChatInterface, EmbeddingsInterface, SpeechToTextInterface, TextToImageInterface {

  /**
   * The Gemini Client.
   *
   * @var \Gemini\Client|null
   */
  protected $client;

  /**
   * API Key.
   *
   * @var string
   */
  protected string $apiKey = '';

  /**
   * Run moderation call, before a normal call.
   *
   * @var bool
   */
  protected bool $moderation = TRUE;

  /**
   * If system message is presented, we store here.
   *
   * @var \Gemini\Data\Content|null
   */
  protected Content|null $systemMessage = NULL;

  /**
   * Function call plugin manager.
   */
  protected FunctionCallPluginManager $functionCallPluginManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);

    $instance->functionCallPluginManager = $container->get('plugin.manager.ai.function_calls');

    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getConfiguredModels(?string $operation_type = NULL, array $capabilities = []): array {
    if (!$this->isUsable($operation_type, $capabilities)) {
      return [];
    }
    $this->loadClient();

    $supported_models = [];
    try {
      $models = $this->client->models()->list()->toArray();

      if (!empty($models['models'])) {
        foreach ($models['models'] as $model) {
          // Separate models by operation type.
          switch ($operation_type) {
            case 'embeddings':
              if (!preg_match('/^(models\/)(.)*(embedding-)/i', trim($model['name']))) {
                continue 2;
              }
              break;

            case 'text_to_image':
              // Only include models that support image generation output.
              if (preg_match('/^(models\/)(.)*(embedding-)/i', trim($model['name']))) {
                continue 2;
              }
              $supported = $model['supportedGenerationMethods'] ?? [];
              if (!in_array('generateContent', $supported)) {
                continue 2;
              }
              break;

            case 'speech_to_text':
              // Speech-to-text uses Gemini's multimodal generateContent
              // endpoint. Only include generative models, not embedding
              // models.
              if (preg_match('/^(models\/)(.)*(embedding-)/i', trim($model['name']))) {
                continue 2;
              }
              break;

            default:
              if (preg_match('/^(models\/)(.)*(embedding-)/i', trim($model['name']))) {
                continue 2;
              }
              break;
          }
          $supported_models[$model['name']] = $model['displayName'];
        }
      }
    }
    catch (\JsonException $e) {
      throw new AiResponseErrorException('Couldn\'t fetch gemini models.');
    }

    return $supported_models;
  }

  /**
   * {@inheritdoc}
   */
  public function isUsable(?string $operation_type = NULL, array $capabilities = []): bool {
    if (!$this->getConfig()->get('api_key')) {
      return FALSE;
    }

    if ($operation_type) {
      return in_array($operation_type, $this->getSupportedOperationTypes());
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedOperationTypes(): array {
    // @todo We need to add other operation types here later.
    return [
      'chat',
      'chat_with_tools',
      'embeddings',
      'text_to_image',
      'speech_to_text',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getConfig(): ImmutableConfig {
    return $this->configFactory->get('gemini_provider.settings');
  }

  /**
   * {@inheritdoc}
   */
  public function getApiDefinition(): array {
    $definition = Yaml::parseFile(
      $this->moduleHandler->getModule('gemini_provider')
        ->getPath() . '/definitions/api_defaults.yml'
    );
    return $definition;
  }

  /**
   * {@inheritdoc}
   */
  public function getModelSettings(string $model_id, array $generalConfig = []): array {
    return $generalConfig;
  }

  /**
   * {@inheritdoc}
   */
  public function getSetupData(): array {
    return [
      'key_config_name' => 'api_key',
      'default_models' => [
        'chat' => 'models/gemini-1.5-pro',
        'chat_with_image_vision' => 'models/gemini-1.5-pro',
        'chat_with_complex_json' => 'models/gemini-1.5-pro',
        'embeddings' => 'models/gemini-embedding-001',
        'text_to_image' => 'models/gemini-2.5-flash-image',
        'speech_to_text' => 'models/gemini-2.0-flash',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function embeddingsVectorSize(string $model_id): int {
    return match ($model_id) {
      'models/text-embedding-004' => 768,
      'models/gemini-embedding-001' => 3072,
      default => 0,
    };
  }

  /**
   * {@inheritdoc}
   */
  public function setAuthentication(mixed $authentication): void {
    $this->apiKey = $authentication;
    $this->client = NULL;
  }

  /**
   * {@inheritdoc}
   *
   * @param array|string|\Drupal\ai\OperationType\Chat\ChatInput $input
   *   The chat array, string or ChatInput.
   * @param string $model_id
   *   The model id to use.
   * @param array $tags
   *   Extra tags to set.
   */
  public function chat(array|string|ChatInput $input, string $model_id, array $tags = []): ChatOutput {
    $this->loadClient();

    // Prepare inputs for gemini.
    $chat_input = $input;
    if ($input instanceof ChatInput) {
      $chat_input = [];

      if ($this->systemMessage) {
        $role = Role::from('model');
        $chat_input[] = Content::parse($this->systemMessage, $role);
      }

      /** @var \Drupal\ai\OperationType\Chat\ChatMessage $message */
      foreach ($input->getMessages() as $message) {
        if ($message->getRole() == 'system') {
          $message->setRole('model');
        }

        if ($message->getRole() == 'assistant') {
          $message->setRole('user');
        }

        if (!in_array($message->getRole(), ['model', 'user'])) {
          $error_message = sprintf('The role %s, is not supported by Gemini Provider.', $message->getRole());
          throw new AiResponseErrorException($error_message);
        }

        // Build the content data.
        $content_parts[] = $message->getText();
        // Check for images and added in the content data.
        if (count($message->getImages())) {
          foreach ($message->getImages() as $image) {
            $content_parts[] = Blob::from([
              'mimeType' => $image->getMimeType(),
              'data' => $image->getAsBase64EncodedString(''),
            ]);
          }
        }

        // Format the chat content data.
        $role = Role::from($message->getRole());
        $chat_input[] = Content::parse($content_parts, $role);
      }
    }

    $configuration = $this->getConfiguration();
    $configuration['topK'] = (int) ($configuration['topK'] ?? 0);

    // Handle structured JSON schema output if defined.
    if ($input instanceof ChatInput && $input->getChatStructuredJsonSchema()) {
      $json_schema = $input->getChatStructuredJsonSchema();
      $configuration['responseMimeType'] = ResponseMimeType::APPLICATION_JSON;
      $configuration['responseSchema'] = $this->convertJsonSchemaToGeminiSchema($json_schema);
    }

    // Set configuration.
    $config = new GenerationConfig(...$configuration);

    // Generate response.
    $response = $this->client->generativeModel($model_id)
      ->withGenerationConfig($config);

    // Handle chat tools if defined.
    $chat_tools = $input->getChatTools();
    if ($chat_tools !== NULL) {
      $functions = [];
      foreach ($chat_tools->getFunctions() as $function) {
        [$properties, $required] = $this->buildSchema($function->getProperties());

        $functions[] = new FunctionDeclaration(
          $function->getName(),
          $function->getDescription(),
          new Schema(
            type: DataType::OBJECT,
            properties: $properties,
            required: $required,
          )
        );
      }

      if ($functions !== []) {
        $response->withTool(new Tool($functions));
      }
    }

    if ($this->streamed) {
      $streamedIterator = $response->streamGenerateContent(...$chat_input);
      $message = new GeminiChatMessageIterator($streamedIterator);
    }
    else {
      $response = $response->generateContent(...$chat_input);
      $text = '';
      $tool_outputs = [];

      foreach ($response->parts() as $part) {
        // Get text response part.
        if ($part->text !== NULL) {
          $text .= $part->text;
        }

        // Return tool calls to the caller (ai_agents) instead of executing them
        // internally. The agent execution loop handles tool execution and sends
        // results back to the LLM for synthesis.
        if ($part->functionCall !== NULL && $chat_tools !== NULL) {
          $args = [];
          if ($part->functionCall->args) {
            foreach ($part->functionCall->args as $key => $value) {
              $args[$key] = $value;
            }
          }
          $tool_outputs[] = new ToolsFunctionOutput(
            $chat_tools->getFunctionByName($part->functionCall->name),
            'gemini_tool_' . uniqid(),
            $args
          );
        }
      }

      $message = new ChatMessage('', $text);
      if (!empty($tool_outputs)) {
        $message->setTools($tool_outputs);
      }
    }
    $chat_output = new ChatOutput($message, $response, []);
    // We only set the token usage if its not streamed or in a fiber.
    if (!$this->streamed && !\Fiber::getCurrent()) {
      $this->setChatTokenUsage($chat_output, $response);
    }
    return $chat_output;
  }

  /**
   * Helper function to set the token usage on chat output.
   *
   * @param \Drupal\ai\OperationType\Chat\ChatOutput $chat_output
   *   The chat output to set the token usage on.
   * @param \Gemini\Contracts\Arrayable $raw_response
   *   The response array containing token usage.
   *
   * @return \Drupal\ai\OperationType\Chat\ChatOutput
   *   The chat output with token usage set.
   */
  protected function setChatTokenUsage(ChatOutput $chat_output, Arrayable $raw_response): ChatOutput {
    $response = $raw_response->toArray();
    $chat_output->setTokenUsage(new TokenUsageDto(
      input: $response['usageMetadata']['promptTokenCount'] ?? NULL,
      output: $response['usageMetadata']['candidatesTokenCount'] ?? NULL,
      total: $response['usageMetadata']['totalTokenCount'] ?? NULL,
      reasoning: $response['usageMetadata']['thoughtsTokenCount'] ?? NULL,
      cached: $response['usageMetadata']['cachedContentTokenCount'] ?? NULL,
    ));
    return $chat_output;
  }

  /**
   * Enables moderation response, for all next coming responses.
   */
  public function enableModeration(): void {
    $this->moderation = TRUE;
  }

  /**
   * Disables moderation response, for all next coming responses.
   */
  public function disableModeration(): void {
    $this->moderation = FALSE;
  }

  /**
   * Gets the raw client.
   *
   * @param string $api_key
   *   If the API key should be hot swapped.
   *
   * @return \Gemini\Client
   *   The Gemini client.
   */
  public function getClient(string $api_key = '') {
    if ($api_key) {
      $this->setAuthentication($api_key);
    }

    $this->loadClient();
    return $this->client;
  }

  /**
   * Loads the Gemini Client with authentication if not initialized.
   */
  protected function loadClient(): void {
    if (!$this->client) {
      if (!$this->apiKey) {
        $this->setAuthentication($this->loadApiKey());
      }

      $this->client = \Gemini::factory()
        ->withApiKey($this->apiKey)
        ->withHttpClient($this->httpClient)
        ->make();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function setConfiguration(array $configuration): void {
    parent::setConfiguration($configuration);

    // Normalize config for Gemini.
    $this->configuration['stopSequences'] = !empty($this->configuration['stopSequences']) && $this->configuration['stopSequences'] !== '' && $this->configuration['stopSequences'] !== NULL
      ? explode(',', $this->configuration['stopSequences'])
      : [];

    // Remove responseSchema and responseMimeType from configuration as they
    // are handled separately in the chat() method via ChatInput.
    unset($this->configuration['responseSchema']);
    unset($this->configuration['responseMimeType']);
  }

  /**
   * {@inheritdoc}
   */
  public function setChatSystemRole(string|null $message): void {
    if (!empty($message)) {
      $role = Role::from('model');
      $this->systemMessage = Content::parse($message, $role);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function embeddings(string|EmbeddingsInput $input, string $model_id, array $tags = []): EmbeddingsOutput {
    $this->loadClient();
    // Normalize the input if needed.
    if ($input instanceof EmbeddingsInput) {
      $input = $input->getPrompt();
    }
    try {
      $response = $this->client->embeddingModel($model_id)->embedContent($input);
    }
    catch (\Exception $e) {
      // @todo Handle the exception properly.
      throw $e;
    }

    return new EmbeddingsOutput($response->embedding->values, $response->toArray(), []);
  }

  /**
   * {@inheritdoc}
   */
  public function speechToText(string|SpeechToTextInput $input, string $model_id, array $tags = []): SpeechToTextOutput {
    $this->loadClient();

    // Normalize the input.
    if ($input instanceof SpeechToTextInput) {
      $binary = $input->getBinary();
      $mime_type = $input->getFile()->getMimeType();
    }
    else {
      $binary = $input;
      $mime_type = 'audio/mp3';
    }

    // Normalize MIME types for Gemini client compatibility.
    // Gemini lib uses 'audio/mp3'.
    if ($mime_type === 'audio/mpeg') {
      $mime_type = 'audio/mp3';
    }

    // Convert audio binary to a Gemini Blob.
    $audio_blob = Blob::from([
      'mimeType' => $mime_type,
      'data' => base64_encode($binary),
    ]);

    $configuration = $this->getConfiguration();
    $prompt = 'Transcribe the following audio file. Return only the transcription text without any additional commentary.';
    if (!empty($configuration['language'])) {
      $prompt = sprintf(
        'Transcribe the following audio file. The language is %s. Return only the transcription text without any additional commentary.',
        $configuration['language']
      );
    }

    // Use generateContent with a transcription prompt.
    // Gemini does not provide a dedicated speech-to-text endpoint.
    // Transcription is achieved via the multimodal generateContent API
    // with a prompt, unlike providers with native STT (e.g., OpenAI Whisper).
    $response = $this->client->generativeModel($model_id)
      ->generateContent([
        $prompt,
        $audio_blob,
      ]);

    $text = '';
    foreach ($response->parts() as $part) {
      if ($part->text !== NULL) {
        $text .= $part->text;
      }
    }

    return new SpeechToTextOutput($text, $response->toArray(), []);
  }

  /**
   * {@inheritdoc}
   */
  public function maxEmbeddingsInput($model_id = ''): int {
    return 2048;
  }

  /**
   * {@inheritdoc}
   */
  public function textToImage(string|TextToImageInput $input, string $model_id, array $tags = []): TextToImageOutput {
    $this->loadClient();

    if ($input instanceof TextToImageInput) {
      $input = $input->getText();
    }

    // Build generation config with IMAGE response modality.
    $config_args = [
      'responseModalities' => [ResponseModality::IMAGE],
    ];

    // Apply aspect ratio from configuration if set.
    $configuration = $this->getConfiguration();
    if (!empty($configuration['aspectRatio'])) {
      $config_args['imageConfig'] = new ImageConfig(
        aspectRatio: $configuration['aspectRatio'],
      );
    }

    $config = new GenerationConfig(...$config_args);

    try {
      $response = $this->client->generativeModel($model_id)
        ->withGenerationConfig($config)
        ->generateContent($input);
    }
    catch (\Exception $e) {
      throw new AiResponseErrorException($e->getMessage());
    }

    $images = [];
    foreach ($response->parts() as $part) {
      if ($part->inlineData !== NULL) {
        $mime_type = $part->inlineData->mimeType->value;
        $extension = match ($mime_type) {
          'image/png' => 'png',
          'image/jpeg', 'image/jpg' => 'jpg',
          'image/webp' => 'webp',
          default => 'png',
        };
        $images[] = new ImageFile(
          base64_decode($part->inlineData->data),
          $mime_type,
          'generated-image.' . $extension,
        );
      }
    }

    if (empty($images)) {
      throw new AiResponseErrorException('No image data found in the Gemini response.');
    }

    return new TextToImageOutput($images, $response->toArray(), []);
  }

  /**
   * Build schema for tools.
   *
   * @param \Drupal\ai\OperationType\Chat\Tools\ToolsPropertyInputInterface[] $properties
   *   List of properties.
   *
   * @return array{0: \Gemini\Data\Schema[], 1: string[]}
   *   The list of property schemas and the list of required properties
   */
  private function buildSchema(array $properties): array {
    $schema_properties = [];
    $required = [];

    foreach ($properties as $property) {
      $property_type = DataType::tryFrom(strtoupper($property->getType()));
      // Get object properties.
      if ($property_type === DataType::OBJECT) {
        /** @var \Drupal\ai\OperationType\Chat\Tools\ToolsPropertyInputInterface[] $sub_properties */
        $sub_properties = $property->getProperties();
        [$object_properties, $object_required] = $this->buildSchema($sub_properties);
      }
      // Set array items type.
      elseif ($property_type === DataType::ARRAY) {
        $property_items = $property->getItems();
        if (is_string($property_items)) {
          $items = new Schema(DataType::OBJECT);
        }
        elseif ($property_items !== NULL) {
          $items = new Schema(DataType::tryFrom(strtoupper($property_items['type'])));
        }
      }

      // Build properties schema.
      $property_name = $property->getName();
      $schema_properties[$property_name] = new Schema(
        type: $property_type,
        format: DataFormat::tryFrom($property->getFormat()),
        description: $property->getDescription(),
        enum: $property->getEnum(),
        properties: $object_properties ?? NULL,
        required: $object_required ?? NULL,
        items: $items ?? NULL,
        minLength: $property->getMinLength(),
        maxLength: $property->getMaxLength(),
        pattern: $property->getPattern() ?: NULL,
        example: $property->getExampleValue(),
        default: $property->getDefault(),
        minimum: $property->getMinimum(),
        maximum: $property->getMaximum(),
      );

      // Collect required properties.
      if ($property->isRequired()) {
        $required[] = $property_name;
      }
    }

    return [$schema_properties, $required];
  }

  /**
   * Converts a JSON schema array to a Gemini Schema object.
   *
   * The AI module uses OpenAI-style JSON schemas. This method converts that
   * format to Gemini's native Schema format for structured output.
   *
   * @param array $json_schema
   *   The JSON schema array from ChatInput::getChatStructuredJsonSchema().
   *
   * @return \Gemini\Data\Schema
   *   The Gemini Schema object.
   */
  private function convertJsonSchemaToGeminiSchema(array $json_schema): Schema {
    // Extract the actual schema from the wrapper structure.
    // OpenAI format: ['name' => '...', 'schema' => ['type' => ..., ...]].
    // Gemini needs only the inner schema definition.
    $schema = $json_schema['schema'] ?? $json_schema;

    return $this->buildGeminiSchemaFromArray($schema);
  }

  /**
   * Recursively builds a Gemini Schema from a JSON schema array.
   *
   * @param array $schema
   *   The JSON schema array.
   *
   * @return \Gemini\Data\Schema
   *   The Gemini Schema object.
   */
  private function buildGeminiSchemaFromArray(array $schema): Schema {
    $type = DataType::tryFrom(strtoupper($schema['type'] ?? 'string'));

    $properties = NULL;
    $required = NULL;
    $items = NULL;

    // Handle object type with properties.
    if ($type === DataType::OBJECT && !empty($schema['properties'])) {
      $properties = [];
      foreach ($schema['properties'] as $name => $property_schema) {
        $properties[$name] = $this->buildGeminiSchemaFromArray($property_schema);
      }
      $required = $schema['required'] ?? NULL;
    }

    // Handle array type with items.
    if ($type === DataType::ARRAY && !empty($schema['items'])) {
      $items = $this->buildGeminiSchemaFromArray($schema['items']);
    }

    return new Schema(
      type: $type,
      format: isset($schema['format']) ? DataFormat::tryFrom($schema['format']) : NULL,
      description: $schema['description'] ?? NULL,
      enum: $schema['enum'] ?? NULL,
      properties: $properties,
      required: $required,
      items: $items,
    );
  }

  /**
   * Handles function call.
   *
   * @param \Gemini\Data\Part $part
   *   Data part.
   *
   * @return string|bool
   *   Functional call output as string or FALSE if there is no output.
   *
   * @throws \Drupal\Component\Plugin\Exception\ContextException
   *    If the value does not pass validation.
   */
  private function handleFunctionCall(Part $part): string|bool {
    // Handle function call.
    if ($part->functionCall !== NULL) {
      $function_call_instance = $this->functionCallPluginManager->getFunctionCallFromFunctionName($part->functionCall->name);

      // Set context.
      foreach ($part->functionCall->args as $argument => $value) {
        $function_call_instance->setContextValue($argument, $value);
      }

      // Execute function.
      if ($function_call_instance instanceof ExecutableInterface) {
        $function_call_instance->execute();
      }

      // Return function response.
      if ($function_call_instance instanceof ExecutableFunctionCallInterface) {
        return $function_call_instance->getReadableOutput();
      }
    }
    return FALSE;
  }

}
