# User timezone and common date presentation

**Status:** Draft
**Date:** 2026-11-27
**Scope:** Core date formatting for Filament, Blade, notifications, exports and the API contract
**Chosen approach:** One Core formatter and a per-request timezone context, mirroring `LocaleContext`

## Problem

Dates are stored and exchanged without one agreed rule, and nothing shows them in the reader's timezone.

- `config('app.timezone')` is `UTC` by default and is the frame Eloquent reads and writes in. Nothing in `app` or `Modules/*/app` calls `->timezone(`.
- Filament tables and infolists call `->dateTime()` directly, for example `ImportSessionsTable.php` and `MediaTable.php`.
- `RecordOrigin` converts its UTC value to `config('app.timezone')` on read, so its display frame is the storage frame.
- The user has `lang` and `LocalizationMiddleware` applies it per request. There is no timezone equivalent. A guest has no profile at all.

A reader in Los Angeles and a reader in Rome should each see their own local time, and every other surface that carries a date must say the same thing the same way.

## Goals

1. One contract for dates outside the database. Every HTTP route and webhook speaks UTC in both directions. Responses carry ISO 8601 in UTC with `Z`. Request bodies and query filters carrying an instant are normalized to UTC before they reach a service: a value with an offset is converted, a value without an offset is read as UTC. Display on human surfaces uses the reader's timezone.
2. One Core formatter used by Filament, Blade, notifications, mail and exports. No surface formats a date by itself.
3. A reader's timezone is an IANA identifier, for example `America/New_York`. It is never an offset and never derived from a country or from `lang`.
4. Guests are supported. Their timezone comes from the browser and is kept in a cookie, not in the database.
5. Registered users get `users.timezone`. It is filled once, from the browser, at first login, and the user can change it. A value the user chose is never overwritten.
6. A default timezone exists as the Core setting `core.default_timezone`, the same shape as `core.default_language`. UTC applies when the setting is empty or invalid.
7. A date typed by a user in a form is read in that user's timezone and stored in UTC.

## Non-goals

- No environment variable for the default timezone.
- No change to `APP_TIMEZONE`, which stays the storage frame.
- No display in the timezone of the event's location. The formatter accepts an explicit timezone so a later change can add it.
- No change to how `lang` is chosen. Saving `lang` at first login is not part of this work.
- No change of the MySQL session timezone. That is tracked separately.

## Anti-goals

- Dates stored in a per-user timezone.
- A surface that shows a date without a timezone label where the reader's zone is unknown.
- A selector that asks every user to configure a timezone before using the product.

## Resolution order

For one request or job the display timezone is the first of:

1. `users.timezone`, when the user is authenticated and the value is set.
2. The browser timezone from the timezone cookie, for guests and for users without a stored value.
3. The Core setting `core.default_timezone`.
4. `UTC`.

The result is held in a request-scoped `TimezoneContext`, the same pattern as `LocaleContext` and `ContentLocale`. Queued jobs, mail and notifications read the timezone of the user they address. When there is no user they use steps 3 and 4 and print the timezone abbreviation next to the time.

## Parts

**Formatter.** A Core class with two operations. One returns the wire form: ISO 8601 UTC with `Z`. The other returns the display form for a value, taking the timezone from `TimezoneContext` unless an explicit one is passed, and the format from the current language.

**Timezone context and middleware.** A middleware next to `LocalizationMiddleware` fills the context from the order above. It runs in the same middleware groups.

**Browser timezone.** A small script reads `Intl.DateTimeFormat().resolvedOptions().timeZone` and stores it in a cookie when the value is a valid IANA identifier. The server validates the cookie value against `DateTimeZone::listIdentifiers()` and ignores anything else.

**First login.** When an authenticated request has a valid browser timezone and `users.timezone` is empty, the value is saved once. The user can edit it in the profile.

**Filament.** The global configuration of date columns, entries and date pickers routes through the formatter, so existing `->dateTime()` calls keep working without edits. Filament form input is converted from the user's timezone to UTC before save.

**HTML output.** Server-rendered dates are written as `<time datetime="...Z">` with fallback text. The script upgrades them to the reader's timezone, which covers the first visit of a guest before the cookie exists.

**API.** Resources return the wire form. Clients convert. The profile resource exposes `timezone` so clients can start from it. Incoming instants, in bodies and in query filters such as a range on `created_at`, pass through one normalizer that converts to UTC. Date-only values, such as a birthday or a daily period start, are not instants: they are neither converted nor given a time. An architecture test forbids formatting dates by hand in API resources.

**Setting and column.** A migration adds nullable `users.timezone`. The `core.default_timezone` setting follows the existing `core.default_language` registration, validated against the IANA list.

## Constraints

- Core stays generic, stable and backward friendly. Existing `->dateTime()` calls and existing API consumers must keep working. The API change is to guarantee `Z` and UTC where it is not already so.
- Multi-database rules apply to the migration: it must run on SQLite.
- Every PHP file declares strict types and the type coverage gate stays at 100.

## Approaches considered

1. **Central formatter and request context, chosen.** One class, one context, one middleware. Every surface calls the same code.
2. **Per-surface rules.** Filament sets its timezone, the API keeps Eloquent serialization and mail converts alone. Faster to write, but the surfaces drift apart, which is the failure this work exists to prevent.
3. **Store dates in the user's timezone.** Rejected. The same data would carry different values per user and comparisons would break.

Browser-only without a profile column was also considered. It cannot serve mail, notifications, queued jobs and exports, which run without a browser, so the stored value is kept as an optional override filled once.

## Left to the builder

- Verify in the installed Laravel 13 source that Eloquent date serialization already produces UTC with `Z`, and add a test that pins it.
- Find in Filament 5 the supported global timezone and date configuration. Do not assume names from older versions.
- Check how `CrudController` and the filter layer parse date values today, and keep existing clients that send a value without an offset working.
- Decide the exact class names and the cookie name, following sibling files.
- Decide the display formats per language and per style: date, date and time, relative.
- Decide where the browser script is shipped, given there is no Blade frontend in this repository.
- Decide how the profile exposes the timezone selector in the Filament user resource.
- Write the tests: resolution order, invalid cookie, first-login save without overwrite, input conversion, wire form, a value inside a daylight saving gap.
