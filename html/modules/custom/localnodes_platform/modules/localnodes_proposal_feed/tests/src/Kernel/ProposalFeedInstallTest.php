<?php

declare(strict_types=1);

namespace Drupal\Tests\localnodes_proposal_feed\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\localnodes_proposal_feed\Event\ProposalFeedEvent;
use Drupal\localnodes_proposal_feed\Event\ProposalFeedEvents;
use Drupal\localnodes_proposal_feed\ProposalSourceCollector;
use Drupal\localnodes_proposal_feed\ProposalSourceInterface;
use Drupal\localnodes_proposal_feed_test\FixtureProposalSource;
use Drupal\user\Entity\User;

/**
 * The proposal feed module installs and offers its seam to other modules.
 *
 * Other modules post their proposals to the feed through this seam: the
 * event names, the event class and the tagged source adapters. The names
 * are a contract, so this test pins them.
 *
 * @group localnodes_platform
 * @group localnodes_proposal_feed
 */
final class ProposalFeedInstallTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'options',
    'entity',
    'flexible_permissions',
    'group',
    'localnodes_proposal_feed',
    'localnodes_proposal_feed_test',
  ];

  /**
   * The module installs and collects tagged source adapters.
   */
  public function testInstallsAndCollectsSources(): void {
    $this->assertTrue($this->container->get('module_handler')->moduleExists('localnodes_proposal_feed'));

    $collector = $this->container->get('localnodes_proposal_feed.sources');
    $this->assertInstanceOf(ProposalSourceCollector::class, $collector);

    $sources = $collector->getSources();
    $this->assertArrayHasKey('localnodes_proposal_feed_test.fixture_source', $sources);
    $this->assertInstanceOf(FixtureProposalSource::class, $sources['localnodes_proposal_feed_test.fixture_source']);
    $this->assertInstanceOf(ProposalSourceInterface::class, $sources['localnodes_proposal_feed_test.fixture_source']);
  }

  /**
   * The collector routes an entity to the adapter that applies, or none.
   */
  public function testCollectorFindsTheAdapterThatApplies(): void {
    $collector = $this->container->get('localnodes_proposal_feed.sources');

    $proposal = User::create(['name' => FixtureProposalSource::FIXTURE_LABEL]);
    $this->assertInstanceOf(FixtureProposalSource::class, $collector->getSourceFor($proposal));

    $other = User::create(['name' => 'Not a proposal']);
    $this->assertNull($collector->getSourceFor($other));
  }

  /**
   * The event names and the event class other modules dispatch.
   */
  public function testEventContract(): void {
    $this->assertSame('localnodes_proposal_feed.opened', ProposalFeedEvents::OPENED);
    $this->assertSame('localnodes_proposal_feed.changed', ProposalFeedEvents::CHANGED);
    $this->assertSame('localnodes_proposal_feed.closed', ProposalFeedEvents::CLOSED);

    $proposal = User::create(['name' => FixtureProposalSource::FIXTURE_LABEL]);
    $event = new ProposalFeedEvent($proposal);
    $this->assertSame($proposal, $event->getSource());

    $dispatched = $this->container->get('event_dispatcher')->dispatch($event, ProposalFeedEvents::OPENED);
    $this->assertSame($event, $dispatched);
  }

  /**
   * The kinds, states and visibility values adapters return.
   */
  public function testValueContract(): void {
    $this->assertSame('payment', ProposalSourceInterface::KIND_PAYMENT);
    $this->assertSame('decision', ProposalSourceInterface::KIND_DECISION);
    $this->assertSame('open', ProposalSourceInterface::STATE_OPEN);
    $this->assertSame('ended', ProposalSourceInterface::STATE_ENDED);
    $this->assertSame('closed', ProposalSourceInterface::STATE_CLOSED);
    $this->assertSame('carried_out', ProposalSourceInterface::STATE_CARRIED_OUT);
    $this->assertSame('not_carried_out', ProposalSourceInterface::STATE_NOT_CARRIED_OUT);
    $this->assertSame('withdrawn', ProposalSourceInterface::STATE_WITHDRAWN);
    $this->assertSame('public', ProposalSourceInterface::VISIBILITY_PUBLIC);
    $this->assertSame('community', ProposalSourceInterface::VISIBILITY_COMMUNITY);
    $this->assertSame('group', ProposalSourceInterface::VISIBILITY_GROUP);
  }

}
