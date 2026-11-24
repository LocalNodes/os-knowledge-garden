Status: draft, not posted

Before posting, search the Drupal core issue queue (component "phpunit") for an existing report ("bootstrap.php duplicate module namespace", "drupal_phpunit_find_extension_directories order", "RecursiveDirectoryIterator order test bootstrap") and add to that one if it exists. Attach `patches/core-tests-bootstrap-sorted-extension-scan.patch` from our repository.

# drupal.org Issue: The PHPUnit bootstrap maps a duplicated module name to a different copy on different machines

## Issue Metadata

- **Title:** The PHPUnit bootstrap maps a duplicated module name to a different copy on different machines
- **Project:** Drupal core
- **Version:** 10.6.x (seen on 10.6.21; 11.x has the same function)
- **Component:** phpunit
- **Category:** Bug report
- **Priority:** Normal
- **Status:** Active

## Problem/Motivation

`core/tests/bootstrap.php` registers a PSR-4 namespace for every extension it finds. `drupal_phpunit_find_extension_directories()` walks each directory with `RecursiveDirectoryIterator` and keeps the last `.info.yml` it sees for each extension name. The iterator returns entries in file system order. That order is not sorted and can vary between machines and with directory history.

When two extensions share a name in one directory tree, the namespace therefore points at a different copy depending on the machine. For modules of the same origin, site discovery breaks ties by path and keeps the last candidate. It also filters by extension type and gives different origins their own precedence; the test helper does not reproduce all of those rules.

This happens in practice with the AI module. AI 1.5 still ships deprecated copies of modules that are now their own projects (`ai/modules/ai_search`, `ai/modules/ai_content_suggestions` and others). With both `drupal/ai` and `drupal/ai_search` installed, a Kernel test can autoload `Drupal\ai_search\…` from `modules/contrib/ai/modules/ai_search` on one CI runner and from `modules/contrib/ai_search` on the next. The bootstrap's namespace entry is registered before the kernel adds its own, so its copy wins. A test of the real `ai_search` module then passes or fails depending on which runner it lands on.

### Steps to reproduce

1. Install `drupal/ai` 1.5.x and `drupal/ai_search` 1.3.x side by side.
2. On a file system whose directory order lists `ai_search` before `ai` (for example, create `ai` first on tmpfs), call `drupal_phpunit_find_extension_directories('…/modules')`.
3. `ai_search` maps to `modules/contrib/ai/modules/ai_search`. With the opposite order, it maps to `modules/contrib/ai_search`, the copy `ExtensionDiscovery` picks.

## Proposed resolution

Collect the `.info.yml` files first, sort them by path name, and then keep the last per name. Every machine then resolves the same set of paths consistently. For candidates of the same type and origin, it is the copy `ExtensionDiscovery` picks.

With the patch, the nine duplicated module names in our tree (all from the AI module's deprecated copies and their test modules) resolve to the copy `ExtensionDiscovery` returns, whatever the creation order. A name shared by a module and a theme, such as core's `evil` test extensions, can still differ from site discovery, which filters by type; the patch does not change that.

## Remaining tasks

- Review.
- A unit test for `drupal_phpunit_find_extension_directories()` with two same-named extensions created in both orders.
- Optional follow-up: the order across scanned roots (core, profiles, modules, sites) still differs from `ExtensionDiscovery`'s origin weights. The patch leaves that alone.

## API changes

None.

## AI disclosure

This report and patch were drafted with an AI assistant and checked by the account holder before posting.
