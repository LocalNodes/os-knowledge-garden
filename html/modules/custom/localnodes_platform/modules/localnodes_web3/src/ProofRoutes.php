<?php

declare(strict_types=1);

namespace Drupal\localnodes_web3;

use Symfony\Component\Routing\Route;

/**
 * Which group fund and vote routes need a session proven by a signature.
 *
 * Every route of the fund and vote modules needs it unless it is listed in
 * EXEMPT with its reason. The route subscriber marks them; a real GET or
 * HEAD still passes the guard, so pages stay readable and only changing
 * requests (form posts, API posts) need the proof. The few routes whose GET
 * changes something are listed in PROOF_ON_READ and need it for reads too.
 *
 * A route of these modules that nobody listed is marked too, so a new route
 * is closed until someone decides otherwise. MARKED is the reviewed list of
 * the routes as they are today; the kernel test keeps it complete.
 *
 * Routes that the REST and JSON:API modules generate for these modules'
 * entity types are marked too, collection POST included. They are known by
 * the resource each route serves, read from the resource's own definition,
 * not by the route's name or path. Neither module is enabled by the
 * distribution; this keeps the proof in place if a site operator enables
 * one.
 */
final class ProofRoutes {

  /**
   * The modules whose routes are covered.
   */
  public const MODULES = [
    'safe_smart_accounts',
    'group_treasury',
    'social_group_treasury',
    'safe_governance',
    'safe_governance_group',
    'gas_sponsor',
    'localnodes_web3',
  ];

  /**
   * Routes that never need the proof, each with the reason.
   */
  public const EXEMPT = [
    'localnodes_web3.signature_proof_challenge' => 'The proof step itself.',
    'localnodes_web3.signature_proof' => 'The proof step itself.',
    'localnodes_web3.signature_proof_status' => 'Read-only answer to whether this session is proven.',
    'localnodes_web3.email_link' => 'Signed-out sign-in by emailed link; no session to prove yet.',
    'localnodes_web3.email_link_sign_in' => 'Signed-out sign-in by emailed link; no session to prove yet.',
    'localnodes_web3.email_link_open' => 'Signed-out sign-in by emailed link; no session to prove yet.',
    'localnodes_web3.export_challenge' => 'Site export asks for its own fresh signature on every use.',
    'group_treasury.fund_creation_status' => 'GET only: reports fund-setup progress to a fund manager. It may record a result the site verifies on chain, but never starts, resends or signs a setup; that is the marked create form.',
    'group_treasury.treasury' => 'Its only form re-reads the public balance (TreasuryRefreshForm); it changes no fund.',
    'gas_sponsor.network_fees' => 'Its only form re-reads the public fee balance (NetworkFeeRefreshForm); it changes no fund.',
    'safe_governance_group.votes' => 'Its only form re-reads the public vote record (RefreshVotesForm); voting posts to the marked vote route.',
    'safe_governance_group.proposal' => 'Its only form re-reads the public vote record (RefreshVotesForm); voting posts to the marked vote route.',
  ];

  /**
   * Routes of these modules that need a proven session for any change.
   *
   * Reads are listed too: GET passes, and listing them keeps the inventory
   * whole. Routes from SafeNetwork's entity route provider are included.
   */
  public const MARKED = [
    'entity.safe_account.collection',
    'entity.safe_configuration.collection',
    'entity.safe_network.add_form',
    'entity.safe_network.collection',
    'entity.safe_network.delete_form',
    'entity.safe_network.edit_form',
    'entity.safe_transaction.collection',
    'group_treasury.create',
    'group_treasury.link',
    'group_treasury.propose_transaction',
    'group_treasury.transaction_view',
    'group_treasury.unlink',
    'safe_governance_group.carry_out_retry',
    'safe_governance_group.carry_out_status',
    'safe_governance_group.members',
    'safe_governance_group.members_autocomplete',
    'safe_governance_group.members_withdraw',
    'safe_governance_group.proposal_message',
    'safe_governance_group.proposal_new',
    'safe_governance_group.proposal_new_decision',
    'safe_governance_group.proposal_new_payment',
    'safe_governance_group.proposal_publish',
    'safe_governance_group.proposal_recipient',
    'safe_governance_group.setup',
    'safe_governance_group.setup_approve',
    'safe_governance_group.setup_messages',
    'safe_governance_group.setup_restart',
    'safe_governance_group.setup_status',
    'safe_governance_group.vote',
    'safe_governance_group.vote_message',
    'safe_smart_accounts.admin_settings',
    'safe_smart_accounts.api_safe_configuration',
    'safe_smart_accounts.api_safe_update_deployment',
    'safe_smart_accounts.api_transaction_data',
    'safe_smart_accounts.api_transaction_execute',
    'safe_smart_accounts.api_transaction_sign',
    'safe_smart_accounts.api_transaction_status',
    'safe_smart_accounts.signer_autocomplete',
    'safe_smart_accounts.transaction_create',
    'safe_smart_accounts.transaction_view',
    'safe_smart_accounts.user_account_create',
    'safe_smart_accounts.user_account_list',
    'safe_smart_accounts.user_account_manage',
  ];

  /**
   * Marked routes whose GET and HEAD need the proof too, each with the reason.
   */
  public const PROOF_ON_READ = [
    // SetupSignatureController::messages() prepares the setup approvals on
    // first read (SetupApprovals::prepare()), which builds the Safe
    // transaction and saves the group's voting setup state.
    'safe_governance_group.setup_messages',
  ];

  /**
   * Whether a route belongs to one of the covered modules.
   *
   * @param string $name
   *   The route name.
   * @param \Symfony\Component\Routing\Route $route
   *   The route.
   * @param array<string, string> $entityProviders
   *   Entity type ID => providing module, for entity.* and _entity_* routes.
   * @param array<string, string[]> $resources
   *   For routes generated by REST or JSON:API: 'rest:' plus the resource
   *   config ID, or 'jsonapi:' plus the resource type name => the modules
   *   that provide the resource and the entity type it serves.
   */
  public static function covers(string $name, Route $route, array $entityProviders, array $resources = []): bool {
    foreach (self::MODULES as $module) {
      if (str_starts_with($name, $module . '.')) {
        return TRUE;
      }
    }
    if (preg_match('/^entity\.([a-z0-9_]+)\./', $name, $match) && in_array($entityProviders[$match[1]] ?? '', self::MODULES, TRUE)) {
      return TRUE;
    }
    foreach (['_entity_form', '_entity_list', '_entity_view'] as $key) {
      $value = $route->getDefault($key);
      if (is_string($value) && in_array($entityProviders[explode('.', $value)[0]] ?? '', self::MODULES, TRUE)) {
        return TRUE;
      }
    }
    $rest = $route->getDefault('_rest_resource_config');
    if (is_string($rest) && array_intersect($resources['rest:' . $rest] ?? [], self::MODULES)) {
      return TRUE;
    }
    $type = $route->getDefault('resource_type');
    if ($route->getDefault('_is_jsonapi') && is_string($type) && array_intersect($resources['jsonapi:' . $type] ?? [], self::MODULES)) {
      return TRUE;
    }
    foreach (['_controller', '_form', '_title_callback'] as $key) {
      $value = $route->getDefault($key);
      if (is_string($value)) {
        foreach (self::MODULES as $module) {
          if (str_starts_with(ltrim($value, '\\'), 'Drupal\\' . $module . '\\') || str_starts_with($value, $module . '.')) {
            return TRUE;
          }
        }
      }
    }
    return FALSE;
  }

}
