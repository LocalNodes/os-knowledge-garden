#!/usr/bin/env bash
#
# Rebuilds the 25-file import fixture set in ./files (Phase 22, plan 22-04).
#
# macOS only: it uses Apple's textutil (DOCX, legacy Word 97-2003 .doc and
# ODT writers), Ghostscript (PDF writer, image-only PDF, encryption) and zip.
# Other machines use the committed files; this script is their provenance.
#
# Every word in these files is fictional and place-free (PLACE-01: a
# community, never a town). Each text-bearing file carries a unique phrase,
# "fixture-phrase heron-NN", so the importer tests can assert extraction
# exactly. manifest.yml fixes the expected import outcome of every file.
#
# Usage: ./build.sh   (idempotent: it rewrites files/ from scratch)

set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUT="$HERE/files"
GS="${GS:-/opt/homebrew/bin/gs}"
AUTHOR="Fictional Steward"

missing=0
for tool in textutil zip; do
  if ! command -v "$tool" >/dev/null 2>&1; then
    echo "build.sh: required tool '$tool' not found (macOS textutil and zip are needed)." >&2
    missing=1
  fi
done
if [ ! -x "$GS" ]; then
  echo "build.sh: Ghostscript not found at $GS (brew install ghostscript, or set GS=/path/to/gs)." >&2
  missing=1
fi
if [ "$missing" -ne 0 ]; then
  exit 1
fi

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

rm -rf "$OUT"
mkdir -p "$OUT"

# Escape a string for a PostScript string literal.
ps_escape() {
  printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e 's/(/\\(/g' -e 's/)/\\)/g'
}

# Writes a one-page PostScript file: title, two wrapped paragraphs and the
# fixture phrase on a line of its own (so extraction can't split it).
# Args: out.ps title phrase paragraph1 paragraph2
make_ps() {
  local out="$1" title="$2" phrase="$3" p1="$4" p2="$5"
  local y=720 line
  {
    echo '%!PS'
    echo "[ /Title ($(ps_escape "$title")) /Author ($(ps_escape "$AUTHOR")) /DOCINFO pdfmark"
    echo '/Helvetica findfont 15 scalefont setfont'
    echo "72 $y moveto ($(ps_escape "$title")) show"
    echo '/Helvetica findfont 11 scalefont setfont'
    y=$((y - 30))
    for para in "$p1" "$p2"; do
      while IFS= read -r line; do
        echo "72 $y moveto ($(ps_escape "$line")) show"
        y=$((y - 15))
      done < <(printf '%s\n' "$para" | fold -s -w 84 | sed -e 's/ *$//')
      y=$((y - 10))
    done
    echo "72 $y moveto ($(ps_escape "Reference: $phrase")) show"
    echo 'showpage'
  } > "$out"
}

# Text PDF through pdfwrite. Args: out.pdf title phrase p1 p2 [extra gs args]
make_pdf() {
  local out="$1" title="$2" phrase="$3" p1="$4" p2="$5"
  shift 5
  local ps="$WORK/$(basename "$out").ps"
  make_ps "$ps" "$title" "$phrase" "$p1" "$p2"
  "$GS" -q -dSAFER -dBATCH -dNOPAUSE -sPAPERSIZE=a4 -sDEVICE=pdfwrite "$@" \
    -sOutputFile="$out" "$ps"
}

# Word-family document through textutil. Args: format out title-or-empty
# phrase p1 p2
make_textutil() {
  local fmt="$1" out="$2" title="$3" phrase="$4" p1="$5" p2="$6"
  local html="$WORK/$(basename "$out").html"
  {
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'
    echo "<p>$p1</p>"
    echo "<p>$p2</p>"
    echo "<p>Reference: $phrase</p>"
    echo '</body></html>'
  } > "$html"
  local args=(-convert "$fmt" -strip -author "$AUTHOR")
  if [ -n "$title" ]; then
    args+=(-title "$title")
  fi
  textutil "${args[@]}" -output "$out" "$html"
}

# --- 10 text PDFs (nine pdf-NN files plus the D-16 file name) -------------

make_pdf "$OUT/pdf-01-tool-share-rules.pdf" "Tool Share Rules" "fixture-phrase heron-01" \
  "Members of the tool share borrow ladders, saws and the long-handled loppers for up to one week at a time. Each tool has a paper tag that names the last person who used it, so questions about blunt blades go to the right neighbour." \
  "Return tools clean and dry to the green cupboard by the meeting room. If something breaks, say so in the tool share notebook; the repair circle fixes most things at its next session and nobody is charged for honest wear."

