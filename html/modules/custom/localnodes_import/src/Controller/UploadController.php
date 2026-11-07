<?php

declare(strict_types=1);

namespace Drupal\localnodes_import\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\file\Plugin\Validation\Constraint\FileExtensionConstraint;
use Drupal\file\Plugin\Validation\Constraint\FileSizeLimitConstraint;
use Drupal\file\Upload\FileUploadHandler;
use Drupal\file\Upload\FormUploadedFile;
use Drupal\group\Entity\GroupInterface;
use Drupal\localnodes_import\UploadRules;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * One-file JSON upload for S2 "Upload files" (D-11, RESEARCH Pattern 1).
 *
 * upload.js sends each chosen file in its own POST, so PHP's
 * max_file_uploads (20) and post_max_size never drop a file. The route
 * requires the X-CSRF-Token header and the group import permission
 * (T-22-35). Core's upload handler validates the file (T-22-37) and stores
 * it as a temporary private file. Its id is bound to this user and group in
 * private tempstore; the form only accepts bound ids (T-22-36).
 */
final class UploadController extends ControllerBase {

  /**
   * Private tempstore collection for bound uploads.
   */
  public const TEMPSTORE = 'localnodes_import';

  public function __construct(
    private readonly FileUploadHandler $uploadHandler,
    private readonly FileSystemInterface $fileSystem,
    private readonly PrivateTempStoreFactory $tempStoreFactory,
    private readonly UploadRules $rules,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('file.upload_handler'),
      $container->get('file_system'),
      $container->get('tempstore.private'),
      $container->get('localnodes_import.upload_rules'),
      $container->get('logger.channel.localnodes_import'),
    );
  }

  /**
   * The tempstore key holding a group's bound uploads.
   */
  public static function tempStoreKey(int|string $gid): string {
    return 'uploads:' . $gid;
  }

  /**
   * Accepts exactly one file in the "file" field.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   200 {"fid": int}, or 422 {"error": copy-table string}.
   */
  public function upload(GroupInterface $group, Request $request): JsonResponse {
    $uploaded = $request->files->get('file');
    if (!$uploaded instanceof UploadedFile) {
      return $this->refuse($this->t('Choose at least one file to import.'));
    }
    $name = $this->rules->cleanFileName($uploaded->getClientOriginalName());
    if (!$uploaded->isValid()) {
      if (in_array($uploaded->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], TRUE)) {
        return $this->refuse($this->rules->refusalMessage(UploadRules::REFUSED_TOO_BIG, $name));
      }
      return $this->refuse($this->t('Something went wrong on our side. Try importing it again.'));
    }

    // The same refusals upload.js shows before sending, checked again here.
    $refusal = $this->rules->refusalFor($name, (int) $uploaded->getSize());
    if ($refusal !== NULL) {
      return $this->refuse($this->rules->refusalMessage($refusal, $name));
    }

    $gid = (int) $group->id();
    $destination = 'private://localnodes_import/' . $gid;
    if (!$this->fileSystem->prepareDirectory($destination, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      $this->logger->error('Upload refused: the private folder @dir cannot be written.', ['@dir' => $destination]);
      return new JsonResponse(['error' => (string) $this->t('Something went wrong on our side. Try importing it again.')], 500);
    }

    try {
      $result = $this->uploadHandler->handleFileUpload(new FormUploadedFile($uploaded), $this->rules->validators(), $destination, FileExists::Rename, FALSE);
    }
    catch (\Throwable $e) {
      $this->logger->error('Upload of @name failed: @message', ['@name' => $name, '@message' => $e->getMessage()]);
      return new JsonResponse(['error' => (string) $this->t('Something went wrong on our side. Try importing it again.')], 500);
    }

    if ($result->hasViolations()) {
      $violation = $result->getViolations()->get(0);
      $constraint = $violation->getConstraint();
      $message = match (TRUE) {
        $constraint instanceof FileExtensionConstraint => $this->rules->refusalMessage(UploadRules::REFUSED_TYPE, $name),
        $constraint instanceof FileSizeLimitConstraint => $this->rules->refusalMessage(UploadRules::REFUSED_TOO_BIG, $name),
        default => (string) $violation->getMessage(),
      };
      return $this->refuse($message);
    }

    $file = $result->getFile();
    $store = $this->tempStoreFactory->get(self::TEMPSTORE);
    $key = self::tempStoreKey($gid);
    $bound = $store->get($key);
    $bound = is_array($bound) ? $bound : [];
    $bound[(int) $file->id()] = $name !== '' ? $name : $file->getFilename();
    $store->set($key, $bound);

    return new JsonResponse(['fid' => (int) $file->id()]);
  }

  /**
   * A 422 with one copy-table string.
   */
  private function refuse(string|\Stringable $message): JsonResponse {
    return new JsonResponse(['error' => (string) $message], 422);
  }

}
