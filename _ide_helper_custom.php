<?php

declare(strict_types=1);

// Hand-written PHPStan stub for custom facade macros.
// No ide-helper command generates this file: keep it tracked in git.

namespace Illuminate\Support\Facades {
    /**
     * Laravel Cache Facade with custom extensions.
     *
     * Standard Laravel methods (inherited from base facade):
     *
     * @method static bool supportsTags() Check if the cache driver supports tags
     * @method static \Illuminate\Contracts\Cache\TaggedCache tags(array|string $names) Get a tagged cache instance
     * @method static mixed get(string $key, mixed $default = null) Retrieve an item from the cache
     * @method static bool has(string $key) Check if an item exists in the cache
     * @method static bool put(string $key, mixed $value, \DateTimeInterface|\DateInterval|int|null $ttl = null) Store an item in the cache
     * @method static bool forget(string $key) Remove an item from the cache
     * @method static bool flush() Remove all items from the cache
     * @method static mixed remember(string $key, \DateTimeInterface|\DateInterval|int|null $ttl, \Closure $callback) Get an item from the cache, or execute the given Closure and store the result
     * @method static mixed rememberForever(string $key, \Closure $callback) Get an item from the cache, or execute the given Closure and store the result forever
     *
     * Custom methods (registered via macros):
     * @method static \Illuminate\Contracts\Cache\Repository memo() Get the memoized cache repository
     * @method static void clearByEntity(\Illuminate\Database\Eloquent\Model|string|array<string|object> $entity) Clear cache by the specified entity
     * @method static void clearByUser(\Modules\Core\Models\User $user, \Illuminate\Database\Eloquent\Model|string|array<string|object>|null $entity = null) Clear cache elements of the user, when no entity is given or one of them uses the cache
     * @method static void clearByGroup(\Spatie\Permission\Models\Role $role, \Illuminate\Database\Eloquent\Model|string|array<string|object>|null $entity = null) Clear cache elements by user group and only by entity if specified
     */
    final class Cache {}
}
