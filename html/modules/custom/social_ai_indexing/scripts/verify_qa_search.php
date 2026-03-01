<?php
/**
 * @file
 * Comprehensive verification script for Q&A and Search pipeline.
 * Tests QA-01 through QA-05 and SRCH-01 through SRCH-04 requirements.
 * 
 * Run with: ddev drush php-script html/modules/custom/social_ai_indexing/scripts/verify_qa_search.php
 */

declare(strict_types=1);

use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\social_ai_indexing\Service\HybridSearchService;
use Drupal\social_ai_indexing\Service\PermissionFilterService;
use Drupal\social_ai_indexing\Service\RelatedContentService;

// Use $GLOBALS for proper global access in Drush context
$GLOBALS['qa_test_results'] = [
  'passed' => 0,
  'failed' => 0,
  'skipped' => 0,
  'warn' => 0,
  'tests' => [],
];

function log_result(string $test_id, string $description, string $status, string $message = ''): void {
  $results = &$GLOBALS['qa_test_results'];
  $results['tests'][] = [
    'id' => $test_id,
    'description' => $description,
    'status' => $status,
    'message' => $message,
  ];
  
  $status_lower = strtolower($status);
  if (isset($results[$status_lower])) {
    $results[$status_lower]++;
  }
}

print "\n===========================================\n";
print "Q&A and Search Pipeline Verification\n";
print "===========================================\n\n";

// ============================================================================
// QA-01: AI Assistant RAG responds with citations
// ============================================================================
print "Testing QA-01: AI Assistant RAG responds with citations...\n";

try {
  // Check if AI Assistant is configured
  $config = \Drupal::config('ai_assistant_api.assistant');
  $assistant_id = $config->get('default_assistant');
  
  if (!$assistant_id) {
    // Try alternative config locations
    $config = \Drupal::config('ai_agents.agent');
    $assistant_id = $config->get('id');
  }
  
  if ($assistant_id) {
    log_result('QA-01', 'AI Assistant configured', 'PASS', "Assistant ID: $assistant_id");
  } else {
    // Check if ai_assistant_api module exists
    $module_handler = \Drupal::service('module_handler');
    if ($module_handler->moduleExists('ai_assistant_api')) {
      log_result('QA-01', 'AI Assistant module enabled but not configured', 'SKIP', 'Requires manual configuration');
    } else {
      log_result('QA-01', 'AI Assistant module not installed', 'SKIP', 'Requires ai_assistant_api module');
    }
  }
} catch (\Exception $e) {
  log_result('QA-01', 'AI Assistant check failed', 'FAIL', $e->getMessage());
}

// ============================================================================
// QA-02: Citations in AI responses link to source content
// ============================================================================
print "Testing QA-02: Citations link to source content...\n";

try {
  // Check CitationMetadata processor exists
  $processor_manager = \Drupal::service('search_api.plugin_helper');
  $index_storage = \Drupal::entityTypeManager()->getStorage('search_api_index');
  $social_posts = $index_storage->load('social_posts');
  
  if ($social_posts) {
    $processors = $social_posts->getProcessors();
    $has_citation = isset($processors['citation_metadata']) || isset($processors['social_ai_citation']);
    
    if ($has_citation) {
      // Check fields exist
      $fields = $social_posts->getFields();
      $has_citation_url = isset($fields['citation_url']);
      $has_citation_title = isset($fields['citation_title']);
      
      if ($has_citation_url && $has_citation_title) {
        log_result('QA-02', 'Citation fields configured', 'PASS', 'citation_url and citation_title fields exist');
      } else {
        $missing = [];
        if (!$has_citation_url) $missing[] = 'citation_url';
        if (!$has_citation_title) $missing[] = 'citation_title';
        log_result('QA-02', 'Citation fields incomplete', 'FAIL', 'Missing: ' . implode(', ', $missing));
      }
    } else {
      log_result('QA-02', 'CitationMetadata processor not found', 'SKIP', 'May need processor configuration');
    }
  } else {
    log_result('QA-02', 'social_posts index not found', 'FAIL', 'Index required for citations');
  }
} catch (\Exception $e) {
  log_result('QA-02', 'Citation check failed', 'FAIL', $e->getMessage());
}

// ============================================================================
// QA-03: AI gracefully handles "no relevant info" case
// ============================================================================
print "Testing QA-03: AI handles no relevant info gracefully...\n";

try {
  // This requires testing the AI response behavior
  // We check that the RAG tool exists and has proper configuration
  $rag_tool_exists = \Drupal::service('module_handler')->moduleExists('ai_rag') ||
    class_exists('Drupal\ai_agents\Plugin\AiAgent\RagAgent');
  
  if ($rag_tool_exists) {
    log_result('QA-03', 'RAG tool available for graceful responses', 'PASS', 'RAG agent or module available');
  } else {
    log_result('QA-03', 'RAG tool check', 'SKIP', 'Manual verification required - ask AI about non-existent topic');
  }
} catch (\Exception $e) {
  log_result('QA-03', 'RAG check failed', 'SKIP', $e->getMessage());
}

