<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Bruno Cesar Alves Marcelino / Scientia International
 * Distributed under the GNU GPL v3 or later.
 */

namespace APP\plugins\generic\googleBooks\classes\Migration;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Repairs plugin settings created by Google Books Integration 0.1.0.0/0.1.0.1.
 *
 * OMP 3.5 resolves enabled generic plugins by comparing
 * plugin_settings.plugin_name with LOWER(versions.product_class_name).
 * GoogleBooksPlugin therefore uses googlebooksplugin as its canonical plugin
 * name. Earlier releases stored settings under googleBooks, which prevented
 * the lazy-loaded plugin from registering its LoadHandler on normal requests.
 */
final class PluginSettingsMigrator
{
    public const CANONICAL_PLUGIN_NAME = 'googlebooksplugin';

    /** @var string[] */
    public const LEGACY_PLUGIN_NAMES = ['googleBooks', 'googlebooks'];

    /**
     * Move legacy rows to the canonical OMP 3.5 plugin key.
     *
     * Canonical values are preserved when both forms exist. The enabled flag
     * is merged with logical OR so an active installation is not disabled by
     * the repair. The operation is transactional and idempotent.
     */
    public static function migrate(): bool
    {
        if (!Schema::hasTable('plugin_settings')) {
            return true;
        }

        try {
            $affectedContextIds = [];
            DB::transaction(function () use (&$affectedContextIds): void {
                $legacyNames = array_values(array_unique(array_map(
                    static fn (string $name): string => strtolower($name),
                    self::LEGACY_PLUGIN_NAMES,
                )));

                $legacyRows = DB::table('plugin_settings')
                    ->whereIn(DB::raw('LOWER(plugin_name)'), $legacyNames)
                    ->orderBy('plugin_setting_id')
                    ->get();

                foreach ($legacyRows as $legacy) {
                    $contextId = $legacy->context_id === null ? null : (int) $legacy->context_id;
                    $affectedContextIds[(int) $contextId] = true;
                    $settingName = (string) $legacy->setting_name;
                    $canonical = self::findCanonicalRow($contextId, $settingName);

                    if ($canonical === null) {
                        DB::table('plugin_settings')
                            ->where('plugin_setting_id', (int) $legacy->plugin_setting_id)
                            ->update(['plugin_name' => self::CANONICAL_PLUGIN_NAME]);
                        continue;
                    }

                    [$value, $type] = self::mergeValues(
                        $settingName,
                        $canonical->setting_value,
                        (string) ($canonical->setting_type ?? ''),
                        $legacy->setting_value,
                        (string) ($legacy->setting_type ?? ''),
                    );

                    DB::table('plugin_settings')
                        ->where('plugin_setting_id', (int) $canonical->plugin_setting_id)
                        ->update([
                            'plugin_name' => self::CANONICAL_PLUGIN_NAME,
                            'setting_value' => $value,
                            'setting_type' => $type,
                        ]);

                    DB::table('plugin_settings')
                        ->where('plugin_setting_id', (int) $legacy->plugin_setting_id)
                        ->delete();
                }
            });

            self::clearSettingCaches(array_map('intval', array_keys($affectedContextIds)));
            return true;
        } catch (Throwable $e) {
            error_log('Google Books plugin setting migration failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Read the old enabled row only when migration could not complete.
     */
    public static function legacyEnabled(?int $contextId): bool
    {
        if (!Schema::hasTable('plugin_settings')) {
            return false;
        }

        try {
            $legacyNames = array_values(array_unique(array_map(
                static fn (string $name): string => strtolower($name),
                self::LEGACY_PLUGIN_NAMES,
            )));
            $query = DB::table('plugin_settings')
                ->whereIn(DB::raw('LOWER(plugin_name)'), $legacyNames)
                ->where('setting_name', 'enabled');
            self::applyContext($query, $contextId);
            $row = $query->orderBy('plugin_setting_id')->first();
            return $row !== null && self::toBool($row->setting_value);
        } catch (Throwable $e) {
            error_log('Google Books legacy enabled-setting lookup failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Pure merge rule used by deterministic regression tests.
     *
     * @return array{0:mixed,1:string}
     */
    public static function mergeValues(
        string $settingName,
        mixed $canonicalValue,
        string $canonicalType,
        mixed $legacyValue,
        string $legacyType,
    ): array {
        if ($settingName === 'enabled') {
            return [self::toBool($canonicalValue) || self::toBool($legacyValue) ? 1 : 0, 'bool'];
        }

        if (self::isEmpty($canonicalValue) && !self::isEmpty($legacyValue)) {
            return [$legacyValue, $legacyType !== '' ? $legacyType : 'string'];
        }

        return [$canonicalValue, $canonicalType !== '' ? $canonicalType : ($legacyType !== '' ? $legacyType : 'string')];
    }


    /** @param int[] $contextIds */
    private static function clearSettingCaches(array $contextIds): void
    {
        $pluginNames = array_values(array_unique(array_map(
            static fn (string $name): string => strtolower($name),
            array_merge([self::CANONICAL_PLUGIN_NAME], self::LEGACY_PLUGIN_NAMES),
        )));
        foreach ($contextIds as $contextId) {
            foreach ($pluginNames as $pluginName) {
                Cache::forget("pluginSettings-{$contextId}-{$pluginName}");
            }
        }
    }

    private static function findCanonicalRow(?int $contextId, string $settingName): ?object
    {
        $query = DB::table('plugin_settings')
            ->where('plugin_name', self::CANONICAL_PLUGIN_NAME)
            ->where('setting_name', $settingName);
        self::applyContext($query, $contextId);
        return $query->orderBy('plugin_setting_id')->first();
    }

    private static function applyContext(object $query, ?int $contextId): void
    {
        if ($contextId === null) {
            $query->whereNull('context_id');
            return;
        }
        $query->where('context_id', $contextId);
    }

    private static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return $value != 0;
        }
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
