<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Router;

/**
 * Laravel 13 renamed the CSRF middleware to PreventRequestForgery and kept VerifyCsrfToken and
 * ValidateCsrfToken as deprecated subclasses of it.
 *
 * The aliases are not interchangeable everywhere: group removal and the panel's middleware list compare class
 * strings, so a group built from one name and filtered by the other silently does nothing. These cases pin the
 * canonical name in every group this application assembles by hand.
 *
 * @return list<string>
 */
function middlewareGroup(string $group): array
{
    return array_values(array_filter(
        app(Router::class)->getMiddlewareGroups()[$group] ?? [],
        'is_string',
    ));
}

it('protects the web group against request forgery', function (): void {
    expect(middlewareGroup('web'))
        ->toContain(PreventRequestForgery::class)
        ->not->toContain(VerifyCsrfToken::class)
        ->not->toContain(ValidateCsrfToken::class);
});

it('protects the auth group against request forgery', function (): void {
    expect(middlewareGroup('auth'))
        ->toContain(PreventRequestForgery::class)
        ->not->toContain(VerifyCsrfToken::class)
        ->not->toContain(ValidateCsrfToken::class);
});

it('points sanctum at the canonical forgery middleware', function (): void {
    // Sanctum reads this class name to decide which middleware to swap out of the stateful stack. Leaving a
    // deprecated alias here means the swap targets a class the group does not contain.
    expect(config('sanctum.middleware.validate_csrf_token'))
        ->toBe(PreventRequestForgery::class);
});

it('disables request forgery protection for test requests', function (): void {
    // TestCase::setUp() calls withoutMiddleware(), which binds that exact class name to a passthrough
    // instance. The groups resolve PreventRequestForgery, so naming a deprecated alias there, as this suite
    // did on Laravel 12, would leave every POST in the suite checked for a token no test request carries.
    expect(app(PreventRequestForgery::class))
        ->not->toBeInstanceOf(PreventRequestForgery::class);
});
