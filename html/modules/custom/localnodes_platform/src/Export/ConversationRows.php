<?php

declare(strict_types=1);

namespace Drupal\localnodes_platform\Export;

use Drupal\Core\Database\Connection;

/**
 * Finds the rows outside the message tables that refer to a conversation.
 *
 * When one member writes to another, Open Social records more than the
 * message itself: a "message" entity and an "activity" for the notice (with
 * the sender, the recipient and a link to the conversation), the activity's
 * read status and digest entries, queued jobs that carry the same ids, and
 * a per-person "last read" entry for the conversation in users_data, and
 * the usage record of any file put into a message. The export leaves the
 * message tables out; this class says which of these other rows, and which
 * files, go with them.
 *
 * Rows are matched by id, never by text: most of them hold only numbers.
 */
final class ConversationRows {

  /**
   * Entity types that are a conversation or a message in one.
   */
  public const PRIVATE_TYPES = ['private_message', 'private_message_thread'];

  /**
   * Message ids that refer to a conversation, or NULL until computed.
   *
   * @var array<int, true>|null
   */
  private ?array $messageIds = NULL;

  /**
   * Activity ids that refer to a conversation, or NULL until computed.
   *
   * @var array<int, true>|null
   */
  private ?array $activityIds = NULL;

  /**
   * Files used only in conversations, by id, or NULL until computed.
   *
   * @var array<int, string>|null
   */
  private ?array $files = NULL;

  public function __construct(private readonly Connection $database) {}

  /**
   * The tables that hold such rows on this site, with the column each is
   * matched on.
   *
   * @return array<string, string>
   *   Unprefixed table name => key column ('' for the queue tables, which
   *   are matched on their contents).
   *
   * @throws \RuntimeException
   *   When a table of that kind lacks the expected column: the export
   *   refuses rather than guess.
   */
  public function tables(): array {
    $schema = $this->database->schema();
    $tables = [];
    foreach (['message' => 'mid', 'message_field_data' => 'mid', 'activity' => 'id', 'activity_field_data' => 'id', 'activity_notification_status' => 'aid', 'user_activity_digest' => 'activity', 'users_data' => 'module', 'file_usage' => 'id', 'file_managed' => 'fid', 'queue' => '', 'advancedqueue' => ''] as $table => $column) {
      if ($schema->tableExists($table)) {
        $tables[$table] = $column;
      }
    }
    // Field tables: message__*, activity__* (LIKE treats "_" as any
    // character, so the prefix is checked again here).
    foreach (['message', 'activity'] as $base) {
      foreach ($schema->findTables($base . '%') as $table) {
        if (str_starts_with($table, $base . '__')) {
          $tables[$table] = 'entity_id';
        }
      }
    }
    foreach ($tables as $table => $column) {
      if ($column !== '' && !$schema->fieldExists($table, $column)) {
        throw new \RuntimeException("Export refused: table $table has no $column column, so its rows about private messages cannot be found.");
      }
    }
    foreach (['queue' => ['name', 'data'], 'advancedqueue' => ['type', 'payload'], 'file_usage' => ['fid', 'type'], 'file_managed' => ['uri']] as $table => $columns) {
      foreach (isset($tables[$table]) ? $columns : [] as $column) {
        if (!$schema->fieldExists($table, $column)) {
          throw new \RuntimeException("Export refused: table $table has no $column column, so its rows about private messages cannot be found.");
        }
      }
    }
    ksort($tables);
    return $tables;
  }

  /**
   * The "message" entity ids that refer to a conversation.
   *
   * @return array<int, true>
   */
  public function messageIds(): array {
    if ($this->messageIds !== NULL) {
      return $this->messageIds;
    }
    $ids = [];
    $schema = $this->database->schema();
    if ($schema->tableExists('message__field_message_related_object')) {
      $query = $this->database->select('message__field_message_related_object', 'r')
        ->fields('r', ['entity_id'])
        ->condition('r.field_message_related_object_target_type', self::PRIVATE_TYPES, 'IN');
      foreach ($query->execute()->fetchCol() as $id) {
        $ids[(int) $id] = TRUE;
      }
    }
    // Open Social's own private message fields on a message, when set.
    foreach (['message__field_message_private_message', 'message__field_message_pm_thread'] as $table) {
      if ($schema->tableExists($table)) {
        foreach ($this->database->select($table, 't')->fields('t', ['entity_id'])->execute()->fetchCol() as $id) {
          $ids[(int) $id] = TRUE;
        }
      }
    }
    return $this->messageIds = $ids;
  }

