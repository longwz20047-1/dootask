<?php

// [CUSTOM:report-channel]

namespace Tests\Feature\Report;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TaskFieldDefinitionsMigrationTest extends TestCase
{
    public function test_table_exists()
    {
        $this->assertTrue(Schema::hasTable('task_field_definitions'));
    }

    public function test_required_columns_exist()
    {
        $cols = ['id', 'scope', 'project_id', 'flow_item_id', 'code', 'name', 'type',
                 'options', 'default_value', 'required', 'sort', 'enabled',
                 'is_builtin', 'aggregatable', 'aggregate_strategy', 'has_index',
                 'created_at', 'updated_at'];
        foreach ($cols as $c) {
            $this->assertTrue(
                Schema::hasColumn('task_field_definitions', $c),
                "Missing column: $c"
            );
        }
    }

    public function test_seed_hours_field()
    {
        $hours = DB::table('task_field_definitions')->where('code', 'hours')->first();
        $this->assertNotNull($hours);
        $this->assertEquals('global', $hours->scope);
        $this->assertEquals('number', $hours->type);
        $this->assertTrue((bool) $hours->is_builtin);
        $this->assertTrue((bool) $hours->required);
        $this->assertTrue((bool) $hours->aggregatable);
        $this->assertEquals('sum', $hours->aggregate_strategy);
        // v1.12 Sprint 1 Pass 1 M5 fix: has_index is false until §22 IndexBuilder completes async ALTER
        $this->assertFalse((bool) $hours->has_index);
    }

    public function test_seed_note_field()
    {
        $note = DB::table('task_field_definitions')->where('code', 'note')->first();
        $this->assertNotNull($note);
        $this->assertEquals('global', $note->scope);
        $this->assertEquals('textarea', $note->type);
        $this->assertTrue((bool) $note->is_builtin);
        $this->assertFalse((bool) $note->required);
    }

    public function test_unique_scope_project_code_constraint()
    {
        $pre = DB::connection()->getTablePrefix();
        $rows = DB::select("SHOW INDEX FROM {$pre}task_field_definitions WHERE Key_name = 'uniq_scope_project_code'");
        $this->assertNotEmpty($rows);
    }
}
