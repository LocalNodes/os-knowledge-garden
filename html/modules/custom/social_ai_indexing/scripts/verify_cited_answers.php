<?php

/**
 * @file
 * D-20 live check, answer half (Phase 22, plan 22-27; AI-SPEC §5).
 *
 * Runs the reference cases in tests/fixtures/eval/cases.yml through the real
 * assistant path with real Gemini, Solr and Qdrant, one record per case and
 * persona, and appends the records to 22-live-run.jsonl.
 *
 * - Door 'answer': AiAssistantApiRunner::process() with the case's scope as
 *   the DeepChat context (the DeepChat path minus HTTP), inside
 *   account_switcher->switchTo() ... switchBack(). P-ANON is the anonymous
 *   user; P-FORGE is the P-NON account sending a forged scope hint.
 * - Door 'overview': AiOverviewController::overview() under the switched
 *   account, so the controller's cache is in the path (RQ-19, RQ-20). For
 *   RQ-20, P-ANON also asks AiOverviewService directly, as a defence-in-
 *   depth leak check (over HTTP, visitors get 403 under D-27).
 *
 * Every chat and embeddings request is counted at the provider proxy events:
 * a request that reached Gemini fires the pre event with no forced output
 * (cache hits and blocks force one); a refused request (429, 503) fires pre
 * but not post. A spy logger watches every channel during each run (the log
 * door). Each case's `forbidden` strings are checked at the LLM-input,
 * registry, answer and log doors (RQ-17: answer and Sources only).
 *
 * Use only on local DDEV with fictional fixtures and public pages (seeded by
 * localnodes_import/scripts/seed_live_check.php). Never prints fixture body
 * text except inside the jsonl records, which hold only fictional and public
 * text.
 *
 * Usage:
 *   ddev drush php-script html/modules/custom/social_ai_indexing/scripts/verify_cited_answers.php -- [options]
 * Options:
 *   --cases=RQ-01,RQ-07,NONSENSE  Only these cases (default: all but RQ-13).
 *   --personas=P-ANON,P-MEM       Only these personas.
 *   --destructive                 Allow RQ-13 (changes G-COM, deletes G-PUB).
 *   --space=20                    Seconds between model-calling runs.
 *   --out=<path>                  jsonl to append to (default: the phase
 *                                 folder's 22-live-run.jsonl).
 *   --dry-run                     List the runs and exit; no model calls.
 *   --in-process                  Run every case in this one process (the
 *                                 long-lived stress variant). By default
 *                                 each run gets its own drush process, as
 *                                 each web request does in production.
 *   --run-index=<n>               Internal: run only planned run n (the
 *                                 child process of the default mode).
 *
 * Exit 1 on any Critical failure (E1, E2, E3, E10). E5, E11 and E12 are
 * reported, not fatal; a run that errored twice (429/503) is reported as
 * incomplete, not as a failure.
 */

use Drupal\ai\Event\PostGenerateResponseEvent;
use Drupal\ai\Event\PreGenerateResponseEvent;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai_assistant_api\Data\UserMessage;
use Drupal\comment\CommentInterface;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Logger\RfcLoggerTrait;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\social_ai_indexing\Citation\CitationInvariants;
use Drupal\social_ai_indexing\Citation\CitedAnswer;
use Drupal\social_ai_indexing\Controller\AiOverviewController;
use Drupal\social_ai_indexing\EventSubscriber\CitationRewriteSubscriber;
use Drupal\social_ai_indexing\Service\AiOverviewService;
use Drupal\user\Entity\User;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Records every message logged on any channel (leak door 6).
 */
final class LiveRunSpyLogger implements LoggerInterface {

  use RfcLoggerTrait;

  /**
   * Logged messages, placeholders filled in, with the channel.
   *
   * @var list<string>
   */
  public array $records = [];

  /**
   * {@inheritdoc}
   */
  public function log($level, string|\Stringable $message, array $context = []): void {
    $text = (string) $message;
    $replace = [];
    foreach ($context as $key => $value) {
      if (is_scalar($value) || $value instanceof \Stringable) {
        $replace[$key] = (string) $value;
      }
    }
    $text = strtr($text, $replace);
    $this->records[] = ($context['channel'] ?? '?') . ': ' . $text;
  }

}

/**
 * Counts and captures provider requests (doors 1 and E11).
 */
final class LiveRunCapture {

  /**
   * Requests seen in the current run.
   *
   * @var list<array>
   */
  public array $requests = [];

  /**
   * Totals across the whole script run, by operation type.
   *
   * @var array<string, array{sent: int, ok: int, cached_or_blocked: int}>
   */
  public array $totals = [];

  /**
   * Requests by event id, waiting for their post event.
   *
   * @var array<string, int>
   */
  private array $open = [];

  /**
   * Loop counts from AgentFinishedExecutionEvent in the current run.
   *
   * @var list<int>
   */
  public array $loops = [];

  /**
   * Starts a new run.
   */
  public function begin(): void {
    $this->requests = [];
    $this->open = [];
    $this->loops = [];
  }

  /**
   * Last in the pre-generate chain: forced outputs and caps are final here.
   */
  public function onPre(PreGenerateResponseEvent $event): void {
    $type = $event->getOperationType();
    $this->totals[$type] ??= ['sent' => 0, 'ok' => 0, 'cached_or_blocked' => 0];
    if ($event->getForcedOutputObject() !== NULL) {
      $this->totals[$type]['cached_or_blocked']++;
      $this->requests[] = ['type' => $type, 'sent' => FALSE, 'tags' => $event->getTags()];
      return;
    }
    $this->totals[$type]['sent']++;
    $record = [
      'type' => $type,
      'sent' => TRUE,
      'ok' => FALSE,
      'tags' => $event->getTags(),
      'model' => $event->getModelId(),
    ];
    $input = $event->getInput();
    if ($input instanceof ChatInput) {
      $texts = [(string) $input->getSystemPrompt()];
      foreach ($input->getMessages() as $message) {
        $texts[] = $message->getRole() . ': ' . $message->getText();
      }
      $record['input_text'] = implode("\n", $texts);
      $record['system_prompt'] = (string) $input->getSystemPrompt();
      $config = $event->getConfiguration();
      $record['config'] = [
        'maxOutputTokens' => $config['maxOutputTokens'] ?? NULL,
        'thinking' => isset($config['thinkingConfig']) ? 'set' : NULL,
        'temperature' => array_key_exists('temperature', $config) ? $config['temperature'] : 'absent',
        'topP' => array_key_exists('topP', $config) ? $config['topP'] : 'absent',
        'topK' => array_key_exists('topK', $config) ? $config['topK'] : 'absent',
      ];
    }
    $this->requests[] = $record;
    $this->open[$event->getRequestThreadId()] = array_key_last($this->requests);
  }

