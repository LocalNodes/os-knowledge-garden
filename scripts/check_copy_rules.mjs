#!/usr/bin/env node
/**
 * Copy-rule check over Phase 22's user-facing strings (D-04, PLACE-01;
 * 22-UI-SPEC Part 6 and the Copywriting Contract; 22-AI-SPEC §5 E9).
 *
 * Scans:
 *   html/modules/custom/localnodes_import/{templates,src,config/install,js}
 *   html/modules/custom/social_ai_indexing/{templates,js,deepchat_styles}
 *   html/modules/custom/social_ai_indexing/src/Citation/CitedAnswer.php
 *     (the no-answer and fallback copy, AI-SPEC E9 "server-owned copy")
 *   html/modules/custom/localnodes_platform/config/install/block.block.socialblue_aideepchatchatbot.yml
 *   html/modules/custom/localnodes_platform/{templates,src/Controller,src/Plugin/EmailBuilder}
 *     (Phase 23, 23-13: the organizer guide, the welcome mail and the other
 *     pages the module ships; the report form's copy is already covered under
 *     localnodes_import/src)
 * and only the strings a person reads there:
 *   - Twig: text between tags, {{ '…'|t }} literals, {% trans %} bodies, and
 *     the aria-label, title, alt, placeholder and value attributes. Comments
 *     ({# … #}) and expressions ({{ node.label }}) are not copy.
 *   - PHP: t('…'), $this->t('…'), new TranslatableMarkup('…') and
 *     formatPlural(…, '…', '…') literals, and class constants holding prose
 *     (a value with a space). Identifiers, logger messages and comments are
 *     not copy.
 *   - JS: Drupal.t('…') and Drupal.formatPlural literals, and literals set as
 *     textContent or as an aria-label/title attribute.
 *   - YAML: label, description, message, first_message, bot_name, title,
 *     placeholder, text and help values. Machine names are not copy.
 *
 * Flags (one "FLAG <file>:<line> <rule>: <text>" line each):
 *   - place:  your town, your area, local residents, near you, neighbours
 *   - system: node, entity, index, indexing, RAG, Solr, Qdrant, cron, batch,
 *             queue, embedding, LLM, token
 *   - button: a string that is only Submit, OK, Yes, Continue, Cancel, Retry,
 *             Try again or Refresh
 *
 * A line holding a `copy-allow` comment, or directly under one, is skipped
 * (organizer-only help text that has to name a system thing). Exit 1 on any
 * finding, 0 when clean, 2 on bad arguments.
 *
 * Usage:
 *   node scripts/check_copy_rules.mjs [--extra <dir-or-file>]... [--only-extra]
 * --extra adds a folder or file to the scan (the tests plant bad copy this
 * way); --only-extra scans just the --extra paths. No dependencies.
 */
