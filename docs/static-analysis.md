# Static analysis

PHPStan runs at level 9 with Larastan over the application and every module (`phpstan.neon` at the
repository root; module test suites and `Modules/*/stubs` are excluded, see the comments there).

| Command | What it answers |
|---|---|
| `composer check` | Did this change add a report? `[OK]` means nothing new beyond the baseline. |
| `composer check:debt` | How much is left: the same analysis without the baseline, with a breakdown by identifier. |
| `composer test:type-coverage` | Is every parameter, return and property typed? A gate at 100%, not a report. |

Compare two runs report by report (file, line, identifier, message), never by the total: a fix and a
new defect cancel out in a count. A pull request that touches the analysis lists what disappeared and
what appeared.

## The baseline

`phpstan-baseline.neon` freezes the reports that existed on 2026-09-17, so a new one fails the run. It
is debt made visible, not a list of things that are fine. Nothing is ever added to it: a new report is
fixed in the code. When a fix makes entries stale, remove exactly those (run once with
`reportUnmatchedIgnoredErrors: true` in a probe config to list them) and leave every other line as it is.

## Models declare their columns

Every model carries, in its own class docblock, an `@property` tag for each column of its table
(`@property-read` for a column the model never writes), with the type the column has once cast:

```php
/**
 * The subject of every commercial relationship.
 *
 * @property int $id
 * @property string $name
 * @property CounterpartyKind $kind
 * @property bool $is_customer
 * @property \Carbon\CarbonImmutable|null $deleted_at
 */
final class Counterparty extends Model
```

Relations, relation counts and accessors are not declared: Larastan reads them from the methods. Two
kinds of attribute the model reads from another model are declared like columns, so their reads are
typed too: the translatable fields of an `ITranslatableModel` (`getTranslatableFields()`, read from the
translation row), and the attributes ERP's `ProxiesCounterparty` and `ProxiesCounterpartyBankAccount`
delegate (`counterpartyAttributes()`, `bankAccountAttributes()`). An attribute a trait routes to
another model for every class that uses it is declared on the trait itself, in its docblock:
`ProxiesCounterparty` (the counterparty identity), `ProxiesCounterpartyBankAccount` (the account) and
Core's `HasPlace` (`geolocation` and the address of the place). A table shared by several models (`Taxonomy` and its subclasses, `Entity`,
`Preset`, `Presettable`) is declared once, on the topmost model that owns it, and inherited.

Why: Larastan cannot read the schema from the migrations, which name tables through enums and build
columns through helpers. With the tags in place, `checkModelProperties` is on, so a column name passed
as a string to `where()`, `pluck()`, `forceFill()`, `create()` or `update()` is checked against the
columns the model declares, and a column read off a model is typed.

**Arrays of attributes.** `create()`, `fill()`, `forceFill()`, `update()` and the relation
equivalents take `array<model-property<TModel>, mixed>`. Larastan accepts an array whose keys are
literal column names, and one whose keys are only `string` (a form's `$data`, passed through as it
is). It rejects the two mixed: `$data['reference'] = ...` or `[...$line, 'line_no' => 1]` added to
an untyped array. Build the array from a literal list of keys instead (ERP's
`LineAttributes::only($line, ['item_id', 'quantity'])`), or set the extra attribute on the model
after `make()`. A selected alias (`selectRaw('SUM(qty) as qty_sum')`) is not a property: read it
with `getAttribute('qty_sum')` and narrow the value. A pivot is a relation: read it with
`getRelation('pivot')` and an `instanceof` check, as `Field::presetPivot()` and
`DeliveryNoteLine::invoiceLink()` do.

**Writing the tags.** Core's `php artisan models:declare-columns --module=<Name>` writes the missing
tags from the development database (it runs `ide-helper:models --nowrite`, or reads `--from=<file>`;
`--dry-run` prints them). It keeps every existing docblock line and every tag written by hand, so it is
safe to run again after a migration adds a column. Read the diff: from then on the tags are maintained
by hand, with the migrations. Do not use `ide-helper:models --write`, which regenerates the whole
docblock and loses human comments; `--nowrite` keeps the gitignored `_ide_helper_models.php` for the IDE.

**The drift test.** `tests/Feature/ModelsDeclareTheirColumnsTest.php` compares, for every model under
`app/Models` and `Modules/*/app/Models`, the columns of its table (as `RefreshDatabase` migrates them)
with the tags of the class and its parents. It fails naming the model, the table and the column that
has no tag (as `table.column`), and for a tag that names neither a column, a relation, an accessor nor
one of the attributes read from another model. A column that exists
on one database driver only is listed by name in `tests/Support/ModelColumnTags.php`
(`DRIVER_SPECIFIC_COLUMNS`) with its reason.

## Trait contracts

Three Core traits require an interface through `@phpstan-require-implements`, and a model that uses the
trait declares it:

| Trait | Contract |
|---|---|
| `HasTranslations` | `ITranslatableModel` |
| `HasVersions` | `IVersionedModel` |
| `Search\Traits\Searchable` | `ISearchableModel` (extends `IEmbeddableModel`) |

Generic code asks for the contract (`$owner instanceof IVersionedModel`,
`class-string<Model&ISearchableModel>`, `is_a($class, ISearchableModel::class, true)`) instead of probing
with `method_exists()`. The app tests `TranslatableModelsImplementTheContractTest`,
`VersionedModelsImplementTheContractTest` and `SearchableModelsImplementTheContractTest` fail for a
model that uses a trait without its contract. Details per contract: `Modules/Core/docs/rag/MODULE.md`.

## Rules in `phpstan.neon`

A report is fixed in the code. A rule in `ignoreErrors` is for a check that conflicts with how the code
must be written, never for one that is inconvenient. Each rule:

- names the `identifier`, and a `message` pattern narrow enough that the same identifier elsewhere is
  still reported;
- is limited by `path` or `paths` when the reason holds only there;
- has its reason in a comment right above it: what the check sees, why it is not a defect, and what the
  rule would hide if it were wrong.

Existing examples: the trigger DDL passed to `unprepared()` (migrations and `MigrateUtils`), the raw SQL
of Core's CRUD aggregates (`Services/Crud`), column names passed through a base model class in generic
code (Illuminate's `Model` or Core's `Overrides\Model`, alone or with a contract: Larastan reads no
`@property` tag of an interface, so a contract cannot declare the columns), JSON paths (`meta->key`)
read as column names, and table-qualified columns in the joins of ERP's report services.

A single site that cannot be expressed in types gets an inline `@phpstan-ignore <identifier>` with the
reason on the same line, not a rule. Never widen a type to `mixed`, lower a threshold or exclude a path
to make a report go away.