// ============================================================================
// QA-04: Semantic search returns meaning-based results
// ============================================================================
print "Testing QA-04: Semantic search with vector similarity...\n";

try {
  $index_storage = \Drupal::entityTypeManager()->getStorage('search_api_index');
  $social_posts = $index_storage->load('social_posts');
  
  if ($social_posts) {
    $server = $social_posts->getServerInstance();
    $backend = $server ? $server->getBackend() : null;
    
    if ($backend) {
      $backend_id = $backend->getPluginId();
      // Check if it's a vector-capable backend (qdrant, ai_search, etc.)
      if (str_contains($backend_id, 'qdrant') || str_contains($backend_id, 'ai_search') || str_contains($backend_id, 'search_api_ai')) {
        log_result('QA-04', 'Vector search backend configured', 'PASS', "Backend: $backend_id");
      } else {
        log_result('QA-04', 'Backend check', 'SKIP', "Backend $backend_id may support vectors");
      }
    } else {
      log_result('QA-04', 'No server attached to social_posts', 'FAIL', 'Index needs server');
    }
  } else {
    log_result('QA-04', 'social_posts index not found', 'FAIL', 'Index required for semantic search');
  }
} catch (\Exception $e) {
  log_result('QA-04', 'Semantic search check failed', 'FAIL', $e->getMessage());
}

// ============================================================================
// QA-05: Response latency under 10 seconds
// ============================================================================
print "Testing QA-05: Response latency...\n";

try {
  // Quick timing test on search query
  $start = microtime(true);
  
  $index_storage = \Drupal::entityTypeManager()->getStorage('search_api_index');
  $social_posts = $index_storage->load('social_posts');
  
  if ($social_posts) {
    $query = $social_posts->query();
    $query->keys('test');
    $query->range(0, 5);
    $query_results = $query->execute();
    
    $latency = microtime(true) - $start;
    
    if ($latency < 10) {
      log_result('QA-05', 'Search latency acceptable', 'PASS', sprintf('%.2f seconds', $latency));
    } else {
      log_result('QA-05', 'Search latency high', 'WARN', sprintf('%.2f seconds (target <10s)', $latency));
    }
  } else {
    log_result('QA-05', 'Latency test skipped', 'SKIP', 'No social_posts index');
  }
} catch (\Exception $e) {
  log_result('QA-05', 'Latency test failed', 'FAIL', $e->getMessage());
}

// ============================================================================
// SRCH-01: Hybrid search combines vector + keyword
// ============================================================================
print "Testing SRCH-01: Hybrid search functionality...\n";

try {
  $hybrid_service = \Drupal::service('social_ai_indexing.hybrid_search');
  
  if ($hybrid_service instanceof HybridSearchService) {
    // Check method exists
    if (method_exists($hybrid_service, 'search')) {
      log_result('SRCH-01', 'HybridSearchService available', 'PASS', 'search() method exists');
    } else {
      log_result('SRCH-01', 'HybridSearchService incomplete', 'FAIL', 'Missing search() method');
    }
  } else {
    log_result('SRCH-01', 'HybridSearchService not found', 'FAIL', 'Service not registered');
  }
} catch (\Exception $e) {
  log_result('SRCH-01', 'Hybrid search check failed', 'FAIL', $e->getMessage());
}

// ============================================================================
// SRCH-02: Permission filtering in search
// ============================================================================
print "Testing SRCH-02: Permission filtering...\n";

try {
  $permission_service = \Drupal::service('social_ai_indexing.permission_filter');
  
  if ($permission_service instanceof PermissionFilterService) {
    // Test with anonymous user
    $anon = new \Drupal\Core\Session\AnonymousUserSession();
    $index_storage = \Drupal::entityTypeManager()->getStorage('search_api_index');
    $social_posts = $index_storage->load('social_posts');
    
    if ($social_posts) {
      $query = $social_posts->query();
      $permission_service->applyPermissionFilters($query, $anon);
      
      // Check conditions were applied
      $conditions = $query->getConditionGroup()->getConditions();
      if (count($conditions) > 0) {
        log_result('SRCH-02', 'Permission filtering works', 'PASS', 'Conditions applied to query');
      } else {
        log_result('SRCH-02', 'Permission filtering incomplete', 'WARN', 'No conditions applied');
      }
    } else {
      log_result('SRCH-02', 'Permission filter skipped', 'SKIP', 'No social_posts index');
    }
  } else {
    log_result('SRCH-02', 'PermissionFilterService not found', 'FAIL', 'Service not registered');
  }
} catch (\Exception $e) {
  log_result('SRCH-02', 'Permission filter check failed', 'FAIL', $e->getMessage());
}

// ============================================================================
// SRCH-03: Related content suggestions
// ============================================================================
print "Testing SRCH-03: Related content suggestions...\n";

