<?php

use App\Support\Corridor\Chainage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Jurisdictions get a kind (section / facility / area, with an optional carriageway), a stable code and name, numeric chainage bounds (metres, indexed), a description, an optional
 * map colour token, authorship and soft deletes. `location`, `start_chainage` and `end_chainage` stay as the display
 * strings (the model keeps them in sync from the numbers). The three real rows are backfilled from their strings:
 * nothing is invented, and a row whose chainage cannot be read keeps NULL bounds (reported, never guessed).
 *
 * The table is converted to InnoDB on MySQL/MariaDB (it is tiny) so the new jurisdiction_members table can hold real
 * foreign keys to it. Reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE jurisdictions ENGINE=InnoDB');
        }

        Schema::table('jurisdictions', function (Blueprint $table) {
            if (! Schema::hasColumn('jurisdictions', 'kind')) {
                // section = a chainage band (what daily works are attributed to) | facility = a point or short range with a
                // subtype | area = a polygon. A backed PHP enum (App\Enums\JurisdictionKind), never free text.
                $table->string('kind', 16)->default('section')->index()->after('id');
                $table->string('subtype', 32)->nullable()->after('kind');
                $table->string('carriageway', 16)->nullable()->after('subtype'); // both | northbound | southbound
            }
            if (! Schema::hasColumn('jurisdictions', 'geometry')) {
                $table->json('geometry')->nullable()->after('incharge'); // areas: simplified GeoJSON Polygon
            }
            if (! Schema::hasColumn('jurisdictions', 'code')) {
                $table->string('code', 32)->nullable()->unique()->after('id');
            }
            if (! Schema::hasColumn('jurisdictions', 'name')) {
                $table->string('name')->nullable()->after('code');
            }
            if (! Schema::hasColumn('jurisdictions', 'start_m')) {
                $table->unsignedInteger('start_m')->nullable()->index()->after('end_chainage');
            }
            if (! Schema::hasColumn('jurisdictions', 'end_m')) {
                $table->unsignedInteger('end_m')->nullable()->index()->after('start_m');
            }
            if (! Schema::hasColumn('jurisdictions', 'description')) {
                $table->text('description')->nullable()->after('incharge');
            }
            if (! Schema::hasColumn('jurisdictions', 'colour_token')) {
                $table->string('colour_token', 32)->nullable()->after('description');
            }
            if (! Schema::hasColumn('jurisdictions', 'created_by')) {
                $table->string('created_by', 50)->nullable()->after('colour_token');
            }
            if (! Schema::hasColumn('jurisdictions', 'updated_by')) {
                $table->string('updated_by', 50)->nullable()->after('created_by');
            }
            if (! Schema::hasColumn('jurisdictions', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        // An area has no chainage: the display strings become optional.
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE jurisdictions MODIFY start_chainage VARCHAR(255) NULL, MODIFY end_chainage VARCHAR(255) NULL');
        } elseif (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('jurisdictions', fn (Blueprint $t) => $t->string('start_chainage')->nullable()->change());
        }

        $numbers = ['one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5];
        foreach (DB::table('jurisdictions')->orderBy('id')->get() as $row) {
            $update = [];
            if ($row->start_m === null) {
                $update['start_m'] = Chainage::token($row->start_chainage);
            }
            if ($row->end_m === null) {
                $update['end_m'] = Chainage::token($row->end_chainage);
            }
            if ($row->name === null) {
                $update['name'] = $row->location;
            }
            if ($row->code === null) {
                $code = null;
                if (preg_match('/^\s*phase\s+(\w+)\s*$/i', (string) $row->location, $m) === 1) {
                    $n = $numbers[strtolower($m[1])] ?? (ctype_digit($m[1]) ? (int) $m[1] : null);
                    $code = $n !== null ? 'PH-'.$n : null;
                }
                $update['code'] = $code !== null && ! DB::table('jurisdictions')->where('code', $code)->exists() ? $code : 'JU-'.$row->id;
            }
            if ($update !== []) {
                DB::table('jurisdictions')->where('id', $row->id)->update($update);
            }
        }
    }

    public function down(): void
    {
        Schema::table('jurisdictions', function (Blueprint $table) {
            foreach (['code' => 'unique', 'start_m' => 'index', 'end_m' => 'index', 'kind' => 'index'] as $column => $kind) {
                if (Schema::hasColumn('jurisdictions', $column)) {
                    $kind === 'unique' ? $table->dropUnique(['code']) : $table->dropIndex([$column]);
                }
            }
        });
        Schema::table('jurisdictions', function (Blueprint $table) {
            $columns = array_values(array_filter(
                ['kind', 'subtype', 'carriageway', 'geometry', 'code', 'name', 'start_m', 'end_m', 'description', 'colour_token', 'created_by', 'updated_by', 'deleted_at'],
                fn (string $c): bool => Schema::hasColumn('jurisdictions', $c),
            ));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
