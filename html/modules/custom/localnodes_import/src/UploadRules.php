<?php

declare(strict_types=1);

namespace Drupal\localnodes_import;

use Drupal\Component\Utility\Environment;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * What the file importer accepts, shared by the form and the JSON endpoint.
 *
 * One place for the extension allow-list, the per-file size limit, the
 * refusal reason codes and their copy (UI-SPEC copy table), so the no-JS
 * form, the upload endpoint and upload.js (through drupalSettings) agree.
 */
final class UploadRules {

  use StringTranslationTrait;

  public const REFUSED_TYPE = 'unsupported_type';
  public const REFUSED_TOO_BIG = 'too_big';
  public const REFUSED_EMPTY = 'empty';

  /**
   * Extensions that get the "spreadsheets and slides" copy.
   */
  public const SPREADSHEETS_AND_SLIDES = [
    'csv', 'numbers', 'ods', 'xls', 'xlsm', 'xlsx',
    'key', 'odp', 'pps', 'ppsx', 'ppt', 'pptx',
  ];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly FileSystemInterface $fileSystem,
  ) {}

  /**
   * Extensions from localnodes_import.settings.
   *
   * @return string[]
   *   Lower-case extensions without dots.
   */
  public function allowedExtensions(): array {
    $list = (string) $this->configFactory->get('localnodes_import.settings')->get('allowed_extensions');
    return array_values(array_filter(preg_split('/\s+/', strtolower($list)) ?: []));
  }

  /**
   * The per-file limit: the setting, capped by PHP's own upload limit.
   */
  public function maxFileBytes(): int {
    $setting = (int) $this->configFactory->get('localnodes_import.settings')->get('max_file_mb') * 1048576;
    $php = (int) Environment::getUploadMaxSize();
    return $php > 0 ? min($setting, $php) : $setting;
  }

  /**
   * The per-file limit in whole MB, for help text and copy.
   */
  public function maxFileMegabytes(): int {
    return (int) floor($this->maxFileBytes() / 1048576);
  }

  /**
   * Core upload validators for one importer file (T-22-37).
   */
  public function validators(): array {
    return [
      'FileExtension' => ['extensions' => implode(' ', $this->allowedExtensions())],
      'FileSizeLimit' => ['fileLimit' => $this->maxFileBytes()],
      'FileNameLength' => [],
    ];
  }

  /**
   * The refusal reason for a file, or NULL when it can be sent.
   *
   * A pre-check only: core's upload handler still validates every file.
   */
  public function refusalFor(string $name, int $size): ?string {
    if (!in_array($this->extension($name), $this->allowedExtensions(), TRUE)) {
      return self::REFUSED_TYPE;
    }
    if ($size === 0) {
      return self::REFUSED_EMPTY;
    }
    if ($size > $this->maxFileBytes()) {
      return self::REFUSED_TOO_BIG;
    }
    return NULL;
  }

  /**
   * The copy-table string for a refusal.
   */
  public function refusalMessage(string $reason, string $name = ''): TranslatableMarkup {
    return match ($reason) {
      self::REFUSED_TOO_BIG => $this->t('This file is bigger than @limit MB. Make it smaller or split it, then add it again.', ['@limit' => $this->maxFileMegabytes()]),
      self::REFUSED_EMPTY => $this->t('This file is empty.'),
      default => in_array($this->extension($name), self::SPREADSHEETS_AND_SLIDES, TRUE)
        ? $this->t("Spreadsheets and slides can't be imported yet. Save it as a PDF, then add it again.")
        : $this->t("This type of file can't be imported. Choose a PDF, Word, OpenDocument, text or Markdown file."),
    };
  }

  /**
   * The browser-supplied file name, reduced to safe display text.
   *
   * Only ever shown as text (Topic title, report cell); never used as a
   * path. Directory parts and control characters are dropped.
   */
  public function cleanFileName(string $name): string {
    // FileSystem::basename() is multibyte-safe; PHP's basename() is locale
    // dependent.
    $name = $this->fileSystem->basename(str_replace('\\', '/', $name));
    $name = (string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $name);
    return mb_substr(trim($name), 0, 255);
  }

  /**
   * Lower-case extension of a file name, without the dot.
   */
  private function extension(string $name): string {
    $dot = strrpos($name, '.');
    return $dot === FALSE ? '' : strtolower(substr($name, $dot + 1));
  }

}
