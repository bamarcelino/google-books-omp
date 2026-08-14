<?php

declare(strict_types=1);

namespace Illuminate\Support\Facades {
    final class Schema
    {
        public static function hasTable(string $name): bool
        {
            return $name === 'plugin_settings';
        }
    }

    final class Cache
    {
        /** @var string[] */
        public static array $forgotten = [];

        public static function forget(string $key): void
        {
            self::$forgotten[] = $key;
        }
    }

    final class RawExpression
    {
        public function __construct(public string $value)
        {
        }
    }

    final class DB
    {
        /** @var array<int,array<string,mixed>> */
        public static array $rows = [];

        public static function raw(string $value): RawExpression
        {
            return new RawExpression($value);
        }

        public static function table(string $name): FakeQuery
        {
            if ($name !== 'plugin_settings') {
                throw new \RuntimeException('Unexpected table: ' . $name);
            }
            return new FakeQuery();
        }

        public static function transaction(callable $callback): mixed
        {
            $snapshot = self::$rows;
            try {
                return $callback();
            } catch (\Throwable $e) {
                self::$rows = $snapshot;
                throw $e;
            }
        }
    }

    final class FakeQuery
    {
        /** @var array<int,callable(array<string,mixed>):bool> */
        private array $filters = [];
        private ?string $orderColumn = null;

        public function whereIn(mixed $column, array $values): self
        {
            $normalized = array_map('strtolower', array_map('strval', $values));
            if ($column instanceof RawExpression && strtoupper($column->value) === 'LOWER(PLUGIN_NAME)') {
                $this->filters[] = static fn (array $row): bool => in_array(strtolower((string) $row['plugin_name']), $normalized, true);
                return $this;
            }
            throw new \RuntimeException('Unsupported whereIn expression.');
        }

        public function where(string $column, mixed $operatorOrValue, mixed $value = null): self
        {
            $expected = func_num_args() === 2 ? $operatorOrValue : $value;
            $operator = func_num_args() === 2 ? '=' : (string) $operatorOrValue;
            if ($operator !== '=') {
                throw new \RuntimeException('Unsupported operator: ' . $operator);
            }
            $this->filters[] = static fn (array $row): bool => ($row[$column] ?? null) === $expected;
            return $this;
        }

        public function whereNull(string $column): self
        {
            $this->filters[] = static fn (array $row): bool => ($row[$column] ?? null) === null;
            return $this;
        }

        public function orderBy(string $column): self
        {
            $this->orderColumn = $column;
            return $this;
        }

        /** @return object[] */
        public function get(): array
        {
            return array_map(static fn (array $row): object => (object) $row, $this->matchingRows());
        }

        public function first(): ?object
        {
            $rows = $this->matchingRows();
            return $rows === [] ? null : (object) $rows[0];
        }

        /** @param array<string,mixed> $values */
        public function update(array $values): int
        {
            $count = 0;
            foreach (DB::$rows as &$row) {
                if (!$this->matches($row)) {
                    continue;
                }
                $row = array_merge($row, $values);
                $count++;
            }
            unset($row);
            return $count;
        }

        public function delete(): int
        {
            $before = count(DB::$rows);
            DB::$rows = array_values(array_filter(DB::$rows, fn (array $row): bool => !$this->matches($row)));
            return $before - count(DB::$rows);
        }

        /** @return array<int,array<string,mixed>> */
        private function matchingRows(): array
        {
            $rows = array_values(array_filter(DB::$rows, fn (array $row): bool => $this->matches($row)));
            if ($this->orderColumn !== null) {
                $column = $this->orderColumn;
                usort($rows, static fn (array $a, array $b): int => ($a[$column] ?? 0) <=> ($b[$column] ?? 0));
            }
            return $rows;
        }

        /** @param array<string,mixed> $row */
        private function matches(array $row): bool
        {
            foreach ($this->filters as $filter) {
                if (!$filter($row)) {
                    return false;
                }
            }
            return true;
        }
    }
}

namespace {
    require_once dirname(__DIR__) . '/classes/Migration/PluginSettingsMigrator.php';

    use APP\plugins\generic\googleBooks\classes\Migration\PluginSettingsMigrator;
    use Illuminate\Support\Facades\Cache;
    use Illuminate\Support\Facades\DB;

