<p>&nbsp;</p>
<p align="center">
	<a href="https://github.com/getlaraplate" target="_blank">
		<img src="https://raw.githubusercontent.com/getlaraplate/brand/main/logos/blueprint/laraplate.svg" width="160" alt="Laraplate" />
    </a>
</p>
<p>&nbsp;</p>
<p align="center">
    <img src="https://img.shields.io/badge/Laravel-12.x-FF2D20?style=for-the-badge&logo=laravel&logoColor=white" alt="Laravel 12">
    <img src="https://img.shields.io/badge/PHP-8.5+-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP 8.5+">
    <img src="https://img.shields.io/badge/License-GNU_AGPL_v3-green?style=for-the-badge" alt="GNU AGPL v3 License">
</p>
<p>&nbsp;</p>

> ⚠️ **Caution**: This package is a **work in progress**. **Don't use this in production or use at your own risk**—no guarantees are provided... or better yet, collaborate with me to create the definitive Laravel boilerplate; that's the right place to introduce your ideas. Let me know your ideas...

## About Laraplate

### Requirements

-   PHP >= 8.5
-   Laravel 12.x
-   Composer merge-plugin for modular loading (`composer.*.json`, `Modules/*/composer.json`)
-   Node.js for Vite build (`npm run dev` / `npm run build`)

### Core Dependencies (project root)

-   `laravel/framework` ^12
-   `laravel/sanctum` ^4 for API auth
-   `nwidart/laravel-modules` ^12 (<12.0.4) + `coolsam/modules` + `joshbrw/laravel-module-installer` for modular structure
-   `spatie/fork` for parallel tasks
-   `symfony/http-client` for HTTP integrations

### Install

```sh
# vendor packages
composer install

# db prepare
php artisan migrate:fresh
php artisan db:seed
php artisan module:seed --all
```

### Laraplate Modules

-   ⚡ **Core**: main common boilerplate functionalities. About [Core Module](https://github.com/getlaraplate/laracore).
-   ✎ **CMS**: common content management functionalities. About [CMS module](https://github.com/getlaraplate/laracms).
-   ✨ **AI**: artificial intelligence capabilities (embeddings, vector search, automatic translation). About [AI Module](https://github.com/getlaraplate/laraai).
-   📊 **ERP**: accounting and operations (multi-company, journal entries, tax codes, commercial scaffolding). About [ERP Module](https://github.com/getlaraplate/laraerp).
-   🏭 **MES**: manufacturing execution foundation (work orders, shop-floor events, traceability, KPIs). About [MES Module](https://github.com/getlaraplate/larames).
-   🔗 **SAO**: _Simply Another Orchestrator_ — a correlation engine between code, errors and work; a standalone ticketing core with optional VCS, log-source and issue-tracker integrations. About [SAO Module](https://github.com/getlaraplate/larasao).

Each module owns its backoffice presence: a `Modules/{Name}/app/Filament/{Name}Plugin.php`
class is auto-discovered by `coolsam/modules` and registers the module's resources, pages
and widgets, plus its own navigation group and icon (`afterRegister()`). The admin panel
provider therefore lists no module: it only declares the application's own groups.

### Environment (main variables)

-   `APP_*`: environment, URL, port, localization, logo.
-   `DB_*`: connection (default `pgsql`), host/port, credentials.
-   `SESSION_*`: driver (default `redis`), lifetime and domain.
-   `TRUSTED_PROXIES`: comma-separated list of proxy IPs trusted for the `X-Forwarded-*` headers (default `127.0.0.1,::1`). Required when the app runs behind a reverse proxy (nginx in front of FrankenPHP/PHP-FPM), otherwise the app does not recognise the original HTTPS scheme and generates `http` URLs that the browser blocks as mixed content. Use `*` to trust the calling IP.
-   `CACHE_STORE`, `CACHE_PREFIX`, `QUEUE_CONNECTION`, `FILESYSTEM_DISK`, `BROADCAST_CONNECTION`: default stores/drivers (redis/redis/local/log).
-   `REDIS_*`, `MEMCACHED_HOST`: cache/session configuration.
-   `LOG_*`: log channel/stack/level.
-   `MAIL_*`, `AWS_*`: mail and S3 (commented out by default).
-   `ELASTIC_*`, `SCOUT_DRIVER`, `SCOUT_QUEUE`: advanced search (`elasticsearch` driver, queued indexing).
-   `OPENAI_API_KEY`: AI key (commented out).
-   Core env toggles: `FORCE_HTTPS`. User, CRUD and retention toggles (`auth.email_verification.enabled`, `auth.registration.enabled`, `auth.two_factor.enabled`, `auth.licenses.enabled`, `auth.social_login.enabled`, `crud.dynamic_entities`, `expose_api` (gates every `/api` route, Core and modules), `soft_deletes.expiration_days`) are runtime settings managed from Filament > Settings. Settings are listed without the module prefix (the module is a column) and read from config as `core.<name>`.
-   Vector search has no Core setting. The single switch is `features.embeddings.enabled` in the AI module (read as `ai.features.embeddings.enabled`); the model whose vectors the indexes hold is `features.embeddings.serving_model`. Core asks the AI module for the vector profile (model, dimensions, similarity) and runs keyword search when there is none, for instance without the AI module.

### Deprecations

-   **`app/crud/grid/*` (the Grid surface) was removed** in Core 1.75.0. Its Funnels concept survives as **facets** on the ordinary CRUD routes, served by `Modules\Core\Services\Crud\CrudService`.

<br>
<br>

<p align="center">
	<a href="https://laravel.com" target="_blank">
		<img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo">
	</a>
</p>

## About Laravel

### Framework

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

-   [Simple, fast routing engine](https://laravel.com/docs/routing).
-   [Powerful dependency injection container](https://laravel.com/docs/container).
-   Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
-   Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
-   Database agnostic [schema migrations](https://laravel.com/docs/migrations).
-   [Robust background job processing](https://laravel.com/docs/queues).
-   [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

### Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

You may also try the [Laravel Bootcamp](https://bootcamp.laravel.com), where you will be guided through building a modern Laravel application from scratch.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## License

Laraplate and its modules are free software, licensed under the
[GNU AGPL v3](https://www.gnu.org/licenses/agpl-3.0.html). The full licence text is in [LICENSE](LICENSE).

Laraplate is dual licensed: a commercial license is available to organisations that cannot accept
the AGPL obligations. The AGPL edition is the project: every release is published under the AGPL
at the same time and with the same code as any commercial release, with no commercial-only
features. See [our licensing commitment](https://github.com/getlaraplate/.github/blob/main/CONTRIBUTING.md#our-licensing-commitment).

Copyright (c) 2025 swolley

## TODO and FIXME

For a complete and detailed list of TODOs, read:
- [Core Module TODO](Modules/Core/README.md#todo-and-fixme)
- [CMS Module TODO](Modules/CMS/README.md#todo-and-fixme)
- [AI Module TODO](Modules/AI/README.md#todo-and-fixme)
- [ERP Module TODO](Modules/ERP/README.md#todo)
- [MES Module Roadmap](Modules/MES/README.md#roadmap)
- [SAO Module Roadmap](Modules/SAO/README.md#roadmap)