  /**
   * A request that came back.
   */
  public function onPost(PostGenerateResponseEvent $event): void {
    $index = $this->open[$event->getRequestThreadId()] ?? NULL;
    if ($index === NULL) {
      return;
    }
    unset($this->open[$event->getRequestThreadId()]);
    $type = $event->getOperationType();
    $this->totals[$type]['ok']++;
    $this->requests[$index]['ok'] = TRUE;
    $output = $event->getOutput();
    if ($output instanceof ChatOutput) {
      $usage = $output->getTokenUsage();
      $this->requests[$index]['tokens'] = [
        'input' => $usage->input,
        'output' => $usage->output,
        'reasoning' => $usage->reasoning,
      ];
      $message = $output->getNormalized();
      $this->requests[$index]['output_text'] = $message instanceof ChatMessage ? $message->getText() : '';
    }
  }

  /**
   * The agent's loop count at its finished event.
   */
  public function onFinished(object $event): void {
    if (method_exists($event, 'getAgentId') && $event->getAgentId() === CitationRewriteSubscriber::AGENT_ID) {
      $this->loops[] = (int) $event->getLoopCount();
    }
  }

  /**
   * Counts for the current run: sent, ok and refused per type.
   */
  public function runCounts(): array {
    $counts = [];
    foreach ($this->requests as $request) {
      $type = $request['type'];
      $counts[$type] ??= ['sent' => 0, 'ok' => 0, 'refused' => 0, 'cached_or_blocked' => 0];
      if (!$request['sent']) {
        $counts[$type]['cached_or_blocked']++;
        continue;
      }
      $counts[$type]['sent']++;
      $counts[$type][$request['ok'] ? 'ok' : 'refused']++;
    }
    return $counts;
  }

}

/**
 * The live runner.
 */
final class CitedAnswersLiveRunner {

  public const SEED_STATE = 'localnodes_import.live_check_seed';
  public const RQ13_STATE = 'social_ai_indexing.live_run_rq13_done';
  public const NONSENSE = 'zzqx blorf nonsense';
  public const BASE_URL = 'http://localhost';
  public const INJECTION_URL = 'https://skill-share-circle.example/circle';
  public const RETRY_WAIT = 60;

  // E11 per-question bounds (AI-SPEC §5).
  public const MAX_LOOPS = 5;
  public const MAX_INPUT_TOKENS = 20000;
  public const MAX_OUTPUT_TOKENS = 3000;

  private array $seed;
  private array $options;
  private LiveRunCapture $capture;
  private LiveRunSpyLogger $spy;

  /**
   * One cookie jar per logged-in persona.
   *
   * @var array<string, \GuzzleHttp\Cookie\CookieJar>
   */
  private array $jars = [];

  /**
   * Expected-source keys resolved to entity keys ("node:12").
   *
   * @var array<string, string>
   */
  private array $sourceKeys = [];

  private array $records = [];
  private array $critical = [];
  private array $notes = [];
  private bool $modelCalled = FALSE;

  public function __construct(array $options) {
    $this->options = $options;
    $seed = \Drupal::state()->get(self::SEED_STATE);
    if (!is_array($seed) || empty($seed['groups']) || empty($seed['users'])) {
      throw new \RuntimeException('No seed map in state ' . self::SEED_STATE . '; run seed_live_check.php first.');
    }
    $this->seed = $seed;
    $this->capture = new LiveRunCapture();
    $this->spy = new LiveRunSpyLogger();
  }

  /**
   * Runs the selected cases; returns the exit code.
   */
  public function run(): int {
    $runs = $this->plan();
    printf("Planned runs: %d\n", count($runs));
    foreach ($runs as $run) {
      printf("  %s %s scope=%s door=%s turns=%d%s\n", $run['case'], $run['persona'], var_export($run['scope'], TRUE), $run['door'], count($run['questions']), $run['variant'] ? ' (' . $run['variant'] . ')' : '');
    }
    if (!empty($this->options['dry-run'])) {
      return 0;
    }
    if (empty($this->options['in-process']) && !isset($this->options['run-index'])) {
      return $this->runIsolated($runs);
    }
    if (isset($this->options['run-index'])) {
      // Child of runIsolated(): exactly one planned run, no spacing.
      $runs = array_slice($runs, (int) $this->options['run-index'], 1);
    }

    $this->resolveSourceKeys();
    $dispatcher = \Drupal::service('event_dispatcher');
    $dispatcher->addListener(PreGenerateResponseEvent::EVENT_NAME, [$this->capture, 'onPre'], -2000);
    $dispatcher->addListener(PostGenerateResponseEvent::EVENT_NAME, [$this->capture, 'onPost'], -2000);
    $dispatcher->addListener('ai_agents.finished_execution', [$this->capture, 'onFinished'], -2000);
    \Drupal::service('logger.factory')->addLogger($this->spy);

    $by_case = [];
    foreach ($runs as $run) {
      if ($run['case'] === 'RQ-13' && $run['variant'] === 'mutate') {
        // RQ-13 changes the seeded site; only after every earlier run of
        // this invocation completed.
        if (array_filter($this->records, static fn (array $r): bool => $r['error'] !== NULL)) {
          $this->notes[] = 'RQ-13 change NOT made: an earlier run did not complete; RQ-13 skipped.';
          break;
        }
        $this->mutateForStaleIndex();
        continue;
      }
      if ($this->modelCalled) {
        sleep((int) ($this->options['space'] ?? 20));
      }
      $record = $run['door'] === 'overview' ? $this->runOverview($run) : $this->runAssistant($run);
      $this->modelCalled = TRUE;
      $this->evaluate($run, $record);
      $this->records[] = $record;
      $by_case[$run['case']][] = $record;
      $this->append($record);
      $daily = $record['error'] !== NULL && str_contains($record['error'], 'daily_quota');
      printf("[%s] %s %s%s: %s | sources %d | chat %d emb %d | %s\n",
        $record['status'], $record['case'], $record['persona'], $record['variant'] ? ' (' . $record['variant'] . ')' : '',
        $record['outcome'], count($record['citations']),
        $record['requests']['chat']['sent'] ?? 0, $record['requests']['embeddings']['sent'] ?? 0,
        $record['failures'] ? implode('; ', $record['failures']) : 'ok');
      if ($daily) {
        // A spent daily quota refuses every later request: stop here, so no
        // request is wasted and RQ-13 never runs on a half-done day.
        $this->notes[] = 'Stopped after ' . $record['case'] . ' ' . $record['persona'] . ': daily quota spent.';
        break;
      }
    }
    $this->crossChecks($by_case);
    return $this->report();
  }

