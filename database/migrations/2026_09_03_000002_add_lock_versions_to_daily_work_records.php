<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<int, string> */
    private array $tables = [
        'daily_works',
        'rfi_objections',
    ];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'lock_version')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->unsignedBigInteger('lock_version')->default(0);
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'lock_version')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('lock_version');
            });
        }
    }
};
