// Canned AI responses for the S7 chat and S8 overview a11y specs (22-28).
//
// RECORDED, THEN CANNED. Nothing in the a11y suite may reach Gemini (orch
// ruling 2026-11-06: 22-27's live run owns the free key's daily quota). The
// specs intercept the two AI endpoints with page.route() and fulfil them with
// the bodies below, and helpers/ai-guard.ts fails any test where a request to
// those endpoints was not fulfilled by the route handler.
//
// Source of the text: .planning/phases/22-document-importer-cited-group-answers/22-live-run.jsonl
//   - RQ-01 (P-ANON, PASS, 2026-11-06T05:04:21Z): the `answer` field, i.e. the
//     server-rewritten CitedAnswer::toMarkdown() output, with its one citation
//     [1] Bioregionalism -> /node/29.
//   - RQ-02 (P-ANON, PASS, 2026-11-06T05:04:46Z): its first sentence, verbatim,
//     and that sentence's citation [1] Cosmo-Localization -> /node/30,
//     renumbered [2] for the overview (the overview is assembled from two
//     recorded answers; no recorded overview exists yet).
// Node ids are the desktop live-check site's; the a11y site doesn't follow
// these links, the specs only check they're root-relative.
//
// Response shapes are the real controllers':
//   - POST /api/deepchat -> DeepChatApi::createResponse(): {html, should_continue}.
//     `html` is the markdown after DeepChatApi::rewriteMarkdownMessage(),
//     CommonMark and Xss::filter() (the shape deepchat-links.js reads: a <p>
//     whose text is exactly "Sources" followed by a <ul> of "[n] <a>" items).
//     The real response also appends ai_chatbot's message buttons (the copy
//     icon); they're left out here and checked in the follow-up live run.
//   - GET /api/ai/overview?q= -> AiOverviewController::overview():
//     {summary_html, sources: [{n, title, url}]} or {summary_html: null}.
//     summary_html is AiOverviewService::toHtml() output: p/strong/em/ul/ol/li,
//     no attributes, [n] markers as plain text.
//
// Follow-up after 22-27 day 4 (22-28-SUMMARY.md, deferred-items.md): run the
// S7 'answered' and S8 'loaded' states once against a real answer to confirm
// the live markup matches these canned bodies.

/** RQ-01's recorded answer, as CitedAnswer::toMarkdown() returned it. */
export const RQ01_QUESTION = 'What does bioregionalism organize life around?';

export const RQ01_MARKDOWN =
  'Bioregionalism organizes human, social, and economic life around the natural boundaries of ecosystems, ' +
  'such as watersheds and living landscapes, rather than administrative abstractions [1]. It emphasizes living ' +
  'according to the ordering of seasonal cycles and the guiding principles of the natural order [1].\n\n' +
  '**Sources**\n- [1] [Bioregionalism](/node/29)';

/** The /api/deepchat JSON body for RQ-01 (CommonMark output of RQ01_MARKDOWN). */
export const CHAT_RQ01_RESPONSE = {
  html:
    '<p>Bioregionalism organizes human, social, and economic life around the natural boundaries of ecosystems, ' +
    'such as watersheds and living landscapes, rather than administrative abstractions [1]. It emphasizes living ' +
    'according to the ordering of seasonal cycles and the guiding principles of the natural order [1].</p>\n' +
    '<p><strong>Sources</strong></p>\n' +
    '<ul>\n<li>[1] <a href="/node/29">Bioregionalism</a></li>\n</ul>\n',
  should_continue: false,
};

export const CHAT_RQ01_SOURCES = [{ n: 1, title: 'Bioregionalism', url: '/node/29' }];

/** The /api/ai/overview JSON body for "bioregionalism" (RQ-01 + RQ-02 sentences). */
export const OVERVIEW_BIOREGIONALISM_RESPONSE = {
  summary_html:
    '<p>Bioregionalism organizes human, social, and economic life around the natural boundaries of ecosystems, ' +
    'such as watersheds and living landscapes, rather than administrative abstractions [1]. It emphasizes living ' +
    'according to the ordering of seasonal cycles and the guiding principles of the natural order [1].</p>\n' +
    '<p>Cosmo-localization is a concept that describes the dynamic potential of globally distributed knowledge ' +
    'commons working alongside the localized production of value [2].</p>',
  sources: [
    { n: 1, title: 'Bioregionalism', url: '/node/29' },
    { n: 2, title: 'Cosmo-Localization', url: '/node/30' },
  ],
};

/** What the controller returns when there's nothing to say (no panel). */
export const OVERVIEW_NONE_RESPONSE = { summary_html: null };

/**
 * The /api/deepchat JSON body when nothing in the group answers the question
 * (22-30). Not recorded: the text is the server-owned constant
 * CitedAnswer::NO_ANSWER_GROUP (the Copywriting Contract's "No relevant
 * content" row, in a group), as CommonMark renders a one-paragraph answer.
 * No Sources list.
 */
export const NO_ANSWER_GROUP =
  "I couldn't find anything about that in this group's documents. Try asking with different words, or open the group's documents yourself.";

export const CHAT_NO_ANSWER_RESPONSE = {
  html: `<p>${NO_ANSWER_GROUP}</p>\n`,
  should_continue: false,
};