  /**
   * Runs each planned run in its own drush process; returns the exit code.
   *
   * Production answers each question in a fresh web request, so request-
   * local caches never carry a decision from one question to the next.
   * The RQ-13 leak of 2026-11-07 came from running every case in one
   * process; --in-process keeps that as the deliberate stress variant.
   * Children append their records to the jsonl; this process reads them
   * back for the cross-checks and the summary.
   */
  private function runIsolated(array $runs): int {
    $out = $this->options['out'] ?? (dirname(DRUPAL_ROOT) . '/.planning/phases/22-document-importer-cited-group-answers/22-live-run.jsonl');
    $drush = dirname(DRUPAL_ROOT) . '/vendor/bin/drush';
    $pass = [];
    foreach ($this->options as $key => $value) {
      if (!in_array($key, ['in-process', 'run-index', 'dry-run', 'space'], TRUE)) {
        $pass[] = '--' . $key . ($value === TRUE ? '' : '=' . $value);
      }
    }
    $by_case = [];
    $model_called = FALSE;
    foreach ($runs as $index => $run) {
      $mutate = $run['case'] === 'RQ-13' && $run['variant'] === 'mutate';
      if ($mutate && array_filter($this->records, static fn (array $r): bool => $r['error'] !== NULL)) {
        $this->notes[] = 'RQ-13 change NOT made: an earlier run did not complete; RQ-13 skipped.';
        break;
      }
      if (!$mutate && $model_called) {
        sleep((int) ($this->options['space'] ?? 20));
      }
      $before = is_file($out) ? count(file($out)) : 0;
      $command = escapeshellarg($drush) . ' --root=' . escapeshellarg(DRUPAL_ROOT) . ' php-script ' . escapeshellarg(__FILE__)
        . ' -- ' . implode(' ', array_map('escapeshellarg', [...$pass, '--run-index=' . $index]));
      $output = (string) shell_exec($command . ' 2>&1');
      foreach (preg_split('/\R/', $output) as $line) {
        // The child's status line (or RQ-13's change line); not its summary.
        if (preg_match('/^(\[(PASS|FAIL|CRITICAL|INCOMPLETE)\]|RQ-13 change)/', $line)) {
          print $line . "\n";
        }
      }
      $new = array_slice(is_file($out) ? file($out, FILE_IGNORE_NEW_LINES) : [], $before);
      $records = 0;
      foreach ($new as $json) {
        $record = json_decode($json, TRUE);
        if (!is_array($record)) {
          continue;
        }
        foreach (($record['requests'] ?? []) as $type => $count) {
          foreach (['sent', 'ok', 'cached_or_blocked'] as $field) {
            $this->capture->totals[$type][$field] = ($this->capture->totals[$type][$field] ?? 0) + (int) ($count[$field] ?? 0);
          }
        }
        if (isset($record['status'])) {
          $this->records[] = $record;
          $by_case[$record['case']][] = $record;
          $records++;
        }
      }
      if (!$mutate) {
        $model_called = TRUE;
        if ($records !== 1) {
          $this->critical[] = sprintf('%s %s: the child process wrote %d records (expected 1); see its output.', $run['case'], $run['persona'], $records);
          print $output;
          break;
        }
        $last = end($this->records);
        if ($last['error'] !== NULL && str_contains($last['error'], 'daily_quota')) {
          $this->notes[] = 'Stopped after ' . $last['case'] . ' ' . $last['persona'] . ': daily quota spent.';
          break;
        }
      }
    }
    $this->notes[] = 'Each run in its own process (' . count($runs) . ' planned).';
    $this->crossChecks($by_case);
    return $this->report();
  }

  /**
   * Expands cases.yml into runs, in a safe order (RQ-13 last).
   */
  private function plan(): array {
    $file = dirname(__DIR__) . '/tests/fixtures/eval/cases.yml';
    $cases = Yaml::decode((string) file_get_contents($file));
    $only = isset($this->options['cases']) ? array_filter(explode(',', $this->options['cases'])) : NULL;
    $personas = isset($this->options['personas']) ? array_filter(explode(',', $this->options['personas'])) : NULL;
    $destructive = !empty($this->options['destructive']);

    $runs = [];
    $last = [];
    $want = static fn (string $id): bool => $only === NULL ? $id !== 'RQ-13' : in_array($id, $only, TRUE);
    foreach ($cases as $case) {
      $id = $case['id'];
      if (!$want($id) || $case['door'] === 'import') {
        continue;
      }
      if ($id === 'RQ-13' && !$destructive) {
        print "RQ-13 skipped: it needs --destructive and runs last.\n";
        continue;
      }
      $base = [
        'case' => $id,
        'door' => $case['door'],
        'questions' => (array) $case['question'],
        'expect' => $case['expect'],
        'expected_sources' => $case['expected_sources'] ?? [],
        'required_facts' => $case['required_facts'] ?? [],
        'forbidden' => $case['forbidden'] ?? [],
        'variant' => '',
      ];
      $persona_list = (array) $case['persona'];
      $scopes = is_array($case['scope']) ? $case['scope'] : [$case['scope']];
      $case_runs = [];
      foreach ($persona_list as $position => $persona) {
        foreach ($scopes as $scope) {
          $run = $base + ['persona' => $persona, 'scope' => $scope, 'position' => $position];
          if ($id === 'RQ-10') {
            $run['variant'] = 'scope ' . $scope;
          }
          if ($id === 'RQ-20') {
            $run['variant'] = 'run ' . ($position + 1);
            // P-MEM is the positive control: a member may see G-MO's
            // members-only text, so it must reach the model. The forbidden
            // list is the non-members' check (runs 1 and 3, P-ANON).
            if ($persona === 'P-MEM') {
              $run['forbidden'] = [];
            }
          }
          $case_runs[] = $run;
        }
      }
      if ($id === 'RQ-20') {
        $case_runs[] = ['persona' => 'P-ANON', 'scope' => NULL, 'position' => 3, 'variant' => 'service direct'] + $base;
      }
      if ($id === 'RQ-13') {
        // Overview before and after, so a stale cache entry would show.
        $overview = ['door' => 'overview', 'questions' => ['public and community notices for group G'], 'expect' => 'no_answer', 'expected_sources' => [], 'required_facts' => []] + $base;
        $case_runs = array_merge(
          [['persona' => 'P-NON', 'scope' => NULL, 'position' => 0, 'variant' => 'overview before'] + $overview],
          [['persona' => 'P-NON', 'scope' => NULL, 'position' => 0, 'variant' => 'mutate'] + $base],
          $case_runs,
          [['persona' => 'P-NON', 'scope' => NULL, 'position' => 0, 'variant' => 'overview after'] + $overview],
        );
      }
      foreach ($case_runs as $run) {
        if ($personas !== NULL && !in_array($run['persona'], $personas, TRUE) && $run['variant'] !== 'mutate') {
          continue;
        }
        if ($id === 'RQ-13') {
          $last[] = $run;
        }
        else {
          $runs[] = $run;
        }
      }
    }
    if ($only === NULL || in_array('NONSENSE', $only, TRUE)) {
      // Door 4: every no_answer reply must equal this one, byte for byte.
      $runs[] = [
        'case' => 'NONSENSE', 'door' => 'answer', 'questions' => [self::NONSENSE], 'expect' => 'no_answer',
        'expected_sources' => [], 'required_facts' => [], 'forbidden' => [], 'variant' => '',
        'persona' => 'P-NON', 'scope' => 'G', 'position' => 0,
      ];
    }
    return array_merge($runs, $last);
  }

