<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Text outside Latin-1 has to survive a write and a read through both a varchar and a clob column. On Db2 for i that
| holds only when the columns are created in UTF-8 (the db2 connection's ccsid, 1208): a column left to the job's
| EBCDIC CCSID turns the characters it cannot hold into substitution characters. Bare test case, no wrapping transaction.
*/
it('round-trips non Latin-1 text through a varchar and a text column', function (): void {
    $table = 'db2_unicode_probe';
    $text = 'città €✓ 日本';
    Schema::dropIfExists($table);
    Schema::create($table, static function (Blueprint $table): void {
        $table->id();
        $table->string('title', 64);
        $table->text('body');
    });

    try {
        $connection = DB::connection();
        $connection->table($table)->insert(['title' => $text, 'body' => $text]);
        $row = $connection->table($table)->first();

        expect($row->title)->toBe($text)
            ->and($row->body)->toBe($text);
    } finally {
        Schema::dropIfExists($table);
    }
})->group('db2');
