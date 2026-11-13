<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Unit\Export;

use Drupal\Core\Logger\LoggerChannel;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\localnodes_web3\Export\ExportProofLogs;
use Drupal\localnodes_web3\Export\ExportProofLoggerFactory;
use Drupal\siwe_login\Exception\InvalidSiweMessageException;
use Drupal\siwe_login\Siwe\SignatureVerifier;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/** @group localnodes_platform */
final class ExportSignatureExceptionLogTest extends UnitTestCase {
  public function testGlobalExceptionLoggerIsQuietOnlyDuringExportProof(): void {
    $messages = [];
    $backend = $this->createMock(LoggerInterface::class);
    $backend->method('log')->willReturnCallback(function ($level, $message, array $context) use (&$messages): void { $messages[] = [$message, $context]; });
    $channel = new LoggerChannel('siwe_login');
    $channel->setLoggers([0 => [$backend]]);
    $inner = $this->createMock(LoggerChannelFactoryInterface::class);
    $inner->method('get')->willReturn($channel);
    $factory = new ExportProofLoggerFactory($inner);
    $container = new ContainerBuilder();
    $container->set('logger.factory', $factory);
    $previous = \Drupal::hasContainer() ? \Drupal::getContainer() : NULL;
    \Drupal::setContainer($container);
    try {
      ExportProofLogs::quiet(function (): void {
        try { (new SignatureVerifier())->recoverAddress('PRIVATE message', '0xdead'); }
        catch (InvalidSiweMessageException) {}
      });
      $this->assertSame([], $messages, 'The real exception constructor cannot log during export proof.');
      \Drupal::logger('siwe_login')->warning('ordinary sign-in outcome');
      $this->assertCount(1, $messages);
      $this->assertSame('ordinary sign-in outcome', $messages[0][0]);
      try { ExportProofLogs::quiet(function (): void { throw new \RuntimeException(); }); }
      catch (\RuntimeException) {}
      \Drupal::logger('siwe_login')->warning('after exception');
      $this->assertCount(2, $messages, 'Logging is restored even when the scoped operation throws.');
    }
    finally { if ($previous) \Drupal::setContainer($previous); else \Drupal::unsetContainer(); }
  }
}