  /**
   * The account for a persona.
   */
  private function account(string $persona): AccountInterface {
    if ($persona === 'P-ANON') {
      return new AnonymousUserSession();
    }
    $key = $persona === 'P-FORGE' ? 'P-NON' : $persona;
    $account = User::load($this->seed['users'][$key] ?? 0);
    if ($account === NULL) {
      throw new \RuntimeException("No seeded user for $persona");
    }
    return $account;
  }

  /**
   * The scope hint sent for a case scope: a fixture key's group id, or as is.
   */
  private function hint(mixed $scope): mixed {
    if ($scope === NULL) {
      return NULL;
    }
    return isset($this->seed['groups'][$scope]) ? (string) $this->seed['groups'][$scope] : (string) $scope;
  }

  /**
   * Runs one assistant case (all its turns) as one persona, retrying once.
   */
  private function runAssistant(array $run): array {
    $record = $this->newRecord($run);
    for ($attempt = 1; $attempt <= 2; $attempt++) {
      $this->capture->begin();
      $spy_from = count($this->spy->records);
      $account = $this->account($run['persona']);
      $switcher = \Drupal::service('account_switcher');
      $switcher->switchTo($account);
      $started = hrtime(TRUE);
      try {
        /** @var \Drupal\ai_assistant_api\AiAssistantApiRunner $runner */
        $runner = \Drupal::service('ai_assistant_api.runner');
        $assistant = \Drupal::entityTypeManager()->getStorage('ai_assistant')->load('group_assistant');
        // A fresh thread per attempt: no history from another case or from
        // a failed attempt. RQ-18's turns share one.
        $thread = 'p22live_' . bin2hex(random_bytes(6));
        $hint = $this->hint($run['scope']);
        $answer = '';
        foreach ($run['questions'] as $question) {
          $runner->setAssistant($assistant);
          $runner->setThreadsKey($thread);
          $runner->setContext($hint === NULL ? [] : ['group_id' => $hint]);
          $runner->setVerboseMode(FALSE);
          $runner->setThrowException(TRUE);
          $runner->setUserMessage(new UserMessage($question));
          $output = $runner->process();
          $message = $output->getNormalized();
          $answer = $message instanceof ChatMessage ? $message->getText() : '';
          $record['role'] = $message instanceof ChatMessage ? $message->getRole() : get_debug_type($message);
        }
        $record['latency_ms'] = (int) round((hrtime(TRUE) - $started) / 1e6);
        $record['answer'] = $answer;
        $registry = \Drupal::service('social_ai_indexing.source_registry');
        $record['registry'] = $this->registryRecords($registry->all(), $account);
        $record['rewritten'] = $registry->wasRewritten();
        $record['invariant_violations'] = CitationInvariants::check($answer, $registry);
        $record['citations'] = $this->citations($answer, $registry->all());
        $record['error'] = NULL;
      }
      catch (\Throwable $e) {
        $record['error'] = self::errorCode($e);
      }
      finally {
        $switcher->switchBack();
      }
      $this->finishCapture($record, $spy_from, $attempt);
      if ($record['error'] === NULL) {
        break;
      }
      if (str_contains((string) $record['error'], 'daily_quota')) {
        break;
      }
      if ($attempt === 1) {
        printf("  %s %s: %s; waiting %d s, then one retry\n", $run['case'], $run['persona'], $record['error'], self::RETRY_WAIT);
        sleep(self::RETRY_WAIT);
      }
    }
    if ($record['error'] === NULL) {
      $this->linkStatuses($record, $run['persona']);
    }
    return $record;
  }

  /**
   * Runs one overview request as one persona, retrying once.
   */
  private function runOverview(array $run): array {
    $record = $this->newRecord($run);
    $query = $run['questions'][0];
    for ($attempt = 1; $attempt <= 2; $attempt++) {
      $this->capture->begin();
      $spy_from = count($this->spy->records);
      $account = $this->account($run['persona']);
      $switcher = \Drupal::service('account_switcher');
      $switcher->switchTo($account);
      $started = hrtime(TRUE);
      try {
        if ($run['variant'] === 'service direct') {
          /** @var \Drupal\social_ai_indexing\Service\AiOverviewService $service */
          $service = \Drupal::service('social_ai_indexing.ai_overview');
          $data = $service->generate($query, $account) ?? ['summary_html' => NULL];
          $record['http_status'] = NULL;
        }
        else {
          $controller = AiOverviewController::create(\Drupal::getContainer());
          $response = $controller->overview(Request::create('/api/ai/overview', 'GET', ['q' => $query]));
          $record['http_status'] = $response->getStatusCode();
          $data = json_decode((string) $response->getContent(), TRUE) ?: [];
        }
        $record['latency_ms'] = (int) round((hrtime(TRUE) - $started) / 1e6);
        $record['answer'] = (string) ($data['summary_html'] ?? '');
        $record['overview'] = $data;
        $record['citations'] = [];
        foreach ($data['sources'] ?? [] as $source) {
          $record['citations'][] = ['n' => $source['n'] ?? NULL, 'title' => $source['title'] ?? '', 'url' => $source['url'] ?? '', 'key' => $this->keyForUrl((string) ($source['url'] ?? ''))];
        }
        $record['registry'] = [];
        $record['invariant_violations'] = self::overviewViolations($data);
        $record['error'] = NULL;
      }
      catch (\Throwable $e) {
        $record['error'] = self::errorCode($e);
      }
      finally {
        $switcher->switchBack();
      }
      $this->finishCapture($record, $spy_from, $attempt);
      if ($record['error'] === NULL) {
        break;
      }
      if (str_contains((string) $record['error'], 'daily_quota')) {
        break;
      }
      if ($attempt === 1) {
        sleep(self::RETRY_WAIT);
      }
    }
    if ($record['error'] === NULL) {
      $this->linkStatuses($record, $run['persona']);
    }
    return $record;
  }

