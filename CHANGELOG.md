# Changelog

All notable changes to this project will be documented in this file.

## [2.0.0] - 2026-11-25

### 🚀 Features

- *(console)* Models:declare-columns writes each model's column tags
- *(modules)* [**breaking**] Bump Core v2.0.0, CMS v2.0.0, Shop v0.1.1

### 🐛 Bug Fixes

- *(console)* Decimal casts are numeric strings, and a model without columns stays untouched

### 💼 Other

- Update subproject commits for AI, CMS, Core, and Shop modules
- Update dependencies in composer.lock and package-lock.json; bump versions for aws-sdk-php, guzzlehttp/promises, guzzlehttp/psr7, open-telemetry/api, openspout, and source-map-js. Update subproject commits for Core and ERP modules.
- Update subproject commits for AI, CMS, Core, CRM, ERP, MES, SAO, and Shop modules; mark initial steps completed in the PHPStan typed model columns plan.

### 📚 Documentation

- *(cache)* Spec and plan for cache membership labels
- *(plans)* Tick cache labels tasks 1-3
- *(plans)* Tick cache labels tasks 4-5
- *(plans)* Tick cache labels task 6
- *(plans)* Tick cache labels task 7
- *(plans)* Tick cache labels task 8
- *(plans)* Tick cache labels task 9
- *(plans)* Tick cache labels task 10
- *(plans)* Tick cache labels task 11
- *(plans)* Tick cache labels task 12
- *(plans)* Close the cache membership labels plan
- *(specs)* English fallback for the user documentation, spec and plan
- *(plans)* Close the documentation locale fallback plan
- *(plans)* Extended entity search, task 7 step 5 done in a follow-up
- *(plans)* Close the CrudService bulk paging note, the skip is not reachable
- *(plans)* Extended entity search, note on the Typesense date fix
- *(sao)* Close the ingest correlation and census plan
- *(plans)* Versioning slice 5, task C1 done
- *(plans)* Versioning slice 5, task C2 done
- *(plans)* Slice 5 Part C documentation, spec and review status lines
- *(rules)* Make strong typing and coverage a gate, with what it costs to get wrong
- *(rules)* Keep the type coverage gate, drop the 100% line coverage rule
- *(plan)* The column command lives in Core; Tasks 2 and 3 ticked
- Closure table hierarchies design and plan; Db2 design points to it
- *(plans)* Baseline numbers of the closure table hierarchies plan
- *(plans)* Tick task 2 of the closure table plan
- *(plans)* Note the deferred server run of task 2
- *(plans)* Tick task 3 of the closure table plan
- *(plans)* Tick task 4 of the closure table plan
- *(plans)* Tick task 5 of the closure table plan
- *(plans)* Tick task 6 of the closure table plan
- *(plans)* Tick task 7 of the closure table plan
- *(plans)* After-benchmark numbers of the closure table plan
- *(plans)* Keep the benchmark step open until the server run
- *(plans)* A/B benchmark with Xdebug off
- *(plans)* A/B with category paths through the search indexer
- *(plans)* A/B on SQLite and MySQL after the MySQL round
- *(plans)* A/B without hierarchy paths
- *(plans)* Close the closure-table hierarchies plan
- *(plans)* Record deleted tests and the upgrade path; drop a stale phpstan baseline entry
- *(plans)* The closure-table pull requests are open

### 🧪 Testing

- Keep the suite off the database server and Redis of .env
- Say which process the forced hosts bind
- *(suite)* Run the composer test commands in parallel
- *(suite)* Reuse config and routes between tests
- *(models)* Every model with translations declares the contract
- *(contracts)* Versioned and searchable models implement their contracts

### ⚙️ Miscellaneous Tasks

- *(deps)* Update dependencies to latest versions
- *(modules)* Update submodule commits for AI, CMS, CRM, Core, ERP, MES, and SAO
- *(ide)* Drop removed cache repository methods
- *(composer)* Update test commands to disable XDEBUG_MODE for improved performance
- *(composer)* Update command paths in composer.json for consistency
- *(phpstan)* Stop checking column names against model properties
- *(phpstan)* Name the trigger DDL and the trait guards
- *(phpstan)* Name the Scout and CRUD raw SQL rules, record Task 6 progress
- Lock without the staudenmeir packages
- Keep the ext-oci8 platform override in the lock
- *(phpstan)* Drop baseline entries of the removed TreeCollection paths
- *(modules)* Point Core and CMS at the closure-table branches

## [1.15.0] - 2026-11-18

### 🚀 Features

- *(scripts)* Host plan-status here and discover repositories instead of declaring them
- *(scripts)* Add composer check:debt to count what the baseline holds
- *(sao)* Affected version & version-maturity census — bump SAO + close plan
- Adaptive driver-agnostic bulk indexing for scout:import
- Sidebar opens only the active page's group
- AI model selection per feature and setting actions
- Horizon supervisor for embedding switch index chunks; plan and spec for chunked index writes
- *(stock-reservation)* Deliver ERP/MES stock reservation plan + bump submodule pointers
- *(stock-reservation)* Amendment supersedes the source order (Task 9)
- *(ai)* Carry out the owner's decisions on the AI module review
- One authentication and one switch for every api route
- *(plan-status)* Leave reference specs out of the "no plan" list
- Public CMS categories on the api by default (bump CMS, spec amendment)
- Bump Core for the scope filter guard (scope filters task 1)
- Bump Core for the filter scope declarations (scope filters task 6)
- Bump Core for scope filter storage and ACL validation (scope filters task 2)
- Bump Core and CMS for scope filter application (scope filters task 3)
- Bump Core for client scope filter requests (scope filters task 4)
- Backoffice content language in the admin panel; bump Core and CMS
- Add CRM module as a submodule and update related documentation
- *(versioning)* Slice 1 of the Core rebuild, library absorbed
- *(admin)* Laraplate brand icon as the Filament logo and favicon
- *(crm,erp)* Contact role, person names, intercompany (D27 to D29), plan closed
- *(crm)* Lead conversion (D30), section 11 reviewed
- *(versioning)* Slice 2 of the Core rebuild, reconstruction and anchors
- *(versioning)* Slice 3 of the Core rebuild, the version-set manager
- *(versioning)* Slice 4 Part A, the save scope and the translation history
- *(plan-status)* Show when each plan and spec last changed
- *(plans)* A finished plan declares itself in front matter and delivery status together
- *(plan-status)* Report the modules of every plan
- *(plan-status)* Add --git-dates for when a plan started and finished
- *(ai)* Implement documentation permission patterns with short-form requirements
- *(tests)* Add performance timings for AI module tests
- *(modules)* Bump Core v1.80.0, AI v3.2.0, CMS v1.48.0, ERP v2.1.1, SAO v1.6.1, Shop v0.1.0

### 🐛 Bug Fixes

- Bump AI to avoid double-index on the bulk embedding path
- Bump AI for documentation stub embedDocumentsBatch
- Bump AI for RepairEmbeddingsTest embedDocumentsBatch mock
- Bump SAO for TicketEmbeddableTest embedDocumentsBatch mock
- *(scripts)* Plan-status ends a task at any top-level section
- *(scripts)* Plan-status flags a task as probably done only if it creates files
- *(scripts)* Plan-status reports only real code markers
- Send an invalidated admin session back to the panel login
- Horizon supervisor for the embedding model switch queue; spec records the fix wave and two open points
- *(stock-reservation)* Amend releases the source hold on amendment confirm (Task 8)
- *(stock-reservation)* Amended-status integrity (Task 10)
- *(stock-reservation)* MES reservation hardening (Task 11)
- API access control final review fixes (bump Core and CMS, plan and spec)
- API access control follow-up fixes (bump Core, CMS, ERP, plan and spec)
- Parts hydrate with their own select or their owner's (bump Core, CMS, ERP, plan and spec)
- Parts hydrate with the select of any model along their chain (bump Core, CMS, Shop, plan and spec)
- CMS reference re-index as a system read (bump Core, CMS)
- Bump Core and CMS for the scope filters task 2 review fixes
- Bump Core for the scope filters task 3 review fixes
- Bump Core and AI for the scope filters task 4 review fixes
- Bump Core and CMS for the scope filters task 5 review (facets ACL leak)
- Bump Core for the scope filters final review, record its follow-ups
- Named rate limiters stay off Redis under the test runner; bump MES (generic CRUD writes refused on machine messages)
- App.default_locale for the translation fallback; bump Core, CMS, AI and SAO
- Update favicon URL in .env.example to correct path
- *(admin)* Blueprint icon set for the favicon, update Core
- *(plan-status)* Harden git dates and module lists
- *(plan-status)* Ignore module paths that are not plain directory names
- *(tests)* Stop BypassFinals from leaking memory on every boot
- *(http)* Use PreventRequestForgery everywhere the CSRF middleware is named

### 💼 Other

- Add media AI analysis and search design spec

Design for making media (image/audio/video/document) searchable: deterministic
embedded-metadata extraction in Core plus async AI enrichment through the
existing ModelRequiresIndexing pre-processing pipeline. Media indexed standalone
and as single-owner enrichment (analysis on claim, cascade via media->model).
Core display metadata rides Spatie custom_properties (no new media columns); AI
owns an extension table keyed by content_hash to dedup analysis and embedding
compute across duplicated files, contributing to the search document via a Core
seam so Core stays AI-agnostic. No sentiment. Phase 2 (additive) adds a
timestamped chunk store and keyframe vision; a true shared-asset DAM is left as
a non-precluded future.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
- Record open questions in media AI analysis spec

Split section 13 into open design decisions (ACL/security, locale/multilingual,
media lifecycle beyond claim, contributor-seam choice, phase-1 media-type scope,
DAM) and implementation details to confirm. Update the status line to point at
them. Nothing resolved yet; this makes the undecided surface explicit before
planning.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
- Update subproject commits for AI, ERP, and MES modules
- Decide ACL, locale and master switch in media analysis spec

- M16: unified result set; media inherits owner ACL, re-authorized at
  rehydration via a Core registry keyed by owner morph type; switchable through
  the core.media.search_visibility setting (owner default vs open agnostic
  gallery).
- M17: raw tracks (transcript/OCR) stay in the source language; the surrogate is
  translated to supported locales via the existing pipeline; media belongs to
  the base article, not to translations (accepted for now, to evolve).
- M18: a runtime Settings master switch enables/disables the whole media LLM
  subsystem; off leaves the deterministic layer + fallback indexing only.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
- Decide media lifecycle after claim (M19)

Owner fixed at claim (no owner-move). Metadata-only edit re-embeds and reindexes
media + owner without LLM; a file replacement (hash change) re-analyzes; soft
-delete removes from index and reindexes owner (analysis kept); force-delete
removes from index, drops embeddings and reindexes owner. The hash-keyed shared
analysis is refcounted and deleted only when the last media for that hash is
force-deleted. Re-analysis fires only on hash change.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
- Decide media search contributor seam (M4a)

A single generic Core "searchable contributor" seam that any Core searchable
model exposes; AI registers as a contributor for Media. No duplication and no
reuse of CMS's content-specific registry. The content extension seam's search
-contribution should build on this same Core seam; content ownership machinery
stays in CMS. Cross-spec coordination flagged.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
- Decide phase-1 media-type scope and frame phase-2 visual track (M20)

Phase 1 handles image (caption+OCR), audio (transcription), document/PDF
(text+OCR) and video (audio transcription only); every other type is
deterministic-only and still searchable by name/type, with expansion left as an
additive future. Reframe section 11 as the phase-2 visual "what is seen" track
(keyframe vision for video, optional deeper image visual), the deep tier behind
the master switch.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
- Mark media spec design decisions closed

All section 13a design decisions are settled (M1-M20); only a true DAM stays a
non-precluded future and section 13b implementation details remain for the plan.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
- Add config-driven analysis model registry (M21)

Analysis models are a registry per capability (vision, transcription) with an
active default operators can switch/extend from config, mirroring the embeddings
registry; defaults claude-sonnet-5 (vision) and self-hosted Whisper
(transcription), reusing neuron-ai's ProviderFactory. Folds the transcription
vendor choice into the registry. Update section 13b residuals: neuron-ai
multimodal confirmation, the Whisper service, and PHP library approvals.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
- Approve PHP metadata/PDF dependencies for media analysis

getid3 (audio/video tags) and smalot/pdfparser (PDF text) approved as new
composer deps; images use native exif/iptcparse. OCR-for-scans approach still to
confirm during implementation.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
- Add media AI analysis & search implementation plan (Phase 1)

14-task TDD plan derived from the media spec (M1-M21): config + model registry,
content_hash + deterministic extraction, AI-owned ai_media_analysis table,
generic Core searchable-contributor seam, Media Searchable/IEmbeddable, event +
listener + fallback, AnalyzeMediaJob, transcription backend, embedding dedup,
owner-inherited ACL registry, single-owner enrichment cascade, lifecycle,
Filament surface, docs. Phase 2 and DAM out of scope.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
- Refactor code structure for improved readability and maintainability
- Media analysis Task 1: config, model registry, master switch

Bump Modules/AI to the Task 1 commit and tick Task 1 in the plan.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
- Media analysis Task 2: deterministic metadata extraction

Bump Modules/Core to the Task 2 commit, lock getid3 + smalot/pdfparser, and tick
Task 2 in the plan.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
- Media analysis Task 3: AI-owned analysis table

Bump Modules/AI to the Task 3 commit and tick Task 3 in the plan.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
- Media analysis Task 4: generic searchable-contributor seam

