<?php

declare(strict_types=1);

it('points the Elasticsearch client of the tests at an address that is not a real server', function (): void {
    $hosts = config('elastic.client.connections.default.hosts');

    expect($hosts)->toBe(['http://127.0.0.1:9'])
        ->and(config('elastic.client.connections.default.retries'))->toBe(0);
});
