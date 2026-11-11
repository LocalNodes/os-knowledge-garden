<?php

namespace Drupal\localnodes_platform\Drush\Commands;

use Drupal\Component\Utility\Html;
use Drupal\group\Entity\GroupInterface;
use Drupal\user\UserInterface;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush command that sets up a provisioned community's organizer (Phase 23).
 *
 * The container entrypoint used to build a sign-in link, print it in the log
 * and send a plain mail with curl. This command does it inside Drupal: it
 * creates the organizer's account and sends one welcome mail whose sign-in
 * button is built in the mail builder and appears nowhere else (T-23-13). The
 * command prints fixed sentences only: never an address, never a link.
 *
 * The first command also makes the community's first group (23-09). The second
 * command, localnodes:topic-types, makes the page types the organizer
 * confirmed at sign-up (D-20, ONB-06). The third command,
 * localnodes:seed-community, applies the four texts the sign-up drafted from
 * the organizer's description (23-34, T-2).
 *
 * A sign-up site (23-35, Tom's billing rule B-1) runs localnodes:organizer-setup
 * instead of localnodes:organizer-welcome: the same account and first group,
 * but the account is blocked and no mail is sent. The welcome mail of a
 * sign-up site is sent by POST /localnodes/activate after the first payment
 * (OrganizerActivation). Manual and demo runs keep localnodes:organizer-welcome.
 */
class OrganizerCommands extends DrushCommands {

  /**
   * State key set once the welcome mail went out; later runs send nothing.
   */
  protected const STATE_MAIL_SENT = 'localnodes.install_step.organizer_mail_sent';

  /**
   * State key set before the account is created (the entrypoint's own marker).
   */
  protected const STATE_ACCOUNT = 'localnodes.install_step.organizer_account';

  /**
   * State key set once organizer-setup made the blocked account (23-35).
   */
  protected const STATE_ORGANIZER_SETUP = 'localnodes.install_step.organizer_setup';

  /**
   * State key that holds the organizer's uid, read by OrganizerActivation.
   */
  protected const STATE_ORGANIZER_UID = 'localnodes.organizer_uid';

  /**
   * State key set once the community's first group exists (23-09).
   */
  protected const STATE_FIRST_GROUP = 'localnodes.install_step.first_group';

  /**
   * State key set once the community's four texts are applied (23-34).
   */
  protected const STATE_COMMUNITY_SEED = 'localnodes.install_step.community_seed';

  /**
   * State key that holds the assistant's context text (23-34, ruling S).
   *
   * Read at run time by social_ai_indexing's CommunityContextSubscriber.
   */
  public const STATE_COMMUNITY_CONTEXT = 'localnodes_platform.community_context';

  /**
   * Longest value of LOCALNODES_COMMUNITY_SEED that is looked at (T-23-96).
   *
   * 23-33 keeps the encoded seed under 12,000 characters; anything longer than
   * this is not a seed the sign-up made.
   */
  protected const COMMUNITY_SEED_ENV_MAX_LENGTH = 16384;

  /**
   * The four texts of a community seed, and the most characters each keeps.
   *
   * 23-33 writes them inside these lengths; they are applied again here
   * because the value crossed a workflow and a container start.
   */
  protected const COMMUNITY_SEED_TEXTS = [
    'about' => 600,
    'group_description' => 400,
    'assistant_context' => 600,
    'welcome_post' => 1200,
  ];

  /**
   * Title of the draft welcome post (T-2 d).
   */
  protected const DRAFT_TITLE = 'Draft: welcome post';

  /**
   * Longest site slogan (the tagline field's own limit).
   */
  protected const SLOGAN_MAX_LENGTH = 255;

  /**
   * Longest user name Drupal accepts.
   */
  protected const USERNAME_MAX_LENGTH = UserInterface::USERNAME_MAX_LENGTH;

  /**
   * The most page types one provisioning creates (D-20).
   */
  protected const TOPIC_TYPES_MAX = 12;

  /**
   * Longest value of LOCALNODES_TOPIC_TYPES that is looked at (T-23-30).
   *
   * Twelve labels of 40 characters in JSON and base64 are under 1,000
   * characters, so anything near this size is not a list the sign-up made.
   */
  protected const TOPIC_TYPES_ENV_MAX_LENGTH = 8192;

  /**
   * The sign-up form's page type rule, written again here (T-23-30).
   *
   * Two to 40 characters: letters, digits, spaces and & - ' /. The Nuxt
   * sign-up checks the same rule; this copy is the one that guards the site.
   */
  protected const TOPIC_TYPE_LABEL_PATTERN = '/^[\p{L}\p{N} &\'\-\/]{2,40}\z/u';

  /**
   * Icon given to every new page type (Open Social's own default icon).
   */
  protected const TOPIC_TYPE_ICON = 'icon-topic';

  /**
   * Creates the organizer account and sends the welcome mail.
   *
   * Reads ORGANIZER_EMAIL and ORGANIZER_NAME from the environment. Exit code 0
   * means the mail went out now or on an earlier run. Exit code 1 means a
   * variable is missing or unusable, or the mail was not sent; the marker stays
   * unset so the next start retries (D-17).
   */
  #[CLI\Command(name: 'localnodes:organizer-welcome', aliases: ['ln-organizer-welcome'])]
  #[CLI\Usage(name: 'localnodes:organizer-welcome', description: 'Create the organizer (ORGANIZER_EMAIL, ORGANIZER_NAME) and send the welcome mail once')]
  public function organizerWelcome(): int {
    $state = \Drupal::state();
    if ($state->get(self::STATE_MAIL_SENT)) {
      $this->output()->writeln('Welcome mail already sent.');
      return self::EXIT_SUCCESS;
    }

    $variables = $this->organizerVariables();
    if ($variables === NULL) {
      return self::EXIT_FAILURE;
    }
    [$email, $name] = $variables;

    $account = $this->loadOrCreateAccount($email, $name);
    if (!$account) {
      return self::EXIT_FAILURE;
    }
    // A later activation call finds the same account (23-35).
    $state->set(self::STATE_ORGANIZER_UID, (int) $account->id());

    // The organizer finds a first group ready, named after the community, so
    // the guide can say "open your group" (23-09, research Open Question 7).
    if (!$this->ensureFirstGroup($account)) {
      return self::EXIT_FAILURE;
    }

    // The mail builder makes the one-time sign-in link and puts it in the body.
    $sent = \Drupal::service('email_factory')
      ->newTypedEmail('localnodes_platform', 'organizer_welcome', $account)
      ->send();
    if ($sent !== TRUE) {
      $this->output()->writeln('Welcome mail not sent.');
      return self::EXIT_FAILURE;
    }

    $state->set(self::STATE_MAIL_SENT, gmdate('Y-m-d\TH:i:s\Z'));
    $this->output()->writeln('Welcome mail sent.');
    return self::EXIT_SUCCESS;
  }

  /**
   * Creates a sign-up site's organizer, blocked, and the first group (23-35).
   *
   * Tom's billing rule B-1: a sign-up site must not have a usable organizer
   * login before its first payment. This makes the same account as
   * localnodes:organizer-welcome (sitemanager role, random password) but
   * blocked, makes the first group with the organizer as group manager, and
   * keeps the uid in state for OrganizerActivation. It sends no mail: POST
   * /localnodes/activate sends the welcome mail after the payment.
   *
   * Exit code 0 means the account and group exist (now or from an earlier
   * run). Exit code 1 means a variable is missing or unusable, or the account
   * or group cannot be made; the step marker stays unset so the next start
   * retries. Only fixed sentences are printed, never an address or a link.
   */
  #[CLI\Command(name: 'localnodes:organizer-setup', aliases: ['ln-organizer-setup'])]
  #[CLI\Usage(name: 'localnodes:organizer-setup', description: 'Create the organizer (ORGANIZER_EMAIL, ORGANIZER_NAME) blocked until the first payment, and the first group; no mail')]
  public function organizerSetup(): int {
    $variables = $this->organizerVariables();
    if ($variables === NULL) {
      return self::EXIT_FAILURE;
    }
    [$email, $name] = $variables;

    $account = $this->loadOrCreateAccount($email, $name, TRUE);
    if (!$account) {
      return self::EXIT_FAILURE;
    }
    if (!$this->ensureFirstGroup($account)) {
      return self::EXIT_FAILURE;
    }

    $state = \Drupal::state();
    $state->set(self::STATE_ORGANIZER_UID, (int) $account->id());
    $state->set(self::STATE_ORGANIZER_SETUP, gmdate('Y-m-d\TH:i:s\Z'));
    $this->output()->writeln('Organizer account ready (blocked until the first payment).');
    return self::EXIT_SUCCESS;
  }

  /**
   * Reads and checks ORGANIZER_EMAIL and ORGANIZER_NAME.
   *
   * @return array{0: string, 1: string}|null
   *   The address and the name, or NULL after printing a fixed sentence that
   *   names only the variable, never its value.
   */
  protected function organizerVariables(): ?array {
    $email = trim((string) getenv('ORGANIZER_EMAIL'));
    $name = trim((string) getenv('ORGANIZER_NAME'));
    foreach (['ORGANIZER_EMAIL' => $email, 'ORGANIZER_NAME' => $name] as $variable => $value) {
      if ($value === '') {
        $this->output()->writeln("$variable is not set.");
        return NULL;
      }
    }
    if (!\Drupal::service('email.validator')->isValid($email)) {
      $this->output()->writeln('ORGANIZER_EMAIL is not a valid email address.');
      return NULL;
    }
    return [$email, $name];
  }

  /**
   * Creates the community's first group once, with the organizer as manager.
   *
   * The group is a flexible group named after the community (SITE_NAME), with
   * no address and no location: a community needs no place (PLACE-02, D-23).
   * Only the organizer is added, as group manager (T-23-31). It is visible to
   * signed-in members of the community only, which is the safer start; the
   * organizer can open it up later.
   *
   * Runs once per site: the state key is set when the group exists. Without
   * SITE_NAME there is nothing to name it after, so nothing is made and
   * nothing is printed. A group that an earlier run saved before it could set
   * the key is found again by name and owner, not made twice.
   *
   * @return bool
   *   FALSE (after printing a fixed sentence) when the group cannot be made.
   */
  protected function ensureFirstGroup(UserInterface $account): bool {
    $state = \Drupal::state();
    $name = trim((string) getenv('SITE_NAME'));
    if ($name === '' || $state->get(self::STATE_FIRST_GROUP)) {
      return TRUE;
    }

    $entity_type_manager = \Drupal::entityTypeManager();
    if (!$entity_type_manager->getStorage('group_type')->load('flexible_group')) {
      $this->output()->writeln('The flexible group type does not exist.');
      return FALSE;
    }

    $storage = $entity_type_manager->getStorage('group');
    $label = mb_substr($name, 0, 255);
    $existing = $storage->loadByProperties([
      'type' => 'flexible_group',
      'label' => $label,
      'uid' => $account->id(),
    ]);
    $created = !$existing;
    /** @var \Drupal\group\Entity\GroupInterface $group */
    $group = $existing ? reset($existing) : $storage->create([
      'type' => 'flexible_group',
      'label' => $label,
      'uid' => $account->id(),
      'field_flexible_group_visibility' => 'community',
    ]);
    if ($created) {
      $group->save();
    }

    // The group type makes its creator a group manager when it saves a group;
    // this covers a type that does not, and a group found from an earlier run.
    $role = 'flexible_group-group_manager';
    $member = $group->getMember($account);
    if (!$member) {
      $group->addMember($account, ['group_roles' => [$role]]);
    }
    elseif (!isset($member->getRoles(FALSE)[$role])) {
      $relationship = $member->getGroupRelationship();
      $relationship->set('group_roles', array_merge(array_column($relationship->get('group_roles')->getValue(), 'target_id'), [$role]));
      $relationship->save();
    }

    $state->set(self::STATE_FIRST_GROUP, gmdate('Y-m-d\TH:i:s\Z'));
    $this->output()->writeln($created ? 'First group created.' : 'First group already exists.');
    return TRUE;
  }

  /**
   * Creates the page types the organizer confirmed at sign-up.
   *
   * Reads LOCALNODES_TOPIC_TYPES: a JSON list of names, base64url encoded, set
   * by the provisioning workflow. Every name is checked again with the sign-up
   * form's rule; a name that fails is skipped. A name that matches an existing
   * page type ignoring case is not created, so the four Open Social types and
   * "Reference" stay as they are and a second run adds nothing. Exit code 0
   * means done or nothing to do; exit code 1 means the value is not a list of
   * names, or the vocabulary is missing. The command prints fixed sentences
   * only and never the value.
   */
  #[CLI\Command(name: 'localnodes:topic-types', aliases: ['ln-topic-types'])]
  #[CLI\Usage(name: 'localnodes:topic-types', description: 'Create the page types in LOCALNODES_TOPIC_TYPES that do not exist yet')]
  public function topicTypes(): int {
    $raw = trim((string) getenv('LOCALNODES_TOPIC_TYPES'));
    if ($raw === '') {
      $this->output()->writeln('No page types to add.');
      return self::EXIT_SUCCESS;
    }

    $decoded = $this->decodeTopicTypes($raw);
    if ($decoded === NULL) {
      $this->output()->writeln('LOCALNODES_TOPIC_TYPES is not a list of names.');
      return self::EXIT_FAILURE;
    }

    $entity_type_manager = \Drupal::entityTypeManager();
    if (!$entity_type_manager->getStorage('taxonomy_vocabulary')->load('topic_types')) {
      $this->output()->writeln('The topic_types vocabulary does not exist.');
      return self::EXIT_FAILURE;
    }

    [$labels, $skipped] = $this->cleanTopicTypeLabels($decoded);
    $storage = $entity_type_manager->getStorage('taxonomy_term');

    // New types follow the existing ones, in the organizer's order.
    $weight = 0;
    foreach ($storage->loadByProperties(['vid' => 'topic_types']) as $term) {
      $weight = max($weight, (int) $term->getWeight());
    }

    $added = 0;
    foreach ($labels as $label) {
      $this->beforeTopicTypeSave($label);
      // The vocabulary is read again for every name, right before its save:
      // a second run at the same moment may have made this name since the
      // list was read.
      if ($this->topicTypeExists($label)) {
        continue;
      }
      $term = $storage->create([
        'vid' => 'topic_types',
        'name' => $label,
        'weight' => ++$weight,
      ]);
      if ($term->hasField('field_topic_type_icon')) {
        $term->set('field_topic_type_icon', self::TOPIC_TYPE_ICON);
      }
      $term->save();
      $added++;
    }

    $this->output()->writeln("Added $added page types.");
    if ($skipped > 0) {
      $this->output()->writeln("Skipped $skipped names that are not allowed.");
    }
    return self::EXIT_SUCCESS;
  }

  /**
   * Runs just before a page type is checked and saved.
   *
   * Does nothing. A test overrides it to act as a second writer, to prove the
   * check that follows reads the vocabulary again.
   */
  protected function beforeTopicTypeSave(string $label): void {
  }

  /**
   * Whether a page type with this name exists, ignoring case.
   *
   * Compared in PHP, so the answer does not depend on the database collation.
   */
  protected function topicTypeExists(string $label): bool {
    $wanted = mb_strtolower($label);
    $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')
      ->loadByProperties(['vid' => 'topic_types']);
    foreach ($terms as $term) {
      if (mb_strtolower(trim((string) $term->label())) === $wanted) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Decodes base64url JSON into a list of strings.
   *
   * @return string[]|null
   *   The list, or NULL when the value is anything else.
   */
  protected function decodeTopicTypes(string $raw): ?array {
    $list = $this->decodeBase64UrlJson($raw, self::TOPIC_TYPES_ENV_MAX_LENGTH);
    if ($list === NULL || !array_is_list($list)) {
      return NULL;
    }
    foreach ($list as $label) {
      if (!is_string($label)) {
        return NULL;
      }
    }
    return $list;
  }

  /**
   * Decodes an environment value that is base64url JSON (padding optional).
   *
   * @return array|null
   *   The decoded array or object (as an array), or NULL when the value is too
   *   long, is not base64url, is not JSON, or is JSON of any other kind.
   */
  protected function decodeBase64UrlJson(string $raw, int $max_length): ?array {
    if (strlen($raw) > $max_length || !preg_match('/^[A-Za-z0-9_-]+={0,2}$/', $raw)) {
      return NULL;
    }
    $json = base64_decode(strtr(rtrim($raw, '='), '-_', '+/'), TRUE);
    if ($json === FALSE) {
      return NULL;
    }
    try {
      $decoded = json_decode($json, TRUE, 4, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return NULL;
    }
    return is_array($decoded) ? $decoded : NULL;
  }

  /**
   * Cleans the names: allow-list, no repeats ignoring case, at most twelve.
   *
   * @param string[] $labels
   *   The decoded names.
   *
   * @return array{0: string[], 1: int}
   *   The names to use, and how many were skipped for failing the rule.
   */
  protected function cleanTopicTypeLabels(array $labels): array {
    $clean = [];
    $seen = [];
    $skipped = 0;
    foreach ($labels as $label) {
      $label = trim(preg_replace('/ {2,}/', ' ', trim($label)) ?? '');
      if (!preg_match(self::TOPIC_TYPE_LABEL_PATTERN, $label)) {
        $skipped++;
        continue;
      }
      $key = mb_strtolower($label);
      if (isset($seen[$key])) {
        continue;
      }
      $seen[$key] = TRUE;
      $clean[] = $label;
    }
    return [array_slice($clean, 0, self::TOPIC_TYPES_MAX), $skipped];
  }

  /**
   * Applies the community's four texts from sign-up, once per site.
   *
   * Reads LOCALNODES_COMMUNITY_SEED: base64url JSON of {about,
   * group_description, assistant_context, welcome_post}, set by the
   * provisioning workflow (23-33, 23-34). The texts are organizer-derived, so
   * each is cleaned again here (control characters to spaces, a length cap)
   * and escaped on output.
   *
   * The organizer is found by ORGANIZER_EMAIL and never created, and the
   * command never needs the account to sign in, so it works the same when the
   * account is blocked until activation (23-35). It runs after the account and
   * the first group exist.
   *
   * Runs once: the state marker is set only after every part is done, so a
   * restart never puts the old text over the organizer's edits, and a run that
   * stopped half way finds what it made and carries on. Exit code 0 means
   * applied, applied earlier, or nothing to do; exit code 1 means the value is
   * not a seed, or the organizer account is not there. The command prints fixed
   * sentences only and never the value or a text.
   */
  #[CLI\Command(name: 'localnodes:seed-community', aliases: ['ln-seed-community'])]
  #[CLI\Usage(name: 'localnodes:seed-community', description: 'Apply the community texts in LOCALNODES_COMMUNITY_SEED once (tagline, About page, group description, assistant context, draft welcome post)')]
  public function seedCommunity(): int {
    $raw = trim((string) getenv('LOCALNODES_COMMUNITY_SEED'));
    if ($raw === '') {
      $this->output()->writeln('No community seed.');
      return self::EXIT_SUCCESS;
    }

    $state = \Drupal::state();
    if ($state->get(self::STATE_COMMUNITY_SEED)) {
      $this->output()->writeln('Community seed already applied.');
      return self::EXIT_SUCCESS;
    }

    $texts = $this->decodeCommunitySeed($raw);
    if ($texts === NULL) {
      $this->output()->writeln('LOCALNODES_COMMUNITY_SEED is not a community seed.');
      return self::EXIT_FAILURE;
    }

    $email = trim((string) getenv('ORGANIZER_EMAIL'));
    if ($email === '') {
      $this->output()->writeln('ORGANIZER_EMAIL is not set.');
      return self::EXIT_FAILURE;
    }
    $found = \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['mail' => $email]);
    if (!$found) {
      $this->output()->writeln('The organizer account does not exist.');
      return self::EXIT_FAILURE;
    }
    /** @var \Drupal\user\UserInterface $organizer */
    $organizer = reset($found);

    // Everything is looked up before anything is written, so a site that is
    // not ready changes nothing and is retried whole on the next start.
    $group = $this->findFirstGroup($organizer);
    if (!$group) {
      $this->output()->writeln('The first group does not exist.');
      return self::EXIT_FAILURE;
    }
    $node_types = \Drupal::entityTypeManager()->getStorage('node_type');
    if (!$node_types->load('page') || !$node_types->load('topic')) {
      $this->output()->writeln('The page or topic content type does not exist.');
      return self::EXIT_FAILURE;
    }

    $this->applyTaglineAndAboutPage($organizer, $texts['about']);
    $this->applyGroupDescription($group, $texts['group_description']);
    // The assistant's context is a per-site value read at run time by
    // social_ai_indexing's CommunityContextSubscriber (ruling S); the text is
    // already cleaned, and it is fenced again where it enters the prompt.
    \Drupal::state()->set(self::STATE_COMMUNITY_CONTEXT, $texts['assistant_context']);
    $this->applyDraftWelcomePost($organizer, $group, $texts['welcome_post']);

    // Set last: every part above is done.
    $state->set(self::STATE_COMMUNITY_SEED, gmdate('Y-m-d\TH:i:s\Z'));
    $this->output()->writeln('Community seed applied.');
    return self::EXIT_SUCCESS;
  }

  /**
   * Decodes and cleans the four texts of a community seed.
   *
   * @return array<string, string>|null
   *   The cleaned texts by key, or NULL when the value is not exactly an object
   *   of the four keys with a string each that is not empty once cleaned.
   */
  protected function decodeCommunitySeed(string $raw): ?array {
    $decoded = $this->decodeBase64UrlJson($raw, self::COMMUNITY_SEED_ENV_MAX_LENGTH);
    if ($decoded === NULL || array_is_list($decoded) || count($decoded) !== count(self::COMMUNITY_SEED_TEXTS)) {
      return NULL;
    }
    $texts = [];
    foreach (self::COMMUNITY_SEED_TEXTS as $key => $max_length) {
      if (!isset($decoded[$key]) || !is_string($decoded[$key])) {
        return NULL;
      }
      $text = $this->cleanSeedText($decoded[$key], $max_length);
      if ($text === '') {
        return NULL;
      }
      $texts[$key] = $text;
    }
    return $texts;
  }

  /**
   * Cleans one seed text: control characters to spaces, one paragraph, a cap.
   */
  protected function cleanSeedText(string $text, int $max_length): string {
    $text = preg_replace('/[\p{Cc}\p{Cs}]+/u', ' ', $text) ?? '';
    $text = trim(preg_replace('/\s{2,}/u', ' ', $text) ?? '');
    return rtrim(mb_substr($text, 0, $max_length));
  }

  /**
   * Finds the community's first group the way localnodes:organizer-welcome made it.
   *
   * By type, name (SITE_NAME) and owner. Never creates it.
   */
  protected function findFirstGroup(UserInterface $organizer): ?GroupInterface {
    $name = trim((string) getenv('SITE_NAME'));
    if ($name === '' || !\Drupal::entityTypeManager()->getStorage('group_type')->load('flexible_group')) {
      return NULL;
    }
    $groups = \Drupal::entityTypeManager()->getStorage('group')->loadByProperties([
      'type' => 'flexible_group',
      'label' => mb_substr($name, 0, 255),
      'uid' => $organizer->id(),
    ]);
    return $groups ? reset($groups) : NULL;
  }

  /**
   * Makes a text safe as one paragraph of rich text.
   *
   * @return array{value: string, format: string}
   *   The paragraph escaped as HTML in basic_html, or, on a site without that
   *   format, the text with its tags removed in plain text (which escapes it on
   *   output).
   */
  protected function paragraph(string $text): array {
    if (\Drupal::entityTypeManager()->getStorage('filter_format')->load('basic_html')) {
      return ['value' => '<p>' . Html::escape($text) . '</p>', 'format' => 'basic_html'];
    }
    return ['value' => trim(strip_tags($text)), 'format' => 'plain_text'];
  }

  /**
   * The text of the community name used in titles.
   */
  protected function communityName(): string {
    $name = trim((string) getenv('SITE_NAME'));
    if ($name === '') {
      $name = trim((string) \Drupal::config('system.site')->get('name'));
    }
    return $name;
  }

  /**
   * Sets the tagline and makes the About page, once.
   *
   * The tagline is the About text's first sentence. It is a per-site value, set
   * like the site name that site:install sets; it is not module config. The
   * page is found again by title and owner when an earlier run saved it before
   * it could set the marker.
   */
  protected function applyTaglineAndAboutPage(UserInterface $organizer, string $about): void {
    \Drupal::configFactory()->getEditable('system.site')
      ->set('slogan', $this->firstSentence($about))
      ->save();

    $title = mb_substr('About ' . $this->communityName(), 0, 255);
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    if ($storage->loadByProperties(['type' => 'page', 'title' => $title, 'uid' => $organizer->id()])) {
      return;
    }
    $storage->create([
      'type' => 'page',
      'title' => $title,
      'uid' => $organizer->id(),
      'status' => 1,
      // The same visibility as the first group: the community.
      'field_content_visibility' => 'community',
      'body' => $this->paragraph($about),
    ])->save();
  }

  /**
   * Sets the first group's description, unless it already has one.
   */
  protected function applyGroupDescription(GroupInterface $group, string $text): void {
    if (!$group->hasField('field_group_description') || !$group->get('field_group_description')->isEmpty()) {
      return;
    }
    $group->set('field_group_description', $this->paragraph($text))->save();
  }

  /**
   * Makes the draft welcome post: an unpublished, sticky Topic in the group.
   *
   * It is owned by the organizer, so the organizer and site managers see it and
   * nobody else does until it is published. It is never published here. Found
   * again by title and owner when an earlier run saved it before it could set
   * the marker. The Topic's page type is required; "News" is used.
   */
  protected function applyDraftWelcomePost(UserInterface $organizer, GroupInterface $group, string $text): void {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $existing = $storage->loadByProperties(['type' => 'topic', 'title' => self::DRAFT_TITLE, 'uid' => $organizer->id()]);
    if ($existing) {
      $node = reset($existing);
    }
    else {
      $values = [
        'type' => 'topic',
        'title' => self::DRAFT_TITLE,
        'uid' => $organizer->id(),
        'status' => 0,
        'sticky' => 1,
        'field_content_visibility' => 'community',
        'body' => $this->paragraph($text),
      ];
      $type = $this->welcomePostTopicType();
      if ($type !== NULL) {
        $values['field_topic_type'] = $type;
      }
      $node = $storage->create($values);
      $node->save();
    }
    if (!$group->getRelationshipsByEntity($node, 'group_node:topic')) {
      $group->addRelationship($node, 'group_node:topic');
    }
  }

  /**
   * The page type id for the welcome post: "News", else the first one.
   */
  protected function welcomePostTopicType(): ?int {
    $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties(['vid' => 'topic_types']);
    foreach ($terms as $term) {
      if (mb_strtolower(trim((string) $term->label())) === 'news') {
        return (int) $term->id();
      }
    }
    return $terms ? (int) reset($terms)->id() : NULL;
  }

  /**
   * The first sentence of a text, as plain text of at most 255 characters.
   *
   * A sentence ends at a full stop, question mark or exclamation mark that is
   * followed by a space or the end; a text without one is used whole.
   */
  protected function firstSentence(string $text): string {
    $sentence = preg_match('/^.*?[.!?](?=\s|$)/us', $text, $match) ? $match[0] : $text;
    $sentence = trim(strip_tags($sentence));
    // A lone angle bracket survives strip_tags; a slogan never needs one.
    $sentence = trim(str_replace(['<', '>'], '', $sentence));
    return rtrim(mb_substr($sentence, 0, self::SLOGAN_MAX_LENGTH));
  }

  /**
   * Loads the organizer by exact address, or creates the account.
   *
   * A new account has a random password and only the sitemanager role
   * (T-23-14, never administrator). It is active, or blocked when $blocked is
   * TRUE (a sign-up site before its first payment, 23-35). An account that
   * already exists is left as it is: no role is added and its status is not
   * changed.
   *
   * @return \Drupal\user\UserInterface|null
   *   The account, or NULL (after printing a fixed sentence) when it cannot be
   *   created.
   */
  protected function loadOrCreateAccount(string $email, string $name, bool $blocked = FALSE): ?UserInterface {
    $storage = \Drupal::entityTypeManager()->getStorage('user');
    $existing = $storage->loadByProperties(['mail' => $email]);
    if ($existing) {
      return reset($existing);
    }

    if (!\Drupal::entityTypeManager()->getStorage('user_role')->load('sitemanager')) {
      $this->output()->writeln('The sitemanager role does not exist.');
      return NULL;
    }

    // Marked before the account exists, as the entrypoint did: from here on an
    // account with this address is this command's own.
    \Drupal::state()->set(self::STATE_ACCOUNT, gmdate('Y-m-d\TH:i:s\Z'));

    $account = $storage->create([
      'name' => $this->uniqueUsername($name),
      'mail' => $email,
      'pass' => bin2hex(random_bytes(16)),
      'status' => $blocked ? 0 : 1,
      'roles' => ['sitemanager'],
    ]);
    $account->save();
    return $account;
  }

  /**
   * Builds a free user name from the organizer name, "<name> Admin".
   *
   * "<name> Admin" is the name the entrypoint gave this account. Characters
   * Drupal refuses in user names are dropped and spaces collapsed; a name that
   * is already taken gets " 2", " 3" and so on.
   */
  protected function uniqueUsername(string $name): string {
    // The character class is core's own (UserNameConstraintValidator).
    $clean = preg_replace('/[^\x{80}-\x{F7} a-z0-9@+_.\'-]/iu', '', $name) ?? '';
    $clean = trim(preg_replace('/ +/', ' ', $clean) ?? '');
    if ($clean === '') {
      $clean = 'Community';
    }
    $storage = \Drupal::entityTypeManager()->getStorage('user');
    for ($n = 1;; $n++) {
      $suffix = ' Admin' . ($n > 1 ? " $n" : '');
      $base = rtrim(mb_substr($clean, 0, self::USERNAME_MAX_LENGTH - mb_strlen($suffix)));
      $candidate = $base . $suffix;
      if (!$storage->loadByProperties(['name' => $candidate])) {
        return $candidate;
      }
    }
  }

}