  /**
   * A record with the run's identity and empty measurements.
   */
  private function newRecord(array $run): array {
    return [
      'case' => $run['case'],
      'persona' => $run['persona'],
      'variant' => $run['variant'],
      'door' => $run['door'],
      'scope' => $run['scope'],
      'scope_hint' => $this->hint($run['scope']),
      'question' => count($run['questions']) === 1 ? $run['questions'][0] : $run['questions'],
      'expect' => $run['expect'],
      'at' => gmdate('c'),
      'answer' => '',
      'role' => NULL,
      'rewritten' => FALSE,
      'outcome' => 'error',
      'fallback' => FALSE,
      'reason' => NULL,
      'citations' => [],
      'registry' => [],
      'labels' => ['found' => 0, 'kept' => 0, 'dropped' => 0],
      'loops' => NULL,
      'tokens' => ['input' => 0, 'output' => 0, 'reasoning' => 0],
      'chat_calls' => [],
      'latency_ms' => NULL,
      'prompt_hash' => NULL,
      'link_status' => [],
      'invariant_violations' => [],
      'leaks' => [],
      'attempts' => [],
      'requests' => [],
      'error' => NULL,
      'failures' => [],
      'critical' => [],
      'status' => 'PASS',
    ];
  }

  /**
   * Folds the capture and spy output for one attempt into the record.
   */
  private function finishCapture(array &$record, int $spy_from, int $attempt): void {
    $counts = $this->capture->runCounts();
    $record['attempts'][] = ['attempt' => $attempt, 'error' => $record['error'], 'requests' => $counts];
    foreach ($counts as $type => $count) {
      foreach ($count as $key => $value) {
        $record['requests'][$type][$key] = ($record['requests'][$type][$key] ?? 0) + $value;
      }
    }
    if ($record['error'] !== NULL) {
      return;
    }
    $inputs = [];
    $chat_calls = [];
    $last_output = '';
    foreach ($this->capture->requests as $request) {
      if ($request['type'] !== 'chat' || !$request['sent']) {
        continue;
      }
      $inputs[] = $request['input_text'] ?? '';
      $record['prompt_hash'] ??= substr(hash('sha256', $request['system_prompt'] ?? ''), 0, 12);
      $tokens = $request['tokens'] ?? ['input' => NULL, 'output' => NULL, 'reasoning' => NULL];
      foreach (['input', 'output', 'reasoning'] as $key) {
        $record['tokens'][$key] += (int) ($tokens[$key] ?? 0);
      }
      $chat_calls[] = ['tags' => array_values($request['tags']), 'config' => $request['config'] ?? NULL, 'tokens' => $tokens];
      if (in_array('ai_agents_group_assistant', $request['tags'], TRUE) || $record['door'] === 'answer') {
        $last_output = (string) ($request['output_text'] ?? $last_output);
      }
    }
    $record['chat_calls'] = $chat_calls;
    $record['llm_input'] = $inputs;
    $record['loops'] = $this->capture->loops ? max($this->capture->loops) : count($chat_calls);
    $record['log_lines'] = array_slice($this->spy->records, $spy_from);
    // How much the log door saw, by channel (the lines stay out of the jsonl).
    $record['log_channels'] = array_count_values(array_map(static fn (string $line): string => strstr($line, ':', TRUE) ?: '?', $record['log_lines']));
    $record['raw_model_text'] = $last_output;

    $answer = $record['answer'];
    if ($record['door'] === 'answer') {
      $found = CitationRewriteSubscriber::countLabels($last_output);
      $kept = count($record['citations']);
      $record['labels'] = ['found' => $found, 'kept' => $kept, 'dropped' => max(0, $found - $kept)];
      if ($answer === CitedAnswer::fallbackMarkdown()) {
        $record['outcome'] = 'fallback';
        $record['fallback'] = TRUE;
      }
      elseif ($answer === CitedAnswer::NO_ANSWER_GROUP || $answer === CitedAnswer::NO_ANSWER_SITE) {
        $record['outcome'] = 'no_answer';
      }
      else {
        $record['outcome'] = $record['citations'] ? 'cited' : 'uncited';
      }
      // The answer_summary reason code, from the spy (never content).
      foreach ($record['log_lines'] as $line) {
        if (preg_match('/answer_summary .*reason=(\S+)/', $line, $m)) {
          $record['reason'] = $m[1];
        }
        if (preg_match('/guard_fallback .*reason=(\S+)/', $line, $m)) {
          $record['reason'] = 'guard_fallback:' . $m[1];
        }
      }
    }
    else {
      $record['outcome'] = $answer !== '' ? 'cited' : 'no_overview';
      $record['labels'] = ['found' => CitationRewriteSubscriber::countLabels($last_output), 'kept' => count($record['citations']), 'dropped' => 0];
      $record['labels']['dropped'] = max(0, $record['labels']['found'] - $record['labels']['kept']);
    }
  }

  /**
   * Registry entries with their excerpt, scope and access for the asker.
   */
  private function registryRecords(array $sources, AccountInterface $account): array {
    $excerpts = [];
    foreach ($this->capture->requests as $request) {
      if (($request['type'] ?? '') !== 'chat' || empty($request['input_text'])) {
        continue;
      }
      if (preg_match_all('/\[R(\d+)\] [^\n]*\n<<<SOURCE_TEXT\n(.*?)\nSOURCE_TEXT>>>/s', $request['input_text'], $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
          $excerpts[(int) $match[1]] = $match[2];
        }
      }
    }
    $records = [];
    foreach ($sources as $source) {
      $entity = \Drupal::entityTypeManager()->getStorage($source->entityType)->load($source->entityId);
      $records[] = [
        'label' => $source->label(),
        'key' => $source->key(),
        'title' => $source->title,
        'url' => $source->url,
        'type' => $source->type,
        'groups' => $entity instanceof ContentEntityInterface ? $this->groupsOf($entity) : [],
        'viewable' => $entity instanceof ContentEntityInterface && $entity->access('view', $account),
        'excerpt' => $excerpts[$source->n] ?? NULL,
      ];
    }
    return $records;
  }

