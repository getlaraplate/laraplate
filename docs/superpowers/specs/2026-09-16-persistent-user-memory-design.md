# Persistent user memory (cross-session)

**Status:** decided on 2026-11-27, see *Decision (2026-11-27)* below: memory of the user across conversations is **not planned at present**; conversation memory (recent turns) is delivered in the AI module, switched off by default.

**Date:** 2026-09-16

**Module:** AI. Adjacent to the RAG assistant program (R-track), but a separate concern: this is *conversational memory of the user*, not document/data retrieval. Inspired by patterns in supermemory (fact profile, contradiction handling, temporal decay), rebuilt inside Laraplate's auth model.

## Decision (2026-11-27)

The owner decided, on 2026-11-27, to split this design in two.

**Conversation memory: delivered, off by default.** The recent turns of the same conversation reach the model as data in the authorized context, as *Conversation memory, alongside user memory* describes, with these deliberate differences:

- **Turns, not messages.** A turn is a question and the answers that follow it (the answer, and the follow-up written after a decision on a write). The setting is `features.chat.history.turns`, seeded 6, at most 20; `features.chat.history.enabled` is seeded `false`. Each text is cut at `ai.features.chat.history.max_chars` (2000). The keys `ai.features.memory.recent_turns*` were not created.
- **`speaker`, not `role`.** A turn is `{speaker: person|assistant, content}`: `AssistantControlPlaneData::assertPromptSafe()` refuses `role` as control-plane data, rightly, and the capability `conversation_memory` lists `speaker` in its fields.
- **No summary.** The conversation summary is not injected and the summarize hook is not wired: the window of recent turns is the whole memory. `MemoryService` stays dormant.
- **Stricter filtering.** A turn whose answer was refused, or with any text that the instruction classifier flags, is left out whole, rather than carried as data.
- **Per conversation.** `memory_enabled` turns it off for one conversation (`POST /app/crud/update/ai/conversations/{conversation}/memory`); `GET /app/ai/capabilities` returns `features.history`.

The threat model, the controls and the decision requested of the security review are in `Modules/AI/docs/rag/ASSISTANT_HISTORY_SECURITY_REVIEW.md`; the feature stays off until that review is accepted.

**Memory of the user across conversations: not planned at present.** Nothing of *Data model*, *Extraction and write*, *Retrieval and injection* (user facts), *Control, erasure and trust* or the user-facts half of *Integration* is built, and nothing is prepared for it. The reasons:

- **Personal data that outlives the conversation.** It needs consent, a place where the person sees and deletes what is remembered, retention per kind of fact and an erasure path before the first fact is written; the end-user screen belongs to another repository.
- **Memory poisoning.** One wrong or injected fact acts in every later conversation, about every subject, for as long as it lives: the opposite of the bounded window of recent turns.
- **The identity of the speaker.** Guest and impersonated sessions must never write or read facts, and the session that tells an impersonation apart does not exist in a queue worker, where extraction would run: a mistake fails open silently.
- **Truth and cost.** Facts need deduplication, supersession and expiry to stay true, and extraction costs model calls on every long conversation.

The design above stays as the starting point if a concrete need appears; it will then need its own security review. The module documentation says that memory across conversations is not provided (`Modules/AI/docs/rag/MODULE.md`, `Modules/AI/docs/ARCHITECTURE.md`, the user guide `Modules/AI/docs/rag/ASSISTANT_PROPOSALS_USER.md`).

## Problem

The assistant remembers only *within* one conversation: `MemoryService` summarizes a long chat and re-injects that summary into the same chat's system prompt (`getContextForNewMessage`). Extracted facts *are* persisted today, but only inside per-conversation `ConversationSummary.facts` snapshots (a JSON blob tied to one `conversation_id`) — they are never promoted to a cross-session, per-user, queryable store, and injection reads only the current conversation's summaries. So the user repeats stable facts every new conversation.

## Decision summary

Introduce a per-user, tenant-scoped store of durable **memory facts**, extracted from conversations, superseded on contradiction, expired by TTL, and injected (bounded) into the assistant prompt on later conversations. Facts are the user's own data; they are never shared across users or tenants. A dedicated table holds them (not the Core Graph): a fact is a row, retrieval is an indexed per-user query, and supersession/TTL reuse Laraplate's validity/versioning patterns.

## Scope and isolation