make_pdf "$OUT/pdf-02-orchard-pruning-notes.pdf" "Orchard Pruning Notes" "fixture-phrase heron-02" \
  "The winter pruning day covers the twelve apple trees and the two old pears along the lower path. Volunteers work in pairs, one cutting and one stacking, and every cut branch goes to the habitat pile rather than the bonfire." \
  "Young trees get a light shape only. The oldest pear is left almost untouched because the woodpeckers nest in its hollow limb, and the group agreed last year that the birds come before a tidy crown."

make_pdf "$OUT/pdf-03-rainwater-barrels.pdf" "Rainwater Barrel Guide" "fixture-phrase heron-03" \
  "Each plot holder may connect one rainwater barrel to the shared shed gutter. Barrels need a lid and a tap, and the overflow hose must point at the willow bed so that heavy rain never floods the paths." \
  "In dry spells the barrels are shared in turn, using the chalk board by the shed to book a fill. Empty and scrub every barrel before winter so that no water freezes inside and splits the plastic."

make_pdf "$OUT/pdf-04-potluck-checklist.pdf" "Potluck Checklist" "fixture-phrase heron-04" \
  "Every potluck needs a label for each dish listing its main ingredients, so that people with allergies can choose safely. Bring your own plate and cup where you can; the spare crockery box lives under the long bench." \
  "The washing-up team rotates each month and the list is pinned inside the kitchen door. Leftovers are offered to the volunteers first and then packed into the shared fridge with a date written on the lid."

make_pdf "$OUT/pdf-05-beekeeping-log.pdf" "Beekeeping Log, Spring" "fixture-phrase heron-05" \
  "Both hives came through the cold months with strong colonies. The first inspection found capped brood on five frames in the blue hive and four in the white hive, with plenty of stored honey left over from autumn." \
  "Visitors watch from behind the hedge marker and never open a hive on their own. The next inspection is planned for a calm, warm afternoon, and the two trained keepers will bring the spare veils for learners."

make_pdf "$OUT/pdf-06-repair-cafe-flyer.pdf" "Repair Cafe Flyer" "fixture-phrase heron-06" \
  "Bring a lamp that flickers, a jacket with a broken zip or a bicycle that squeaks. The repair cafe meets on the first weekend of the month in the community room, and the volunteers show you how to mend it yourself." \
  "There is no charge and no booking. Tea and cake are on the side table, and a jar for donations helps buy spare fuses, thread and inner tubes. Electrical repairs are checked by a second volunteer before they leave."

make_pdf "$OUT/pdf-07-meadow-survey.pdf" "Meadow Survey Summary" "fixture-phrase heron-07" \
  "Survey volunteers counted flowering plants in ten square frames placed along the meadow transect. Bird's-foot trefoil and knapweed were the most common, and three frames showed yellow rattle for the first time since sowing." \
  "The group will keep the late summer cut and remove the hay so the soil stays poor enough for wildflowers. A second count is planned for midsummer, and new volunteers can learn the frame method on the day."

make_pdf "$OUT/pdf-08-childcare-rota.pdf" "Childcare Rota for Gatherings" "fixture-phrase heron-08" \
  "During long gatherings two adults look after the children in the play corner so that parents can join the discussion. The rota pairs a regular volunteer with a newer one, and nobody covers more than one session in a row." \
  "The play corner has a box of crayons, a rug and a set of wooden blocks. Snacks must be free of nuts, and any child who needs a parent is walked back to the main room rather than called across it."

make_pdf "$OUT/pdf-09-compost-troubleshooting.pdf" "Compost Troubleshooting" "fixture-phrase heron-09" \
  "A wet, smelly heap needs more brown material: add shredded cardboard or dry leaves and turn it with the fork. A dry heap that never warms up needs green material and a can of water poured over each new layer." \
  "Keep cooked food, meat and dairy out of the open bays; they go in the sealed hot bin instead. The compost team turns the bays every second week and posts the temperature readings on the shed door."

