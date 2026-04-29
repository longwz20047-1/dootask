<?php
// [CUSTOM:report-channel]
// v1.12 Sprint 1 spec reviewer M5+M6 fix:
//   M5: hours seed has_index should be false (per spec §3.2 line ~1203-1204:
//       has_index is set true only after §22 IndexBuilder completes async ALTER)
//   M6: aggregate_strategy default should be 'none' not '' (spec §3.2 line ~1201)

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FixFieldDefinitionsSeedDefaults extends Migration
{
    public function up()
    {
        // M5: hours has_index 1 -> 0
        DB::table('task_field_definitions')
            ->where('code', 'hours')
            ->where('scope', 'global')
            ->update(['has_index' => false, 'updated_at' => now()]);

        // M6: aggregate_strategy '' -> 'none' for both hours and note
        DB::table('task_field_definitions')
            ->where('aggregate_strategy', '')
            ->update(['aggregate_strategy' => 'none', 'updated_at' => now()]);

        // M6 schema-level: change column default
        // Note: dootask uses MariaDB; ALTER COLUMN SET DEFAULT works
        DB::statement("ALTER TABLE " . DB::connection()->getTablePrefix() . "task_field_definitions
            MODIFY COLUMN aggregate_strategy varchar(32) NOT NULL DEFAULT 'none' COMMENT 'sum/avg/count/distinct/none'");
    }

    public function down()
    {
        // M5 rollback: hours has_index 0 -> 1
        DB::table('task_field_definitions')
            ->where('code', 'hours')
            ->where('scope', 'global')
            ->update(['has_index' => true, 'updated_at' => now()]);

        // M6 rollback: 'none' -> ''
        DB::table('task_field_definitions')
            ->where('aggregate_strategy', 'none')
            ->update(['aggregate_strategy' => '', 'updated_at' => now()]);

        DB::statement("ALTER TABLE " . DB::connection()->getTablePrefix() . "task_field_definitions
            MODIFY COLUMN aggregate_strategy varchar(32) NOT NULL DEFAULT '' COMMENT 'sum/avg/count/distinct'");
    }
}