- A fact belongs to one **user**. Retrieval and every write are filtered by `user_id`, always. No cross-user read. Tenant is inherited via the Core user: AI tables carry no tenant column today (the assistant is Global-scope, like `Conversation`), so `user_id` is the isolation key; a tenant column is added only if/when AI tenant scoping lands.
- **Module-agnostic:** facts describe the user, so they surface to that same user in any module (a preference stated in CMS is useful in ERP). Module scope (R1a) governs docs/data retrieval, not user memory.
- Facts are the user's own data, not ACL-record-gated content: no per-record ACL applies. Secrets are never stored (see security invariants).
- **The guest principal is excluded, always.** Anonymous traffic is not a person: it resolves to the single shared guest account (`config('permission.users.guest')`, matched by `User::isGuest()` — the configured guest name/username, or any user with no email), and `ai_conversations.user_id` is not nullable, so every anonymous conversation hangs off that same row. Writing memory there would pool facts stated by unrelated strangers into one profile and inject them back into every later anonymous session: a cross-person leak wearing a valid `user_id`. Guests therefore neither produce nor consume memory facts — no extraction promotion, no injection — and the rule is enforced at the memory service itself, so no caller can opt out of it.
- **An impersonated session is excluded, always.** When an operator impersonates a user (`Lab404\Impersonate`, `User::isImpersonated()`), the words in the conversation are the operator's, not the account holder's: promoting them would write one person's statements into another person's permanent profile, under that person's `user_id`, with no trace that someone else authored them. Impersonation exists to reproduce what a user sees, not to edit who they are. So no facts are written during impersonation, and none are read either: showing the account holder's stored personal facts to whoever is impersonating them is the same leak in the opposite direction. Treated exactly like the guest case, at the same chokepoint.
- Both exclusions answer one question: *is there a single, identified person who owns these words and is entitled to this profile?* A guest fails it because the principal is shared; an impersonated session fails it because the speaker and the subject are different people. Anything else that breaks that correspondence later (a service account, a shared kiosk login) is excluded by the same test.

## Data model

New table (module-prefixed, e.g. `ai_memory_facts`), one row per fact:

- `id`, `user_id` (FK to Core users, indexed) — the isolation key, matching `Conversation`. No tenant column (see Scope and isolation).
- `fact` — the bounded plain-text fact.
- `fact_hash` — hash of the normalized text, indexed with `user_id`. Turns dedup into one indexed lookup instead of loading a profile into PHP on every write. Deliberately an **index, not a unique constraint**: the uniqueness we actually want is "among active rows" (not soft-deleted, not superseded, not expired), which a plain unique index cannot express, and a partial or functional index would not survive the SQLite test suite. Index plus an application check.
- `kind` — `standing` or `topical` (`FactKind`). A standing fact is a durable trait that bears on every answer (language, role, presentation preferences); a topical fact is tied to a subject, a document or a moment. The extractor assigns it (see Extraction), defaulting to `topical`.
- `subject` (nullable) — a short normalized slot key for standing facts (`ui.theme`, `language`, `role`, `export.format`). This is what makes supersession deterministic; see below.
- `source_message_id` (nullable FK, `nullOnDelete`) and `source_conversation_id` (nullable FK, `nullOnDelete`) — provenance. The conversation link is not redundant with the message one: it answers "which facts came from this chat" in one query instead of a join through messages, and that question has to be cheap because it is what an honest "forget this conversation" is built on.
- `confidence` — normalized score when available.
- `learned_at` — when first extracted.
- `expires_at` (nullable) — TTL; null = no expiry. **Set per kind** (see Expiry).
- `superseded_by` (nullable self-FK) + `superseded_at` — supersession chain; an active fact has both null.
- standard timestamps / soft-delete per the module model standard.

"Active facts for a user" = `user_id` match, not soft-deleted, `superseded_by` null, `expires_at` null-or-future. One indexed query.

## Extraction and write

