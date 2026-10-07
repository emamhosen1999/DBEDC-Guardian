<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'request_logs_created_at_index';

    /**
     * Retention pruning and the log viewer both filter/sort on created_at. The create migration already adds an
     * index on it; this only adds one where no index leads with created_at (the 1.1 GB production table is
     * therefore not rebuilt needlessly).
     */
    public function up(): void
    {
        if (! Schema::hasTable('request_logs')) {
            return;
        }

        foreach (Schema::getIndexes('request_logs') as $index) {
            if (($index['columns'][0] ?? null) === 'created_at') {
                return;
            }
        }

        Schema::table('request_logs', function (Blueprint $table): void {
            $table->index('created_at', self::INDEX);
        });
    }

    public function down(): void
    {
        // Not dropped: the index may predate this migration (the original create migration), so it is not ours to remove.
    }
};
