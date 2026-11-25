<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_platform\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\Tests\social_ai_indexing\Kernel\GroupAccessKernelTestBase;

/**
 * The native membership deletion records only a confirmed scoped safety choice.
 *
 * @group localnodes_platform
 * @group house_privacy
 */
final class DepartedAuthorDepartureFormsTest extends GroupAccessKernelTestBase {

  protected static $modules = ['localnodes_platform'];

  public static function safetyChoices(): array {
    return ['ordinary removal' => [FALSE], 'explicit safety removal' => [TRUE]];
  }

  /** @dataProvider safetyChoices */
  public function testNativeRemovalRecordsTheActualChoice(bool $safety): void {
    $group = $this->createSecretGroup('Cedar House');
    $manager = $this->createPersona('house_manager');
    $target = $this->createPersona('member_to_remove');
    $group->addMember($manager, ['group_roles' => ['flexible_group-group_manager']]);
    $this->addMember($group, $target);
    $relationship = $group->getMember($target)->getGroupRelationship();
    $this->container->get('current_user')->setAccount($manager);
    $native = $this->container->get('entity_type.manager')->getFormObject('group_content', 'delete')->setEntity($relationship);
    $this->assertSame('group_content_flexible_group-group_membership_delete_form', $native->getFormId());
    $route = $this->container->get('router.route_provider')->getRouteByName('entity.group_content.delete_form');
    $request = $this->container->get('request_stack')->getCurrentRequest();
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, 'entity.group_content.delete_form');
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    $request->attributes->set('group', $group);
    $request->attributes->set('group_content', $relationship);
    $this->container->get('current_route_match')->resetRouteMatch();
    $state = (new FormState())->setFormObject($native);
    $form = $this->container->get('form_builder')->buildForm($native, $state);
    $this->assertSame(0, $form['localnodes_close_retained_reading']['#default_value']);
    $state->setValue('localnodes_close_retained_reading', $safety);
    foreach ($form['actions']['submit']['#submit'] as $callback) call_user_func_array($callback, [&$form, &$state]);
    $this->assertNull($this->container->get('entity_type.manager')->getStorage('group_content')->loadUnchanged($relationship->id()));
    $this->assertFalse((bool) $group->getMember($target));
    $this->assertNotFalse($group->getMember($manager));
    $marker = $this->container->get('localnodes_platform.group_notice_cleanup');
    $this->assertNotNull($marker->departureTime((int) $target->id(), (int) $group->id()));
    $this->assertTrue($marker->wasRemoved((int) $target->id(), (int) $group->id()));
    $this->assertSame($safety, $marker->retainedReadingClosed((int) $target->id(), (int) $group->id()));
  }

  public function testNativeSelfLeaveIgnoresAForgedSafetyValue(): void {
    $group = $this->createSecretGroup('Cedar House');
    $target = $this->createPersona('leaving_member');
    $this->addMember($group, $target);
    $relationship = $group->getMember($target)->getGroupRelationship();
    $this->container->get('current_user')->setAccount($target);
    $native = $this->container->get('entity_type.manager')->getFormObject('group_content', 'group-leave')->setEntity($relationship);
    $this->assertSame('group_content_flexible_group-group_membership_group-leave_form', $native->getFormId());
    // Run the helper around the actual native submitter. The ordinary leave
    // route's final redirect does not influence the stored safety choice.
    $state = (new FormState())->setFormObject($native);
    $form = ['actions' => ['submit' => ['#submit' => [[$native, 'submitForm']]], 'cancel' => []]];
    $this->container->get('localnodes_platform.house_departure_forms')->alter($form, $state);
    $this->assertArrayNotHasKey('localnodes_close_retained_reading', $form);
    $state->setValue('localnodes_close_retained_reading', TRUE);
    foreach ($form['actions']['submit']['#submit'] as $callback) call_user_func_array($callback, [&$form, &$state]);
    $marker = $this->container->get('localnodes_platform.group_notice_cleanup');
    $this->assertNotNull($marker->departureTime((int) $target->id(), (int) $group->id()));
    $this->assertFalse($marker->wasRemoved((int) $target->id(), (int) $group->id()));
    $this->assertFalse($marker->retainedReadingClosed((int) $target->id(), (int) $group->id()));
  }

  public function testActualBulkDeleteActionRecordsEachRemoval(): void {
    $group = $this->createSecretGroup('Bulk removal house');
    $manager = $this->createPersona('bulk_house_manager');
    $group->addMember($manager, ['group_roles' => ['flexible_group-group_manager']]);
    $first = $this->createPersona('first_bulk_member');
    $second = $this->createPersona('second_bulk_member');
    $this->addMember($group, $first);
    $this->addMember($group, $second);
    $relationships = [$group->getMember($first)->getGroupRelationship(), $group->getMember($second)->getGroupRelationship()];
    $this->container->get('current_user')->setAccount($manager);
    // Prepare the real action as ViewsBulkOperationsActionProcessor does before
    // it executes anything: initialize() creates it and sets the view data as
    // context, populateQueue() gives it the view, process() checks access for
    // each item and then calls executeMultiple(). The shipped manage-members
    // view needs profile and search modules this kernel does not install, so
    // the view here is a minimal one over the same relationship table.
    \Drupal\views\Entity\View::create([
      'id' => 'localnodes_bulk_members_test',
      'label' => 'Bulk members test',
      'base_table' => 'group_relationship_field_data',
      'base_field' => 'id',
      'display' => ['default' => ['id' => 'default', 'display_plugin' => 'default', 'display_title' => 'Default', 'position' => 0, 'display_options' => []]],
    ])->save();
    $view = $this->container->get('views.executable')->get(\Drupal\views\Entity\View::load('localnodes_bulk_members_test'));
    $view->setDisplay('default');
    $context = [
      'action_id' => 'social_group_delete_group_content_action',
      'view_id' => 'localnodes_bulk_members_test',
      'display_id' => 'default',
      'list' => array_map(static fn($relationship) => [$relationship->id(), $relationship->language()->getId()], $relationships),
      'configuration' => [],
      'preconfiguration' => [],
      'arguments' => [$group->id()],
      'exclude_mode' => FALSE,
    ];
    $action = $this->container->get('plugin.manager.views_bulk_operations_action')->createInstance('social_group_delete_group_content_action', $context['configuration']);
    $action->setContext($context);
    $action->setView($view);
    foreach ($relationships as $relationship) {
      $this->assertTrue($action->access($relationship, $manager, TRUE)->isAllowed(), 'The shipped group manager role may remove this member through the bulk action.');
    }
    $action->executeMultiple($relationships);
    $markers = $this->container->get('localnodes_platform.group_notice_cleanup');
    foreach ([$first, $second] as $former) {
      $this->assertFalse((bool) $group->getMember($former));
      $this->assertNotNull($markers->departureTime((int) $former->id(), (int) $group->id()));
      $this->assertTrue($markers->wasRemoved((int) $former->id(), (int) $group->id()), 'The bulk action reaches the actual relationship predelete hook.');
      $this->assertFalse($markers->retainedReadingClosed((int) $former->id(), (int) $group->id()), 'Only a separate explicit safety choice closes reading.');
    }
    $this->assertNotFalse($group->getMember($manager));
  }

  public function testAfterTheFactSafetyCloseOnlyForAHouseOrganizerAboutAFormerMember(): void {
    $group = $this->createSecretGroup('Safety house');
    $manager = $this->createPersona('safety_manager');
    $group->addMember($manager, ['group_roles' => ['flexible_group-group_manager']]);
    $member = $this->createPersona('ordinary_member');
    $this->addMember($group, $member);
    $former = $this->createPersona('former_member');
    $this->addMember($group, $former);
    $never = $this->createPersona('never_member');
    $this->container->get('current_user')->setAccount($manager);
    $group->removeMember($former);
    $markers = $this->container->get('localnodes_platform.group_notice_cleanup');
    $gid = (int) $group->id();
    $access = $this->container->get('access_manager');
    $can = fn($target, $actor) => $access->checkNamedRoute('localnodes_platform.close_retained_reading', ['group' => $gid, 'user' => $target->id()], $actor);
    $this->assertTrue($can($former, $manager));
    $this->assertFalse($can($former, $member), 'An ordinary member cannot close it.');
    $this->assertFalse($can($former, $former), 'Nobody targets themselves.');
    $this->assertFalse($can($never, $manager), 'Someone who never joined has nothing to close.');
    $this->assertFalse($can($member, $manager), 'A current member is not a former member.');
    $this->assertFalse($can($manager, $manager));
    $this->assertFalse($can($former, new \Drupal\Core\Session\AnonymousUserSession()));

    // A form built before the person rejoined does nothing when submitted.
    $submit = function ($target) use ($group): \Drupal\Core\Form\FormStateInterface {
      $state = (new FormState())->addBuildInfo('args', [$group, $target])->setValues(['confirm' => 1]);
      $this->container->get('form_builder')->submitForm(\Drupal\localnodes_platform\Form\CloseRetainedReadingForm::class, $state);
      return $state;
    };
    $this->addMember($group, $former);
    $state = $submit($former);
    $this->assertNotSame([], $state->getErrors());
    $this->assertFalse($markers->retainedReadingClosed((int) $former->id(), $gid), 'A rejoined member is not closed.');
    $group->removeMember($former);

    $state = $submit($former);
    $this->assertSame([], $state->getErrors());
    $this->assertTrue($markers->retainedReadingClosed((int) $former->id(), $gid));
    $this->assertTrue($markers->wasRemoved((int) $former->id(), $gid));
    $this->assertFalse($can($former, $manager), 'Closing twice is refused, never reopened.');
    $this->assertTrue($markers->retainedReadingClosed((int) $former->id(), $gid));
  }

}
