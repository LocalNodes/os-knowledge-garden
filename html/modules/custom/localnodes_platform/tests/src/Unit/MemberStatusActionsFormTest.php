<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Unit;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;

/** @group localnodes_platform */
final class MemberStatusActionsFormTest extends UnitTestCase {

  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  public function testScopedPeopleControlsSurviveTheProfilesLaterAlter(): void {
    require_once dirname(__DIR__, 3) . '/localnodes_platform.module';
    $actor = $this->createMock(AccountInterface::class);
    $actor->method('hasPermission')->willReturnCallback(static fn ($permission) => $permission === 'block users');
    $actions = [];
    foreach (['user_block_user_action' => 'Block the selected users', 'user_unblock_user_action' => 'Unblock the selected users'] as $id => $label) {
      $action = $this->createMock(ConfigEntityInterface::class);
      $action->method('label')->willReturn($label);
      $actions[$id] = $action;
    }
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadMultiple')->willReturn($actions);
    $entities = $this->createMock(EntityTypeManagerInterface::class);
    $entities->method('getStorage')->with('action')->willReturn($storage);
    $container = new ContainerBuilder();
    $container->set('current_user', $actor);
    $container->set('entity_type.manager', $entities);
    \Drupal::setContainer($container);
    $form = ['header' => ['user_bulk_form' => ['action' => [
      '#type' => 'select', '#title' => 'Action', '#empty_option' => '- Select -',
      '#options' => ['', 'user_add_role_action' => 'Add an administrator role'],
    ]]]];
    $state = new FormState();
    localnodes_platform_form_views_form_user_admin_people_page_1_alter($form, $state, 'views_form_user_admin_people_page_1');
    $this->assertNotEmpty($form['#after_build']);
    // The profile's later alter leaves only its old block control. Our final
    // build must restore unblock without restoring any role/credential action.
    $form['header']['user_bulk_form']['action']['#options'] = [
      '' => '- Select -', 'user_block_user_action' => 'Block the selected users',
    ];
    foreach ($form['#after_build'] as $callback) {
      $form = $callback($form, $state);
    }
    $choice = $form['header']['user_bulk_form']['action'];
    $this->assertSame([
      '' => '- Select -', 'user_block_user_action' => 'Block the selected users',
      'user_unblock_user_action' => 'Unblock the selected users',
    ], $choice['#options']);
    $this->assertSame('select', $choice['#type']);
    $this->assertSame('Action', $choice['#title']);
  }

}
