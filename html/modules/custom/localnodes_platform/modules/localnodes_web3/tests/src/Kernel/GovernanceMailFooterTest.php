<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_web3\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * The LocalNodes footer on the organizers' vote-result emails (25-25, D-03).
 *
 * The safe_governance_group module is generic: its wrong-result emails end
 * with "Sent by ‹site name›" and never name LocalNodes. On a LocalNodes
 * site every mail goes out in the LocalNodes mail template, whose
 * footer under the card already reads "Sent by ‹community› · a community
 * on LocalNodes", once (agreed wording; 23-13 T-5). So localnodes_web3 takes
 * the generic line out of these emails' body, through hook_mail_alter(),
 * and the governance email's footer is the template's: it ends with
 * " · a community on LocalNodes", and appears once. Other mails, and the
 * module's footer-less notice, are left as they are.
 *
 * localnodes_web3 is enabled without its .info.yml dependencies (they pull
 * in Open Social, which a Kernel test can't boot); only its hooks are used.
 *
 * @group localnodes_web3
 */
final class GovernanceMailFooterTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'localnodes_web3'];

  /**
   * The generic footer line, as safe_governance_group writes it.
   */
  private const FOOTER = 'Sent by Repair Collective';

  /**
   * The wrong-result emails lose the generic line; the template prints it.
   */
  public function testGovernanceAlertMailsLeaveTheFooterToTheTemplate(): void {
    foreach (['wrong_result', 'escalation', 'daily_mismatch'] as $key) {
      $lines = ['A wrong vote result was reported.', 'Open the proposal: https://example.com/group/1/votes/2'];
      $message = $this->message('safe_governance_group', $key, [...$lines, self::FOOTER]);
      $this->container->get('module_handler')->alter('mail', $message);
      $this->assertSame($lines, $message['body'], $key);
      $this->assertStringNotContainsString('Sent by', implode(' ', $message['body']), $key . ': no second footer inside the card.');
    }
  }

  /**
   * Other mails are left alone.
   */
  public function testOtherMailsAreUntouched(): void {
    $cases = [
      ['safe_governance_group', 'changes_waiting', ['1 change is waiting.', 'Approve new members: https://example.com']],
      ['user', 'password_reset', ['Reset your password.', self::FOOTER]],
    ];
    foreach ($cases as [$module, $key, $body]) {
      $message = $this->message($module, $key, $body);
      $this->container->get('module_handler')->alter('mail', $message);
      $this->assertSame($body, $message['body'], "$module $key");
    }
  }

  /**
   * A mail message as MailManager hands it to hook_mail_alter().
   *
   * @param string $module
   *   The module.
   * @param string $key
   *   The key.
   * @param string[] $body
   *   The body lines.
   *
   * @return array<string, mixed>
   *   The message.
   */
  private function message(string $module, string $key, array $body): array {
    return [
      'id' => $module . '_' . $key,
      'module' => $module,
      'key' => $key,
      'to' => 'ana@example.com',
      'langcode' => 'en',
      'params' => ['site' => 'Repair Collective'],
      'subject' => 'Check a vote result in Seed library',
      'body' => $body,
      'headers' => [],
    ];
  }

}
