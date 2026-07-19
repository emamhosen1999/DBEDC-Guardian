<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fold SERVER-side exceptions into the existing client crash-triage model.
 *
 * WHY EXTEND RATHER THAN ADD A PARALLEL TABLE
 * -------------------------------------------
 * The two streams are the two halves of ONE incident: the mobile app reports
 * "Network request failed on PunchButton" at 09:14 and the API reports
 * "QueryException on POST /api/v1/attendance/punch" at 09:14. Splitting them
 * across two tables means two screens, two prune commands, two resolve flows,
 * and an operator who has to correlate by eye. Everything the group model
 * already provides — fingerprint upsert, occurrence counter, blast radius,
 * resolve/reopen-on-regression, retention — applies verbatim to a server
 * exception. The only real difference is the *sample* columns, which are
 * nullable-by-design here, exactly as the mobile columns are for server rows.
 *
 * A `source` discriminator keeps the streams separable (filterable, countable)
 * without forking the schema. Server fingerprints are additionally salted with
 * the source (see ClientErrorLog::fingerprintFor) so a server exception can
 * never collide into a mobile group.
 *
 * BACKWARD COMPATIBILITY: `source` defaults to 'mobile', so every pre-existing
 * row keeps its correct meaning without a data backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_error_logs', function (Blueprint $table) {
            // mobile | server — the stream discriminator.
            $table->string('source', 16)->default('mobile')->after('fingerprint');

            // ── server-only sample columns (null for mobile rows) ──
            // `error_type` already carries the exception CLASS for server rows;
            // a second column would duplicate it and split the search index.
            $table->string('file', 255)->nullable()->after('stack');
            $table->unsignedInteger('line')->nullable()->after('file');
            $table->string('http_method', 10)->nullable()->after('line');
            $table->string('path', 255)->nullable()->after('http_method');
            $table->string('route_name', 191)->nullable()->after('path');
            $table->unsignedSmallInteger('status_code')->nullable()->after('route_name');
            // Correlates every exception raised inside one request, and lets an
            // operator tie a server group back to a support ticket / access log.
            $table->string('request_id', 64)->nullable()->after('status_code');
        });

        Schema::table('client_error_logs', function (Blueprint $table) {
            $table->index('source');
            // The admin list's default order within a single stream.
            $table->index(['source', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::table('client_error_logs', function (Blueprint $table) {
            $table->dropIndex(['source', 'last_seen_at']);
            $table->dropIndex(['source']);
        });

        Schema::table('client_error_logs', function (Blueprint $table) {
            $table->dropColumn([
                'source', 'file', 'line', 'http_method',
                'path', 'route_name', 'status_code', 'request_id',
            ]);
        });
    }
};