  /**
   * The activity ids that refer to a conversation.
   *
   * An activity refers to one when its related entity is a private message
   * or a conversation, or when the message it was made from refers to one.
   *
   * @return array<int, true>
   */
  public function activityIds(): array {
    if ($this->activityIds !== NULL) {
      return $this->activityIds;
    }
    $ids = [];
    $schema = $this->database->schema();
    if ($schema->tableExists('activity__field_activity_entity')) {
      $query = $this->database->select('activity__field_activity_entity', 'e')
        ->fields('e', ['entity_id'])
        ->condition('e.field_activity_entity_target_type', self::PRIVATE_TYPES, 'IN');
      foreach ($query->execute()->fetchCol() as $id) {
        $ids[(int) $id] = TRUE;
      }
    }
    $messages = array_keys($this->messageIds());
    if ($messages && $schema->tableExists('activity__field_activity_message')) {
      foreach (array_chunk($messages, 1000) as $chunk) {
        $query = $this->database->select('activity__field_activity_message', 'm')
          ->fields('m', ['entity_id'])
          ->condition('m.field_activity_message_target_id', $chunk, 'IN');
        foreach ($query->execute()->fetchCol() as $id) {
          $ids[(int) $id] = TRUE;
        }
      }
    }
    return $this->activityIds = $ids;
  }

  /**
   * The files used in conversations and nowhere else.
   *
   * A file put into a message (an inline image) is recorded in file_usage
   * against the message. A file also used elsewhere stays in the export;
   * only its usage rows for the conversation are left out.
   *
   * @return array<int, string>
   *   File id => its URI.
   */
  public function files(): array {
    if ($this->files !== NULL) {
      return $this->files;
    }
    $files = [];
    $schema = $this->database->schema();
    if ($schema->tableExists('file_usage') && $schema->tableExists('file_managed')) {
      $fids = $this->database->select('file_usage', 'u')->fields('u', ['fid'])
        ->condition('u.type', self::PRIVATE_TYPES, 'IN')->distinct()->execute()->fetchCol();
      foreach (array_chunk($fids, 1000) as $chunk) {
        $shared = $this->database->select('file_usage', 'u')->fields('u', ['fid'])
          ->condition('u.fid', $chunk, 'IN')->condition('u.type', self::PRIVATE_TYPES, 'NOT IN')->distinct()->execute()->fetchCol();
        $only = array_diff($chunk, $shared);
        if ($only) {
          $query = $this->database->select('file_managed', 'f')->fields('f', ['fid', 'uri'])->condition('f.fid', $only, 'IN');
          foreach ($query->execute()->fetchAllKeyed() as $fid => $uri) {
            $files[(int) $fid] = (string) $uri;
          }
        }
      }
    }
    ksort($files);
    return $this->files = $files;
  }

  /**
   * One row as it goes into the export: unchanged, changed, or NULL to leave
   * it out.
   *
   * @param string $table
   *   The unprefixed table name, one of tables().
   * @param array<string, mixed> $row
   *   The row, by column name.
   */
  public function keep(string $table, string $column, array $row): ?array {
    if ($table === 'users_data') {
      // "Last read" times per conversation, keyed by its id.
      return ($row['module'] ?? NULL) === 'private_message' ? NULL : $row;
    }
    if ($table === 'file_usage') {
      return in_array($row['type'] ?? NULL, self::PRIVATE_TYPES, TRUE) ? NULL : $row;
    }
    if ($table === 'file_managed') {
      return isset($this->files()[(int) ($row['fid'] ?? 0)]) ? NULL : $row;
    }
    if ($table === 'queue') {
      return $this->keepQueueItem($row);
    }
    if ($table === 'advancedqueue') {
      return $this->keepJob($row);
    }
    $ids = str_starts_with($table, 'message') ? $this->messageIds() : $this->activityIds();
    return isset($ids[(int) ($row[$column] ?? 0)]) ? NULL : $row;
  }

