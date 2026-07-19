<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mobile client crash / error telemetry ("Client Diagnostics").
 *
 * SEPARATE from the web-frontend `error_logs` table (POST /api/log-error): the
 * mobile app carries device / build / breadcrumb context a browser never has,
 * and phones home in BATCHES from offline queues. Overloading error_logs would
 * mix two very different shapes.
 *
 * GROUPING MODEL — one row per unique `fingerprint`, NOT one row per crash.
 * A native app in a crash loop (bad release, backgrounded socket, retry storm)
 * can emit the *same* error thousands of times per minute across the fleet.
 * Storing every occurrence is a self-inflicted DoS on our own database. Instead
 * the server computes a fingerprint (error_type + normalized message + top stack
 * frame) and UPSERTS: first sighting inserts, every repeat bumps `count` and
 * `last_seen_at` and refreshes the latest sample. This is the Sentry/Bugsnag
 * "issue" model — the unit an operator triages is the bug, not the occurrence.
 *
 * Because occurrences collapse into one row, blast-radius facts that would live
 * in per-occurrence rows are kept as bounded aggregate columns on the group:
 * `affected_devices`, `affected_users` (capped distinct sets) and
 * `platform_counts`. This lets the grouped list show "12 devices / 4 users /
 * android 9, ios 3" without an unbounded occurrences table.
 *
 * `severity` is an INDEXED STRING with app-level validation (fatal|error|warning),
 * NOT a DB enum — sqlite-safe and matches repo convention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_error_logs', function (Blueprint $table) {
            $table->id();

            // Grouping key. UNIQUE so the upsert has exactly one row to bump and
            // a crash loop can never fan out into unbounded rows.
            $table->string('fingerprint', 64)->unique();

            // ── latest sample (overwritten on each repeat) ──
            $table->text('message');
            $table->string('error_type', 191)->nullable();
            // fatal | error | warning — validated in the app layer, not a DB enum.
            $table->string('severity', 16)->default('error')->index();
            $table->longText('stack')->nullable();
            $table->string('screen', 191)->nullable();
            $table->string('platform', 32)->nullable()->index();
            $table->string('os_version', 64)->nullable();
            $table->string('device_model', 191)->nullable();
            $table->string('app_version', 64)->nullable()->index();
            $table->string('build', 64)->nullable();
            $table->string('device_id', 191)->nullable();
            // Null tolerates pre-login crashes (device_id only). Not FK-constrained:
            // telemetry must survive a user being deleted.
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('session_id', 191)->nullable();
            $table->json('breadcrumbs')->nullable();
            $table->json('context')->nullable();

            // ── group aggregates (blast radius across all occurrences) ──
            // Bounded distinct sets + a platform tally, merged on each ingest.
            $table->json('affected_devices')->nullable();
            $table->json('affected_users')->nullable();
            $table->json('platform_counts')->nullable();

            // ── occurrence bookkeeping ──
            $table->unsignedBigInteger('count')->default(1);
            $table->timestamp('occurred_at')->nullable();   // client clock, latest sample
            $table->timestamp('received_at')->nullable();    // server clock, first sighting
            $table->timestamp('last_seen_at')->nullable()->index();

            // ── triage ──
            $table->timestamp('resolved_at')->nullable()->index();
            $table->unsignedBigInteger('resolved_by')->nullable();

            $table->timestamps();

            // Common admin filter/sort combos.
            $table->index(['resolved_at', 'last_seen_at']);
            $table->index(['severity', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_error_logs');
    }
};
