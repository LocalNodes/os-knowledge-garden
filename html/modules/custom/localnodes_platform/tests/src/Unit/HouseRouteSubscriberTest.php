<?php

namespace Drupal\Tests\localnodes_platform\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\localnodes_platform\Activity\HouseActivityFactory;
use Drupal\localnodes_platform\LocalnodesPlatformServiceProvider;
use Drupal\localnodes_platform\Routing\HouseRouteSubscriber;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/** @group localnodes_platform */
final class HouseRouteSubscriberTest extends UnitTestCase {

  public function testMembershipChecksSupplementAllInstalledEndpointChecks(): void {
    $routes = new RouteCollection();
    $participation = ['comment.reply', 'ajax_comments.add', 'ajax_comments.reply', 'ajax_comments.save_reply', 'like_and_dislike.vote'];
    $enrollment = ['view.event_enrollments.view_enrollments', 'view.event_manage_enrollment_invites.page_manage_enrollment_invites', 'view.event_manage_enrollments.page_manage_enrollments', 'view.event_manage_enrollment_requests.page_manage_enrollment_requests', 'social_event_managers.add_enrollees', 'social_event_managers.vbo.execute_configurable', 'social_event_managers.vbo.confirm', 'social_event.update_enrollment_request', 'social_event.enrollment_request_decline_form', 'social_event.request_enroll_dialog', 'entity.event_enrollment.canonical', 'entity.event_enrollment.edit_form', 'entity.event_enrollment.delete_form'];
    foreach (array_merge($participation, $enrollment, ['ordinary.page']) as $name) {
      $routes->add($name, new Route('/test', [], ['_permission' => 'original permission', '_custom_access' => 'original checker']));
    }
    $routes->add('social_mentions.autocomplete', new Route('/mentions', ['_controller' => 'original'], ['_permission' => 'access mentions']));
    $method = new \ReflectionMethod(HouseRouteSubscriber::class, 'alterRoutes');
    $method->setAccessible(TRUE);
    $method->invoke(new HouseRouteSubscriber(), $routes);
    $enrollmentMethod = new \ReflectionMethod(\Drupal\localnodes_platform\Routing\HouseEnrollmentRouteSubscriber::class, 'alterRoutes');
    $enrollmentMethod->setAccessible(TRUE);
    $enrollmentMethod->invoke(new \Drupal\localnodes_platform\Routing\HouseEnrollmentRouteSubscriber(), $routes);
    foreach ($participation as $name) $this->assertSame('TRUE', $routes->get($name)->getRequirement('_localnodes_house_participation'), $name);
    foreach ($enrollment as $name) $this->assertSame('TRUE', $routes->get($name)->getRequirement('_localnodes_house_enrollment'), $name);
    foreach (array_merge($participation, $enrollment, ['ordinary.page']) as $name) {
      $this->assertSame('original permission', $routes->get($name)->getRequirement('_permission'));
      $this->assertSame('original checker', $routes->get($name)->getRequirement('_custom_access'));
    }
    $this->assertNull($routes->get('ordinary.page')->getRequirement('_localnodes_house_participation'));
    $this->assertSame('access mentions', $routes->get('social_mentions.autocomplete')->getRequirement('_permission'));
    $this->assertSame('\\Drupal\\localnodes_platform\\Mention\\ContextualMentionController::suggestions', $routes->get('social_mentions.autocomplete')->getDefault('_controller'));
  }

  public function testOptionalActivityFactoryKeepsItsOriginalDependencies(): void {
    $container = new ContainerBuilder();
    $provider = new LocalnodesPlatformServiceProvider();
    $provider->alter($container);
    $this->assertFalse($container->hasDefinition('activity_creator.activity_factory'));
    $definition = $container->register('activity_creator.activity_factory', 'OriginalFactory')->setArguments(['original', 'dependencies'])->setShared(FALSE);
    $provider->alter($container);
    $this->assertSame(HouseActivityFactory::class, $definition->getClass());
    $this->assertSame(['original', 'dependencies'], $definition->getArguments());
    $this->assertFalse($definition->isShared());
  }

}