make_pdf "$OUT/scan_0042_final(2).pdf" "Garden Committee Minutes, March" "fixture-phrase heron-10" \
  "Present were five committee members and two guests. The committee agreed to move the seed swap to the second weekend of the month and to ask the tool share for a second wheelbarrow before the planting season starts." \
  "The treasurer reported that the plot fees cover the water bill for the year. Action points: draft a welcome note for new plot holders, repaint the shed sign and collect quotes for a new gate latch before the next meeting."

# --- Image-only scanned PDF (no text layer) ---------------------------------

make_ps "$WORK/scanned-notice.ps" "Notice to Plot Holders" "fixture-phrase heron-11" \
  "This notice was scanned from paper and exists only as a picture. The cider press moves to the north shed in autumn, and anyone who wants a pressing slot should write their name on the sheet by the gate." \
  "The press must be washed down after every use and the pulp goes to the compost bays, never to the hedge. Questions go to the orchard team at the next gathering."
"$GS" -q -dSAFER -dBATCH -dNOPAUSE -sPAPERSIZE=a4 -sDEVICE=pdfimage24 -r60 \
  -sOutputFile="$OUT/scanned-notice.pdf" "$WORK/scanned-notice.ps"

# --- Encrypted PDF (opening needs a password) -------------------------------

make_pdf "$OUT/locked-budget.pdf" "Locked Budget" "fixture-phrase heron-12" \
  "This budget sheet lists the fictional costs for seed, compost and gate repairs over the growing year. It was saved with a password so that only the treasurer could open it, which is why the importer cannot read it." \
  "Plot fees cover the water bill, the seed swap covers itself, and the remaining money is held for the new gate latch. Every figure in this document is invented for testing." \
  -sOwnerPassword=fixture-owner-pass -sUserPassword=fixture-user-pass -dEncryptionR=3 -dKeyLength=128

# --- Truncated PDF (first half of a valid file) -----------------------------

make_pdf "$WORK/truncated-report-full.pdf" "Annual Report" "fixture-phrase heron-13" \
  "The annual report describes a busy year: forty new members, two work days a month and a seed swap that gave away more packets than ever. The full report was cut short in transit, so only its first half survives." \
  "Next year the group hopes to plant a hedge along the north fence, start a second compost bay and run a series of evening talks on soil care. Every figure in this report is invented for testing."
full_size=$(wc -c < "$WORK/truncated-report-full.pdf" | tr -d ' ')
head -c $((full_size / 2)) "$WORK/truncated-report-full.pdf" > "$OUT/truncated-report.pdf"

# --- 6 DOCX (one with a core title that differs from its file name) ---------

make_textutil docx "$OUT/volunteer-rota.docx" "" "fixture-phrase heron-14" \
  "The volunteer rota covers watering, the shed key and the Saturday welcome table. Each slot lasts two hours, and people swap slots by writing on the shared calendar rather than by private message." \
  "New volunteers shadow a regular for their first slot. If nobody has signed up for a slot by Thursday evening, the coordinator sends one friendly reminder to the whole group and then leaves it."

make_textutil docx "$OUT/workshop_signup_sheet.docx" "" "fixture-phrase heron-15" \
  "Sign up here for the spring workshops: grafting fruit trees, building a bug hotel and sowing a pollinator strip. Each workshop takes twelve people, and the waiting list moves up in the order names were written." \
  "Workshops are free for members and visitors alike. Bring gloves and sturdy shoes; aprons and tools are provided. If you can no longer come, cross out your name so the next person on the list can take the place."

make_textutil docx "$OUT/handbook-draft-v2.docx" "Seed Library Handbook" "fixture-phrase heron-16" \
  "The seed library lends seed packets the way a book library lends books. Members take up to five packets each season, grow the plants and return a share of the seed they save at the end of the year." \
  "Every returned packet needs the plant name, the year and the grower's first name. Seeds from plants that may have crossed with a neighbour are kept in a separate tray marked for experiments."

make_textutil docx "$OUT/newsletter-autumn.docx" "" "fixture-phrase heron-17" \
  "The autumn newsletter thanks everyone who came to the harvest supper and the leaf-raking morning. Over sixty people shared food, and the leaf bins are now full enough to make leaf mould for two seasons." \
  "Coming up: the bulb planting day, a talk on winter cover crops and the annual general meeting. Members can propose a motion by handing it to any committee member at least two weeks before the meeting."

