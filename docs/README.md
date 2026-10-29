# Repository documentation conventions

This convention separates **general** documentation from documentation that should be **indexed for RAG**.

## Rules

- General repository documentation lives under `docs/`.
- Material that the application RAG should ingest lives under `docs/rag/`.
- Each module keeps its own documentation under `Modules/{ModuleName}/docs/`.
- Module RAG material lives under `Modules/{ModuleName}/docs/rag/`.
- `resources/` is not used for product documentation (Laravel assets only).

## Automatic indexing (`ai:index-rag-docs` without `--path`)

Roots are resolved by the `rag_paths()` helper (see `Modules/AI/app/Helpers/helpers.php`). In short:

- Native Laraplate modules (same Composer `vendor` as the AI module): only `docs/rag` under that module.
- The main application and third-party modules: `docs/rag` plus any relative subpaths from `AI_FAQ_DOCS_PATH` / `ai.features.faq.documentation_path`.
- Absolute custom paths are added only when they exist and do not point inside a native Laraplate module directory.

This avoids accidentally indexing every Markdown file under `docs/` that was never meant for RAG.

## Recommended workflow

- Write human-readable docs in `docs/` or `Modules/*/docs/`.
- Copy or move into `*/docs/rag/` only what you want the assistant to see.
- Run `php artisan ai:index-rag-docs` (or `--full` when you need a full rebuild of the vector store).

## Engineering audits

- [Database connection affinity audit](database-connection-affinity-audit.md)

## Operations

- [Releasing (contributor notes; releases are cut by the maintainers)](releasing.md)
