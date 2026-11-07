# P2P Foundation Wiki fixtures (MediaWiki)

These files are saved responses from the P2P Foundation Wiki
(https://wiki.p2pfoundation.net). They are the first corpus for the document
importer (KNOW-02, DEMO-SCRIPT P2P slice) and its MediaWiki tests.

## Provenance

- **Capture date:** 4 November 2026
- **How captured:** Captured on 2026-11-04 in a real browser session (the
  project's orchestrator Claude session, browser automation on Tom's machine),
  because the wiki challenges server-side clients (22-CONTEXT D-23). Tom to
  confirm this capture method; a by-hand re-capture can replace these files.
- **Why not fetched live:** the wiki answers server-side requests with a
  Cloudflare challenge (HTTP 403, `cf-mitigated: challenge`). The importer
  never fetches the wiki live in tests and never tries to get round the
  challenge (D-13: honest User-Agent). These are static test fixtures.
- **Site:** P2P Foundation Wiki, generator `MediaWiki 1.43.11`
  (from `siteinfo.json` and the `<meta name="generator">` tag in
  `bioregionalism.page.html`)
- **API path:** `/api.php` (`https://wiki.p2pfoundation.net/api.php`), not `/w/api.php`

## License

CC BY-SA 4.0, https://creativecommons.org/licenses/by-sa/4.0/
(from `siteinfo.json` → `query.rightsinfo.url`; `rightsinfo.text` is
"Creative Commons Attribution-ShareAlike").

These files are third-party content from the P2P Foundation Wiki, licensed
CC BY-SA 4.0. They are kept in this repository under that same license, not
under the project's own license. Any copy or adaptation of them must keep the
attribution below and stay under CC BY-SA 4.0.

## Pages

All `*.parse.json` files are `action=parse` responses with
`prop=text|revid|displaytitle&disableeditsection=1&disabletoc=1&redirects=1&format=json&formatversion=2`.
None of the five pages was a redirect (`parse.redirects` is empty in each).

| Page title | Page address | revid | Fixture file | Captured |
|---|---|---|---|---|
| Bioregionalism | https://wiki.p2pfoundation.net/Bioregionalism | 151033 | `bioregionalism.parse.json` | 2026-11-04 |
| Cosmo-Localization | https://wiki.p2pfoundation.net/Cosmo-Localization | 143653 | `cosmo-localization.parse.json` | 2026-11-04 |
| Cosmo-Localism | https://wiki.p2pfoundation.net/Cosmo-Localism | 148307 | `cosmo-localism.parse.json` | 2026-11-04 |
| Commons-Based Peer Production | https://wiki.p2pfoundation.net/Commons-Based_Peer_Production | 150616 | `commons-based-peer-production.parse.json` | 2026-11-04 |
| Commons Transition | https://wiki.p2pfoundation.net/Commons_Transition | 118570 | `commons-transition.parse.json` | 2026-11-04 |

Other files:

| File | Address | Used for |
|---|---|---|
| `siteinfo.json` | `https://wiki.p2pfoundation.net/api.php?action=query&meta=siteinfo&siprop=general\|rightsinfo&format=json&formatversion=2` | Site name, generator and license (`rightsinfo`) |
| `bioregionalism.page.html` | https://wiki.p2pfoundation.net/Bioregionalism (article page, HTML only) | MediaWiki detection from the `generator` meta tag and the `EditURI` link (`https://wiki.p2pfoundation.net/api.php?action=rsd`) |

## Attribution

- Source: P2P Foundation Wiki, https://wiki.p2pfoundation.net/Bioregionalism, CC BY-SA 4.0
- Source: P2P Foundation Wiki, https://wiki.p2pfoundation.net/Cosmo-Localization, CC BY-SA 4.0
- Source: P2P Foundation Wiki, https://wiki.p2pfoundation.net/Cosmo-Localism, CC BY-SA 4.0
- Source: P2P Foundation Wiki, https://wiki.p2pfoundation.net/Commons-Based_Peer_Production, CC BY-SA 4.0
- Source: P2P Foundation Wiki, https://wiki.p2pfoundation.net/Commons_Transition, CC BY-SA 4.0

## Handling

The page HTML in these files is untrusted third-party input. The importer
sanitises it (HtmlSanitizer and the `imported_html` text format) before
anything is stored (threat T-22-03).