Bump Modules/Core to the Task 4 commit and tick Task 4 in the plan.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
- Media analysis Task 5: Media searchable + embeddable

Bump Modules/Core to the Task 5 commit and tick Task 5 in the plan.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
- Media analysis Task 6: pipeline wiring + fallback

Bump Modules/AI to the Task 6 commit and tick Task 6 in the plan.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
- Media analysis Task 7: analysis job + search contribution

Bump Modules/AI to the Task 7 commit and tick Task 7 in the plan.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
- Media analysis Task 8: Whisper transcription backend

Bump Modules/AI to the Task 8 commit and tick Task 8 in the plan.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
- Media analysis Task 8: document Whisper service (not vendored)

Bump Modules/AI to the Whisper docs commit and update Task 8 in the plan.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
- Media analysis: Whisper on port 8001, docs point at canonical repo

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
- Check domain action permission coverage at application level

Moved from Core: the check spans the domain actions of every installed
module, SAO ticket alias included.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_016bQ9UWLJjnRQA6sGEYNGPL
- Update subproject commit references for AI, CMS, and Core modules
- Fix echo statement and streamline update process in update_app.sh
- Update subproject commits for AI, CMS, and Core modules
- Update package-lock.json to version 4.3.4 for @tailwindcss/node and @tailwindcss/oxide modules, along with their dependencies. Also, update subproject commits for AI, CMS, and Core modules.
- Update subproject commits for AI, Core, MES, SAO, and Shop modules.
- Update subproject commits for AI, CMS, CRM, and ERP modules.

### 📚 Documentation

