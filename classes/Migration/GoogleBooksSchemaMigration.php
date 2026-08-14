<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Bruno Cesar Alves Marcelino / Scientia International
 * Distributed under the GNU GPL v3 or later.
 */

namespace APP\plugins\generic\googleBooks\classes\Migration;

use APP\core\Application;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

final class GoogleBooksSchemaMigration extends Migration
{
    /**
     * Determine whether the database already contains the schema required by
     * the current plugin release. Kept public so the dashboard can self-heal
     * installations upgraded through 0.1.1.0, whose package did not include
     * an upgrade.xml descriptor.
     */
    public static function isCurrent(): bool
    {
        if (!Schema::hasTable('google_books_records') || !Schema::hasTable('google_books_sync_runs') || !Schema::hasTable('google_books_delivery_files')) {
            return false;
        }

        foreach (['feed_eligible', 'last_feed_checked_at', 'discovery_error', 'feed_error'] as $column) {
            if (!Schema::hasColumn('google_books_records', $column)) {
                return false;
            }
        }
        foreach (['books_skipped', 'books_feed_ineligible'] as $column) {
            if (!Schema::hasColumn('google_books_sync_runs', $column)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Idempotently repair the plugin schema when an older in-place upgrade
     * missed its database migration.
     */
    public static function ensureCurrent(): void
    {
        if (!self::isCurrent()) {
            (new self())->up();
        }
    }
    public function up(): void
    {
        PluginSettingsMigrator::migrate();

        if (!Schema::hasTable('google_books_records')) {
            Schema::create('google_books_records', function (Blueprint $table) {
                $table->bigIncrements('record_id');
                $table->bigInteger('context_id');
                $table->bigInteger('submission_id');
                $table->bigInteger('publication_id')->nullable();
                $table->string('isbn13', 13);
                $table->string('isbn10', 10)->nullable();
                $table->string('google_volume_id', 128)->nullable();
                $table->text('google_self_link')->nullable();
                $table->text('google_info_link')->nullable();
                $table->text('google_preview_link')->nullable();
                $table->string('discovery_status', 32)->default('not_checked');
                $table->string('sync_status', 32)->default('pending');
                $table->boolean('feed_eligible')->default(false);
                $table->string('metadata_hash', 64)->nullable();
                $table->string('content_hash', 64)->nullable();
                $table->dateTime('feed_modified_at')->nullable();
                $table->dateTime('last_discovered_at')->nullable();
                $table->dateTime('last_verified_at')->nullable();
                $table->dateTime('last_feed_checked_at')->nullable();
                $table->text('discovery_error')->nullable();
                $table->text('feed_error')->nullable();
                // Kept for backwards compatibility with 0.1.0.x installs.
                $table->text('last_error')->nullable();
                $table->dateTime('created_at');
                $table->dateTime('updated_at');

                $table->unique(['context_id', 'isbn13'], 'google_books_isbn_unique');
                $table->index(['context_id', 'submission_id'], 'google_books_submission_context');
                $table->index(['context_id', 'google_volume_id'], 'google_books_volume_context');
                $table->index(['context_id', 'sync_status'], 'google_books_sync_status');
                $table->index(['context_id', 'discovery_status'], 'google_books_discovery_status');
                $table->foreign('context_id', 'google_books_context_fk')
                    ->references(Application::getContextDAO()->primaryKeyColumn)
                    ->on(Application::getContextDAO()->tableName)
                    ->onDelete('cascade');
                $table->foreign('submission_id', 'google_books_submission_fk')
                    ->references('submission_id')
                    ->on('submissions')
                    ->onDelete('cascade');
            });
        } else {
            $this->upgradeRecordsTable();
        }

        if (!Schema::hasTable('google_books_sync_runs')) {
            Schema::create('google_books_sync_runs', function (Blueprint $table) {
                $table->bigIncrements('run_id');
                $table->bigInteger('context_id');
                $table->bigInteger('user_id')->nullable();
                $table->string('mode', 32);
                $table->string('status', 32)->default('running');
                $table->integer('books_scanned')->default(0);
                $table->integer('books_linked')->default(0);
                $table->integer('books_not_found')->default(0);
                $table->integer('books_updated')->default(0);
                $table->integer('books_unchanged')->default(0);
                $table->integer('books_retired')->default(0);
                $table->integer('books_failed')->default(0);
                $table->integer('books_skipped')->default(0);
                $table->integer('books_feed_ineligible')->default(0);
                $table->text('details')->nullable();
                $table->dateTime('started_at');
                $table->dateTime('completed_at')->nullable();

                $table->index(['context_id', 'started_at'], 'google_books_runs_context');
                $table->foreign('context_id', 'google_books_runs_context_fk')
                    ->references(Application::getContextDAO()->primaryKeyColumn)
                    ->on(Application::getContextDAO()->tableName)
                    ->onDelete('cascade');
            });
        } else {
            $this->upgradeRunsTable();
        }

        if (!Schema::hasTable('google_books_delivery_files')) {
            Schema::create('google_books_delivery_files', function (Blueprint $table) {
                $table->bigIncrements('delivery_file_id');
                $table->bigInteger('context_id');
                $table->string('transport_key', 96);
                $table->string('path_hash', 64);
                $table->text('remote_path');
                $table->string('fingerprint', 64);
                $table->bigInteger('file_size')->default(0);
                $table->string('status', 32)->default('pending');
                $table->text('last_error')->nullable();
                $table->dateTime('delivered_at')->nullable();
                $table->dateTime('created_at');
                $table->dateTime('updated_at');

                $table->unique(['context_id', 'transport_key', 'path_hash'], 'google_books_delivery_unique');
                $table->index(['context_id', 'transport_key', 'status'], 'google_books_delivery_status');
                $table->foreign('context_id', 'google_books_delivery_context_fk')
                    ->references(Application::getContextDAO()->primaryKeyColumn)
                    ->on(Application::getContextDAO()->tableName)
                    ->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('google_books_delivery_files');
        Schema::dropIfExists('google_books_sync_runs');
        Schema::dropIfExists('google_books_records');
    }

    private function upgradeRecordsTable(): void
    {
        $columns = [
            'feed_eligible' => fn (Blueprint $table) => $table->boolean('feed_eligible')->default(false),
            'last_feed_checked_at' => fn (Blueprint $table) => $table->dateTime('last_feed_checked_at')->nullable(),
            'discovery_error' => fn (Blueprint $table) => $table->text('discovery_error')->nullable(),
            'feed_error' => fn (Blueprint $table) => $table->text('feed_error')->nullable(),
        ];
        foreach ($columns as $column => $add) {
            if (!Schema::hasColumn('google_books_records', $column)) {
                Schema::table('google_books_records', $add);
            }
        }
    }

    private function upgradeRunsTable(): void
    {
        $columns = [
            'books_skipped' => fn (Blueprint $table) => $table->integer('books_skipped')->default(0),
            'books_feed_ineligible' => fn (Blueprint $table) => $table->integer('books_feed_ineligible')->default(0),
        ];
        foreach ($columns as $column => $add) {
            if (!Schema::hasColumn('google_books_sync_runs', $column)) {
                Schema::table('google_books_sync_runs', $add);
            }
        }
    }
}