- **Before anything else, the principal is checked:** a guest, an absent user, or an impersonated session ends the write path immediately — no extraction promotion, no row. The rest of this section applies only to an identified, non-guest user speaking for themselves.
- **Extraction classifies and slots.** `extractFacts()` returns `list<array{fact: string, kind: FactKind, subject: ?string}>` instead of bare strings: the same LLM call, a richer schema. A missing, misspelled or absent `kind` resolves to `topical`, and a bare string entry is accepted as topical, so a model that ignores the schema degrades instead of producing nothing. The default is deliberate and asymmetric: a fact wrongly marked `standing` enters every future prompt and stays; a fact wrongly marked `topical` merely has to earn its place by relevance. Fail toward the cheaper mistake.
- Reuse `MemoryService::extractFacts()` and `createSummarySnapshot()`, which already exist and already do the work; only the hook that reaches them is new, and it goes in `respond()` (see Integration). No new extraction logic, no second trigger cadence.
- On write, **dedup** by `fact_hash` against the user's active facts: an exact (normalized) duplicate is dropped. Provenance (`source_message_id`, `source_conversation_id`, `learned_at`, `confidence`) is stored on every fact.

### Supersession by subject

A memory that accumulates contradictions is worse than no memory: "prefers dark mode" and "prefers light mode" both active, both injected, and the assistant acting confidently on whichever it reads first. That is the failure that makes people switch a memory feature off, so v1 has to answer it rather than defer it.

The answer is the `subject` key. **A new active `standing` fact supersedes the existing active fact with the same `(user_id, subject)`**: the old row gets `superseded_by`/`superseded_at`, the chain and the provenance survive, and the profile reflects the change instead of recording both sides of it.

What makes this the right mechanism rather than a clever one:

- It is deterministic and free. No second LLM call, no semantic comparison, no reasoning at write time.
- **It never feeds stored facts back into a prompt.** The obvious alternative, asking a model to reconcile a new fact against the existing profile, would put user-authored stored text back into an LLM call: a cost, and a prompt-injection surface opened precisely in the path we spent this spec hardening.
- It rides the schema change we are already making. `subject` is one more field in the JSON object the extractor was going to emit anyway.

Scope: `subject` is expected on `standing` facts, which genuinely have slots (language, theme, role, preferred export format). `topical` facts are episodic, do not occupy a slot, and do not supersede: they are bounded by dedup and expiry instead. A standing fact arriving with no subject is stored without one and simply never supersedes, which is the safe degradation.

**Contradiction resolution beyond same-subject** (reconciling semantically conflicting facts in different slots) stays deferred. Same-subject supersession covers the case that actually hurts.

### Expiry

`expires_at` is set **per kind**, because the two kinds age differently and a single TTL has to be wrong for one of them:

- `standing` facts do not expire. A trait is not stale because it is old; it is stale when it is replaced, which is what supersession is for.
- `topical` facts expire on a configurable horizon (default of the order of 90 days). An episodic fact that has not been relevant in months is noise competing for a bounded budget.

Expiry is enforced at read time (the active-facts query) and by a prune path. This pairing, supersession for traits and expiry for episodes, is what stops the store growing without bound; a single global TTL defaulting to "never" would have let it.

## Retrieval and injection

- **Topical facts are selected by relevance to the current question, not by recency.** Recency is a tie-break, never the ranking. A profile ordered by `learned_at` spends the whole budget on whatever was said last and can leave the one fact that answers this question just outside the cap. Recency alone is the ordering of a log, not of a memory.
- **Standing facts are injected unconditionally**, under their own small cap, ahead of the topical ones. This is the blind spot pure ranking has: "prefers short answers" scores near zero against "how do I duplicate a purchase order?", yet it shapes that answer as much as any other. A fact that applies to everything cannot be made to compete on topical similarity, because it will always lose.
- Hence **two reads and one composition**, not one read with a hidden switch: a context-free read returning the standing profile, a query-dependent read ranking the topical tail, and a prompt-facing method that unions them under a single budget (standing first, topical for the remainder, deduped). Callers outside the prompt path (a "what do you remember about me" surface, an export) want the context-free read and never the composition.
- A null query is therefore not a fallback to recency: it means there is nothing topical to rank, so only the standing profile is injected. Nothing about the behaviour is hidden behind a nullable argument.
- **Relevance in v1 is lexical and deterministic**, computed in PHP over the user's bounded set of active topical facts: normalized token overlap between the query and each fact, recency breaking ties, top-N by config limit. No new infrastructure, no embedding cost on the write path, no vendor SQL (the test suite runs on SQLite in memory while production is MySQL, so `MATCH ... AGAINST` is not portable here), and the ranking is reproducible in a unit test without an LLM. The candidate set is itself bounded before scoring, so the work stays proportional to a profile, not to a corpus.
- A bounded set of the selected facts is injected into the assistant prompt context as a distinct "user memory" block, with provenance retained internally.
- Injection is **size-bounded** (context-reduction ethos): a small cap, not the whole profile. It sits alongside the existing summary memory-context injection point, not replacing it.
- The injection point is `InAppAssistanceService::respond()`, which already holds the user's message when it builds the prompt context, so the ranking has its query without threading anything. Memory enters as a field of `AssistantPromptContext`, never as a string appended to a system prompt: see Integration.
- A guest or an impersonated session asks and receives nothing: the retrieval call returns an empty set, so no "user memory" block is built and the prompt is byte-identical to the pre-memory one.
- Injected facts are **data, not instructions** — the same guardrail principle applied to retrieved content: a stored fact can never issue instructions to the assistant.

