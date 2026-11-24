# English fallback for the user documentation

**Status:** Proposed

**Date:** 2026-11-19

**Program:** documentation RAG, user corpus. Follows `2026-11-12-documentation-permission-patterns-design.md`, whose last open point was the language of the ten English-only user guides.

## Problem

The retrieval of the in-app assistant and of the palette filters on `metadata.locale` with a single `term`: the locale of the caller, or the default locale when the caller's is not an enabled one. The ten user guides are written and indexed in English only, so they reach a caller whose locale is `en` and nobody else. An Italian user asking the assistant a question about record locking gets no passage, and the assistant refuses.

Translating the guides is a separate, slower job. Until a translation exists the English guide is better than nothing, and once it exists the caller's own language must win.

## Decision summary

1. **The filter admits two locales.** `metadata.locale` is matched with a `terms` query over the caller's locale and the fallback locale, `en`, the source language of the corpus. A caller whose locale is `en` gets the same single-value filter as today.
2. **The caller's language wins.** When the same document is retrieved in both locales, only the caller's one is kept. Two chunks belong to the same document when they share `module` and `canonical_source` (the document key is no help: it hashes the locale, so the two translations of one file have two keys). The preference runs on the raw results, before the safe projection, which drops `canonical_source`.
3. **A translation is a committed file.** It is the same guide with `locale: it` and the same `canonical_source`, indexed by the same `ai:index-rag-docs --profile=user`. Nothing in this change creates or generates one.
4. **The fallback locale is a constant,** `DocumentationRetrievalContext::FALLBACK_LOCALE = 'en'`, not a setting: it names the language the corpus is written in, and no installation has a reason to change it. An installation that wants no fallback has none to switch off, because with no English guide the filter finds nothing in that language.
5. **The reader keeps working.** A reference names one document key, so it points at one locale. The read filter admits both locales, so a reference to an English page still opens for an Italian caller.
6. **The answer language does not change.** `DocumentationAgent` already answers in the language of the question, over passages that may be English.

## What does not change

Tenant, audience, classification, permission and module filters, the mapping, the indexing, the document key, the safe projection and the permission rule of the previous spec. A page is still retrieved only when its permission requirement is met, in whichever locale it is.

## Limits, accepted

- The preference is among the retrieved passages. If the English chunk of a document is retrieved and the translated chunk of the same document is not, the English one is shown. The two are separate chunks with separate scores, and widening the search to chase the translation would change the ranking for every query.
- Dropping the English duplicates can leave fewer than `max_documents` passages. The search is not repeated to fill the gap.
- The caller is not told that a passage is English. A marker in the answer would be a change of the assistant contract and of the interface; it is out of scope here.

## Tests

The filter holds both locales and one for an English caller; a caller with locale `it` retrieves an English-only guide; with an `it` translation of the same guide both retrieved, only the `it` one is returned; a guide that exists only in English is returned beside a translated one; a locale other than the two stays excluded; the permission rule still applies to a fallback guide; the read of an English document key opens for an Italian caller.

## Out of scope

On-the-fly translation of a passage, generated translations stored in the index, a visible "shown in English" marker, per-locale fallback chains, a translation of the ten guides.
