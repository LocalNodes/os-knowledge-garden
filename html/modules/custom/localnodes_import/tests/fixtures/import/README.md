# Document import fixtures

These 25 files are the test set for the document importer (Phase 22, KNOW-01,
AI-SPEC §5 "Import fixture set", eval dimension E7 "no silent gaps"). Success
criterion 1 is judged on importing 20+ PDF and Word files in one action; this
folder is that action.

**Every word in these files is fictional.** The garden, the people and the
figures are invented, and no file names a real town or place (PLACE-01: a
community, never a town). Document metadata is fictional too: the author is
"Fictional Steward" on every file that carries one.

`manifest.yml` fixes the expected outcome of every file before any importer
code exists, so the tests check the importer against it rather than describing
whatever the importer does. Expected totals: 21 imported, 20 of 21 searchable,
1 duplicate, 2 failed, 1 refused.

## What each file exercises

| File | Kind | Expected | Exercises |
|---|---|---|---|
| `pdf-01-…pdf` to `pdf-09-…pdf` | Text PDF | imported | Plain extraction; `fixture-phrase heron-01` to `-09`; `/Title` metadata becomes the Topic title |
| `scan_0042_final(2).pdf` | Text PDF | imported | D-16: Title metadata "Garden Committee Minutes, March" must win over the raw file name; phrase `heron-10` |
| `scanned-notice.pdf` | Image-only PDF | not searchable (scanned PDF) | No text layer (rendered with Ghostscript `pdfimage24`). The picture shows `heron-11` and a cider-press fact that must never reach the index. No metadata title, so the title falls back to "Scanned notice" |
| `locked-budget.pdf` | Encrypted PDF | failed (unreadable) | User and owner passwords set; smalot throws "Secured pdf file are currently not supported". Must not cause a fatal |
| `truncated-report.pdf` | Truncated PDF | failed (unreadable) | The first half of a valid PDF; smalot throws "Unable to find startxref". Must not cause a fatal |
| `volunteer-rota.docx`, `workshop_signup_sheet.docx`, `newsletter-autumn.docx`, `welcome-letter.docx`, `bike-repair-steps.docx` | DOCX | imported | Word2007 extraction (`heron-14`, `-15`, `-17`, `-18`, `-19`); no core title, so the cleaned file name is the title |
| `handbook-draft-v2.docx` | DOCX | imported | Core title "Seed Library Handbook" must win over the file name (D-16); `heron-16` |
| `pond-cleanup-plan.doc`, `fruit_tree_map_notes.doc`, `storytelling-evening.doc` | Word 97-2003 | imported | PhpWord `MsDoc` reader (`heron-20`, `-21`, `-22`). Written by Apple's `textutil`, a Word 97-2003 writer independent of PhpWord (which can't write `.doc`) |
| `shared-kitchen-guidelines.odt` | ODT | imported | ODT extraction; `heron-23` |
| `copy-of-pdf-03.pdf` | Text PDF | duplicate | Byte-identical to `pdf-03-rainwater-barrels.pdf` under another name (D-15): skipped, linking the existing page |
| `budget.xlsx` | Spreadsheet | refused | Unsupported type, refused with a plain message |

Phrase numbers skip `heron-24` and `heron-25`: the duplicate carries pdf-03's
phrase, and the spreadsheet carries none.

## Measured extraction

`probe_extraction.php` reads each manifest row with the libraries
`ai_file_to_text` uses (smalot/pdfparser; PhpWord `Word2007`, `MsDoc`,
`ODText`) and with the module's own extractor classes, and prints one line per
file. Its recorded output is in the 22-04 SUMMARY. Run it from the repository
root:

```bash
docker run --rm -v "$(git rev-parse --show-toplevel)":/var/www/html \
  -w /var/www/html --entrypoint "" \
  ddev/ddev-webserver:v1.24.6-fresh3-built php8.3 \
  html/modules/custom/localnodes_import/tests/fixtures/import/probe_extraction.php 2>/dev/null
```

PhpWord's `MsDoc` reader reads the text of all three `.doc` files but emits
about 56,000 "Uninitialized string offset" PHP warnings per file while doing
so; the probe sends them to stderr.

## Rebuilding

The files are committed, so tests never need to rebuild them. To rebuild on a
Mac (needs `textutil` and `zip`, both built in, and Ghostscript at
`/opt/homebrew/bin/gs`, or set `GS=/path/to/gs`):

```bash
./build.sh
```

The script rewrites `files/` from scratch and exits 1 with a message if a tool
is missing. Ghostscript and textutil stamp creation dates, so rebuilt PDFs and
Word files differ byte-for-byte from the committed ones; the text, titles and
expected outcomes don't change. If you rebuild, rerun the probe and commit the
new files together.