  /**
   * The ids of the groups an entity (or a comment's parent) belongs to.
   */
  private function groupsOf(ContentEntityInterface $entity): array {
    if ($entity instanceof CommentInterface) {
      $parent = $entity->getCommentedEntity();
      return $parent instanceof ContentEntityInterface ? $this->groupsOf($parent) : [];
    }
    $manager = \Drupal::entityTypeManager();
    $type = $manager->hasDefinition('group_relationship') ? 'group_relationship' : 'group_content';
    $ids = [];
    foreach ($manager->getStorage($type)->loadByEntity($entity) as $relationship) {
      $ids[] = (int) $relationship->getGroupId();
    }
    return $ids;
  }

  /**
   * Parses the Sources lines of a rendered answer.
   */
  private function citations(string $answer, array $sources): array {
    $by_url = [];
    foreach ($sources as $source) {
      $by_url[$source->url] = $source->key();
    }
    $citations = [];
    if (preg_match_all('/^- \[(\d+)\] \[(.+)\]\((\S+)\)$/m', $answer, $matches, PREG_SET_ORDER)) {
      foreach ($matches as $match) {
        $citations[] = ['n' => (int) $match[1], 'title' => $match[2], 'url' => $match[3], 'key' => $by_url[$match[3]] ?? $this->keyForUrl($match[3])];
      }
    }
    return $citations;
  }

  /**
   * The entity key behind a root-relative URL ("node:12", "comment:3").
   */
  private function keyForUrl(string $url): ?string {
    if (preg_match('#^/node/(\d+)(?:\#comment-(\d+))?$#', $url, $m)) {
      return isset($m[2]) ? 'comment:' . $m[2] : 'node:' . $m[1];
    }
    $path = parse_url($url, PHP_URL_PATH);
    $fragment = parse_url($url, PHP_URL_FRAGMENT);
    if (is_string($fragment) && preg_match('/^comment-(\d+)$/', $fragment, $m)) {
      return 'comment:' . $m[1];
    }
    try {
      $internal = \Drupal::service('path_alias.manager')->getPathByAlias((string) $path);
      if (preg_match('#^/node/(\d+)$#', $internal, $m)) {
        return 'node:' . $m[1];
      }
    }
    catch (\Throwable) {
    }
    return NULL;
  }

  /**
   * Fetches every Sources link as the persona who asked, from inside the
   * web container (E3: HTTP 200 for the asker).
   */
  private function linkStatuses(array &$record, string $persona): void {
    $client = new Client(['base_uri' => self::BASE_URL, 'http_errors' => FALSE, 'allow_redirects' => FALSE, 'timeout' => 30]);
    $jar = $this->jar($persona);
    foreach ($record['citations'] as $citation) {
      $url = (string) strtok($citation['url'], '#');
      try {
        $status = $client->get($url, $jar ? ['cookies' => $jar] : [])->getStatusCode();
      }
      catch (\Throwable $e) {
        $status = 'error:' . get_class($e);
      }
      $record['link_status'][$citation['url']] = $status;
    }
  }

  /**
   * A logged-in cookie jar for a persona, from a one-time login link.
   */
  private function jar(string $persona): ?CookieJar {
    if ($persona === 'P-ANON') {
      return NULL;
    }
    $key = $persona === 'P-FORGE' ? 'P-NON' : $persona;
    if (isset($this->jars[$key])) {
      return $this->jars[$key];
    }
    $account = $this->account($key);
    $jar = new CookieJar();
    $link = user_pass_reset_url($account) . '/login';
    $path = (string) parse_url($link, PHP_URL_PATH);
    $client = new Client(['base_uri' => self::BASE_URL, 'http_errors' => FALSE, 'allow_redirects' => TRUE, 'cookies' => $jar, 'timeout' => 30]);
    $client->get($path);
    if ($jar->count() === 0) {
      throw new \RuntimeException("One-time login for $persona set no cookie");
    }
    return $this->jars[$key] = $jar;
  }

  /**
   * Maps cases.yml source keys to entity keys.
   */
  private function resolveSourceKeys(): void {
    foreach ($this->seed['fixtures'] as $key => $entity_key) {
      $this->sourceKeys[$key] = $entity_key;
    }
    $storage = \Drupal::entityTypeManager()->getStorage('localnodes_import_item');
    foreach (['G-P2P' => 'topic', 'LIB' => 'file'] as $run_key => $prefix) {
      $run_id = (int) ($this->seed['runs'][$run_key] ?? 0);
      foreach ($storage->loadByProperties(['run' => $run_id]) as $item) {
        $nid = (int) $item->get('nid')->target_id;
        if ($nid < 1) {
          continue;
        }
        $name = (string) $item->get('source_name')->value;
        if ($prefix === 'file') {
          $this->sourceKeys['file:' . $name] = 'node:' . $nid;
          continue;
        }
        if ($name === self::INJECTION_URL) {
          $this->sourceKeys['topic:injection-page'] = 'node:' . $nid;
          continue;
        }
        $slug = strtolower(str_replace('_', '-', rawurldecode(basename((string) parse_url($name, PHP_URL_PATH)))));
        $this->sourceKeys['topic:' . $slug] = 'node:' . $nid;
      }
    }
  }

