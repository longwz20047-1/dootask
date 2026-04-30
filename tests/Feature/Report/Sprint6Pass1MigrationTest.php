<?php

// [CUSTOM:report-channel]
// Sprint 6 Pass 1: 验证 4 migration 落库 + seed
// - task_report_templates (含 default builtin seed)
// - task_report_template_fields
// - task_report_trigger_log
// - project_task_reports.template_id

namespace Tests\Feature\Report;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class Sprint6Pass1MigrationTest extends TestCase
{
    public function test_task_report_templates_table_exists()
    {
        $this->assertTrue(Schema::hasTable('task_report_templates'));
    }

    public function test_task_report_templates_has_required_columns()
    {
        $cols = ['id', 'name', 'scope', 'scope_id', 'is_default', 'is_builtin',
                 'description', 'enabled', 'trigger_rules',
                 'created_at', 'updated_at', 'deleted_at'];
        foreach ($cols as $c) {
            $this->assertTrue(
                Schema::hasColumn('task_report_templates', $c),
                "Missing column: $c"
            );
        }
    }

    public function test_task_report_templates_unique_constraint()
    {
        $pre = DB::connection()->getTablePrefix();
        $rows = DB::select("SHOW INDEX FROM {$pre}task_report_templates WHERE Key_name = 'uniq_scope_id_name'");
        $this->assertNotEmpty($rows);
    }

    public function test_task_report_templates_seeded_default()
    {
        $tpl = DB::table('task_report_templates')
            ->where('is_builtin', true)
            ->where('is_default', true)
            ->first();
        $this->assertNotNull($tpl, 'global default builtin seed missing');
        $this->assertEquals('global', $tpl->scope);
        $this->assertEquals(0, $tpl->scope_id);
    }

    public function test_task_report_template_fields_table_exists()
    {
        $this->assertTrue(Schema::hasTable('task_report_template_fields'));
        $cols = ['id', 'template_id', 'field_id', 'override', 'sort', 'created_at', 'updated_at'];
        foreach ($cols as $c) {
            $this->assertTrue(
                Schema::hasColumn('task_report_template_fields', $c),
                "Missing column: $c"
            );
        }
    }

    public function test_task_report_template_fields_unique_constraint()
    {
        $pre = DB::connection()->getTablePrefix();
        $rows = DB::select("SHOW INDEX FROM {$pre}task_report_template_fields WHERE Key_name = 'uniq_template_field'");
        $this->assertNotEmpty($rows);
    }

    public function test_task_report_trigger_log_table_exists()
    {
        $this->assertTrue(Schema::hasTable('task_report_trigger_log'));
        $cols = ['id', 'task_id', 'template_id', 'rule_idx', 'event', 'mode',
                 'triggered_at', 'user_id', 'created_at', 'updated_at'];
        foreach ($cols as $c) {
            $this->assertTrue(
                Schema::hasColumn('task_report_trigger_log', $c),
                "Missing column: $c"
            );
        }
    }

    public function test_task_report_trigger_log_dedup_unique()
    {
        $pre = DB::connection()->getTablePrefix();
        $rows = DB::select("SHOW INDEX FROM {$pre}task_report_trigger_log WHERE Key_name = 'uniq_trigger_dedup'");
        $this->assertNotEmpty($rows);
    }

    public function test_project_task_reports_has_template_id_column()
    {
        $this->assertTrue(Schema::hasColumn('project_task_reports', 'template_id'));
    }
}