  /**
   * Whether the table has at least one row keep() would change or leave out.
   */
  public function touches(string $table, string $column): bool {
    if ($table === 'users_data') {
      return (bool) $this->database->select('users_data', 'u')->condition('u.module', 'private_message')->countQuery()->execute()->fetchField();
    }
    if ($table === 'file_usage') {
      return (bool) $this->database->select('file_usage', 'u')->condition('u.type', self::PRIVATE_TYPES, 'IN')->countQuery()->execute()->fetchField();
    }
    if ($table === 'file_managed') {
      return $this->files() !== [];
    }
    if ($table === 'queue' || $table === 'advancedqueue') {
      foreach ($this->rows($table) as $row) {
        if ($this->keep($table, $column, $row) !== $row) {
          return TRUE;
        }
      }
      return FALSE;
    }
    $ids = array_keys(str_starts_with($table, 'message') ? $this->messageIds() : $this->activityIds());
    foreach (array_chunk($ids, 1000) as $chunk) {
      if ($this->database->select($table, 't')->condition("t.$column", $chunk, 'IN')->countQuery()->execute()->fetchField()) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Every row of a table, by column name.
   *
   * @return iterable<array<string, mixed>>
   */
  public function rows(string $table): iterable {
    return $this->database->select($table, 't')->fields('t')->execute()->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * Whether the site has any conversation for a queued item to name.
   *
   * Without the conversation tables or any conversation id, no queued item
   * can name one, and the queues go into the copy unchanged.
   */
  private function hasConversations(): bool {
    $schema = $this->database->schema();
    foreach (self::PRIVATE_TYPES as $table) {
      if ($schema->tableExists($table)) {
        return TRUE;
      }
    }
    return $this->messageIds() !== [] || $this->activityIds() !== [];
  }

  /**
   * A queued payload as an array of its fields.
   *
   * An object payload (unserialized without its class) gives its property
   * names; another value names nothing. NULL when the data cannot be read.
   * $is_array says whether the payload itself is an array.
   */
  private static function payloadFields(string $serialized, ?bool &$is_array = NULL): ?array {
    $data = @unserialize($serialized, ['allowed_classes' => FALSE]);
    $is_array = is_array($data);
    if ($data === FALSE && $serialized !== serialize(FALSE)) {
      return NULL;
    }
    if (is_array($data)) {
      return $data;
    }
    if (!is_object($data)) {
      return [];
    }
    $fields = [];
    foreach ((array) $data as $name => $value) {
      // Protected and private property names carry a "\0...\0" prefix.
      $fields[substr((string) $name, (int) strrpos("\0" . $name, "\0"))] = $value;
    }
    unset($fields['__PHP_Incomplete_Class_Name']);
    return $fields;
  }

  /**
   * A core queue item: Open Social's activity queues carry message ids,
   * activity ids and the related entity.
   */
  private function keepQueueItem(array $row): ?array {
    $name = (string) ($row['name'] ?? '');
    if (!str_starts_with($name, 'activity') || !$this->hasConversations()) {
      return $row;
    }
    $data = self::payloadFields((string) ($row['data'] ?? ''), $is_array);
    if ($data === NULL) {
      // Unreadable: it cannot be shown not to name a conversation.
      return NULL;
    }
    if (isset($data['mid']) && isset($this->messageIds()[(int) $data['mid']])) {
      return NULL;
    }
    foreach ((array) ($data['related_object'] ?? []) as $related) {
      if (is_array($related) && in_array($related['target_type'] ?? NULL, self::PRIVATE_TYPES, TRUE)) {
        return NULL;
      }
    }
    if (in_array($data['entity_type'] ?? NULL, self::PRIVATE_TYPES, TRUE)) {
      return NULL;
    }
    // Where no entity type is given (the send and digest queues), these
    // fields carry an activity id or a message id. With an entity type
    // (activity_logger_message), entity_id belongs to that type, checked
    // above.
    $fields = ['activity' => $this->activityIds(), 'activity_id' => $this->activityIds(), 'aid' => $this->activityIds(), 'message' => $this->messageIds(), 'message_id' => $this->messageIds()];
    if (!isset($data['entity_type'])) {
      $fields['entity_id'] = $this->activityIds();
    }
    foreach ($fields as $field => $ids) {
      foreach ((array) ($data[$field] ?? []) as $id) {
        if (is_scalar($id) && isset($ids[(int) $id])) {
          return NULL;
        }
      }
    }
    if (isset($data['activities']) && is_array($data['activities'])) {
      $rest = array_values(array_filter($data['activities'], fn ($id): bool => !isset($this->activityIds()[(int) $id])));
      if (count($rest) !== count($data['activities'])) {
        // An object payload cannot be rewritten without its class.
        if ($rest === [] || !$is_array) {
          return NULL;
        }
        $data['activities'] = $rest;
        $row['data'] = serialize($data);
      }
    }
    return $row;
  }

  /**
   * An Advanced Queue job: the activity mail jobs carry an activity id.
   */
  private function keepJob(array $row): ?array {
    if (!str_starts_with((string) ($row['type'] ?? ''), 'activity') || !$this->hasConversations()) {
      return $row;
    }
    $payload = json_decode((string) ($row['payload'] ?? ''), TRUE);
    if (!is_array($payload)) {
      return NULL;
    }
    if (isset($payload['entity_id']) && isset($this->activityIds()[(int) $payload['entity_id']])) {
      return NULL;
    }
    return $row;
  }

}