    DB::$rows = [
        ['plugin_setting_id' => 1, 'plugin_name' => 'googleBooks', 'context_id' => 7, 'setting_name' => 'enabled', 'setting_value' => 1, 'setting_type' => 'bool'],
        ['plugin_setting_id' => 2, 'plugin_name' => 'googleBooks', 'context_id' => 7, 'setting_name' => 'collectionCode', 'setting_value' => 'OLD0001', 'setting_type' => 'string'],
        ['plugin_setting_id' => 3, 'plugin_name' => 'googlebooksplugin', 'context_id' => 7, 'setting_name' => 'collectionCode', 'setting_value' => 'NEW0001', 'setting_type' => 'string'],
        ['plugin_setting_id' => 4, 'plugin_name' => 'googlebooks', 'context_id' => null, 'setting_name' => 'siteSetting', 'setting_value' => 'legacy-site', 'setting_type' => 'string'],
        ['plugin_setting_id' => 5, 'plugin_name' => 'otherplugin', 'context_id' => 7, 'setting_name' => 'enabled', 'setting_value' => 1, 'setting_type' => 'bool'],
        ['plugin_setting_id' => 6, 'plugin_name' => 'googlebooksplugin', 'context_id' => 8, 'setting_name' => 'enabled', 'setting_value' => 0, 'setting_type' => 'bool'],
        ['plugin_setting_id' => 7, 'plugin_name' => 'googleBooks', 'context_id' => 8, 'setting_name' => 'enabled', 'setting_value' => 1, 'setting_type' => 'bool'],
    ];

    $assertions = 0;
    $failures = [];
    $check = static function (bool $condition, string $message) use (&$assertions, &$failures): void {
        $assertions++;
        if (!$condition) {
            $failures[] = $message;
        }
    };

    $check(PluginSettingsMigrator::migrate(), 'Settings migration reported failure');

    $legacy = array_values(array_filter(DB::$rows, static fn (array $row): bool => strtolower((string) $row['plugin_name']) === 'googlebooks'));
    $canonical = array_values(array_filter(DB::$rows, static fn (array $row): bool => $row['plugin_name'] === 'googlebooksplugin'));

    $check($legacy === [], 'Legacy googleBooks/googlebooks settings were not removed');
    $check(count($canonical) === 4, 'Canonical setting row count is incorrect after migration');

    $find = static function (?int $contextId, string $name) use (&$canonical): ?array {
        foreach ($canonical as $row) {
            if ($row['context_id'] === $contextId && $row['setting_name'] === $name) {
                return $row;
            }
        }
        return null;
    };

    $check(($find(7, 'enabled')['setting_value'] ?? null) === 1, 'Enabled setting was not migrated to googlebooksplugin');
    $check(($find(7, 'collectionCode')['setting_value'] ?? null) === 'NEW0001', 'Existing canonical setting was overwritten by stale legacy data');
    $check(($find(null, 'siteSetting')['setting_value'] ?? null) === 'legacy-site', 'Null-context legacy setting was not migrated');
    $check(($find(8, 'enabled')['setting_value'] ?? null) === 1, 'Canonical false plus legacy true did not preserve the active installation');
    $check(count(array_filter(DB::$rows, static fn (array $row): bool => $row['plugin_name'] === 'otherplugin')) === 1, 'Unrelated plugin settings were modified');
    $check(in_array('pluginSettings-7-googlebooksplugin', Cache::$forgotten, true), 'Canonical context cache was not invalidated');
    $check(in_array('pluginSettings-7-googlebooks', Cache::$forgotten, true), 'Legacy context cache was not invalidated');
    $check(in_array('pluginSettings-0-googlebooksplugin', Cache::$forgotten, true), 'Site-level canonical cache was not invalidated');

    $snapshot = DB::$rows;
    $check(PluginSettingsMigrator::migrate(), 'Idempotent migration reported failure');
    $check(DB::$rows === $snapshot, 'Settings migration is not idempotent');

    $check(PluginSettingsMigrator::legacyEnabled(7) === false, 'Legacy enabled fallback should be false after successful migration');
    $check(PluginSettingsMigrator::mergeValues('enabled', 0, 'bool', 1, 'bool') === [1, 'bool'], 'Enabled merge rule is incorrect');
    $check(PluginSettingsMigrator::mergeValues('collectionCode', 'NEW', 'string', 'OLD', 'string') === ['NEW', 'string'], 'Canonical settings must win over stale legacy values');

    if ($failures !== []) {
        fwrite(STDERR, 'FAILED ' . count($failures) . " of {$assertions} settings migration assertions\n");
        foreach ($failures as $failure) {
            fwrite(STDERR, ' - ' . $failure . "\n");
        }
        exit(1);
    }

    echo "OK {$assertions} settings migration assertions\n";
}
