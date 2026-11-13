<?php

declare(strict_types=1);

namespace Drupal\localnodes_web3\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\localnodes_platform\Export\ExportManager;
use Drupal\localnodes_platform\Export\ExportSignatureProofInterface;
use Drupal\Core\Flood\FloodInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/** Issues proof text, never an export or a download. */
final class ExportChallengeController extends ControllerBase {
  public function __construct(private readonly ExportSignatureProofInterface $proof, private readonly ExportManager $exports, private readonly FloodInterface $flood) {}
  public static function create(ContainerInterface $container): self {
    return new self($container->get('localnodes_web3.export_proof'), $container->get('localnodes_platform.export_manager'), $container->get('flood'));
  }
  public function challenge(Request $request): JsonResponse {
    $result = ['error' => 'We could not confirm it is you. Try again or ask for export help.']; $status = 403;
    try {
      $uid = (int) $this->currentUser()->id();
      $input = json_decode($request->getContent(), TRUE, 8, JSON_THROW_ON_ERROR);
      $action = $input['action'] ?? NULL; $id = $input['exportId'] ?? NULL;
      if (!is_string($action) || !is_string($id) || !$this->flood->isAllowed('localnodes_export_confirm', 5, 3600, (string) $uid)) throw new \RuntimeException();
      if ($action !== 'request' && ($this->exports->current()['id'] ?? NULL) !== $id) throw new \RuntimeException();
      if (!$this->proof->available($uid)) throw new \RuntimeException();
      $result = $this->proof->issue($uid, $action, $id); $status = 200;
    }
    catch (\Throwable) {}
    return new JsonResponse($result, $status, ['Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache', 'Referrer-Policy' => 'no-referrer']);
  }
}