- *(plans)* Close the release tooling plan
- *(superpowers)* Adopt ES multilingual + multi-model embeddings spec/plan
- *(specs)* Settle MCP authorization, rate limiting and audit
- *(superpowers)* Spec + plan for content translation search_text column
- *(ai)* Spec persistent user memory (cross-session)
- *(ai)* Plan persistent user memory + spec accuracy fixes
- *(specs)* Record the MCP token lifecycle
- *(superpowers)* Spec + plan for search modes and strategy resolution
- *(superpowers)* Spec + plan for in-app assistant tools
- *(ai)* Memory design — relevance, subject supersession, conversation memory
- *(specs)* MCP ships read-only in phase 1
- Refresh the spec and plan indexes, correct the end-user retrieval path
- *(superpowers)* Index the unlisted plans/specs; close the ES index-enablement plan
- *(superpowers)* Correct the ES full-mapping 400 root cause (meta/index on object/nested)
- *(superpowers)* Plan the in-place Laravel 13 upgrade
- Relocate backend plans into laraplate and add ecommerce design specs
- *(ai)* Reconcile RAG retrieval-strategy plan and add query-analytics spec+plan
- Record ES translator-400 cutover blocker + fix in 2026-09-12 spec/plan
- *(search)* Record that IntelligentSearchAction is already broken
- *(plans)* Tick what ran today, and record what the reruns found
- Mark ES translator-400 cutover blocker resolved; bump Core submodule
- *(ai)* Record the developer documentation evaluation path
- *(ai)* Record the developer RAG baseline finding (hybrid justified)
- *(ai)* Record the e5 cutover result on the RAG retrieval plan
- *(ai)* Resolve the query-prefix gap; hybrid deferred
- *(ai)* Record the min-similarity abstention on the RAG plan
- Refine content-extension seam (lifecycle, search, registry)
- Content-extender registry is dedicated, not the global morph map
- Clarify ecommerce Product vs ERP Item and composition phasing
- Ecommerce variant-item pivot from v1, mandatory content, symmetric lifecycle
- Record ecommerce open questions (SAO support boundary et al.)
- Product comments come free from CMS content comments
- Reviews reuse shipped CMS comment+rating, drop standalone entity
- Review media via HasMultimedia on CMS Comment (E10a)
- Move comment media-attachments decision to the CMS comments spec
- *(ai)* Record the e5 cutover progress (service switched, multi-model)
- Transparent content merge on Product; close single-extender and visibility
- Cite the real Contributor-User merge pattern in E2a
- E2a spells out the magic getAttribute/setAttribute merge
- No ERP-owned or ERP-derived data in the search index
- Add CRUD facet counters implementation plan and update index
- *(plans)* Close the module testing strategy
- *(plans)* Tick the last two commit steps
- Shop customer = Core user + ERP customer; browsing reuses presets + facets
- Entity is a Core abstraction; product attribute sets are presets per category
- E21 browse-only price/stock snapshot (bounded exception to no-ERP-in-index)
- *(rag)* Reconcile developer hybrid finding — not justified on e5
- Record the build-native-not-Magento decision (section 1a)
- *(sao)* Plan+spec for affected version & version-maturity census
- Spec review — fix registry contradiction, decision order, add open questions
- Close the three seam-blocking questions (C16-C18)
- Implementation plan for the CMS content-extension seam
- *(plan)* Tick seam Task 1 (extended_type column) done
- *(search)* Spec adaptive driver-agnostic bulk indexing
- *(plan)* Tick seam Task 2 (registry + contract) done
- *(plan)* Tick seam Task 4 (default-hide scope + withExtended) done
- *(plan)* Tick seam Task 3 (extender trait + stub) done; Task 4 back-relation verified
- *(plan)* Tick seam Task 5 (batched upcast) done
- *(plan)* Tick seam Task 6 (both-direction lifecycle + guard) done
- *(plan)* Tick seam T7 (search data) + T8 (mapping); point 4 (search filter) open
- *(plan)* Close seam plan — T9 done, delivery status with point-4 deferral
- Mark adaptive bulk indexing spec implemented
- *(plan)* Point 4 (generic-search filter) built; delivery status updated
- Record bulk-indexing N+1 work and the open accessor residual
- Spec the Core approvals subsystem covering deletes and restores
- Plan the Core approvals subsystem
- Approvals spec settles Hide on the decide permission and Content drafts
- Settle six open points in the Core approvals plan and spec
- Record the trait checks Task 2 must move, and the wip tests now closed
- The approvals plan records the measured test baseline
- Record the rule to run Pint from the root on explicit files
- Spec and plan for deferred import search indexing
- Approvals cover deletes and restores
- *(ecommerce)* Lock attribute, tenancy and active-vendor decisions
- *(ai)* Spec and plan for per-feature model selection and setting actions
- Media analysis Tasks 9-12 documented, bump Core and AI
- Media analysis Task 13 design (M22 Filament surface) in spec and plan
- Tick media analysis Task 13a (Core schema seam)
- Tick media analysis Task 13c (read-only media gallery)
- Tick media analysis Task 13d (AI analysis panel + re-analyze action)
- Tick media analysis Task 13b (owner media curation relation manager)
- Close media analysis plan (Task 14 delivery status + documented-in)
- *(ecommerce)* Decide tax display (E25) and record ERP prerequisites
- Correct the approvals plan on who reaches Modifications
- *(shop)* Rename Ecommerce->Shop across the design record; digital products in v1 (E26)
- *(shop)* Decide cart -> ERP order handoff (E27)
- *(shop)* Decide order timing vs payment (E28)
- *(plans)* Reconcile ERP hardening spec1 and phase2a with the code
- *(plans)* Reconcile ERP phase3-remaining release gates
- *(plans)* Reconcile nine plans with the code
- *(plans)* AI moderation votes as the system user
- *(plans)* MES decisions D9 and D11, and the BOM lock cancelled
- Record the rule to check plan status before resuming work
- Close the graph plan, Phase 5 measured and cancelled
- Close the portable search plan, its last step was a parked note
- Close the content provenance plan
- Close the model connection affinity plan
- Close the SAO phase 0, 1a and 1b plans
- Record the rule that Filament is the backoffice and user workflows belong to laraplate-ui
- Close the content extension seam and media analysis plans
- Reconcile six stale plans against the code
- Close SAO phase 3b, narrow the comments moderation plan to the API decision
- Close the comments moderation plan
- Close the two performance plans, with the FIFO and RAG reindex regressions fixed
- MES plan progress, only decisions left open
- Close the measured retrieval tuning L1 plan
- Retrieval tuning overfitting safeguards in spec, plan and explainer
- Refine project rules and remove deprecated Livewire skill
- Tuner noise margin and the profile's cited report
- Plan delivery status for private datasets, evaluation locale and report context
- Plan delivery status for the language restriction; update Core and AI references
- Plan note for the ACL reload of search hits; update the Core reference
- Plan note for the reranker report; update the AI and Core references
- Follow the cross-encoder url rename in the model selection spec
- The cross-encoder has no default address; reword the Whisper port note in the model selection spec
- Add adaptive assistance API contract spec and plan
- Plan note for the empty reranker text and its first real measure; update the Core and CMS references
- Plan notes for the first tuning run (no winner) and reranker.model; update the Core and AI references
- Spec for changing the embedding model from Settings
- Implementation plan for the embedding model switch; spec follows provider:model and deletes the previous model's vectors at activation
- Embedding model switch implemented for Tasks 1-10 and 12; record the divergences and open points
- Embedding model switch Task 13 delivered; R17 confirmed, features.embeddings.active removed
- Index the stack-root spec for MES machine data acquisition
- Embedding model switch delivered; update the AI and Core references
- Embedding switch review fixes and RAG chunks; plan, spec, Horizon comments
- Point the MES plan's open decisions at the machine data acquisition spec
- *(ai)* Automatic conversation title in the assistance contract
- Close Task 2 of the adaptive assistance contract
- Close Task 3 of the adaptive assistance contract
- Close Task 4 of the adaptive assistance contract
- *(erp)* Design native stock reservation / ATP (R1-R8)
- *(shop)* Record E29 (availability/reservation) referencing the ERP reservation design
- Close Task 5 of the adaptive assistance contract
- *(erp)* Add stock reservation / ATP implementation plan
- *(shop)* Decide PSP driver contract (E30)
- Close Task 6 of the adaptive assistance contract
- *(shop)* Decide v1 currency and variant_items.role (E31)
- *(shop)* Add catalog foundation implementation plan (plan #1)
- Close the MES implementation plan
- Close the adaptive assistance contract plan
- Plan for the review of the AI module (Neuron usage, duplication, dead code)
- *(erp,shop)* Reservation at confirm is best-effort, not blocking (make-to-order)
- *(shop)* Record make-to-order item typing as an ERP-3 refinement over best-effort reservation
- Task 1 of the AI module review done
- Task 2 of the AI module review done
- Task 3 of the AI module review done
- Task 4 of the AI module review done
- Task 5 of the AI module review done
- Close the MES machine data foundation plan
- Task 6 of the AI module review done
- Plan for the MES machine data MQTT bridge (step 2)
- Task 7 of the AI module review done
- Governed writes for the in-app assistant (spec and plan), supersede the ActionRequest tools plan
- Task 8 of the AI module review done
- Governed writes plan, task 1 done
- Governed writes plan, task 2 done
- Governed writes plan, tasks 3 and 4 done
- Governed writes, confirmation outside the model (the assistant is stateless)
- Task 9 of the AI module review done
- Task 10 of the AI module review done; IntelligentSearchAction retired
- Governed writes plan, tasks 5 and 6 done
- Close the MES machine data MQTT bridge plan
- Governed writes plan, task 7 done
- Task 12 of the AI module review (suites, delivery status); Task 11 waits for the owner
- Governed writes plan, tasks 8 and 9 done
- Close the governed assistant writes plan and spec
- Plan for the MES machine data states and downtime step (step 3)
- Assistant API contract, writes and actions
- Close the MES machine states and downtime plan (step 3)
- Plan for the MES machine counts and OEE performance and quality step (step 4)
- Close the assistant governed writes plan
- AI module review, guardrails decided (delete the dead path)
- AI module review, memory kept and env switches moved to settings
- Close the AI module review plans; AI_SEARCH_ORCHESTRATION_ENABLED stays env
- Close the MES machine counts and OEE plan (step 4)
- Plan for the MES probe measurements step (step 5)
- Close the MES probe measurements plan (step 5)
- Spec for API access control (guards, default roles, tokens)
- API access control spec covers related records, media and references
- Implementation plan for API access control; spec approved
- Spec and plan for login methods (passkeys, social registration guard, per-method 2FA)
- Reconcile the RAG retrieval strategy plan with the code (Tasks 1 and 2 shipped, 3 to 6 annotated)
- Cancel the retrieval factory steps of the RAG retrieval strategy plan
- Defer hybrid, reranking and graph in the RAG retrieval strategy plan
- Close the RAG retrieval strategy plan (chunk metadata shipped, factory cancelled, hybrid, reranking and graph deferred)
- RAG query analytics privacy review signed off
- Search modes plan, Task 0 Step 1 done (per-stage search timings)
- Close the RAG query analytics plan; update AI subproject commit reference
- Plan for the MES process values step (step 6)
- Link ERP phase 3 external API tasks to the API access control plan
- Close the MES process values plan (step 6)
- API access control plan, tasks 1 and 2 done
- API access control plan, task 3 done
- API access control plan, task 4 done
- API access control plan, task 5 done
- SAO phase 8 input note on typed decision models
- *(search)* Measurements, balanced mode, Tasks 1 and 2 ticked
- *(plans)* Retroactive plan for adaptive bulk indexing, mark the spec implemented
- *(versioning)* Decision log for the revision-centric history and the design spec aligned with it
- *(versioning)* Design the hook for translation changes (D28)
- Bump Core and AI for the search modes documentation; tick Task 6 Step 2
- API access control plan, task 6 done
- API access control plan, task 7 done
- API access control plan, task 8 done
- API access control plan, task 9 done
- API access control
- *(plans)* AI module plan for CI evaluation gate, run observability, MCP server and provider hygiene
- *(plans)* Split MCP into its own Core plan built on the 2026-09-12 spec
- *(plans)* Record owner decisions on laravel/mcp and the MCP_ENABLED env exception
- *(plans)* MCP gate is a seeded Core setting like expose_api, not an env variable
- *(specs)* Right to erasure for contents and media
- *(specs)* Amend the MCP spec with the owner's decisions and the phase 2 catalogue
- *(specs)* Revise right to erasure, add content reports
- *(specs)* Direct exclude action on every surface, exclusions table as history
- *(specs)* Exclude governed by forceDelete, actions in Filament and the Vue UI
- *(specs)* Reports can request deletion through approvals, Hide and Block kept
- *(specs)* Erasure by purpose, force delete writes exclusions, sources cited
- *(specs)* Record the owner's decisions of 2026-10-13
- *(plans)* Right to erasure implementation plan
- *(plans)* Content reports implementation plan, open points decided
- *(specs)* Report reason labels in every available locale
- *(specs)* MCP refuses requests without a valid token with 401
- *(versioning)* Hard-delete coordinator, owner updated_at, registered relations (D29 to D31)
- Neuron AI 4 upgrade spec and plan
- *(versioning)* The schema epoch and the core_versions_* naming (D32, D33)
- *(search)* Close the search modes plan
- Verbatim AGPL-3.0 licence text, copyright in the README (bump modules)
- *(search)* The degraded_reason gap is closed; bump Core and AI
- Scope filters spec and plan
- *(versioning)* Conditions of the save wrapper and expiry of the hard-delete snapshots (D34, D35)
- *(versioning)* Revert context and the touch of updated_at by relation changes (D36, D37)
- *(crud)* Spec and plan for stored property filters
- *(crud)* Stored property filters task 2, locale rule without fallback
- *(crud)* Close the stored property filters plan
- Close the scope filters plan, bump Core and CMS docs
- Correct the scope filters delivery status, bump Core
- *(shop)* Spec and plan for the extender attribute overlay, task 1 done
- *(shop)* Close the extender attribute overlay plan; bump CMS and Core
- Scope filters plan, two follow-ups from the final review
- The Neuron 4 upgrade changes only the AI constraint in Composer
- Neuron 4 upgrade baseline
- MES backend for the edge agent, plan
- CRM module extraction spec and document template binding idea
- Neuron 4 upgrade, Tasks 1 and 2 done
- Neuron 4 upgrade delivery status; AI review fixes and static analysis; Laravel 13 plan drops its Neuron constraint
- Close the neuron 4 upgrade plan and spec
- *(versioning)* D38 versions:compact refuses, D39 revert on a locked record; bump Core
- *(versioning)* D40 relations in forms, D41 pivot state; no open points left
- *(versioning)* D42 to D51 answer the independent recheck; bump Core and CMS
- *(versioning)* D49 keeps the modifications, closing the pending ones; bump Core and CMS
- *(shop)* Design spec for the product admin write-surface (plan #2)
- CRM module spec approved (D1-D26), bump CRM and Shop
- CRM extraction implementation plan, fix D7 aggregate flags
- *(shop)* Implementation plan for the product admin write-surface (plan #2)
- *(versioning)* Supersede milestone-1 plan by D1-D51, confirm D20
- Update the revision-centric aggregate history review
- *(versioning)* Approve the design with D52-D59 and plan the rebuild
- *(shop)* No cross-module references, the call requests the relations it needs
- *(versioning)* D60, reconcile the rebuild plan with D52-D59
- *(specs)* Add the client side of the self-hosted AI services readiness
- State the dual licensing and the AGPL commitment in the README
- *(plans)* Add the client phase 1 plan of the self-hosted AI services
- *(readme)* Translate the environment section to English and fix typos
- *(plans)* Close ERP hardening phase 3 plan, drop 3-03 as redundant
- *(versioning)* Plan Slice 2 of the Core rebuild, add D61
- *(specs)* Aggregate route and widget tool, backend half of the widget contract
- *(plans)* Aggregate route and widget tool plan, spec corrections for relation groups
- Point the links to the getlaraplate organization
- Fix a typo in the work in progress notice
- Use the Laraplate brand logo in the README
- *(core)* Spec and plan for the apps in the user data, update Core
- CRM extraction delivered, bump CRM and ERP
- *(shop)* Close the product admin write-surface plan
- CRM minors fixed, bump CRM and ERP
- CRUD sort by relation and mounted attributes, CRM migration fix, bump Core CRM ERP
- Sales pipeline report moved to the CRM, bump CRM and ERP
- *(crm)* Close D14, quotation stays in the ERP and the CRM Quote is a future step
- *(specs)* Link the sibling specs on master
- *(specs)* Use the getlaraplate/lara* package names in the scaffolding spec
- Close the client phase 1 plan
- *(specs)* Record the maintainer identity defect of the module generator
- *(specs)* Prefill the author prompts from git config in the scaffolding spec
- *(specs)* Drop the environment variables of the module generator
- *(specs)* Start the module changelog with an Initial scaffold entry
- *(specs)* Default the module licence to the application licence
- *(specs)* Extend the module colour palette with five named colours
- Record the review minors of the client phase 1 plan
- *(plans)* Add the module scaffolding generator plan
- Close the palette documentation search plan, one measurement pending
- Plan delivery status corrections for the palette documentation search
- Palette documentation search follow-ups (POST, per-user embedding cache)
- Palette documentation search spec states GET and POST
- Core version actor resolution spec and blocked plan
- *(specs)* AI module on standard servers, replacing the two custom services
- *(plans)* AI module on standard servers implementation plan
- *(versioning)* Plan Slice 3 of the Core rebuild, the version-set manager
- *(specs)* Core command to align the .env with .env.example
- *(specs)* Env:fill covers only the Laraplate-specific variables
- *(specs)* Env:fill leaves out variables the AI plan removes
- *(plans)* Core env:fill command implementation plan
- *(shop)* Reopen VAT, the rate to apply to a sale is still to be decided
- *(shop)* Auction design spec
- *(shop)* Auction slice 1 plan, spec corrections from planning
- *(versioning)* Plan Slice 4 of the Core rebuild, the capture wrappers
- Close the AI standard servers plan
- Close the env:fill plan
- Plan for plan-status git dates and plan modules
- *(rules)* Keep closed superpowers plans instead of deleting them
- *(plans)* Reconcile every plan and spec with the code
- Align the plan-status plan with updated and the bash test harness
- Point the plan-status plan at the website repository
- Tick Task 1 of the plan-status plan
- Tick Task 2 of the plan-status plan
- Plan-status modules and git dates delivered
- Spec for moving runtime tunables from env to settings
- Drop the migration note for removed env variables from the settings spec
- Plan for moving runtime tunables from env to settings
- Tick Tasks 1 to 4 and record the off-meaning rulings in the settings spec and plan
- Close the settings over env plan, drop the moved names from .env.example
- Record the decision to keep ERP_STOCK_RESERVATION_SOFT_TTL
- Record the Infinity verification and the minors fixed afterwards
- *(versioning)* Close Slice 4 Part B
- *(versioning)* Close Part B of Slice 4
- Record the verification of TEI
- Record the TEI reranker and the nginx setup in the verification
- *(sao)* Spec ingest correlation rules, reprocessing, recurrence and deploy containment
- *(sao)* Implementation plan for ingest correlation and census; spec corrections from planning
- *(sao)* Tick plan task 1
- *(sao)* Tick plan task 2
- *(sao)* Tick plan task 3
- *(sao)* Tick plan task 4
- *(sao)* Tick plan task 5
- *(sao)* Tick plan task 6
- *(sao)* Tick plan task 7
- *(sao)* Tick plan task 8
- *(sao)* Tick plan task 9
- *(sao)* Tick plan task 10
- *(sao)* Tick plan task 11
- *(sao)* Tick plan task 12
- *(sao)* Tick plan task 13
- *(sao)* Tick plan task 14
- *(sao)* Tick plan task 15
- *(versioning)* Close Slice 4 with Part C, the hard delete
- *(plans)* Reconcile the Laravel 13 upgrade plan with the current repository
- Update plans index to include Pest 5 toolchain upgrade and clarify Laravel 13 upgrade details
- *(swagger)* Drop the / redirect from the App document
- Follow the test: prefix of benchmark commands
- *(specs)* Vector search owned by the AI module
- Name the serving model setting features.embeddings.serving_model
- *(specs)* CMS imports, one record across several sources
- *(plans)* CMS imports, one tag across several sources
- *(plans)* Core versioning Slice 5, epochs and baselines
- *(plans)* Tick Task 1 of vector search owned by AI
- *(plans)* Tick Task 2 of vector search owned by AI
- *(plans)* Tick Task 3 of vector search owned by AI
- *(plans)* Tick Task 4 of vector search owned by AI
- *(plans)* Tick Task 5 of vector search owned by AI
- *(plans)* Word the owner-gated step as covered by migrate:fresh
- *(plans)* Add the extension-point task and the measured suite constraint
- *(plans)* Close the cross-source tag identity plan
- Spec and plan for AI retrieval improvements
- *(plans)* Tick Task 1 of AI retrieval improvements
- *(plans)* Tick Task 2 of AI retrieval improvements
- *(plans)* Task 2 comparison step left to the owner
- *(plans)* Tick Task 3 of AI retrieval improvements
- *(plans)* Tick Task 4 of AI retrieval improvements
- *(plans)* Tick Task 5 of AI retrieval improvements
- *(plans)* Task 5 comparison step left to the owner
- *(plans)* Tick Task 6 of AI retrieval improvements
- *(plans)* Tick Task 7 of AI retrieval improvements
- *(plans)* Task 7 comparison left to the owner, repair note corrected
- *(plans)* Tick Task 8 of AI retrieval improvements
- *(plans)* Tick Task 9 of AI retrieval improvements
- *(plans)* Tick Task 10 of AI retrieval improvements
- *(plans)* Slice 5 Part A delivered
- *(plans)* Slice 5 Part A review outcome
- *(plans)* Slice 5 Part A, atomic baselines agreed after review
- *(plans)* Slice 5 Part B tasks done, divergences recorded
- *(plans)* Close Part B of versioning slice 5
- *(plans)* Documentation reader route for the reference reader
- *(plans)* Close the aggregate route and widget tool plan
- *(plans)* Documentation reader route Task 1 settled
- *(plans)* Documentation reader route Task 2 done
- *(plans)* Documentation reader route Task 3 done
- *(plans)* Documentation reader route Task 4 done
- *(plans)* Documentation reader route Task 5 done
- *(plans)* Documentation reader route Task 6 done
- *(plans)* Documentation reader route delivered
- *(plans)* Branch lazily, one repository at a time
- Add search and retrieval architecture overview
- *(plans)* Work on master, and tick Tasks 1 to 4
- Record Laravel 13 in the stack line, badges and requirements
- *(plans)* Add the test impact analysis task to the Pest 5 plan
- The structure is Laravel 13, not Laravel 10
- *(skills)* Regenerate the Boost skills for Laravel 13
- *(plans)* Tick the tasks the Laravel 13 upgrade already delivered
- Record the Laravel 13 upgrade
- *(plans)* Tick the closing steps of the Laravel 13 upgrade
- *(plans)* Record Task 1 of the Pest 5 upgrade
- *(plans)* Close the documentation permission patterns plan
- *(specs)* Search and list of extended entities
- *(specs)* Host and extender contracts for extended entity search
- *(specs)* Extract the generic parts of Content
- *(specs)* Extension field conflicts and extender column names
- *(specs)* Record why same-type names are shared only in extension
- *(specs)* Approval needs no condition in the guest product ACL
- *(plans)* Extended entity search and list
- *(plans)* Prerequisites of extended entity search are merged
- *(plans)* Extended entity search, task 1 done
- *(plans)* Extended entity search, task 2 done
- *(plans)* Extended entity search, task 3 done
- *(plans)* Extended entity search, task 4 done
- *(plans)* Extended entity search, task 5 done
- *(plans)* Extended entity search, task 6 done
- *(plans)* Extended entity search, task 7 steps 1 to 4 done
- *(plans)* Extended entity search, task 8 done
- *(plans)* Extended entity search, task 9 done
- *(plans)* Extended entity search, task 9 follow-up
- *(plans)* Extended entity search, task 10 done
- *(plans)* Extended entity search, task 11 done
- *(plans)* Close the extended entity search plan
- *(rules)* Point the versioning rule at the stack release law

### ⚡ Performance

- Bump Core and AI for bulk indexing eager-loads
- Bump Core to honor toSearchableWith on bulk indexing
- Bump CMS for category-ancestor eager-load in the search document

### 🎨 Styling

- *(admin)* Use the brand green as the panel primary colour

### 🧪 Testing

- Stop the suite from calling real embedding services, and hide Pest from PHPStan
- Don't treat a fenced 'Delivery status' example as a closed plan
- Run the SAO application content baseline gate at application level
- Run the CMS application content baseline gate at application level
- Check the AI system user may vote on CMS comments at application level
- Guard the test bootstrap against cached config and remote databases
- Fail on foreign key actions that ForeignKeyDefinition does not declare
- No CSRF check in tests; close Task 1 of the adaptive assistance contract
- Order navigation groups by module, in each module's own process order
- The suite never reaches the Elasticsearch of .env

### ⚙️ Miscellaneous Tasks

- *(modules)* Record the module release documentation
- *(modules)* Bump AI for Sentence Transformers install docs
- *(modules)* Bump Core and AI
- *(migrations)* Bump Core/ERP/MES/SAO submodules to consolidated migrations
- Rimuove docblock ide-helper generati dai model
- *(migrations)* Bump submodules to FK/scoping index pass
- Rimuove ignoreErrors @mixin IdeHelper ora inutile
- Update submodule and composer.lock for dependency versions
- *(ai)* Bump AI submodule for configurable embeddings timeout/batch
- *(composer)* Increase memory limits for PHPStan and Pest commands
- *(tooling)* Stop generating the mixins, stop hiding the seams, narrow PHPStan
- *(modules)* Bump Core, CMS, SAO and AI for the soft-delete and search contracts
- *(tooling)* Decide which rules may change code, and write down why
- *(phpstan)* Freeze the debt in a baseline so new errors fail the run
- *(phpstan)* Regenerate the baseline after narrowing findOrFail
- *(phpstan)* Regenerate the baseline after narrowing the widened types
- *(core)* Bump for the ISoftDeletableModel regression fix
- *(modules)* Bump Core and AI for embedding skip-if-fresh
- *(modules)* Bump Core for search index mapping structure check + sync
- *(modules)* Bump Core for richer scout:check-index diagnostics
- *(modules)* Bump Core for geo_point lat_lon fix
- *(ai)* Bump AI submodule for the prefix-agnostic test stub fix
- *(phpstan)* Shrink the frozen debt from 9.739 to 8.517
- *(phpstan)* Shrink the frozen debt from 8.517 to 8.150
- *(phpstan)* Shrink the frozen debt from 8.150 to 7.993
- *(phpstan)* Name the Builder scopes instead of keeping 1.272 baseline entries
- *(phpstan)* Add expiring to the named Builder scopes
- *(phpstan)* Shrink the Builder scope rule from fourteen names to four
- *(core)* Bump submodule for the expiredAt fix
- *(phpstan)* Shrink the frozen debt from 6.643 to 6.043
- *(phpstan)* Shrink the frozen debt from 6.043 to 5.419
- *(phpstan)* Shrink the frozen debt from 5.419 to 4.297
- *(phpstan)* Shrink the frozen debt from 4.297 to 4.251
- *(phpstan)* Shrink the frozen debt from 4.251 to 4.159
- *(phpstan)* Shrink the frozen debt from 4.159 to 4.075
- *(phpstan)* Shrink the frozen debt from 4.075 to 4.035, and record the method
- *(modules)* Bump Core for Elasticsearch version assertion
- Make embeddings queue rate limit configurable (env + Core bump)
- *(modules)* Bump Core and AI for adaptive embedding batching
- *(ai)* Bump AI for ModelEmbeddingSynchronizer extraction
- *(ai)* Bump AI for cross-model embedding batching
- Update subproject commits for CMS and Shop modules
- Drop env vars moved to runtime settings
- Bump Core, CMS, AI and MES for dashboard widgets and settings cleanup
- Bump modules for module-prefixed setting keys and AI moderation switches
- Bump modules for module-less setting names
- Core navigation group after Documentation; bump modules for domain-first setting names
- Bump Core for third-party code attribution
- *(dependencies)* Update file permissions for chart.js components to executable
- Bump CMS and ERP for the preset module scope fix
- *(dependencies)* Update license-compliance-checker reference and timestamp
- Bump Core, CMS and ERP
- Bump Core (settings approval work in progress)
- Bump Core for the approvals attribution and module-owned is_internal
- Bump Core for Task 1 of the approvals plan, and record what it absorbed
- Bump Core and CMS for Task 2, and catalogue the pre-existing failures
- Bump Core for Task 3, the settings approval wip is closed
- Bump Core, CMS, AI and SAO for Task 4 of the approvals plan
- Align Shop submodule config and bump CMS, MES and Shop
- Bump Core and CMS for Task 5 of the approvals plan
- Bump Core and CMS for Task 6 of the approvals plan
- Bump Core for Task 7 of the approvals plan
- Bump modules for table toolbar buttons and the Close form button
- Bump Core and CMS for Task 8 of the approvals plan
- Bump Core, AI, CMS and ERP for deferred import search indexing
- Bump Core and AI for Tasks 10 and 11 of the approvals plan
- Bump Core and CMS for Task 12 of the approvals plan
- Drop stephenlake/laravel-approval, now part of Core
- Bump Core for Task 13 of the approvals plan
- Bump Core and CMS for Task 9 of the approvals plan
- Bump Core for the API vote fix
- Update subproject commits for Core and Shop modules
- Media analysis Task 9 (embedding vector reuse), bump Core and AI
- Media analysis Task 10 (owner-inherited media visibility), bump Core, CMS, SAO
- Media analysis Task 11 (owner enrichment and reindex cascade), bump Core and AI
- Media analysis Task 12 (media lifecycle), bump Core and AI
- Update subproject commits for AI, CMS, Core, ERP, MES, SAO, and Shop modules; update README with badges
- Queued import search flushes, per-run preset provisioning, Editor.js search text; bump Core and CMS
- Opt-in emulated pgsql prepares, presettable reuse in imports; bump Core and CMS
- Bump Core for the setting value validation
- Bump Core for the pending-approvals list
- Update subproject commits for AI, CMS, ERP, MES, and SAO modules
- Bump SAO, Core and MES for the documentation fixes
- Tiered module priorities, 10 per dependency level
- Drop the phpstan exclusion of the deleted SentenceTransformers generator
- Update AI subproject commit reference
- Update Core and AI subproject commit references
- Lock AWS SDK and Google auth required by the AI module
- Update Core, AI, CMS, SAO, ERP and MES subproject commit references
- Update the AI reference (cross-encoder client posts to {url}/score)
- Update the AI reference (the cross-encoder service has a default model)
- Update the Core and AI references (reranker off by default)
- Update the AI reference (cross-encoder connect timeout)
- Bump Modules/AI to the chunked embedding switch index writes
- Bump Modules/AI to the embedding switch review fixes and RAG chunks
- Bump Core, CMS, MES, SAO and ERP to the translated interface labels
- Bump Core (benchmark output to the console only)
- Bump Core and CMS (facet only_full_group_by fix, publisher ACLs)
- Bump Core and CMS (update validation excludes the record from its own unique checks)
- Clean up middleware removal list in app bootstrap
- Bump Core (two test fixes)
- Require php-mqtt/client for the MES machine bridge
- Update the AI and Core submodules (governed assistant writes, detail tool fix)
- Update the MES submodule
- Update dependencies and submodules
- *(stock-reservation)* Apply reviewer-flagged minor polish (ERP + MES)
- Bump AI and Core (review plans closed)
- *(shop)* Bump to e017d5e — catalog foundation Task 1
- *(shop)* Bump to 074c094 — catalog Task 2 (Product seam consumer)
- *(shop)* Bump to 996af87 — catalog Task 3 (product variants)
- *(shop)* Bump to 487442a — catalog Task 4 (variant-item composition)
- *(shop)* Bump to 2625b87 — catalog foundation complete (Task 5 + polish)
- Remove the nwidart scaffold from the modules and guard the API version prefix
- Update ERP submodule to latest commit and refine update prompt message in script
- Update Core submodule (permissions per guard)
- Update Core submodule (repair permissions per guard)
- Update Core, CMS, ERP, MES and SAO submodules (self-authenticated api routes, 403 for authenticated denials)
- Update Core submodule (failures-only bearer throttle)
- Update Core submodule (bearer claim detection)
- Update ERP subproject commit reference
- Update CMS, Core, and MES subproject commit references
- Add Shop module status to modules_statuses.json
- *(shop)* Enable the Shop module
- *(shop)* Bump to e0857af + baseline 4 irreducible Shop factory/seeder false-positives
- *(MES)* Update subproject commit to 32d25fc
- Update SAO subproject commit reference
- *(core)* Bump to 1da419f0 (relation filters in ACLs)
- *(core)* Bump to relation filter fail-closed fix
- *(core)* Bump to filters cast fail-closed fix
- *(core)* Bump to filters cast round 2 fix
- *(core)* Bump to filters cast round 3 fix
- Bump modules to related records obeying their own permission and ACL
- Bump modules to the read-request method guard and typed relations
- Bump Core and CMS to Task 4 review fix round 1
- Bump Core and CMS to Task 4 review fix round 2
- Bump Core to the scoped relation sync
- Bump Core and AI to the search strategy resolver; tick Task 3
- Bump Core and AI for search retries; tick Task 4
- Bump Core and CMS for the default roles and public ACLs
- Bump Core and CMS for the hidden attributes and the media projection
- Bump Core and CMS for the cover preload
- Bump Core and CMS for the history access fix
- Bump Core for personal API tokens and service accounts
- Bump Core for the token expiry and guest identity fixes
- Bump CMS for the content references in the search document
- Bump CMS to the tags kept across a soft delete
- Bump Core, CMS and SAO to the stale-record guard on the edit pages
- Bump Core, CMS and SAO to the Filament forms that write what they edit
- Bump Core, AI and MES for the two failing Core tests
- Bump Core to the cron jobs with parameters, placeholders and scheduler flags
- Bump CMS to the content position edited only once the content exists
- Bump Core and CMS (stored property filters, plan task 1)
- Bump Core and CMS (stored property filters, plan task 2)
- Bump Core, plan tasks 3 and 4 of stored property filters
- Bump Shop (product title filter test)
- Bump Core and CMS, plan task 5 of scope filters (search)
- Bump Core, tick Task 7 of scope filters (Filament)
- Bump CMS, tick Task 8 of scope filters (CMS defaults)
- Bump Shop (extender attribute overlay, plan task 1)
- Bump Core and Shop, extender attribute overlay plan task 2
- Bump Shop (variant relation overlay test)
- Bump Core and CMS (taxonomy positions in forms and imports, no is_deleted toggles)
- Bump ERP and Shop (detail labels, composition test)
- Bump Core (nested list and relation filter groups), record the scope filters follow-ups
- Bump Core (translation locale on the base query)
- Bump Core (language switch in the user menu), register it on the admin panel
- Bump Core, CMS, ERP, MES and SAO (translation keys named like the database)
- Bump Core (auth:reset-password command, valid backoffice reset link)
- Bump Core (auth:reset-password saves a locked user)
- Bump MES (machine sources on service accounts), tick Task 1 of the edge agent backend plan
- Bump Core (attribute labels from the module lang files)
- Bump MES (operator guide and resolver fix for Task 1)
- Bump Core, CMS, ERP, MES and SAO (translated resource names and navigation groups)
- Bump MES (ingest on the CRUD route through the service account) and Core (ingest no longer self-authenticated), tick Task 2 of the edge agent backend plan
- Bump MES (seeder depends on the permission refresh), clarify the test-runner limiter comment
- Bump Core (backoffice strings from the app lang file)
- Bump CMS, Core, ERP, MES, and SAO to latest subproject commits
- Bump Core, CMS, AI, ERP, MES and SAO (backoffice strings from the module lang files)
- Bump Core (lang:check-translations writes Pint-formatted files)
- Bump MES (loss markers as data_dropped incidents); tick Task 3 of the MES edge agent backend plan
- Bump MES (configuration delivery to the edge agent); tick Task 4 of the MES edge agent backend plan
- Bump MES (Task 4 fix round 1: faithful documents, version counter, delete transaction, config throttle); plan status line
- Bump MES (agent configuration section on the source page); tick Task 5 of the MES edge agent backend plan
- Bump MES (edge agent backend documentation); close the MES edge agent backend plan
- Bump MES (agent ACL requires an active source, Activate error handling, docs wording); plan follow-ups M2 and fixes M1, M3
- Bump MES (agent configuration doc wording, active-source ACL in the RAG doc)
- Bump Core (default_language setting sets the default locale)
- Lock neuron-ai 4.1.12; update AI subproject commit reference
- Install the Neuron 4 agent skills
- Bump AI (HTTP client layer, Neuron 4 guide 2); tick Task 3 of the neuron 4 upgrade plan
- Bump AI (CallbackTool, Neuron 4 guide 3); tick Task 4 of the neuron 4 upgrade plan
- Bump AI (ToolCall value object, Neuron 4 guide 4); tick Task 5 of the neuron 4 upgrade plan
- Bump AI (lenient tool property cast, Neuron 4 guide 5); tick Task 6 Steps 1-2
- Bump AI (workflow ids, Neuron 4 guide 13); tick Task 7 of the neuron 4 upgrade plan
- Bump AI (tool argument errors, Neuron 4 guide 5); tick Task 6 of the neuron 4 upgrade plan
- Bump AI (structured output sections not required); add Step 5 to Task 7 of the neuron 4 upgrade plan
- Bump AI (workflow executor reflection removed, Neuron 4 guide 17); tick Task 8 of the neuron 4 upgrade plan
- Bump AI (Elasticsearch RAG store contract, Neuron 4 guide 19); tick Task 9 of the neuron 4 upgrade plan
- Bump AI (Document accessors, Neuron 4 guide 20); tick Task 10 of the neuron 4 upgrade plan
- Bump AI (system message instructions, Neuron 4 guides 24 and 25); tick Task 11 of the neuron 4 upgrade plan
- Bump AI (provider responses and fakes, Neuron 4 guides 41 and 56); tick Task 12 of the neuron 4 upgrade plan
- Bump AI (the review's minor findings); update the neuron 4 upgrade delivery status
- Bump MES (clock_skew on agent resends); plan 2026-10-19-mes-clock-skew-resends closed
- Update AI subproject to commit a5c411a6e41b664937eb9d6d2b3832e23513b808
- Bump Modules/Core (core_modules table)
- Update the Core and MES submodule references
- Bump Core and CMS for the owner's updated_at (D30, D37, D51)
- *(scripts)* Initialise submodules added upstream in update_app.sh
- *(deps)* Drop the stale pnpm lockfile, the backend installs with npm
- Point submodules and links to the getlaraplate organization
- Rename the Composer package to the getlaraplate vendor
- Align the package description with the repository description
- Call the organization CLA workflow
- *(modules)* Update subproject commits for AI, CMS, Core, CRM, ERP, MES, SAO, and Shop modules
- *(scripts)* Skip the oci8 platform requirement in update_app.sh
- Rename the repository and package from laraplate-<name> to lara<name>
- Update the module references after the rename and logo commits
- *(shop)* Bump to 446e582 — product admin Task 1 (write actions)
- *(shop)* Bump to 33b838a — product admin Task 2 (resource + create wizard)
- *(shop)* Bump to d47b2bd — product admin Task 3 (edit, table, permissions)
- Update the Core reference
- Bump CRM and ERP after the contact role, person names, intercompany and lead conversion work
- *(phpstan)* Drop three baseline entries EnsembleSearchService no longer raises
- Update subproject references for AI, CMS, Core, CRM, ERP, MES, and SAO modules
- Bump AI (the in-app assistant has its own switch, apart from the documentation)
- Update AI subproject reference to latest commit
- Bump AI module (palette documentation search, query embedding cache, heading breadcrumb fix)
- *(phpstan)* Drop 16 search baseline entries that no longer match an error
- *(phpstan)* Record the translations eager load of Searchable, drop stale entries
- Bump AI module (switch harness answers the verify smoke embed)
- *(CRM)* Update subproject commit reference to 3dc7319
- List the AI server variables in .env.example
- List every Laraplate variable in .env.example and keep it complete
- Update subproject commits and enhance CMS documentation
- *(ci)* Added run optimize prompt un app update
- Update environment example and composer dependencies
- Harden the env example guard test and separate the module headers
- Update subproject commits for various modules
- *(env)* Drop the AI_EMBEDDINGS_MINILM variables from .env.example
- Add stress output file and update subproject commits for AI, Core, and MES modules
- Update subproject commits for AI, CMS, Core, ERP, MES, and SAO modules to their latest versions
- Update AI subproject commit to latest version 824f9fe
- Update subproject commits for AI and Core modules to latest versions
- *(env)* SAO probe schedule names
- Update subproject commits for CMS, Core, MES, and SAO modules
- Update dependencies to latest versions
- *(composer)* Remove published-time entries from composer.lock
- *(pakages)* Updated composer and npm packages and linked subrepos to the latest commits
- Update submodule references for AI and Core modules
- Run the license checker as a script
- Install the license checker with Composer again
- Update subproject commits for AI, Core, and ERP modules
- *(deps)* Require Laravel 13, Tinker 3 and laravel-modules 13
- Update subproject commits for all modules
- *(modules)* Reference the Laravel 13 fixes in CMS and Core
- Delete the dead app/Http/Kernel.php
- *(modules)* Reference the Scout 11 fixes and the Laravel 13 README updates
- *(modules)* Bump all modules to their Laravel 13 upgrade
- *(deps)* Upgrade the test runner to Pest 5 and PHPUnit 13
- *(modules)* Update Core submodule to latest commit
- *(modules)* Update submodules to latest commits
- *(composer)* Drop the module list from the package description
- *(modules)* Update Core submodule to latest commit 761b52d
- *(modules)* Update Core submodule to latest commit c624dcd
- *(modules)* Update Core and CMS submodules to latest commits 515f14a and 214415f
- *(modules)* Update AI submodule to latest commit 49ac855
- *(modules)* Update AI and Core submodules to latest commits ff4af49 and 1aab65d
- *(modules)* Update Core submodule to latest commit 517aff4
- *(modules)* Update submodules for AI, CMS, Core, and Shop
- *(modules)* Update submodule commits for AI, CMS, Core, and Shop modules
- *(modules)* Update submodule commits for Core and Shop modules
- *(modules)* Update submodules for CMS, Core, and Shop
- *(modules)* Update submodule commits for CMS, CRM, Core, MES, and SAO
- *(modules)* Update submodule commits for AI and Core

## [1.14.1] - 2026-09-15

### 🚀 Features

- *(modules)* [**breaking**] Record locking overhaul across Core, CMS, ERP and SAO
- *(admin)* Let each module own its navigation group
- *(release)* Choose, skip or confirm each target interactively
- *(release)* Release every pending module, then the application, with --all
- *(release)* Regenerate and verify changelogs against history
- *(release)* Gate commit messages and replace the unused post-commit machinery

### 🐛 Bug Fixes

- *(core)* Bump submodule for fork-safe redis cache purge
- *(filament)* Update Core and CMS for validity table and edit form fixes
- *(admin)* Stop prefetching panel links, which locked records on hover
- *(http)* Trust the reverse proxy headers so HTTPS is detected
- *(core)* Update Core submodule with Number field validation fix
- *(release)* Keep single-line commits and tag the release being written
- *(release)* Rewrite version.sh around a plan and a plain release commit
- *(release)* Read the whole module list before matching a target
- *(release)* Carry the module release level into the application under --all
- *(modules)* Update subproject commits for AI, CMS, Core, ERP, MES, and SAO modules
- *(release)* Keep unreleased work out of CHANGELOG.md

### 💼 Other

- Update submodules and dependencies; enhance update script

- Updated submodule references for CMS and Core modules.
- Updated composer.lock to reflect new versions of dependencies, including:
  - filament packages from v5.7.7 to v5.7.8
  - laravel/framework from v12.68.0 to v12.69.1
  - laravel/socialite from v5.30.1 to v5.31.0
  - league/flysystem from v3.35.3 to v3.36.0
  - monolog/monolog from v3.10.0 to v3.11.0
  - neuron-core/neuron-ai from v3.16.7 to v3.16.8
  - spatie/laravel-medialibrary from v11.23.5 to v11.23.6
  - larastan/larastan from v3.10.0 to v3.11.0
  - rector/rector from v2.6.5 to v2.6.6
- Updated package-lock.json for various @rolldown bindings to version 1.2.7.
- Modified update_app.sh script to provide more informative output during submodule updates and added user prompt for full update confirmation.
- Add security and test data guidelines; update Laravel Boost and Claude documentation

- Introduced a new security tests guideline document outlining best practices for testing security boundaries and escaping user-provided content.
- Added a comprehensive test data guideline document detailing the creation of mutable records, use of factories, and datasets for tests.
- Updated Laravel Boost guidelines to clarify foundational context, skills activation, conventions, verification scripts, and application structure.
- Enhanced Claude documentation with clearer instructions on Laravel Boost guidelines, project rules, and testing practices.
- Updated submodule references for AI, Core, ERP, MES, and SAO modules.
- Modified boost.json to include additional skills and packages for improved development support.
- The application's tool configuration covers the modules
- Declare the test toolchain in the application
- Refactor code structure for improved readability and maintainability

### 🚜 Refactor

- Enhance schema component with intersection observer for deferred loading

### 📚 Documentation

- Pin native modules to one schema and one connection
- *(ai)* Spec RAG R2 — SAO application-content provider
- *(sao)* Implementation plan RAG R2 — SAO application-content provider
- *(plans)* Track ES index-mapping fix + vector enablement (indexing solved, vector retrieval verified)
- *(plans)* Record AI search-path resilience fixes (NeuronAI init, LLM + reranker degradation)
- *(plans)* Check off the ES-gated createIndex mapping integration test
- *(plans)* Record the locking overhaul as shipped, and bump Core
- *(plans)* Close the comments moderation plan as shipped and generalized
- *(plans)* Close two more plans whose empty boxes were not outstanding work
- *(plans)* Close four more, and say which open box is real
- Adopt the subproject as the home of its own specs and plans
- *(plans)* Close the delivered plans and record the retrospective ones
- *(plans)* Close the import framework, the last box was already covered
- *(plans)* Close the ERP factories plan
- *(plans)* The shared SQL parsers Nebula wants already exist
- *(plans)* Finalize ERP factories and demo dataset design
- *(plans)* The module testing strategy keeps one runner, not six
- *(plans)* Correct the ticks the execution did not earn
- *(versioning)* Mark reference-only restore done in milestone-1 plan
- *(specs)* Move MCP server design into the backend repo
- *(release)* Plan the release tooling and move versioning out of the testing strategy
- *(specs)* Remove duplicated sections from MCP server design
- *(changelog)* Regenerate with the corrected git-cliff configuration
- *(release)* Document the release process and expose it through Composer

### 🧪 Testing

- *(release)* Add a harness that drives version.sh against throwaway repositories

### ⚙️ Miscellaneous Tasks

- Update submodule references and add release tooling design document
- *(modules)* Bump Core for non-appended HasPath accessor
- *(modules)* Bump Core and CMS for HasPlace N+1 fixes
- *(modules)* Update submodule references for Core and CMS
- *(modules)* Update submodule references for Core, CMS, ERP, MES, and SAO; add record locking overhaul plan
- *(sao)* Bump submodule to the `select` verb normalization
- *(permissions)* Bump SAO and ERP to the dead-verb cleanup
- *(modules)* Bump Core and ERP for the lock-scope extension point
- *(modules)* Update submodule commits for AI, CMS, Core, ERP, MES, and SAO
- *(env)* Make the example match the stack the app actually runs on
- *(deps)* Update submodule commits for Core, CMS, ERP, MES, and SAO; bump Filament packages to version 5.8.1; add public/flags/ to .gitignore; update app and horizon config to use environment variables for timezone and memory limit
- *(deps)* Update submodule commits for AI, CMS, Core, and SAO; bump inspector-php, mcp, and pint versions; add libc support in package-lock
- *(deps)* Update Core submodule to latest commit ea2253e
- *(deps)* Update CMS and Core submodules to latest commits
- *(core)* Bump submodule for the lock verb permissions
- Bump Core/CMS/AI submodules — ES multilingual index + multi-model embeddings (tasks 1-9)
- Bump Core/CMS/AI/SAO submodules and lock files
- *(sao)* Bump submodule for the transition permission picker
- *(modules)* Bump CMS and Core for the closed-plan documentation pass
- *(docs)* Make "nothing ships documented only in a plan" enforceable
- One versioning script for the whole stack
- *(modules)* Record the toolchain centralization pointers
- *(deps)* Modules own their dependencies, the application keeps the platform
- *(modules)* Record the regenerated module changelogs
- *(modules)* Record the module release documentation

## [1.14.0] - 2026-08-31

### 🚀 Features

- *(admin)* Keep session-backed approvals preview across Livewire updates

### 🐛 Bug Fixes

- *(admin)* Apply settings overlay and scoped log context on the panel

### 💼 Other

- Refactor code structure for improved readability and maintainability

### 📚 Documentation

- *(ai)* Spec RAG R1b — assistant end-to-end evaluation
- *(ai)* Implementation plan RAG R1b — assistant end-to-end evaluation
- Note the grid deprecation and bump Core for cache documentation
- *(plans)* Record the octane readiness plan and its execution outcome

### ⚙️ Miscellaneous Tasks

- *(core)* Bump Core submodule for viewer-relative pending (modifier identity)
- *(deps)* Update dependencies to latest versions
- *(modules)* Bump Core for grid deprecation
- *(modules)* Bump AI and Core for signal-free AI deadlines
- *(modules)* Bump Core and CMS for request-scoped permission caches
- *(modules)* Bump Core for class-independent permission memo key
- *(modules)* Bump Core for bounded depth cache
- *(modules)* Bump Core for the production-path permission memo test
- *(modules)* Bump Core for per-request settings overlay and seed budget warmup
- *(modules)* Bump Core for Spatie reset listener
- *(modules)* Bump Core for swagger-safe media upload rules
- *(modules)* Bump Core for schema-aware boolean input coercion
- *(modules)* Bump Core, CMS, and ERP for CRUD API exposure via settings

## [1.13.8] - 2026-08-27

### 🐛 Bug Fixes

- *(tests)* Force English locale in phpunit and bump CMS/ERP

### ⚙️ Miscellaneous Tasks

- Update submodule references for CMS, Core, and ERP modules

## [1.13.7] - 2026-08-26

### 🚀 Features

- *(perf)* Bump Core submodule for perf:bench benchmarking harness
- *(core)* Bump Core for list freshness endpoint
- *(core)* Bump Core for freshness presence and grid action fix
- *(perf)* Bump Core submodule for the perf stress-test toolkit
- *(mes)* Production-order auto-creation from confirmed sales orders
- *(mes)* Auto quality checks and stock-shortage detection
- *(mes)* Partial consumption on stock shortage
- *(mes)* Notify recipients on material shortage
- *(ai)* Per-module allowlist for embeddings and translation
- *(ai)* Default CRUD tools for the in-app assistant
- *(ai)* Structured filters/sort and request echo for CRUD tools
- *(ai)* Configure-mode view CRUD tool
- *(sao)* Phase 5b — code-to-work, releases and deploy census
- *(sao)* Phase 6 — fix propagation & evidence-based closure
- *(sao)* Phase 7 — gitea and sentry drivers
- *(sao)* Filament surfaces for releases, environments and closure policies
- *(sao)* Read-only filament surface for closure audits
- *(sao)* Apply closure decisions to the ticket workflow
- *(sao)* Deterministic ownership suggestion with read-only surface
- *(sao)* Codeowners ownership-evidence resolver
- *(sao)* Normalize the commit author in vcs reads
- *(sao)* Recent-touch ownership-evidence resolver
- *(sao)* Blame capability and blame-concentration ownership resolver
- *(sao)* Contributor identity directory as the ownership identity-map source
- *(sao)* Ownership suggestion coordinator
- *(sao)* Discover a pull request's changed files for ownership
- *(sao)* Phase 8 — AI-phrased ownership suggestions
- Optional AI text generation via a Core event seam
- *(ai)* Optional listener answering Core's AI text-generation request
- *(ai)* Production-ready live text generation behind its flag
- *(sao)* Connection health check + graylog logs driver
- *(filament)* Enable native database-notifications bell + bump Core

### 🐛 Bug Fixes

- *(core)* Bump Core for ResponseBuilder JsonResource list wrap
- *(ai)* Gate CRUD tools by permission, drop approval-for-unpermitted
- *(tests)* Silence file logging during Pest runs

### 📚 Documentation

- *(ai)* Spec RAG R0 — documentation evaluation baseline
- *(ai)* Implementation plan RAG R0 — documentation evaluation baseline
- *(versioning)* Milestone-1 membership-vector plan + confirmed scope
- *(ai)* Document ai:evaluate-documentation baseline
- *(versioning)* Mark syncVersioned done in milestone-1 plan
- *(ai)* Spec RAG R1a — profile-driven assistant scope
- *(ai)* Implementation plan RAG R1a — profile-driven assistant scope
- *(ai)* R1a spec — no-module in-app stays generic (data scope = module, not page)
- *(mes)* Revise API architecture and record cloud provisioning
- *(mes)* Record Tasks 4-5 implementation progress
- *(mes)* Record Tasks 6-8 implementation progress
- *(mes)* Record Tasks 9-13 completion (MES domain complete)
- *(mes)* Close module — Filament resources + manual consumption follow-up
- *(mes)* RAG user and developer docs for the new capabilities
- Reconcile completed Core/ERP quick-win plans with shipped code
- *(ai)* Record verified HasApprovals moderation behavior for CRUD tools
- Index completed SAO phase-0 and phase-1a plans
- *(sao)* Add phase-3a driver-framework foundation spec and plan
- *(sao)* Settle F4 — encrypted-at-rest connection credentials with env override
- *(sao)* Add phase-3b bindings and issues-sync spec and plan
- *(sao)* Add phase-1b ticket-enrichment spec and plan
- Add Core media foundation spec+plan; wire it as SAO 1b attachments prerequisite
- *(core)* Refine media-foundation trait split (HasMedia + MediaFileNamer to Core, HasMultimedia stays CMS)
- *(core)* Move the whole media foundation to Core (nothing is CMS-specific)
- *(core)* Keep HasMultimedia in CMS for now
- Reconcile phase-6 spec/plan indexes with the follow-on slices
- Spec + plan for the live LLM text generator
- Index the SAO/AI transport + model-binding follow-ups
- Mark CRUD facet-counters as implemented (tier 1)
- Scaffold four design specs (fix-attribution, tracker migration, bulk import, in-app notifications)
- *(sao)* Lock fix-attribution decisions + phase plan (spec #1)
- Mark spec #1 (fix-attribution) implemented in specs index
- *(import)* Mark spec #3 decisions shipped + bump Core gitlink
- Reference the SAO submodule in the root README
- Bump Core for interactive-import + notifications documentation

### ⚡ Performance

- *(crud)* Bump Core submodule for CRUD query/discovery optimizations
- *(core)* Bump Core submodule for entity-resolution index

### ⚙️ Miscellaneous Tasks

- Bump modules for seeding review fixes
- *(sao)* Bump SAO through the phase 1a configuration surfaces
- Update submodule references for Core, ERP, and SAO to latest commits
- *(cms)* Bump CMS for content modification soft-keep
- *(cms)* Bump CMS for live edit protection
- *(core)* Bump Core for approve quorum on write
- *(core)* Bump Core for pending approvals inbox endpoint
- *(core)* Bump Core for latest disapproval endpoint
- Update submodule references for CMS and Core to latest commits; enhance SAO module design documentation with product pillars and architectural clarifications
- *(core)* Bump Core submodule to latest tip
- *(ai)* Bump AI submodule for approve-job test fix
- Update submodule references for CMS, Core, and SAO to latest commits
- Update submodule references for AI and Core to latest commits; add draft design for CRUD facet counters utility in CrudService
- *(ai)* Update AI submodule to latest commit fbdae62
- *(core)* Update Core submodule to latest commit 3c2f2ea
- *(sao)* Close slice 1a — the internal ticketing core
- Bump Modules/AI submodule for RAG R0 documentation evaluation baseline
- Bump Modules/AI submodule for documentation evaluation guides
- *(deps)* Update dependencies and submodules
- Update ERP submodule to latest commit 70add06
- Update submodules for AI, CMS, Core, and ERP to latest commits
- Bump Modules/AI + Core for RAG R1a profile-driven assistant scope
- Bump Core/CMS submodules and update crud facet design doc
- Bump Modules/Core submodule to facet counters ACL completion
- *(mes)* Bump MES submodule for Tasks 4-5 (BOM + Routing)
- *(mes)* Bump MES + ERP submodules for Task 6 (production orders)
- *(mes)* Bump MES submodule for Task 7 (operation execution)
- *(mes)* Bump MES submodule for Task 8 (backflush)
- *(mes)* Bump MES submodule for Task 9 (lot/serial traceability)
- *(mes)* Bump MES submodule for Task 10 (quality/non-conformance)
- *(mes)* Bump MES submodule for Task 11 (capacity)
- *(mes)* Bump MES submodule for Task 12 (downtime/OEE)
- *(mes)* Bump MES submodule for Task 13 (shifts/operators)
- *(mes)* Bump MES submodule for Task 14 (domain-action API)
- *(mes)* Bump MES submodule for Tasks 15-17 and record completion
- *(mes)* Bump Core+MES submodules; MES suite green on PHP 8.5
- *(mes)* Bump MES submodule for RoutingOperation factory fix
- *(ai)* Bump AI submodule with approval-verb CRUD tools
- *(ai)* Bump AI submodule with summarize CRUD tool
- Bump Core (TabularPdfExporter) and AI (export CRUD tool) submodules
- *(erp)* Bump ERP submodule (ReportPdfExporter extends Core exporter)
- *(ai)* Bump AI submodule with bulk CRUD tools
- *(ai)* Bump AI submodule with user RAG guide for CRUD data tools
- *(core)* Bump Core submodule with tabular exporters doc
- *(sao)* Bump SAO submodule with developer RAG how-it-works section
- *(sao)* Bump SAO submodule — phase 3a tasks 1-3 (enums, contracts, registry)
- *(sao)* Bump SAO submodule — phase 3a tasks 4-5 (connection, credential resolver)
- *(sao)* Bump SAO submodule — phase 3a complete (conformance, wiring, docs)
- *(sao)* Bump SAO submodule — phase 3b tasks 1-3 (sync enums, BindingContext, config)
- *(sao)* Bump SAO submodule — phase 3b-core complete (bindings, links, internal driver, issue sync)
- Bump Core and CMS to Core-owned media foundation
- Bump SAO to phase 1b; reconcile media + 1b plans
- Bump SAO to 1b UI, 1c board and the Redmine driver
- Bump SAO with Jira, GitHub, GitLab and Bitbucket drivers
- Bump SAO with 3b-ui connection and binding surfaces
- Bump SAO with phase 5 vcs/releases on the git hosts
- Bump Core and SAO with phase 2 signals and fingerprinting
- Bump SAO with the filament signal resource
- Bump SAO with phase 4 ingest and source profiles
- Bump SAO submodule (youtrack/azure/linear + eight logs drivers)
- Bump SAO submodule (inbound webhook transport for logs connections)
- Bump SAO submodule (scheduled inbound issue polling)
- Bump AI submodule (configurable text-generation model binding)
- Bump SAO submodule (ingest-events read-only Filament surface)
- Bump SAO submodule (signal-to-ticket auto-open)
- Bump Core submodule (CRUD facet-counters tier 2)
- Bump Core submodule (facet tier 2 relation labels)
- Bump Core submodule (facet label search/sort)
- Bump SAO submodule (scheduled connection health probe)
- Bump SAO submodule (ingest replay command)
- Bump SAO submodule (accept ownership suggestion action)
- Bump SAO submodule (SourceProfile Filament CRUD)
- Bump Core/CMS submodules for facet label sources + relation facets
- Bump Core submodule for relation facet cross-filter fix
- Bump Core/CMS submodules for translated FK facet labels
- Bump Core/CMS submodules for base-label relation-facet fix + tests
- Bump Core/CMS submodules for to-one related-column facet
- Bump Core/CMS submodules for facet resolvability guard
- Bump CMS submodule — map/tag-graph insight endpoints
- Bump CMS submodule — Filament location geocode action
- Bump Core + CMS submodules — CRUD dotless relation-count fix, map via select
- Bump CMS submodule — map-select test eager-loads place
- Point submodules at Core/CMS master tips after merge
- Bump CMS submodule — content relation-sync endpoint
- Bump CMS submodule — relation-sync served as web route
- Bump Core + CMS submodules — relation-sync generalized into CRUD update
- Bump Core + CMS submodules — authoring surface for drafts
- Bump Core submodule — ACL filter dynamic placeholders
- Bump Core + CMS submodules — validity moves to role-scoped ACLs
- Bump Core/CMS/MES submodules
- Bump Core submodule (PerfCrud test-ordering pollution fix)
- Bump Core/CMS submodules (default-presettable enum fix + test)
- Bump CMS submodule (ContributorFactory locale-translation seeding)
- Bump Core submodule (CRUD read perf + metadata cache invalidation)
- Bump Core submodule (look-ahead pagination totals flag)
- Bump Core submodule (look-ahead default + pagination mode discriminator)
- Bump Core + CMS submodules (user prefs/first-login endpoints + counted-totals test)
- Bump Core/CMS gitlinks for the generic media HTTP API
- Bump Core/CMS gitlinks for media pending-bucket + row ACL
- Bump Core/CMS gitlinks for unified media upload endpoint
- Bump module gitlinks to released versions
- Bump SAO gitlink to v0.3.0
- Bump Core gitlink for optional login module scope
- Bump Core gitlink for permissions.module_name rename
- Bump Core gitlink for scoped-login RAG docs
- Bump SAO gitlink to include HTTP domain actions
- Bump SAO gitlink for tickets transitions read action
- Bump MES gitlink for dev seeder (demo data, roles, users)
- Bump SAO gitlink for dev seeder (demo data, role, user)
- Bump SAO/ERP/CMS gitlinks for explicit m2m pivot models
- Bump SAO gitlink for release-health read-model (#2)
- Bump SAO gitlink for deploy & rollout ingest core (#1)
- Bump SAO gitlink for deploy webhook transport (#1 complete)
- Bump SAO gitlink for attribution core (spec #1 phase 1)
- Bump SAO gitlink for fix-attribution pipeline (spec #1 complete)
- Bump SAO gitlink for RAG glossary updates + release normalization docs
- Bump SAO gitlink for deterministic release promotion (spec #1)
- Bump SAO gitlink for external tracker migration importer (spec #2)
- Bump SAO gitlink for data retention prune
- *(sao)* Bump submodule — resumable tracker import + history
- *(import)* Bump Core+SAO submodules — generic bulk import (Fase A+B)
- *(import)* Bump Core submodule — Filament monitoring + launcher
- *(import)* Bump Core/CMS/ERP submodules — Tier 1 importable entities
- Bump Core+CMS for import relation layer + cms.content pilot
- Bump Core for import relation metadata in field payload
- Update submodule references for AI, CMS, Core, ERP, MES, and SAO modules
- Update submodule references for Core and SAO modules
- Update submodule references for CMS, Core, MES, and SAO modules
- Bump SAO, ERP, and Core module pointers for test fixes
- Update submodule references for Core, ERP, and MES modules

## [1.13.6] - 2026-08-04

### 🐛 Bug Fixes

- *(tests)* Prevent Pest CallsTerminable shutdown failures

### ⚙️ Miscellaneous Tasks

- Update submodule reference for Modules/Core to latest commit
- Bump CMS, Core, and ERP for optional import console output

## [1.13.5] - 2026-08-04

### 🚀 Features

- *(cache)* Add custom PHPStan stubs for Laravel Cache facade with extended methods
- *(filament)* Bump modules for generate→trait merge
- Delegate root DatabaseSeeder to the Core orchestrator
- *(erp)* Add external cash import foundation
- Add sidebar group component with collapsible functionality and icon support

### 🐛 Bug Fixes

- *(erp)* Complete runtime connection affinity
- Preserve migration connection context
- *(core)* Close connection affinity guard bypasses
- Bump Core submodule for the seed orchestrator review fixes
- Close final database connection affinity gaps
- *(core)* Preserve approval authorization
- *(erp)* Harden external cash import foundation
- Finalize database connection affinity
- Update Core seeding reconciliation

### 📚 Documentation

- *(erp)* Design external source reconciliation
- Complete database connection affinity audit
- *(erp)* Plan Nebula cash import
- *(filament)* Specify generate→trait merge for tables and forms
- *(specs)* Align revision-centric draft with verified behaviour
- *(erp)* Design domain actions over HTTP on the /app surface
- *(erp)* Implementation plan for domain action HTTP routes
- *(core)* Seeder orchestration implementation plan
- *(sao)* Design the SAO orchestrator module
- *(sao)* Implementation plan for phase 0 scaffolding
- *(specs)* Index the SAO and seeder orchestration designs
- *(sao)* Record the completed submodule registration
- *(sao)* Add the documentation and RAG task to the phase 0 plan
- *(core)* Fix seeder plan commit protocol for submodules
- *(core)* Extract test stub instead of an inline anonymous class
- *(erp)* Close 3-01, 3-04 and 3-06 in the master backlog
- *(sao)* Design the phase 1a internal ticketing core
- *(sao)* Correct the 1a authorization section — ACL is implemented
- *(sao)* Mark phase 0 criterion 1 as deliberately open
- *(sao)* Implementation plan for slice 1a
- *(sao)* Correct the 1a plan from what Task 2 revealed
- *(sao)* Fold the Laraplate model standard into the 1a plan
- Index the seeder orchestration plan
- *(sao)* Distinguish release candidates from shipped releases
- *(erp)* Reconcile point zero status
- *(rag)* Define guest boundary closure
- *(sao)* Record two migration constraints the plan got wrong
- *(rag)* Plan guest boundary closure
- *(rag)* Close phase one guest boundary
- *(core)* Unblock Task 11 and fix its multi-submodule commit protocol
- Record database connection affinity audit

### 🧪 Testing

- Preserve model connections in database helpers
- *(core)* Reject falsy connection names

### ⚙️ Miscellaneous Tasks

- *(CMS)* Update subproject commit reference to latest version
- Bump Core and CMS for per-model setting name fix
- Bump Core for observer registration and versionable image fixes
- *(sao)* Register SAO as a submodule
- *(sao)* Activate the SAO module
- Bump Core for the seeder dependency graph
- Bump Core for seeder discovery
- Bump Core for seeder graph builder dependsOn edge tests
- *(sao)* Bump SAO to the phase 0 scaffolding state
- Bump Core for settings seeding columns
- Bump Core for SeedDefinition
- Bump Core for SeedDefinition validation fixes
- Bump Core for SeedReconciler
- Remove unused flag images for de, es, gb, it, and sl
- Bump Core for SeedReconciler test hardening
- Bump Core for the single-pass capability scan
- Bump Core for the strengthened capability-scan test
- Bump Core for observable model-skip logging in the capability scan
- Bump Core for single-pass settings reconciliation
- Bump Core for load-bearing no-force-delete test fix
- Bump Core for the seed run ledger
- Update subproject commits for Core and SAO modules
- Bump Core for settings cleanup
- *(core)* Bump Modules/Core — i18n translations (locale meta, user lang, UI keys)
- Update submodule references for AI, CMS, ERP, and SAO modules
- Update submodule reference for Modules/Core
- Update submodule references for Core and SAO modules; add review log for revision-centric aggregate history

## [1.13.4] - 2026-07-29

### 🚀 Features

- *(search)* Configure adaptive matching
- *(search)* Provision portable database indexes
- *(search)* Adopt adaptive matching profiles
- *(search)* Adopt explicit query syntax
- *(rag)* Integrate authorized application content retrieval
- *(versioning)* Implement core version set infrastructure and CMS versioned categories pilot
- *(filament)* Default Toggle fields to stacked label layout
- *(filament)* Ship make-resources docs and App panel discovery

### 🐛 Bug Fixes

- Preserve model connections in core queries
- Preserve model connections during cms imports
- *(core)* Keep validation lookups connection-aware
- *(erp)* Preserve aggregate connection boundaries
- *(filament)* Render stacked locale flags as circles

### 🚜 Refactor

- Replace pxlrbt/filament-environment-indicator with Core plugin and module version dropdown

### 📚 Documentation

- Require module documentation updates
- Add graph documentation checkpoint
- Record graph benchmark harness
- *(search)* Retain query syntax rationale
- *(ai)* Define evidence-gated RAG retrieval roadmap
- *(ai)* Define in-app assistance security boundary
- *(ai)* Plan protected in-app assistance
- *(ai)* Separate authorization from prompt policy
- *(ai)* Define modular application content retrieval
- *(ai)* Plan modular application content retrieval
- *(ai)* Define contextual provider routing
- *(erp)* Close enterprise implementation points
- *(erp)* Update operational backlog status
- *(erp)* Close operational command phase
- *(erp)* Close item-specific pricing backlog
- Require model connection affinity
- *(erp)* Close integration outbox backlog
- *(erp)* Close architecture vision backlog
- *(erp)* Close journal cash movement backlog
- *(erp)* Close cash movement UI backlog
- *(erp)* Close quotation revision backlog
- *(erp)* Close lock-chain trigger backlog
- *(erp)* Close partner pool settlement backlog
- *(erp)* Close payment request provider backlog
- *(rag)* Record application content delivery gates
- *(erp)* Close site place integration backlog
- *(erp)* Close task calendar export backlog
- *(erp)* Close forced version settings backlog
- *(erp)* Close numbering stress backlog
- *(import)* Plan module import framework
- *(import)* Record Core and CMS completion
- *(erp)* Plan external source importers
- *(plans)* Index ERP external importers
- *(erp)* Align master backlog with importer plan
- *(ia)* Update documentation with canonical agent rules and Laravel Boost guidelines
- Add ERP and MES module links to main README

### 🧪 Testing

- *(graph)* Enforce PSR-4 stubs

### ⚙️ Miscellaneous Tasks

- Update submodule references for Core and ERP modules, and remove unused it_40x30.webp file
- Update submodule reference for Core module
- Update submodule references for AI, CMS, Core, ERP, and MES modules
- Update dependencies and submodule references for CMS and ERP modules
- Update filament packages to version 5.7.1 and bump postcss to 8.5.20
- *(erp)* Update module revision
- *(erp)* Advance numbering stress coverage
- *(core)* Advance import framework revision
- *(deps)* Update filament packages to version 5.7.3 and @emnapi/core/runtime to version 2.0.0-alpha.3; update app.css and actions.js for compatibility
- *(deps)* Update ERP subproject to commit a7447e5
- Update Core and AI submodule pointers for PSR-4 test stubs
- *(deps)* Bump ERP submodule for distinct artisan console badge
- *(deps)* Update ERP submodule to latest commit 63e51d1
- *(deps)* Update submodule pointers for CMS, Core, and ERP to latest commits

## [1.13.3] - 2026-07-14

### 📚 Documentation

- Align search plan with database engine parity
- Define advanced search filter and score contracts

### ⚙️ Miscellaneous Tasks

- Update core search submodule
- Update core database vector search
- Update core embeddings migration
- Update core advanced search contracts
- Update core keyword advanced filters
- Document search filter metadata
- Wire indexed relation search filters
- Update submodule references for AI and ERP modules, and enhance global performance optimization documentation
- Refine testing guidelines and remove deprecated libc entries from package-lock.json

## [1.13.2] - 2026-07-12

### 💼 Other

- Revert "docs(erp): align enterprise module documentation"

This reverts commit 11baf1940fe585ba79bbbaf3f26771ed0de57516.

### 📚 Documentation

- *(erp)* Align enterprise module documentation
- *(erp)* Plan supplier payment run export
- *(erp)* Mark supplier payment export complete
- *(erp)* Mark bank difference reconciliation complete
- *(erp)* Mark bank format import complete
- *(erp)* Mark financial CSV export complete
- *(erp)* Close phase 2b implementation
- *(erp)* Move roadmap to phase 2c
- *(erp)* Update rag limitations pointer
- *(erp)* Mark phase 2c schema complete
- *(erp)* Mark phase 2c mapper complete
- *(erp)* Refresh phase 2c plan status
- *(erp)* Close phase 2b verification checklist
- *(erp)* Mark phase 2c xml validation complete
- Update core graph implementation plan
- *(erp)* Mark phase 2c aruba adapter complete
- Update graph provider rules plan
- Update graph materialized edge plan
- Defer graph materialized edges
- *(erp)* Close phase 2c permissions task
- Update graph search plan
- *(erp)* Add operational command backlog

### ⚙️ Miscellaneous Tasks

- *(modules)* Update ERP submodule reference and document progress on Phase 2A and 2B
- Align ERP phase tracking and module test discovery
- Update cms graph submodule
- Remove Kiro specs index, update submodule references, and enhance admin panel with sidebar accordion functionality

## [1.13.1] - 2026-07-10

### 🐛 Bug Fixes

- *(tests)* Stabilize suite bootstrap and bump module refs

### ⚙️ Miscellaneous Tasks

- *(modules)* Bump AI, CMS, Core, and ERP submodules
- *(modules)* Update submodule references for AI, CMS, Core, and ERP
- *(modules)* Update CMS submodule reference to latest commit
- *(modules)* Update Core submodule reference to latest commit and regenerate composer.lock

## [1.13.0] - 2026-07-09

### 🚀 Features

- *(performance)* Enhance performance optimization strategies and add large dataset guidelines

### 📚 Documentation

- Align RAG corpus guide with user vs developer audiences
- Update MES module plans and decisions

### ⚙️ Miscellaneous Tasks

- Bump CMS to v1.36.4 and sync project guidelines

## [1.11.4] - 2026-07-09

### 🐛 Bug Fixes

- *(app)* Guard validation exception context for all throwables

### ⚙️ Miscellaneous Tasks

- Update submodule references for CMS and Core modules
- Plans for graph api implementations
- *(app)* Wire core validation context and translation updates
- Update Pint configuration and enhance laraplate-specific rules
- Update dependencies and submodule references

## [1.11.3] - 2026-07-07

### 🚀 Features

- Add master rule file and enhance existing rules with descriptions
- Implement app structure with authentication and module management
- Add initial Business module plan and structure
- [**breaking**] Point monorepo to ERP submodule (laraplate-erp)
- *(cms)* Add content provenance, references and ai assistance metadata
- *(cms,core)* Wire record origins registry and generic cms:import

### 🐛 Bug Fixes

- Standardize module naming and update dependencies
- *(core)* Bump submodule for MySQL taxonomies migration trigger fix

### 💼 Other

- Update ERP plans and module references for M6.1 to M7.1 milestones

- Enhanced bank reconciliation (M6.1) with ranked payment suggestions and CSV import functionality.
- Improved returns management (M6.2) with explicit DDT handling for customer and supplier returns, and refined inventory posting processes.
- Updated e-invoice (M6.3) to include basic submission workflow and stub for FatturaPA compliance.
- Advanced pricelists (M7.1) now support sales order and quotation integration with pricing rules.
- Incremented version numbers in swagger documentation for AI, CMS, Core, ERP, and MES modules.
- Updated submodule references to latest commits for all modules.
- Update dependencies and module versions for improved functionality

- Bumped versions for filament packages to v5.6.7 in composer.lock.
- Updated package-lock.json with new versions for @napi-rs/wasm-runtime (1.1.5), @oxc-project/types (0.133.0), and @rolldown bindings (1.0.3).
- Adjusted PHPStan analysis level from 1 to 7 for enhanced code quality checks.
- Refined database configuration by removing deprecated PDO options.
- Enhanced seeders to ensure proper handling of module paths.
- Updated documentation for MES module with new tasks and requirements.
- Updated submodule references for AI, CMS, Core, ERP, and MES modules to their latest commits.

### 📚 Documentation

- *(plan)* Mark Filament ERP core slice in Nebula roadmap
- *(plan)* Align Nebula plan with ERP module paths and naming
- Add RAG multi-instance design and Elasticsearch implementation plan
- Add CMS comments with AI moderation design spec
- Revise CMS comments spec per review feedback
- Add preliminary AI disapproval and implementation plan
- Clarify locale read rule and approval Option A/B
- Use HasTranslations for comments with locale overrides
- Add approval_mode config for comment moderation A/B
- Add RTK documentation and update AGENTS.md
- *(erp)* Align roadmap status
- Add module versioning rule
- Require version bump confirmation
- *(erp)* Mark e-invoice stub complete
- *(erp)* Mark m4 operational reports complete
- *(erp)* Reconcile implementation plans
- *(erp)* Plan accounting golden masters
- Optimize agent rule routing
- *(erp)* Add Spec 1 design for v1 hardening (bugs + money math)
- *(erp)* Add Fix 8 (CRUD write guard) to Spec 1 hardening
- *(erp)* Refine Spec 1 hardening scope after code/test verification
- *(erp)* Add Spec 1 hardening implementation plan
- *(erp)* Update plans and specs for ERP hardening progress

### ⚙️ Miscellaneous Tasks

- Update package versions and submodule references
- *(deps)* Bump symfony/process from 7.4.3 to 7.4.5
- Update composer.lock with new package versions and dependencies
- Remove CLAUDE.md and update dependencies
- Update submodule references and composer.lock content-hash
- *(deps)* Bump psy/psysh from 0.12.18 to 0.12.19
- Add Laravel Boost guidelines and update dependencies
- Update submodule references for Cms and Core modules
- Update dependencies and improve component functionality
- Update Core submodule and add pagination component
- Remove IDE helper files and update .gitignore
- Update Cms submodule to latest commit
- Enhance testing setup and update dependencies
- Update submodule references for AI, Cms, and Core modules
- Update filament packages and submodule references
- *(deps)* Bump league/commonmark from 2.8.0 to 2.8.1
- Update phpunit configuration and Core submodule reference
- Update module activators and testing configuration
- Update dependencies and add sidebar scroll functionality
- Update database configuration for read/write semantics
- Enhance coding principles documentation
- Update composer dependencies and configuration settings
- Update filament packages to version 5.3.5 and enhance documentation
- *(deps)* Bump league/commonmark from 2.8.1 to 2.8.2
- Update dependencies and enhance performance optimizations
- Update dependencies and submodule references
- Add new CRM module and update dependencies
- *(deps-dev)* Bump vite from 6.4.1 to 6.4.2
- Update module structure and dependencies
- *(deps-dev)* Bump axios from 1.13.6 to 1.15.0
- *(deps-dev)* Bump follow-redirects from 1.15.11 to 1.16.0
- Update submodule references and enhance seeder functionality
- Add Business module as a subproject
- Update .env.example and submodule references
- Update .gitignore, add cursor settings, and update submodule references
- Bump submodules to B0+B1 milestones and sync ERP plan
- Bump Business submodule to B2 (M0-ERP) and sync plan
- *(business)* Bump submodule for B3a (CoA and fiscal periods)
- *(business)* Bump submodule for B3b (sequences and journal)
- *(business)* Bump submodule — M1 journal and sequences closed
- Bump Business submodule and update Nebula ERP plan
- *(business)* Bump submodule for Filament accounting domain
- Bump ERP and Core submodules for CRM and factory fix
- *(erp)* Bump ERP submodule for CRM, SO, and M3 foundation
- Update dependencies and enhance test configurations
- Add MES module and update configurations
- Update submodule references and clean up package-lock.json
- Add MES requirements revision document
- Update ERP submodule and development plan for M3.5-M5
- Update submodule references for Core and ERP modules
- Add cache duration registry to configuration
- Update dependencies and refine documentation
- *(plans)* Add initial ecommerce module development plan
- *(plans)* Mark ecommerce module development plan as completed
- Update dependencies and add bugfix for HasValidations trait
- Complete performance optimization tasks in documentation
- Update submodule references for AI, CMS, Core, and ERP modules
- *(plans)* Update ecommerce module embryo plan with architectural decisions and todos
- Update dependencies and enhance rich editor functionality
- Update environment and configuration files for MES module integration
- Update submodule references and Swagger documentation for AI, CMS, Core, ERP, and MES modules
- Update submodule references and composer.lock for module dependencies
- Update module versions and Swagger documentation for testing environment
- Add .cursorignore and AGENTS.md, update package-lock.json, and clean up project structure
- Reorganize testing structure and update module configurations
- *(core)* Bump Core module for GELF logging improvements
- Update .env.example and composer.lock for module dependencies
- *(deps)* Bump symfony/html-sanitizer from 8.0.8 to 8.0.13
- *(deps)* Bump symfony/cache from 8.0.10 to 8.0.13
- *(deps)* Bump symfony/http-kernel from 7.4.11 to 7.4.13
- *(deps)* Bump symfony/routing from 7.4.9 to 7.4.13
- *(deps)* Bump symfony/mime from 7.4.9 to 7.4.13
- *(deps)* Bump symfony/mailer from 7.4.8 to 7.4.12
- *(deps-dev)* Bump symfony/yaml from 7.4.11 to 8.0.13
- Update composer.lock and package-lock.json for new dependencies
- Update submodule references and API documentation
- *(deps)* Bump shell-quote and concurrently
- *(erp)* Record returns follow-up implementation
- *(erp)* Update tracing cast submodule pointer
- *(erp)* Update ERP submodule pointer to latest commit
- *(deps-dev)* Bump form-data from 4.0.5 to 4.0.6
- *(deps)* Bump phpseclib/phpseclib from 3.0.53 to 3.0.55
- *(deps)* Update dependencies in composer.lock and package-lock.json
- Bump Core/CMS/AI/ERP for trait Concerns refactor
- *(deps)* Update dependencies and implement MES module tasks
- Update submodule references for AI, CMS, and ERP modules
- *(erp)* Record accounting golden masters
- *(erp)* Record inventory accounting golden masters
- *(erp)* Record vat settlement confirmation guards
- *(erp)* Record document numbering concurrency guard
- Bump module references and align app bootstrap config
- Sync Core, AI, and CMS submodule references
- *(erp)* Record trial balance csv export
- Record shared csv exporter
- Sync module releases and ERP hardening progress
- Update Core module release
- Sync submodule references after PHPDoc formatting cleanup
- Update submodule references for AI and CMS modules

## [1.11.2] - 2026-01-22

### ⚙️ Miscellaneous Tasks

- Update submodule references and enhance Swagger documentation
- Enhance README and update submodule references
- Update submodule references for AI, Cms, and Core modules to latest commits
- Update package versions and submodule references

## [1.11.1] - 2026-01-15

### 💼 Other

- Create laravel.yml

### ⚙️ Miscellaneous Tasks

- Update dependencies and enhance functionality
- Update package-lock.json and enhance CSS styles
- Update IDE helper models and improve documentation
- Update .vscode settings and submodule commits
- Update composer.json and submodule commits
- Update submodule commits and improve version script
- Update submodule commits and enhance version script validation
- Update versioning method in composer.json
- Update submodule commits and enhance version script logic
- Update submodule commits and refine version script logic
- Update submodule commits and enhance version script functionality
- Update submodule commits and enhance version script debugging
- Update submodule commits for Cms and Core modules
- Clean up post-commit hook script
- Update dependencies and remove unused package
- Update dependencies and submodule commits
- Update project configuration and dependencies
- Code lint with pint and rector
- Update coding principles and module structure
- Update submodule URLs to use HTTPS
- Update module links in README
- Update IDE helper and module configurations
- Update IDE helper models and package dependencies
- Update environment configurations and dependencies
- Update dependencies and improve package configurations
- Update IDE helper files and improve cache functionality
- Enhance IDE helper files and add new traits
- Update Cms submodule and add pushurl synchronization script
- Update submodule commits and improve type hinting
- Enhance IDE helper files and update submodule references
- Enhance IDE helper traits and update PHPStan configuration
- Update Laravel standards and enhance test case structure
- Update environment and configuration files for PHP 8.5 compatibility
- Update Laravel standards and enhance performance optimization guidelines
- Enhance IDE helper models and update workspace configuration
- Update dependencies and enhance configuration files
- Enhance IDE helper models and update submodule references
- Update commit parsing rules and enhance version update script
- Update submodule references for Cms and Core modules
- Update commit parsing rules and submodule references
- Update submodule references for Cms and Core modules to latest commits
- Update IDE helper models and configuration files
- Update environment variable names and package versions
- Update package versions in composer.lock and submodule reference for Core module
- Update IDE helper models, environment variables, and package versions
- Add filament/spatie-laravel-media-library-plugin and update package versions
- Add AI module and update submodule references
- Update composer.json and submodule references for AI, Cms, and Core modules

## [1.11.0] - 2025-09-19

### ⚙️ Miscellaneous Tasks

- Update dependencies and enhance configurations

## [1.10.0] - 2025-09-05

### ⚙️ Miscellaneous Tasks

- Update model properties and enhance CSS styles
- Update configurations, remove outdated tests, and enhance file structure

## [1.9.0] - 2025-08-18

### 💼 Other

- Update Core submodule with README documentation
- Update CMS submodule with README documentation

### ⚙️ Miscellaneous Tasks

- Update dependencies and submodules
- Update composer.json and submodule commits
- Update submodule commits and version script
- Update submodule commits for Cms and Core modules
- Update dependencies, submodules, and configuration files
- Remove outdated configuration and rules files
- Update VSCode configuration files and clean up .gitignore
- Update dependencies, submodules, and Swagger documentation
- Update package versions in composer.lock and package-lock.json
- Update model properties and configurations

## [1.8.2] - 2025-06-23

### ⚙️ Miscellaneous Tasks

- Update composer.lock and CSS/JS dependencies

## [1.8.1] - 2025-06-13

### ⚙️ Miscellaneous Tasks

- Update IDE helper models with new properties

## [1.7.0] - 2025-06-13

### ⚙️ Miscellaneous Tasks

- Update submodule commits for Cms and Core modules
- Update Core module submodule commit
- Update rector configuration and Laravel set list
- Update IDE helper files and improve type hinting
- Enhance IDE helper and logging configuration
- Update composer scripts and improve versioning process
- Update IDE helper models and enhance type hinting
- Update testing framework reference in Laravel best practices
- Enhance Filament integration and update resource tests
- Update IDE helper models and enhance resource tests
- Update IDE helper models and enhance configuration

### ◀️ Revert

- Update IDE helper and remove Filament authentication components

## [1.6.6] - 2025-05-05

### ⚙️ Miscellaneous Tasks

- Update IDE helper models and bump Laravel version
- Update IDE helper models and improve type hinting
- Update IDE helper models and configuration files
- Update IDE helper and configuration files
- Enhance IDE support and update configurations
- Update IDE helper models and improve class definitions

## [1.6.5] - 2025-04-07

### ⚙️ Miscellaneous Tasks

- Update submodule commits for Cms and Core modules

## [1.6.3] - 2025-04-06

### ⚙️ Miscellaneous Tasks

- Update IDE helper models and bump Laravel version

## [1.6.1] - 2025-03-31

### ⚙️ Miscellaneous Tasks

- Update IDE helper models

## [1.5.3] - 2025-03-20

### ⚙️ Miscellaneous Tasks

- Update submodule commits for Cms and Core modules

## [1.5.2] - 2025-03-11

### ⚙️ Miscellaneous Tasks

- Update IDE helper models and environment configuration

## [1.5.1] - 2025-03-07

### ⚙️ Miscellaneous Tasks

- Add Swagger documentation for App, Cms, and Core modules

## [1.5.0] - 2025-03-07

### 💼 Other

- Add Laravel 12 support

### ⚙️ Miscellaneous Tasks

- Update project dependencies and configuration files

## [1.4.0] - 2025-03-03

### 🚀 Features

- Add script for comprehensive Composer dependency updates across modules

## [1.3.0] - 2025-03-03

### 🚜 Refactor

- Modernize PHP code and update project configuration

## [1.2.1] - 2025-02-26

### ⚙️ Miscellaneous Tasks

- Update Laravel and dependencies to latest versions

## [1.2.0] - 2025-02-19

### ⚙️ Miscellaneous Tasks

- Update Prettier configuration and upgrade project dependencies
- Update IDE helper models with generic collection type hints

## [1.1.1] - 2025-02-04

### 🚀 Features

- *(swagger)* Expand Core module API documentation with comprehensive routes

## [1.1.0] - 2025-01-29

### 🚀 Features

- *(swagger)* Enhance API documentation with module-specific tags

## [1.0.0] - 2025-01-25

### 🚀 Features

- *(cache)* Imbastite classi cache
- *(inpsector)* Imbastite classi inspector
- *(locking)* Imbastite classi locking
- *(database)* Imbastiti migrations, factories, seeders
- *(traduzioni)* Imbastite traduzioni
- *(env)* Imbastiti env files
- *(fortify)* Imbastite classi fortify
- *(configs)* Imbastiti config files
- *(database)* Imbastite base migrations
- *(commands)* Imbastiti comandi modulo Core
- *(helpers)* Imbastiti helpers modulo Core
- *(app)* Imbastita app base
- *(routes)* Imbastite rotte modulo Core
- *(griglie)* Imbastite classi griglie
- *(config)* Imbastiti Core config
- *(core)* Imbastito modulo core
- *(logs)* Imbastito log viewer
- *(core)* Correzioni rotte, introduzione Gelf logger, personalizzazione rotte fortify
- *(gelf)* Gelf logger custom classes
- *(elasticsearch)* Bozza search route con elastisearch e vector search
- *(elasticsearch)* Mappati operatori where elasticsearch
- *(swagger)* Working on swagger doc generation
- *(cms)* Definizioni modelli cms
- *(cms)* WIP aggiunta relazione belongsToMany con chiavi multiple
- *(vue)* Riaggiunte dipendenze per generazione frontend e viste
- *(scripts)* Enhance version management script

### 🐛 Bug Fixes

- *(versions)* Fixed models versioning configurations
- *(responsebuilder)* Modificato ResponseBuilder per permettere serializzazione in Cache
- *(crud)* Fixed group_by names in crud operations

### 💼 Other

- *(dependencies)* Updated dependencies
- Introduzione modulo cms
- Definizione tabelle e seeders
- Rinominato module cms
- Seeders cms
- Cms factories
- Contenuti
- Cursorrules
- Splitting project into submodules

### 🚜 Refactor

- *(versioning)* Commit prima di rollback a overtrue/laravel-versionable
- *(various)* Work in progress refactoring from L10 to L11
- *(grids)* Dynamic GridUtils injection
- *(crud)* Working on CrudController refactoring
- *(core)* Moved dependencies and classes to Core module
- *(grids)* Working on grids functionalities
- *(modules)* Code adapting for new modules package version
- *(dpeendencies)* Rimosse dipendenze frontend
- *(cms)* Standardize naming conventions, enhance model definitions, and implement new features
- *(cms)* Enhance content retrieval and module configuration

### 📚 Documentation

- *(phpdoc)* Documentazione per phpstan

### ⚡ Performance

- *(cache)* DynamicEntity unmapped models caching for better performances

### 🧪 Testing

- *(pest)* Sostituito phpunit con pest

### ⚙️ Miscellaneous Tasks

- *(references)* References inf oand icons
- *(dependencies)* Removed unused configurations for old dependencies
- *(classes)* Strict type added to main classes
- *(typechecking)* Type cheking with php-stan
- Update IDE helper files, configuration, and dependencies
- Add Core and Cms modules as git submodules
- Add version management and git hooks scripts

<!-- generated by git-cliff -->
