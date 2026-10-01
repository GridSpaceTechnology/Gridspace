<?php

/**
 * MySQL limits identifiers (index, unique and foreign key names) to 64
 * characters, while SQLite and PostgreSQL do not. Oversized identifiers
 * therefore pass locally and only break once the migrations reach production,
 * so this test derives the identifier every migration would generate and
 * asserts it stays within MySQL's limit.
 */
const MYSQL_MAX_IDENTIFIER_LENGTH = 64;

/**
 * @return array<int, array{0: string, 1: string}>
 */
function migrationIdentifierNames(): array
{
    $names = [];

    foreach (glob(__DIR__.'/../../database/migrations/*.php') as $file) {
        $contents = (string) file_get_contents($file);

        preg_match_all('/Schema::(?:create|table)\(\s*[\'"]([^\'"]+)[\'"]/', $contents, $tables, PREG_OFFSET_CAPTURE);
        preg_match_all('/\$table->/s', $contents, $statements, PREG_OFFSET_CAPTURE);

        foreach ($statements[0] as [$match, $offset]) {
            $end = strpos($contents, ';', $offset);
            $statement = $end === false ? substr($contents, $offset) : substr($contents, $offset, $end - $offset);

            $table = null;

            foreach ($tables[1] as [$name, $tableOffset]) {
                if ($tableOffset < $offset) {
                    $table = $name;
                }
            }

            if ($table === null) {
                continue;
            }

            $identifier = identifierForStatement($table, $statement);

            if ($identifier !== null) {
                $names[] = [basename($file), $identifier];
            }
        }
    }

    return $names;
}

function identifierForStatement(string $table, string $statement): ?string
{
    $columnPattern = '/^\$table->\w+\(\s*[\'"]([A-Za-z0-9_]+)[\'"]/';

    $patterns = [
        '/->(?:index|unique)\(\s*\[(.*?)\]\s*(?:,\s*[\'"]([^\'"]+)[\'"]\s*)?\)/s',
        '/->(?:index|unique)\(\s*([A-Za-z0-9_]+)\s*(?:,\s*[\'"]([^\'"]+)[\'"]\s*)?\)/s',
        $columnPattern,
        '/->foreign\(\s*[\'"]([A-Za-z0-9_]+)[\'"]/s',
    ];

    foreach ($patterns as $index => $pattern) {
        if (preg_match($pattern, $statement, $matches) !== 1) {
            continue;
        }

        if ($index === 2) {
            if (preg_match('/->(index|unique)\(\s*\)/', $statement, $chained) === 1) {
                return $table.'_'.$matches[1].'_'.$chained[1];
            }

            return str_contains($statement, '->constrained(') ? $table.'_'.$matches[1].'_foreign' : null;
        }

        if ($index === 3) {
            return $table.'_'.$matches[1].'_foreign';
        }

        if (($matches[2] ?? '') !== '') {
            return $matches[2];
        }

        $columns = array_map(
            fn (string $column): string => trim(trim($column), '\'" '),
            explode(',', $matches[1])
        );

        $kind = str_contains($matches[0], 'unique') ? 'unique' : 'index';

        return $table.'_'.implode('_', array_filter($columns)).'_'.$kind;
    }

    return null;
}

test('migration identifiers fit within the mysql identifier limit', function (string $file, string $name) {
    expect(strlen($name))->toBeLessThanOrEqual(
        MYSQL_MAX_IDENTIFIER_LENGTH,
        sprintf('%s generates the MySQL identifier "%s", which is %d characters long.', $file, $name, strlen($name))
    );
})->with(migrationIdentifierNames());

test('migration identifiers are discovered', function () {
    expect(migrationIdentifierNames())->not->toBeEmpty();
});
