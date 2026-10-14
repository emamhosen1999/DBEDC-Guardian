<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SCD Type 2 for jurisdiction definitions. `jurisdictions` stays the stable identity (and mirrors the CURRENT values for
 * older readers); every code / name / boundary the jurisdiction ever had is a row here with the dates it was valid.
 * valid_from NULL = "since records began", valid_to NULL = still valid; both inclusive Asia/Dhaka dates. A version is never
 * edited except to close it. `open_for` (unique, NULL once closed) lets the database refuse a second open version; the
 * rest (no time overlap per jurisdiction, no space overlap between live versions) is enforced by JurisdictionVersionService.
 *
 * Seeds one open version per existing jurisdiction, valid_from NULL: past dates are never invented.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('jurisdiction_versions')) {
            Schema::create('jurisdiction_versions', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->id();
                $table->unsignedBigInteger('jurisdiction_id');
                $table->string('kind', 16)->default('section')->index();
                $table->string('subtype', 32)->nullable();
                $table->string('carriageway', 16)->nullable();
                $table->string('code', 32)->nullable();
                $table->string('name');
                $table->unsignedInteger('start_m')->nullable(); // an area has no chainage
                $table->unsignedInteger('end_m')->nullable();
                $table->string('start_chainage')->nullable();
                $table->string('end_chainage')->nullable();
                $table->json('geometry')->nullable();
                $table->date('valid_from')->nullable();
                $table->date('valid_to')->nullable();
                $table->string('change_reason', 500)->nullable();
                $table->string('changed_by', 50)->nullable();
                $table->unsignedBigInteger('open_for')->nullable()->unique();
                $table->timestamps();

                $table->index(['jurisdiction_id', 'valid_from']);
                $table->index(['start_m', 'end_m']);
                $table->index('valid_to');
            });
        }

        foreach (DB::table('jurisdictions')->orderBy('id')->get() as $j) {
            if (DB::table('jurisdiction_versions')->where('jurisdiction_id', $j->id)->exists()) {
                continue;
            }
            DB::table('jurisdiction_versions')->insert([
                'jurisdiction_id' => $j->id, 'kind' => $j->kind ?? 'section', 'subtype' => $j->subtype ?? null, 'carriageway' => $j->carriageway ?? null, 'geometry' => $j->geometry ?? null,
                'code' => $j->code, 'name' => $j->location,
                'start_m' => $j->start_m, 'end_m' => $j->end_m, 'start_chainage' => $j->start_chainage, 'end_chainage' => $j->end_chainage,
                'valid_from' => null, 'valid_to' => null, 'change_reason' => 'Initial definition (records began)',
                'changed_by' => null, 'open_for' => $j->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('jurisdiction_versions');
    }
};
