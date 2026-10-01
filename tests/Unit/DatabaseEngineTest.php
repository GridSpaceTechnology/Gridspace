<?php

use Tests\TestCase;

uses(TestCase::class);

test('mysql tables use innodb so utf8mb4 unique indexes fit', function () {
    expect(config('database.connections.mysql.engine'))->toBe('InnoDB');
});