import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs';
import { dirname, extname, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const custom = (p) => resolve(repoRoot, 'html/modules/custom', p);

const DEFAULT_PATHS = [
  custom('localnodes_import/templates'),
  custom('localnodes_import/src'),
  custom('localnodes_import/config/install'),
  custom('localnodes_import/js'),
  custom('social_ai_indexing/templates'),
  custom('social_ai_indexing/js'),
  custom('social_ai_indexing/deepchat_styles'),
  custom('social_ai_indexing/src/Citation/CitedAnswer.php'),
  custom('localnodes_platform/config/install/block.block.socialblue_aideepchatchatbot.yml'),
  // Phase 23 (23-13, D-22, PLACE-01): the module's templates (guide, mail
  // wrapper and bodies, footer), controllers and mail builders.
  custom('localnodes_platform/templates'),
  custom('localnodes_platform/src/Controller'),
  custom('localnodes_platform/src/Plugin/EmailBuilder'),
];

const PLACE = /\b(your town|your area|local residents|near you|neighbou?rs)\b/i;
// RAG and LLM only in capitals: "rag" and "llm" in lower case aren't the terms.
const SYSTEM = /\b(nodes?|entit(?:y|ies)|index(?:es|ed|ing)?|Solr|Qdrant|cron|batch(?:es)?|queues?|embeddings?|tokens?)\b|\b(RAG|LLM)\b/i;
const SYSTEM_CASE_SENSITIVE = /\b(RAG|LLM)\b/;
const LONE_BUTTONS = ['submit', 'ok', 'yes', 'continue', 'cancel', 'retry', 'try again', 'refresh'];
const YAML_COPY_KEYS = new Set(['label', 'description', 'message', 'first_message', 'bot_name', 'title', 'placeholder', 'text', 'help']);

function parseArgs(argv) {
  const extra = [];
  let onlyExtra = false;
  for (let i = 0; i < argv.length; i++) {
    if (argv[i] === '--extra' && argv[i + 1] !== undefined) {
      extra.push(resolve(process.cwd(), argv[++i]));
    } else if (argv[i] === '--only-extra') {
      onlyExtra = true;
    } else {
      console.error(`usage: check_copy_rules.mjs [--extra <dir-or-file>]... [--only-extra] (bad argument: ${argv[i]})`);
      process.exit(2);
    }
  }
  return { paths: onlyExtra ? extra : [...DEFAULT_PATHS, ...extra] };
}

function walk(path, out) {
  if (!existsSync(path)) {
    console.error(`MISSING ${relative(repoRoot, path) || path}`);
    return out;
  }
  if (statSync(path).isDirectory()) {
    for (const name of readdirSync(path).sort()) {
      walk(resolve(path, name), out);
    }
  } else {
    out.push(path);
  }
  return out;
}

/** Line number (1-based) of a character offset. */
function lineAt(source, offset) {
  let line = 1;
  for (let i = 0; i < offset; i++) {
    if (source.charCodeAt(i) === 10) line++;
  }
  return line;
}

/** Blanks a matched span but keeps its newlines, so offsets stay true. */
const blank = (s) => s.replace(/[^\n]/g, ' ');

/** Unescapes a quoted PHP/JS/YAML literal body. */
function unquote(body, quote) {
  if (quote === "'") return body.replace(/\\'/g, "'").replace(/''/g, "'").replace(/\\\\/g, '\\');
  return body.replace(/\\"/g, '"').replace(/\\n/g, '\n').replace(/\\\\/g, '\\');
}

const LITERAL = String.raw`(?:'((?:[^'\\]|\\.)*)'|"((?:[^"\\]|\\.)*)")`;

/** Collects regex matches of a quoted literal (group 1 or 2) as copy. */
function collect(source, regex, out) {
  for (const m of source.matchAll(regex)) {
    const single = m[1] !== undefined;
    const body = single ? m[1] : m[2];
    const offset = m.index + m[0].indexOf(single ? `'${m[1]}'` : `"${m[2]}"`) + 1;
    out.push({ text: unquote(body, single ? "'" : '"'), offset });
  }
}

function fromPhp(source) {
  // Comments are not copy (keep newlines for line numbers).
  const code = source
    .replace(/\/\*[\s\S]*?\*\//g, blank)
    .replace(/(^|[^:'"])\/\/[^\n]*/g, (m, pre) => pre + blank(m.slice(pre.length)))
    .replace(/^\s*#[^\n]*/gm, blank);
  const out = [];
  collect(code, new RegExp(String.raw`(?:\$this->|\b)t\(\s*` + LITERAL, 'g'), out);
  collect(code, new RegExp(String.raw`new\s+TranslatableMarkup\(\s*` + LITERAL, 'g'), out);
  // Prose held in class constants (CitedAnswer's no-answer and fallback
  // copy). Only values with a space count, so machine ids stay out.
  const constants = [];
  collect(code, new RegExp(String.raw`\bconst\s+[A-Z][A-Z0-9_]*\s*=\s*` + LITERAL, 'g'), constants);
  out.push(...constants.filter((c) => / /.test(c.text) && /[A-Za-z]{2}/.test(c.text)));
  // formatPlural($count, 'singular', 'plural'): both forms.
  for (const m of code.matchAll(new RegExp(String.raw`formatPlural\(\s*[^,]+,\s*` + LITERAL + String.raw`\s*,\s*` + LITERAL, 'g'))) {
    for (const [body, q] of [[m[1] ?? m[2], m[1] !== undefined ? "'" : '"'], [m[3] ?? m[4], m[3] !== undefined ? "'" : '"']]) {
      out.push({ text: unquote(body, q), offset: m.index + m[0].indexOf(body) });
    }
  }
  return out;
}

function fromJs(source) {
  const code = source
    .replace(/\/\*[\s\S]*?\*\//g, blank)
    .replace(/(^|[^:'"\\])\/\/[^\n]*/g, (m, pre) => pre + blank(m.slice(pre.length)));
  const out = [];
  collect(code, new RegExp(String.raw`Drupal\.t\(\s*` + LITERAL, 'g'), out);
  collect(code, new RegExp(String.raw`\.textContent\s*=\s*` + LITERAL, 'g'), out);
  collect(code, new RegExp(String.raw`setAttribute\(\s*['"](?:aria-label|title|placeholder)['"]\s*,\s*` + LITERAL, 'g'), out);
  for (const m of code.matchAll(new RegExp(String.raw`Drupal\.formatPlural\(\s*[^,]+,\s*` + LITERAL + String.raw`\s*,\s*` + LITERAL, 'g'))) {
    for (const body of [m[1] ?? m[2], m[3] ?? m[4]]) {
      out.push({ text: body, offset: m.index + m[0].indexOf(body) });
    }
  }
  return out;
}

function fromTwig(source) {
  const out = [];
  // Translated literals inside expressions, before expressions are blanked.
  collect(source, new RegExp(String.raw`\{\{\s*` + LITERAL + String.raw`\s*\|\s*t\b`, 'g'), out);
  for (const m of source.matchAll(/\{%\s*trans\s*%\}([\s\S]*?)\{%\s*endtrans\s*%\}/g)) {
    out.push({ text: m[1].replace(/\{\{[^}]*\}\}/g, '…'), offset: m.index + m[0].indexOf(m[1]) });
  }
  // Comments, tags and expressions are not copy.
  const text = source
    .replace(/\{#[\s\S]*?#\}/g, blank)
    .replace(/\{%[\s\S]*?%\}/g, blank)
    .replace(/\{\{[\s\S]*?\}\}/g, blank)
    .replace(/<(script|style)\b[\s\S]*?<\/\1>/gi, blank);
  // Attributes a person hears or reads.
  for (const m of text.matchAll(/\b(aria-label|title|alt|placeholder|value)\s*=\s*("([^"]*)"|'([^']*)')/gi)) {
    const body = m[3] ?? m[4];
    if (body.trim()) out.push({ text: body, offset: m.index + m[0].indexOf(body) });
  }
  // Text between tags.
  const stripped = text.replace(/<[^>]*>/g, (m) => blank(m));
  // A blanked tag leaves a run of spaces, so 2+ spaces split separate texts.
  for (const m of stripped.matchAll(/[^\s][^\n]*/g)) {
    for (const t of m[0].split(/\s{2,}/)) {
      if (/[A-Za-z]/.test(t)) out.push({ text: t.trim(), offset: m.index });
    }
  }
  return out;
}

function fromYaml(source) {
  const out = [];
  const lines = source.split('\n');
  let offset = 0;
  for (const line of lines) {
    const m = /^\s*([A-Za-z_]+):\s*(.+?)\s*$/.exec(line);
    if (m && YAML_COPY_KEYS.has(m[1]) && !/^[|>]/.test(m[2])) {
      let value = m[2];
      const q = /^'(.*)'$/.exec(value) ?? /^"(.*)"$/.exec(value);
      if (q) value = unquote(q[1], value[0]);
      if (/[A-Za-z]/.test(value) && !/^[a-z0-9_.:]+$/.test(value)) {
        out.push({ text: value, offset: offset + line.indexOf(m[2]) });
      }
    }
    offset += line.length + 1;
  }
  return out;
}

function extract(file, source) {
  if (file.endsWith('.twig')) return fromTwig(source);
  switch (extname(file)) {
    case '.php':
    case '.module':
    case '.inc':
    case '.install':
      return fromPhp(source);
    case '.js':
    case '.mjs':
      return fromJs(source);
    case '.yml':
    case '.yaml':
      return fromYaml(source);
    default:
      return [];
  }
}

function findings(text) {
  const found = [];
  const place = PLACE.exec(text);
  if (place) found.push(['place', place[0]]);
  const system = SYSTEM.exec(text);
  if (system && (system[1] !== undefined || SYSTEM_CASE_SENSITIVE.test(text))) {
    found.push(['system', system[0]]);
  }
  const bare = text.trim().replace(/[.!…]+$/, '').toLowerCase();
  if (LONE_BUTTONS.includes(bare)) found.push(['button', text.trim()]);
  return found;
}

const { paths } = parseArgs(process.argv.slice(2));
const files = paths.flatMap((p) => walk(p, []));
let flagged = 0;
let strings = 0;

for (const file of files) {
  const source = readFileSync(file, 'utf8');
  const lines = source.split('\n');
  const allowed = (line) => /copy-allow/.test(lines[line - 1] ?? '') || /copy-allow/.test(lines[line - 2] ?? '');
  for (const { text, offset } of extract(file, source)) {
    strings++;
    const line = lineAt(source, offset);
    if (allowed(line)) continue;
    for (const [rule, word] of findings(text)) {
      flagged++;
      const shown = text.replace(/\s+/g, ' ').trim().slice(0, 120);
      console.log(`FLAG ${relative(repoRoot, file) || file}:${line} ${rule} "${word}": ${shown}`);
    }
  }
}

console.log(`copy rules: ${files.length} files, ${strings} strings, ${flagged} findings`);
process.exit(flagged ? 1 : 0);
