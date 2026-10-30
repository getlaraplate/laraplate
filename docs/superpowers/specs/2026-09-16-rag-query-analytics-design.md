# RAG query analytics

**Status:** implemented (2026-10-08) — privacy review signed off the same day (see *Decision (2026-10-08)*); delivery in the plan's *Delivery status*

**Date:** 2026-09-16

**Modules:** AI (owns the documentation RAG path and the analytics writer).

**Plan:** `docs/superpowers/plans/2026-09-16-rag-query-analytics.md`

## Problem

The documentation RAG path answers user questions and keeps no record of them. Nobody can see which questions retrieved nothing (corpus gaps), what languages real usage is in, how often the assistant abstains, or how slow retrieval is in production. Every retrieval-quality decision so far has run against a hand-written fixture (`test:ai:evaluate-documentation`), never against what users actually ask.

Two existing designs name the hole from opposite sides:

- `2026-05-13-rag-multi-instance-design.md` lists analytics as an explicit deferred non-goal (goal #4: "query logging is a **separate** concern from the RAG corpus — privacy, retention — not required for multi-instance correctness"), and its plan sketches a `laraplate_rag_queries` index in Task 7 marked *defer, separate plan, requires privacy review*. This spec is that decision.
- `2026-09-15-measured-retrieval-tuning-l1-design.md` states that runtime tuning (L2) is "blocked on absent search telemetry." This index is that missing telemetry — though wiring it back into tuning is deliberately **not** part of this spec (see *Non-goals*).

## Decision summary

An append-only analytics index records one document per answered documentation-RAG query. It is:

- **Off by default**, behind `config('ai.features.faq.query_logging.enabled')`.
- **Write-only telemetry.** It never feeds retrieval, never trains anything, and is never read on the request path. Reading it is an operator/reporting concern, out of scope here.
- **Privacy-first by construction.** User identity is stored only as a keyed hash, query-text storage is configurable, a retention window prunes old documents, and the whole feature stays unbuilt until a privacy review approves the field set and retention.
- **Fail-open and off the hot path.** The write is queued; a logging failure or a disabled queue never delays or fails an answer.

## The record

One document per answered query, in a dedicated Elasticsearch index `{app}_rag_queries` (same store family as the RAG corpus, its own index — never mixed with document vectors):

| Field | Meaning |
|---|---|
| `id`, `logged_at` | identity and timestamp |
| `user_ref` | keyed hash (HMAC with an app secret) of the user id, never the raw id |
| `tenant` | tenant scope, following existing scoping |
| `profile` | server-owned assistant profile (developer vs in-app), so analytics slice the way evaluation does |
| `locale` | request locale |
| `query` | raw or hashed per `query_text_mode` config; may be omitted entirely |
| `retrieved_count`, `citation_count` | how many candidates and how many grounded citations |
| `answered` | did it return grounded citations, or abstain |
| `latency_ms` | retrieval latency |
| `index` | which physical RAG index served it (developer/user), reusing the multi-instance separation |

Never stored: the answer text, document bodies, raw user id, or any secret.

Guest and impersonated principals are excluded from logging on the same eligibility rule the in-app assistant already applies (`2026-07-16-in-app-ai-assistance-security-design.md`): only an identified user speaking for themselves is logged.

## Configuration

A new subtree under the existing `features.faq` block in `Modules/AI/config/config.php`:

```php
'query_logging' => [
    'enabled' => env('AI_FAQ_QUERY_LOGGING_ENABLED', false),
    'index' => env('AI_FAQ_QUERY_LOG_INDEX', Str::slug(config('app.name')).'_rag_queries'),
    'query_text_mode' => env('AI_FAQ_QUERY_LOG_TEXT', 'hashed'), // hashed | raw | off
    'retention_days' => (int) env('AI_FAQ_QUERY_LOG_RETENTION_DAYS', 90),
],
```

`query_text_mode` defaults to `hashed`: raw query text is opt-in, and `off` records only the structured signal (counts, latency, locale) with no query at all.

## Where the write happens

At the end of the in-app documentation path, after retrieval has resolved its citations (around `InAppDocumentationRetrieval` / the FAQ answer flow), the answer flow dispatches a queued writer with the record above. The answer returns to the caller without waiting for it. If the feature is disabled, no job is dispatched; if the queue or the index is unavailable, the job fails in isolation and the answer is already delivered.

## Privacy review is a prerequisite, not a step

The first task of any implementation plan is a **blocking** privacy review that must approve, before a line of code:

- the field set above, and the `query_text_mode` default;
- the hashing scheme for `user_ref`, including HMAC key storage and rotation (a rotated key breaks correlation across the rotation, which is acceptable and must be stated);
- the retention window and the job that prunes documents past it;
- the guest/impersonation exclusion;
- an erasure hook: because `user_ref` is a keyed hash, a GDPR erasure request maps to deleting documents by that user's hash — the pruning job and the erasure hook must both be specified in the plan.

The feature stays `enabled=false` and unbuilt until that review signs off.

## Decision (2026-10-08): privacy review signed off, with three amendments

The owner approved the review recorded in `Modules/AI/docs/rag/query-analytics-privacy-review.md`. It amends this spec where the two differ:

- **Query text is `raw` by default, with `off` as the alternative; `hashed` is dropped.** The log exists to show what the documentation does not answer, which a hash cannot show, and a hash of a short question is easy to reverse.
- **Retention is 30 days**, not 90, because the question is stored as typed.
- **Settings, not env.** `enabled`, `query_text_mode` and `retention_days` are seeded settings (`features.faq.query_logging.*`, defaults `false`, `raw`, `30`), following the module rule that switches and tuning values are settings. The index name stays in `config.php` like the other RAG indexes.

The HMAC key is `APP_KEY`. It is rotated only on compromise, through `APP_PREVIOUS_KEYS`; the erasure hook matches the hash computed with the current key and with every previous key, so a rotation does not hide a user's older documents from erasure.

## Non-goals

- **Automatic learning from queries.** The index is never read back into retrieval or ranking. Feeding it into L2 tuning (`2026-09-15-measured-retrieval-tuning-l1-design.md`) is a later, separately privacy-reviewed decision, not this one. The multi-instance design forbids the shortcut, and this spec keeps that fence.
- **The search-timing instrumentation of `2026-09-16-search-modes-and-strategy-resolution` (Task 0).** That is ephemeral per-request debug meta on the *application-content* search path. This is a durable *documentation*-RAG query corpus. Different path, different lifetime; do not conflate them.
- **Any read/reporting UI or dashboard** over the index.
- **Logging non-documentation search** (CRUD/application-content search): out of scope.

## Scope boundaries

In scope: the `{app}_rag_queries` index and its mapping, the queued writer behind the flag, the record shape, `user_ref` hashing, the retention/erasure job, and the config keys.

Out of scope: reading or reporting over the index, feeding analytics into retrieval or tuning, the privacy review itself (a prerequisite this spec records but does not perform), and non-documentation search logging.

## Related

- `docs/superpowers/specs/2026-05-13-rag-multi-instance-design.md` (goal #4 — named this a deferred non-goal)
- `docs/superpowers/plans/2026-05-13-rag-multi-instance-elasticsearch.md` (Task 7 — the deferred sketch this formalizes)
- `docs/superpowers/specs/2026-09-15-measured-retrieval-tuning-l1-design.md` (L2 is blocked on the "absent search telemetry" this index would provide)
- `docs/superpowers/specs/2026-07-16-in-app-ai-assistance-security-design.md` (principal eligibility: guest/impersonation exclusion, hashing discipline)
- `Modules/AI/app/Ai/Rag/Retrieval/InAppDocumentationRetrieval.php` (where citations resolve — the write point)
- `Modules/AI/config/config.php`, `features.faq` block (the subtree the new config joins)
