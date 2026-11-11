<?php

declare(strict_types=1);

namespace Drupal\social_ai_indexing_test\Plugin\SolrConnector;

use Drupal\search_api_solr\Plugin\SolrConnector\StandardSolrConnector;
use Drupal\search_api_solr\SearchApiSolrException;
use Solarium\Core\Client\Endpoint;
use Solarium\QueryType\Select\Query\Query;
use Solarium\QueryType\Update\Query\Command\Add;
use Solarium\QueryType\Update\Query\Query as UpdateQuery;

/**
 * A Solr connector that fails like Solr does, repeating what it was sent.
 *
 * Solr's error for a rejected document quotes the field values, and its
 * error for a bad query quotes the keys. This connector answers every update
 * and search with such an error, so a test can check that no log line or
 * exception carries them on. It answers the version questions itself, so no
 * Solr server is needed.
 *
 * @SolrConnector(
 *   id = "social_ai_indexing_echo",
 *   label = @Translation("Echo (test)"),
 *   description = @Translation("Fails every update and search with an error that repeats the request.")
 * )
 */
class EchoSolrConnector extends StandardSolrConnector {

  /**
   * {@inheritdoc}
   */
  public function getSolrVersion($force_auto_detect = FALSE) {
    return '9.8.0';
  }

  /**
   * {@inheritdoc}
   */
  public function getSchemaVersionString($reset = FALSE) {
    return 'drupal-4.3.10-solr-9.x-1';
  }

  /**
   * {@inheritdoc}
   */
  public function isCloud() {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function update(UpdateQuery $query, ?Endpoint $endpoint = NULL) {
    $fields = [];
    foreach ($query->getCommands() as $command) {
      if ($command instanceof Add) {
        foreach ($command->getDocuments() as $document) {
          $fields[] = $document->getFields();
        }
      }
    }
    throw new SearchApiSolrException('Solr endpoint http://localhost:8983/ bad request (code: 400, body: ERROR: Error adding field ' . json_encode($fields) . ')', 400);
  }

  /**
   * {@inheritdoc}
   */
  public function search(Query $query, ?Endpoint $endpoint = NULL) {
    throw new SearchApiSolrException('Solr endpoint http://localhost:8983/ bad request (code: 400, body: org.apache.solr.search.SyntaxError: Cannot parse \'' . $query->getQuery() . '\')', 400);
  }

}