  /**
   * Checks one record against its case (per-run checks).
   */
  private function evaluate(array $run, array &$record): void {
    $fail = function (string $message, bool $critical = FALSE) use (&$record): void {
      $record['failures'][] = $message;
      if ($critical) {
        $record['critical'][] = $message;
      }
    };
    if ($record['error'] !== NULL) {
      $record['status'] = 'INCOMPLETE';
      $this->notes[] = sprintf('%s %s%s did not complete: %s', $run['case'], $run['persona'], $run['variant'] ? ' (' . $run['variant'] . ')' : '', $record['error']);
      return;
    }

    // E1/E10: forbidden strings at every door for this persona.
    $doors = [
      'answer' => $record['answer'] . "\n" . json_encode($record['citations']),
      'registry' => json_encode(array_map(static fn (array $r): array => ['key' => $r['key'], 'title' => $r['title'], 'url' => $r['url']], $record['registry'])),
    ];
    if ($run['case'] !== 'RQ-17') {
      $doors['llm_input'] = implode("\n", $record['llm_input'] ?? []);
      $doors['logs'] = implode("\n", $record['log_lines'] ?? []);
    }
    foreach ($run['forbidden'] as $canary) {
      foreach ($doors as $door => $text) {
        if (stripos($text, (string) $canary) !== FALSE) {
          $record['leaks'][] = "$door: $canary";
          $fail("E1 leak: '$canary' at the $door door", TRUE);
        }
      }
    }
    // E1: every registry entry must be viewable by the asker and, when a
    // group was named, belong to that group (D-17).
    $scope_group = isset($run['scope'], $this->seed['groups'][$run['scope']]) ? (int) $this->seed['groups'][$run['scope']] : NULL;
    foreach ($record['registry'] as $entry) {
      if (!$entry['viewable']) {
        $fail("E1: registry holds {$entry['key']}, which the asker can't view", TRUE);
      }
      if ($scope_group !== NULL && !in_array($scope_group, $entry['groups'], TRUE)) {
        $fail("E1: registry holds {$entry['key']}, outside the scope group", TRUE);
      }
    }

    // E3: citation invariants and HTTP 200 for the asker.
    foreach ($record['invariant_violations'] as $violation) {
      $fail('E3: ' . $violation, TRUE);
    }
    foreach ($record['link_status'] as $url => $status) {
      if ($status !== 200) {
        $fail("E3: $url returned $status for the asker", TRUE);
      }
    }

    // E2: a raw tool message or unrewritten text must never come out.
    if ($run['door'] === 'answer') {
      if (($record['role'] ?? '') === 'tool') {
        $fail('E2: a raw tool message came out', TRUE);
      }
      if (!$record['rewritten'] && !$record['fallback']) {
        $fail('E2: the answer was neither rewritten nor replaced by the fallback', TRUE);
      }
      foreach (['[R', 'NO_RELEVANT_CONTENT', 'Search result: #', '<<<SOURCE_TEXT'] as $marker) {
        if (str_contains($record['answer'], $marker)) {
          $fail("E9: raw marker '$marker' in the answer", TRUE);
        }
      }
    }
    else {
      // Overview HTML holds only the allow-listed tags (E8).
      $html = $record['answer'];
      if ($html !== '' && strip_tags($html, AiOverviewService::ALLOWED_TAGS) !== $html) {
        $fail('E8: overview HTML has tags outside the allow-list', TRUE);
      }
      if ($run['variant'] !== 'service direct' && ($record['http_status'] ?? 200) !== 200) {
        $fail('overview controller returned HTTP ' . $record['http_status']);
      }
    }

    // Expectations (E5 facts, E12 completion; RQ-10 abort).
    $positive = $run['expect'] === 'cited' && !($run['case'] === 'RQ-20' && $run['persona'] !== 'P-MEM');
    if ($positive) {
      if ($record['outcome'] !== 'cited') {
        // A positive control that comes back empty proves nothing (E1).
        $critical = in_array($run['case'], ['RQ-07', 'RQ-11', 'RQ-20'], TRUE);
        $fail(($critical ? 'E1 positive control' : 'E12') . ": expected a cited answer, got {$record['outcome']}", $critical);
      }
      else {
        $cited = array_column($record['citations'], 'key');
        $expected = array_filter(array_map(fn (string $k): ?string => $this->sourceKeys[$k] ?? NULL, $run['expected_sources']));
        if ($run['expected_sources'] && !array_intersect($expected, $cited)) {
          $fail('E5: none of the expected sources (' . implode(', ', $run['expected_sources']) . ') was cited');
        }
        if ($run['case'] === 'RQ-04' && count($record['citations']) < 2) {
          $fail('E5: RQ-04 needs at least two cited pages');
        }
        $body = $record['answer'];
        $position = strrpos($body, "\n" . CitedAnswer::SOURCES_HEADING . "\n");
        if ($position !== FALSE) {
          $body = substr($body, 0, $position);
        }
        foreach ($run['required_facts'] as $pattern) {
          if (@preg_match('/' . str_replace('/', '\/', $pattern) . '/i', strip_tags($body)) !== 1) {
            $fail("E5: required fact /$pattern/ not in the answer");
          }
        }
      }
    }
    elseif ($run['expect'] === 'no_answer' && $run['door'] === 'answer') {
      if ($record['outcome'] !== 'no_answer') {
        $fail("E5: expected the no-answer copy, got {$record['outcome']}");
      }
      if ($record['citations']) {
        $fail('E5: a no-answer case has Sources');
      }
    }
    elseif ($run['expect'] === 'abort') {
      if ($record['citations'] || $record['registry']) {
        $fail('E2: a forged scope returned sources', TRUE);
      }
    }
    elseif ($run['door'] === 'overview' && $run['expect'] === 'no_answer' && $record['citations']) {
      // RQ-13 after the change: the overview may cite only what P-NON can
      // still see; the forbidden check above covers the stale items.
    }

    // E11 bounds, per question.
    if ($run['door'] === 'answer') {
      $turns = max(1, count($run['questions']));
      if (($record['loops'] ?? 0) > self::MAX_LOOPS) {
        $fail('E11: ' . $record['loops'] . ' loops');
      }
      if ($record['tokens']['input'] / $turns > self::MAX_INPUT_TOKENS) {
        $fail('E11: ' . $record['tokens']['input'] . ' input tokens');
      }
      if (($record['tokens']['output'] + $record['tokens']['reasoning']) / $turns > self::MAX_OUTPUT_TOKENS) {
        $fail('E11: ' . ($record['tokens']['output'] + $record['tokens']['reasoning']) . ' output and reasoning tokens');
      }
    }
    foreach ($record['chat_calls'] as $call) {
      $config = $call['config'] ?? [];
      if (empty($config['maxOutputTokens'])) {
        $fail('E11: a chat request without maxOutputTokens');
      }
      foreach (['temperature', 'topP', 'topK'] as $key) {
        if (($config[$key] ?? 'absent') !== 'absent' && $config[$key] !== NULL) {
          $fail("E11: a chat request carries $key");
        }
      }
    }

    if ($record['critical']) {
      $record['status'] = 'CRITICAL';
    }
    elseif ($record['failures']) {
      $record['status'] = 'FAIL';
    }
  }

