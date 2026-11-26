# LocalNodes Proposal Feed

Every proposal, a payment or a decision, shows as one card in its group's activity feed, with a discussion under it. This module owns that card. A module that owns a kind of proposal adds no feed or stream entries of its own.

## How a kind of proposal reaches the feed

1. Register one source adapter: a service tagged `localnodes_proposal_feed.source` that implements `Drupal\localnodes_proposal_feed\ProposalSourceInterface`. The feed asks it for the group, kind, state, visibility, title, text, proposer, opening time, facts and the proposal's own page.
2. Dispatch the feed events with a `Drupal\localnodes_proposal_feed\Event\ProposalFeedEvent` carrying the saved proposal:
   - `ProposalFeedEvents::OPENED` (`localnodes_proposal_feed.opened`): once, when the proposal is created;
   - `ProposalFeedEvents::CHANGED` (`localnodes_proposal_feed.changed`): when something its card shows changes after that;
   - `ProposalFeedEvents::CLOSED` (`localnodes_proposal_feed.closed`): once, when it closes or is withdrawn.

```yaml
services:
  my_module.proposal_source:
    class: Drupal\my_module\MyProposalSource
    tags:
      - { name: localnodes_proposal_feed.source }
```

```php
$dispatcher->dispatch(new ProposalFeedEvent($round), ProposalFeedEvents::OPENED);
```

The event names, the event class and the interface are a contract with other modules and do not change. A later need gets a new, optional interface.

Who sees a card follows the proposal's own visibility (public, community or group, as for a topic), limited to what its group allows, and checked when the card is shown. In a private group only members see it.