make_textutil docx "$OUT/welcome-letter.docx" "" "fixture-phrase heron-18" \
  "Welcome to the community garden. Your plot number is on the key tag, and the shared tools, water barrels and compost bays are open to every member from dawn to dusk on every day of the week." \
  "Please come to one work morning each season and read the short list of garden agreements pinned in the shed. If anything is unclear, the welcome team meets new members at the gate on Saturday mornings."

make_textutil docx "$OUT/bike-repair-steps.docx" "" "fixture-phrase heron-19" \
  "To fix a puncture, take the wheel off, lever the tyre away from the rim and pull out the inner tube. Pump a little air in and listen for the hiss, then mark the hole with chalk before you patch it." \
  "Check the inside of the tyre for the thorn or glass that caused the puncture before refitting the tube. The repair cafe keeps patches, levers and a track pump in the red toolbox for anyone to use."

# --- 3 legacy Word 97-2003 .doc files (Apple's writer; PhpWord can't write .doc)

make_textutil doc "$OUT/pond-cleanup-plan.doc" "" "fixture-phrase heron-20" \
  "The pond cleanup happens once a year in early autumn, after the frogs have left and before the newts settle for winter. Volunteers rake out a third of the blanket weed and leave it on the bank overnight." \
  "Leaving the weed on the bank lets small creatures crawl back into the water. Only a third of the plants are thinned in any year, so the pond always keeps cover for the insects that live in it."

make_textutil doc "$OUT/fruit_tree_map_notes.doc" "" "fixture-phrase heron-21" \
  "These notes go with the hand-drawn fruit tree map in the shed. Each tree has a number painted on a small stake, and the list below gives its variety, the year it was planted and who looks after it." \
  "Tree seven is the late plum that crops every other year. Tree eleven is the crab apple used as a pollinator for the rest of the row, and its fruit goes into the jelly made for the winter fair."

make_textutil doc "$OUT/storytelling-evening.doc" "" "fixture-phrase heron-22" \
  "The storytelling evening invites anyone to share a short tale about growing food, mending things or the history of the garden. Each story lasts no more than five minutes, and the youngest tellers go first." \
  "Stories are not recorded unless the teller agrees. Hot soup is served at the interval, and the evening ends with a song that the group learned together at the very first gathering."

# --- 1 ODT -------------------------------------------------------------------

make_textutil odt "$OUT/shared-kitchen-guidelines.odt" "" "fixture-phrase heron-23" \
  "The shared kitchen is open to every member who has done the short food hygiene walk-through. Label everything you leave in the fridge with your name and the date, and clear your shelf at the end of each week." \
  "Knives go back on the magnetic strip, not in the drawer. The big soup pot belongs to everyone and must be scrubbed and dried before it goes back on the top shelf above the cooker."

# --- Byte-identical copy of an earlier PDF (D-15 duplicate) ------------------

cp "$OUT/pdf-03-rainwater-barrels.pdf" "$OUT/copy-of-pdf-03.pdf"

# --- 1 XLSX (refused: unsupported type) -------------------------------------

X="$WORK/xlsx"
mkdir -p "$X/_rels" "$X/xl/_rels" "$X/xl/worksheets"
cat > "$X/[Content_Types].xml" <<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>
XML
cat > "$X/_rels/.rels" <<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>
XML
cat > "$X/xl/workbook.xml" <<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Budget" sheetId="1" r:id="rId1"/></sheets></workbook>
XML
cat > "$X/xl/_rels/workbook.xml.rels" <<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>
XML
cat > "$X/xl/worksheets/sheet1.xml" <<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>Item</t></is></c><c r="B1" t="inlineStr"><is><t>Fictional cost</t></is></c></row><row r="2"><c r="A2" t="inlineStr"><is><t>Seed packets</t></is></c><c r="B2"><v>40</v></c></row><row r="3"><c r="A3" t="inlineStr"><is><t>Gate latch</t></is></c><c r="B3"><v>25</v></c></row></sheetData></worksheet>
XML
# Fixed timestamps keep the zip bytes stable across rebuilds.
find "$X" -exec touch -t 202611010000 {} +
(cd "$X" && zip -X -q -r "$OUT/budget.xlsx" '[Content_Types].xml' _rels xl)

count=$(find "$OUT" -type f | wc -l | tr -d ' ')
echo "build.sh: wrote $count files to $OUT"
if [ "$count" -ne 25 ]; then
  echo "build.sh: expected 25 files, found $count" >&2
  exit 1
fi