  /**
   * Checks that span runs: door 4, RQ-10 sameness, RQ-20 cache.
   */
  private function crossChecks(array $by_case): void {
    $nonsense = $by_case['NONSENSE'][0]['answer'] ?? NULL;
    if ($nonsense !== NULL && ($by_case['NONSENSE'][0]['error'] ?? NULL) === NULL) {
      foreach ($this->records as $record) {
        if ($record['door'] === 'answer' && $record['expect'] === 'no_answer' && $record['error'] === NULL && $record['scope'] !== NULL && $record['answer'] !== $nonsense) {
          $this->critical[] = "Door 4: {$record['case']} {$record['persona']}'s reply differs from the nonsense-question reply";
        }
      }
    }
    $forged = array_filter($by_case['RQ-10'] ?? [], static fn (array $r): bool => $r['error'] === NULL);
    if (count(array_unique(array_column($forged, 'answer'))) > 1) {
      $this->critical[] = 'E2: the forged scopes got different replies';
    }
    $overview = array_values(array_filter($by_case['RQ-20'] ?? [], static fn (array $r): bool => $r['error'] === NULL && $r['variant'] !== 'service direct'));
    if (count($overview) === 3) {
      if (json_encode($overview[0]['overview']) !== json_encode($overview[2]['overview'])) {
        $this->critical[] = "E1 door 5: P-NON's second overview differs from its first";
      }
      if ($overview[1]['answer'] !== '' && $overview[2]['answer'] === $overview[1]['answer']) {
        $this->critical[] = "E1 door 5: P-NON got P-MEM's cached overview";
      }
    }
  }

  /**
   * Overview structure checks: numbers 1..k, root-relative links.
   */
  private static function overviewViolations(array $data): array {
    $violations = [];
    $html = (string) ($data['summary_html'] ?? '');
    $sources = $data['sources'] ?? [];
    if ($html === '') {
      return $sources ? ['orphan: sources without a summary'] : [];
    }
    preg_match_all('/\[(\d+)\]/', strip_tags($html), $m);
    $first = array_values(array_unique(array_map('intval', $m[1])));
    if ($first !== ($first ? range(1, count($first)) : [])) {
      $violations[] = 'numbering: first uses run ' . implode(', ', $first);
    }
    if (count($first) !== count($sources)) {
      $violations[] = 'count: ' . count($first) . ' numbers, ' . count($sources) . ' sources';
    }
    if (preg_match('#https?://#i', $html)) {
      $violations[] = 'absolute_url_in_body';
    }
    foreach ($sources as $i => $source) {
      if (($source['n'] ?? NULL) !== $i + 1) {
        $violations[] = 'numbering: source ' . ($i + 1) . ' is numbered ' . var_export($source['n'] ?? NULL, TRUE);
      }
      $url = (string) ($source['url'] ?? '');
      if (!str_starts_with($url, '/') || str_starts_with($url, '//')) {
        $violations[] = 'url_not_root_relative: ' . $url;
      }
    }
    return $violations;
  }

  /**
   * RQ-13 setup: G-COM to members-only, G-PUB deleted, nothing reindexed.
   */
  private function mutateForStaleIndex(): void {
    if (\Drupal::state()->get(self::RQ13_STATE)) {
      print "RQ-13 change already made on " . \Drupal::state()->get(self::RQ13_STATE) . "\n";
      return;
    }
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $indexes = \Drupal::entityTypeManager()->getStorage('search_api_index')->loadMultiple(['social_posts', 'social_comments']);
    foreach ($indexes as $index) {
      $index->startBatchTracking();
    }
    $this->capture->begin();
    try {
      $com = $storage->load((int) substr($this->seed['fixtures']['G-COM'], 5));
      $com->set('field_content_visibility', 'group');
      $com->save();
      $pub = $storage->load((int) substr($this->seed['fixtures']['G-PUB'], 5));
      $pub?->delete();
    }
    finally {
      foreach ($indexes as $index) {
        $index->stopBatchTracking();
      }
    }
    $counts = $this->capture->runCounts();
    \Drupal::state()->set(self::RQ13_STATE, gmdate('c'));
    printf("RQ-13 change made: G-COM members-only, G-PUB deleted; embeddings sent %d\n", $counts['embeddings']['sent'] ?? 0);
    $this->append(['case' => 'RQ-13', 'variant' => 'mutate', 'at' => gmdate('c'), 'requests' => $counts, 'note' => 'G-COM set to members-only, G-PUB (and its comment) deleted, no reindex']);
  }

  /**
   * Appends one record to the jsonl.
   */
  private function append(array $record): void {
    $out = $this->options['out'] ?? (dirname(DRUPAL_ROOT) . '/.planning/phases/22-document-importer-cited-group-answers/22-live-run.jsonl');
    unset($record['log_lines']);
    file_put_contents($out, json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
  }

  /**
   * A short error code for a failed run (class and status, no content).
   */
  private static function errorCode(\Throwable $e): string {
    $message = $e->getMessage();
    $status = preg_match('/\b(429|500|503|400|403)\b/', $message, $m) ? $m[1] : '';
    $quota = stripos($message, 'quota') !== FALSE ? ' quota' : '';
    // Gemini names a spent daily allowance "... requests per day ...".
    if (preg_match('/per ?day|PerDay/i', $message)) {
      $quota .= ' daily_quota';
    }
    $retry = preg_match('/retry in ([0-9.]+)\s*s/i', $message, $r) ? ' retry_in=' . $r[1] . 's' : '';
    return get_class($e) . ($status ? " $status" : '') . $quota . $retry . ' | ' . mb_substr(preg_replace('/\s+/', ' ', $message), 0, 300);
  }

  /**
   * Prints the summary; returns the exit code.
   */
  private function report(): int {
    $critical = $this->critical;
    $counts = ['PASS' => 0, 'FAIL' => 0, 'CRITICAL' => 0, 'INCOMPLETE' => 0];
    foreach ($this->records as $record) {
      $counts[$record['status']]++;
      foreach ($record['critical'] as $message) {
        $critical[] = "{$record['case']} {$record['persona']}: $message";
      }
    }
    print "\n=== Summary ===\n";
    printf("Runs: %d (PASS %d, FAIL %d, CRITICAL %d, INCOMPLETE %d)\n", count($this->records), $counts['PASS'], $counts['FAIL'], $counts['CRITICAL'], $counts['INCOMPLETE']);
    foreach ($this->capture->totals as $type => $total) {
      printf("Requests %s: sent %d, ok %d, refused %d, cached or blocked %d\n", $type, $total['sent'], $total['ok'], $total['sent'] - $total['ok'], $total['cached_or_blocked']);
    }
    foreach ($this->notes as $note) {
      print "NOTE: $note\n";
    }
    foreach ($critical as $message) {
      print "CRITICAL: $message\n";
    }
    print $critical ? "Status: CRITICAL FAILURES\n" : "Status: no critical failure\n";
    return $critical ? 1 : 0;
  }

}

// Options from "drush php-script ... -- --cases=RQ-01 --space=20".
$options = [];
foreach (($extra ?? []) as $arg) {
  if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', (string) $arg, $m)) {
    $options[$m[1]] = $m[2] ?? TRUE;
  }
}
$runner = new CitedAnswersLiveRunner($options);
$code = $runner->run();
if ($code !== 0) {
  exit($code);
}
