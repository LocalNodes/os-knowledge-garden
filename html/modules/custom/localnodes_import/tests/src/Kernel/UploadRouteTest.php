<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_import\Kernel;

use Drupal\file\FileInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\Tests\localnodes_import\Kernel\Support\UploadRequestTrait;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The one-file JSON upload endpoint (D-11, T-22-35, T-22-37).
 *
 * @group phase22
 * @group localnodes_import
 */
final class UploadRouteTest extends ImportKernelTestBase {

  use UploadRequestTrait;

  private const ROUTE = 'localnodes_import.upload_file';
  private const CONTROLLER = 'Drupal\localnodes_import\Controller\UploadController';

  /**
   * The group files are uploaded to.
   */
  private GroupInterface $group;

  /**
   * An organizer of the group.
   */
  private UserInterface $manager;

  /**
   * A plain member of the group.
   */
  private UserInterface $member;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->group = $this->createFlexibleGroup('Orchard group');
    $this->manager = $this->createVerifiedUser('orchard_organizer');
    $this->addManager($this->group, $this->manager);
    $this->member = $this->createVerifiedUser('orchard_member');
    $this->addMember($this->group, $this->member);
  }

  /**
   * The route exists, is POST and JSON only, and needs the header token.
   */
  public function testRouteDefinition(): void {
    $this->assertRouteExists();
    $route = $this->container->get('router.route_provider')->getRouteByName(self::ROUTE);
    $this->assertSame('/group/{group}/import/files/upload', $route->getPath());
    $this->assertSame(['POST'], $route->getMethods());
    $this->assertSame('TRUE', $route->getRequirement('_csrf_request_header_token'));
    $this->assertSame('import content into group', $route->getRequirement('_group_permission'));
    $this->assertSame('json', $route->getRequirement('_format'));
  }

  /**
   * No header token: 403. A member with a token: 403. An organizer: allowed.
   */
  public function testAccess(): void {
    $this->assertRouteExists();
    $access = $this->container->get('access_manager');

    $this->setCurrentUser($this->manager);
    $no_header = $this->sessionRequest();
    $this->assertFalse($access->checkRequest($no_header, $this->manager), 'An organizer without the X-CSRF-Token header gets 403');

    $bad_header = $this->sessionRequest();
    $bad_header->headers->set('X-CSRF-Token', 'not-a-token');
    $this->assertFalse($access->checkRequest($bad_header, $this->manager), 'A wrong token gets 403');

    $good = $this->sessionRequest();
    $good->headers->set('X-CSRF-Token', $this->token());
    $this->assertTrue($access->checkRequest($good, $this->manager), 'An organizer with a valid token can upload');

    $this->setCurrentUser($this->member);
    $member = $this->sessionRequest();
    $member->headers->set('X-CSRF-Token', $this->token());
    $this->assertFalse($access->checkRequest($member, $this->member), 'A plain member with a valid token gets 403');
  }

  /**
   * A PDF: 200 with a fid, temporary, owned by the uploader, bound in tempstore.
   */
  public function testUploadOnePdf(): void {
    $this->assertControllerExists();
    $this->setCurrentUser($this->manager);
    $response = $this->upload($this->fixtureUpload('pdf-01-tool-share-rules.pdf', 'Tool share rules.pdf'));

    $this->assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    $body = json_decode((string) $response->getContent(), TRUE);
    $this->assertIsInt($body['fid'] ?? NULL, 'The response carries the new file id');
    $file = $this->container->get('entity_type.manager')->getStorage('file')->load($body['fid']);
    $this->assertInstanceOf(FileInterface::class, $file);
    $this->assertTrue($file->isTemporary(), 'The upload is a temporary file until the import runs');
    $this->assertSame((int) $this->manager->id(), (int) $file->getOwnerId());
    $this->assertStringStartsWith('private://localnodes_import/' . $this->group->id() . '/', $file->getFileUri());

    $bound = $this->container->get('tempstore.private')->get('localnodes_import')->get('uploads:' . $this->group->id());
    $this->assertIsArray($bound);
    $this->assertArrayHasKey($body['fid'], $bound, 'The fid is bound to this uploader and group');
    $this->assertSame('Tool share rules.pdf', $bound[$body['fid']], 'The uploaded name is kept for the title and report');
  }

  /**
   * Refused uploads: 422 with the copy-table string and no file entity.
   */
  public function testRefusedUploads(): void {
    $this->assertControllerExists();
    $this->setCurrentUser($this->manager);
    $file_storage = $this->container->get('entity_type.manager')->getStorage('file');

    $cases = [
      'setup.exe' => [
        $this->fakeUpload('setup.exe', 'MZ fake executable'),
        "This type of file can't be imported. Choose a PDF, Word, OpenDocument, text or Markdown file.",
      ],
      'budget.xlsx' => [
        $this->fixtureUpload('budget.xlsx'),
        "Spreadsheets and slides can't be imported yet. Save it as a PDF, then add it again.",
      ],
      'huge.pdf' => [
        $this->bigUpload('huge.pdf', 21 * 1048576),
        'This file is bigger than 20 MB. Make it smaller or split it, then add it again.',
      ],
      'blank.txt' => [
        $this->fakeUpload('blank.txt', ''),
        'This file is empty.',
      ],
    ];
    foreach ($cases as $name => [$upload, $copy]) {
      $before = count($file_storage->loadMultiple());
      $response = $this->upload($upload);
      $this->assertSame(422, $response->getStatusCode(), "$name is refused");
      $body = json_decode((string) $response->getContent(), TRUE);
      $this->assertSame($copy, $body['error'] ?? NULL, "$name gets the matching copy");
      $this->assertCount($before, $file_storage->loadMultiple(), "$name leaves no file entity behind");
    }
    $bound = $this->container->get('tempstore.private')->get('localnodes_import')->get('uploads:' . $this->group->id());
    $this->assertEmpty($bound, 'Nothing refused is bound for the form');
  }

  /**
   * Calls the controller with one file in the 'file' field.
   */
  private function upload(UploadedFile $upload): Response {
    $request = $this->routedRequest(self::ROUTE, $this->group);
    $request->files->set('file', $upload);
    $controller = $this->container->get('class_resolver')->getInstanceFromDefinition(self::CONTROLLER);
    return $controller->upload($this->group, $request);
  }

  /**
   * A sparse file of $bytes posing as an upload.
   */
  private function bigUpload(string $client_name, int $bytes): UploadedFile {
    $path = tempnam(sys_get_temp_dir(), 'lnimp');
    $handle = fopen($path, 'w');
    fwrite($handle, '%PDF-1.4');
    ftruncate($handle, $bytes);
    fclose($handle);
    return new UploadedFile($path, $client_name, 'application/pdf', UPLOAD_ERR_OK, TRUE);
  }

  /**
   * A POST to the upload route that carries a session cookie.
   *
   * The header check only applies to authenticated requests with a session
   * cookie (CsrfRequestHeaderAccessCheck::access()).
   */
  private function sessionRequest(): Request {
    $request = $this->routedRequest(self::ROUTE, $this->group, 'POST', FALSE);
    $name = $this->container->get('session_configuration')->getOptions($request)['name'];
    $request->cookies->set($name, 'test-session');
    return $request;
  }

  /**
   * A valid header token for the current session.
   */
  private function token(): string {
    return $this->container->get('csrf_token')->get('X-CSRF-Token request header');
  }

  /**
   * Fails (rather than errors) while the route is missing.
   */
  private function assertRouteExists(): void {
    $routes = $this->container->get('router.route_provider')->getRoutesByNames([self::ROUTE]);
    $this->assertNotEmpty($routes, 'The upload route exists');
  }

  /**
   * Fails (rather than errors) while the controller is missing.
   */
  private function assertControllerExists(): void {
    $this->assertTrue(class_exists(self::CONTROLLER), 'UploadController exists');
    $this->assertRouteExists();
  }

}