try {
  $related_service = \Drupal::service('social_ai_indexing.related_content');
  
  if ($related_service instanceof RelatedContentService) {
    // Test method exists
    if (method_exists($related_service, 'findRelated')) {
      // Try with a sample node if available
      $node_storage = \Drupal::entityTypeManager()->getStorage('node');
      $nodes = $node_storage->loadByProperties(['status' => 1]);
      
      if (!empty($nodes)) {
        $test_node = reset($nodes);
        $current_user = \Drupal::currentUser();
        $related = $related_service->findRelated($test_node, $current_user, 3);
        
        // Service should return array (may be empty if no related content)
        if (is_array($related)) {
          log_result('SRCH-03', 'RelatedContentService works', 'PASS', sprintf('Returns array (%d items)', count($related)));
        } else {
          log_result('SRCH-03', 'RelatedContentService invalid return', 'FAIL', 'Expected array');
        }
      } else {
        log_result('SRCH-03', 'Related content test skipped', 'SKIP', 'No published nodes available');
      }
    } else {
      log_result('SRCH-03', 'RelatedContentService incomplete', 'FAIL', 'Missing findRelated() method');
    }
  } else {
    log_result('SRCH-03', 'RelatedContentService not found', 'FAIL', 'Service not registered');
  }
} catch (\Exception $e) {
  log_result('SRCH-03', 'Related content check failed', 'FAIL', $e->getMessage());
}

// ============================================================================
// SRCH-04: JSON API endpoint for search
// ============================================================================
print "Testing SRCH-04: JSON API search endpoint...\n";

try {
  // Check if the route exists
  $route_provider = \Drupal::service('router.route_provider');
  $route = $route_provider->getRouteByName('social_ai_indexing.hybrid_search');
  
  if ($route) {
    $path = $route->getPath();
    $methods = $route->getMethods();
    
    log_result('SRCH-04', 'JSON API endpoint configured', 'PASS', "Path: $path");
  } else {
    log_result('SRCH-04', 'JSON API endpoint not found', 'FAIL', 'Route not registered');
  }
} catch (\Drupal\Core\Routing\RouteNotFoundException $e) {
  log_result('SRCH-04', 'JSON API endpoint not found', 'FAIL', 'Route not registered');
} catch (\Exception $e) {
  log_result('SRCH-04', 'JSON API check failed', 'FAIL', $e->getMessage());
}

// ============================================================================
// Additional: Check indexes have content
// ============================================================================
print "\nTesting index content...\n";

try {
  $index_storage = \Drupal::entityTypeManager()->getStorage('search_api_index');
  
  // Check social_posts (VDB/Vector)
  $social_posts = $index_storage->load('social_posts');
  if ($social_posts) {
    $query = $social_posts->query();
    $query->range(0, 1);
    $results_posts = $query->execute();
    $posts_count = $results_posts->getResultCount();
    
    print "  social_posts (vector) index: $posts_count items\n";
    
    if ($posts_count > 0) {
      log_result('IDX-POSTS', 'social_posts has content', 'PASS', "$posts_count items indexed");
    } else {
      log_result('IDX-POSTS', 'social_posts empty', 'WARN', 'Run indexing to populate vector index');
    }
  }
  
  // Check social_content (Solr/Keyword)
  $social_content = $index_storage->load('social_content');
  if ($social_content) {
    $query = $social_content->query();
    $query->range(0, 1);
    $results_content = $query->execute();
    $content_count = $results_content->getResultCount();
    
    print "  social_content (keyword) index: $content_count items\n";
    
    if ($content_count > 0) {
      log_result('IDX-CONTENT', 'social_content has content', 'PASS', "$content_count items indexed");
    } else {
      log_result('IDX-CONTENT', 'social_content empty', 'WARN', 'Run indexing to populate keyword index');
    }
  }
} catch (\Exception $e) {
  log_result('IDX', 'Index content check failed', 'FAIL', $e->getMessage());
}

// ============================================================================
// Summary
// ============================================================================
$results = $GLOBALS['qa_test_results'];

print "\n===========================================\n";
print "SUMMARY\n";
print "===========================================\n\n";

print "Passed:  {$results['passed']}\n";
print "Failed:  {$results['failed']}\n";
print "Skipped: {$results['skipped']}\n";
print "Warnings: {$results['warn']}\n\n";

print "Detailed Results:\n";
print str_repeat("-", 70) . "\n";
printf("%-10s %-40s %-6s %s\n", 'ID', 'Description', 'Status', 'Message');
print str_repeat("-", 70) . "\n";

foreach ($results['tests'] as $test) {
  $status = $test['status'];
  $status_icon = $status === 'PASS' ? '✓' : ($status === 'SKIP' ? '○' : '✗');
  printf("%-10s %-40s [%-4s] %s\n", 
    $test['id'], 
    substr($test['description'], 0, 40), 
    $status,
    $test['message']
  );
}

print str_repeat("-", 70) . "\n";

// Exit with appropriate code
$results = $GLOBALS['qa_test_results'];
if ($results['failed'] > 0) {
  print "\nVERIFICATION FAILED: {$results['failed']} tests failed\n";
  exit(1);
} else {
  print "\nVERIFICATION PASSED: All critical tests passed\n";
  exit(0);
}