## Conversation memory, alongside user memory

`respond()` is stateless per message: it builds a fresh agent and sends only the current
input, with no prior turns and no summary. So the assistant does not recall the turn before
this one, in the same conversation. That gap is more visible to a user than the cross-session
one this spec is mainly about, and it is fixed here because both land in the same place.

**Recent turns verbatim, plus a summary only past a threshold.**

- **Recent turns** come straight from `conversation->messages()`: the last N, capped by count
  and by characters. No LLM call, no added latency, no cost. This alone answers most of what
  users experience as forgetfulness, because nobody complains that the assistant forgot turn
  forty; they complain that it forgot the previous one.
- **The summary** is injected only once the conversation passes the summarize threshold
  `shouldSummarize()` already decides. Summary alone would not do: it costs an LLM call and
  loses the exact words, which is precisely what recent turns are for ("the one before", "no,
  the other one"). Turns alone would not do either: they grow without bound against a finite
  context budget.

This settles a question the write path leaves open. `createSummarySnapshot()` makes two LLM
calls, one to summarize and one to extract facts. The summary is now injected, so both are
paid for. Had we chosen not to inject it, the honest move would have been to call
`extractFacts()` alone and let summarization go.

**Prior turns are data, exactly like facts.** Assistant messages were output-validated when
stored and user messages input-validated, but a user can plant an instruction in an earlier
turn for the model to follow now. They travel through `AssistantPromptContext` under the same
`assertPromptSafe` treatment and the same capability instruction.

**Order in the context, most stable first:** user memory (who is being answered), then the
conversation summary (what happened earlier), then the recent turns (what was just said). The
model reads durable context before recent noise.

## Security and information-flow invariants

1. Every read and write is filtered by `user_id`; a fact is never visible to another user (and, transitively, another tenant, since a user belongs to one).
2. **A guest principal is never a memory subject.** `User::isGuest()` short-circuits both the write and the read path, at the service, before any query. A guest conversation produces no facts and receives no injected block; a guest read returns an empty set, never the guest row's accumulated facts. This is a hard invariant, not a config toggle, and it holds even if rows for the guest account exist from a bug or a migration.
3. **An impersonated session is never a memory subject.** Neither write nor read, for the same reason and at the same chokepoint as the guest case. Note the mechanics: `isImpersonated()` is derived from the session, not from the user row, so it answers "is *this request* an impersonation" and must be evaluated at the point of decision, inside the request. If fact promotion is ever moved to a queue, the session is gone and the check would silently return false: the impersonation flag must then be captured at request time and carried in the job payload, never re-derived in the worker.
4. Facts are user-owned data; no per-record ACL, but the `user_id` filter is mandatory and fail-closed (a missing/ambiguous principal yields no memory, never all memory).
5. No secrets, credentials, or sensitive tokens are persisted: the extraction prompt and a write-time filter exclude them; when in doubt, drop.
6. Injected facts are untrusted data and cannot instruct the assistant (guardrail-consistent).
7. Provenance (`source_message_id`) never exposes another user's message.
8. Right-to-erasure: deleting a user cascades their facts. Conversation deletion is governed by the control model below, not by a cascade.

## Control, erasure and trust

A memory feature lives or dies on whether people trust it, and trust here is not a tone of voice: it is whether the user can see what is stored, correct it, and make it stop. A profile the user cannot inspect is one they will eventually switch off, and a "forget" button that does not forget is worse than no button. So the control model is part of the design, not a follow-up.

**Deleting a conversation does not delete the facts.** A fact is about the user, not about the chat it surfaced in; losing your profile because you tidied your conversation list is a surprise in the wrong direction. The conversation link is nulled, the fact survives.

That position is only defensible because of the next point, and the two must ship together: **without a way to see and delete their memory, the user's only lever is deleting conversations, and then deletion must cascade.** Decide them apart and you get a store the user cannot reach.

- **`forgetConversation()` deletes the facts sourced from that conversation.** The affordance already exists and already says "forget"; after this feature, left untouched, it would stop forgetting. A word that lies in a product is worse than a missing feature. `source_conversation_id` is what makes this one query.
- **A per-user memory switch**, distinct from the existing per-conversation `memory_enabled`. It lives in the `users.preferences` JSON bag, which already exists and is already synced from the SPA, so it costs no schema change. Off means: nothing written, nothing injected, existing facts retained but inert (turning memory off is not the same gesture as erasing it).
- **A memory management surface**: list your facts with when and where each was learned, delete one, delete all. That covers access, rectification and erasure in a single screen, and it is also the feature that makes the memory legible enough to be liked rather than merely tolerated.

**Repository boundary:** the backend owns the contract (an `/app` surface plus a Filament view for the superadmin). The end-user screen is Vue and therefore belongs to `laraplate-ui`, a separate proprietary repository; its spec is written there, not here. See the stack `AGENTS.md` on where specs live.

## Integration

Memory lives on the governed in-app path, because that is the only path a user message travels.

**The finding that settles it.** Both message endpoints call `InAppAssistanceService::respond()` (`ChatController.php:140`, `:169`); streaming is disabled and returns 422 (`:121`). `ChatService::sendMessage()`, `sendMessageStream()` and `sendMessageWithTools()` have no production caller: they were the original chat, superseded at the HTTP boundary by commit `970f54a feat(ai): integrate protected in-app assistance` and since removed. `checkAndCreateSummaryIfNeeded()` lived inside `sendMessage()` and went with it, so **the summarize hook does not fire at all and `MemoryService` never runs today**. The pre-existing conversation-summary memory is dormant, which is why its absence went unnoticed. An earlier draft of this spec planned memory around that path; it would have shipped a feature with no reachable effect.

- **Write:** `respond()` dispatches a queued promotion job when `shouldSummarize()` is true. Not inline: summarize plus extract are two LLM calls, and charging them to whichever user's message crosses the threshold is a latency cost with no upside. The queue is also where invariant 3 bites, so the eligibility decision is made in the request and carried in the job payload.
- **Read:** facts enter through `AssistantPromptContext` as their own field, and a `user_memory` capability is added to `AssistantPolicyCatalog` beside `in_app_rag`, `read_only_graph` and `application_content`. No string is appended to a system prompt anywhere.

This is not overhead accepted reluctantly; it is a better fit than the abandoned path:

1. `respond()` already holds the user's message when it builds the context, so relevance ranking gets its query with nothing threaded and no agent-caching hazard.
2. `AssistantPromptContext`'s constructor runs `AssistantControlPlaneData::assertPromptSafe()` on every field. "Facts are data, never instructions" stops being a sentence in this document and becomes an assertion executed on every request.
3. Each capability carries an `instruction` string, so the contract governing how the model may treat user facts is policy-compiled and versioned with the policy rather than hand-written into a prompt.
4. Memory becomes policy-gated per profile: `DeveloperHelp` does not list the capability and therefore gets no memory, with nobody having to remember to exclude it.

No change to `respond()`'s security or scope resolution (R1a), and none to the retrieval surfaces.

## Testing and evaluation

- Unit: the fact model / active-facts scope (user+tenant isolation, expiry, supersession filtering); dedup + supersede write logic; secret-exclusion filter; **guest and impersonation exclusion on both paths** (the write persists nothing; the read returns empty even when facts exist on the row).
- Unit: relevance ranking (a topical fact matching the query outranks a more recent unrelated one; ties fall back to recency; the cap is applied after ranking, not before); composition (a standing fact is injected whatever the question is; an absent query yields the standing profile alone; the standing cap cannot eat the whole budget); classification defaults (missing or invalid `kind` becomes `topical`).
- Unit: supersession (a new standing fact with the same subject supersedes the previous one and the old row leaves the active set; a different subject does not; a standing fact with no subject supersedes nothing; topical facts never supersede); expiry per kind (a standing fact gets no `expires_at`, a topical one does).
- Feature: `forgetConversation()` removes the facts sourced from that conversation and leaves the rest; the per-user switch suppresses both write and injection while retaining rows; extraction persists deduped facts at the summarize hook; a later conversation injects the user's active facts and not another user's; expired/superseded facts are excluded; **a guest conversation, and a conversation held inside an impersonated session, cross the summarize threshold writing no facts and receiving no memory block**.
- **Memory-recall eval (contract, ties to R1b):** a slice where the correct answer depends on a fact stated in an earlier conversation. Defined here; built with or after the assistant eval's Level-2, not required for v1's gate.

## Scope boundaries

In scope (v1): the fact table + model, `standing`/`topical` classification and `subject` slotting at extraction, extraction-to-persistence at the existing hook with hash dedup and same-subject supersession, per-kind expiry, relevance-ranked bounded injection into the assistant prompt, conversation memory (recent turns plus summary past threshold), user/tenant isolation, guest and impersonation exclusion, an honest `forgetConversation()`, the per-user memory switch, the backend memory-management surface, tests.

Out of scope: memory influencing **retrieval** (RAG query shaping, graph traversal); the end-user memory screen (Vue, belongs to `laraplate-ui`); embedding-based (semantic) fact relevance, see the upgrade path below; cross-subject semantic contradiction reconciliation; a graph representation of facts (see Upgrade path); multimodal/connector ingestion; sharing memory across users; any change to document/data retrieval or R1a scoping. Memory enters as its own context field, so the seam for a later step that lets standing facts bias retrieval stays clean and unopened.

## Upgrade path: semantic relevance

Lexical overlap ranks a fact that shares words with the question. It will miss a fact that answers the question in different words, which is the ordinary case for paraphrase. The module already has the parts for the better version (`EmbeddingsProviderFactory`, `SentenceTransformersEmbeddingsProvider`, `ElasticsearchRagVectorStore`), so the upgrade is to embed each fact on write and rank by vector similarity at read, with the same bound, the same fallback and the same eligibility rules.

It is deferred, not dismissed, for three reasons: it puts an embedding call on the write path (cost and latency where today there is none), it needs a store and a backfill for existing rows, and it makes the ranking untestable without a provider double. Lexical ranking gets the ordering principle right now, at no infrastructural cost, and the read API (`query` in, ranked facts out) is the same on both sides of the upgrade, so switching the implementation later touches one method.

## Upgrade path: Core Graph nodes

The table is the v1 store because a fact is today flat text about the user (preferences, context) with no relational web to traverse; a dedicated table gives explicit user/tenant isolation, reuses validity/versioning, and answers "active facts for a user" with one indexed query. The Core Graph would add weight and a record-oriented ACL that user facts do not need.

The Graph becomes worth it only if facts must **link to domain records and be traversed** — at which point it buys:

- **Fact ↔ record relations:** a fact connected to the actual records it concerns ("prefers supplier Z on ERP orders", "owns project Y"), as navigable edges rather than isolated strings.
- **Multi-hop memory + data in one traversal:** combine the user's memory with the record graph in a single query ("what does this user usually do with orders like this one") instead of memory and retrieval as separate steps.
- **Reuse of the existing Graph surface** (`expand`/`search`/`stats`, ACL-preserving gateway) instead of a bespoke retrieval query.

If that relational need appears, migrate facts to Graph nodes (or project the table into the graph) keeping the same user/tenant isolation and provenance; the table's `fact`/`source_message_id`/supersession fields map onto node attributes and edges. Until then, this is a deliberate YAGNI deferral, not a limitation.

## Related

- `Modules/AI/app/Services/MemoryService.php` (existing `extractFacts` / summary), `Modules/AI/app/Models/ConversationSummary.php`, `Modules/AI/app/Services/Assistance/InAppAssistanceService.php` (`respond()`, the single message path), `Modules/AI/app/Services/Assistance/AssistantPromptContext.php` and `Policies/AssistantPolicyCatalog.php` (where injected memory must live).
- `docs/superpowers/specs/2026-08-29-assistant-end-to-end-evaluation-design.md` (R1b; the memory-recall eval slice extends it).
- `Modules/Core/app/Models/User.php` (`isGuest()`, `isImpersonated()`, `getImpersonator()`), `Lab404\Impersonate\Services\ImpersonateManager::isImpersonating()` (session-backed), `Modules/Core/config/permission.php` (`permission.users.guest`), `Modules/AI/app/Services/Assistance/AssistantAccessContextFactory.php` (already refuses the in-app assistant to guests — memory applies the same exclusion one layer lower).
- Model standard: the Laraplate model conventions (validations in-model, casts/attributes, Core concerns).
