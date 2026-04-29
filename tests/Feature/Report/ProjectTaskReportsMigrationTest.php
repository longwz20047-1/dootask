<?php

// [CUSTOM:report-channel]

namespace Tests\Feature\Report;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProjectTaskReportsMigrationTest extends TestCase
{
    public function test_project_task_reports_table_exists()
    {
        $this->assertTrue(Schema::hasTable('project_task_reports'));
    }

    public function test_required_columns_exist()
    {
        $cols = ['id', 'task_id', 'parent_id', 'project_id', 'reporter_userid',
                 'work_date', 'values', 'cascade_deleted', 'hours_v',
                 'created_at', 'updated_at', 'deleted_at'];
        foreach ($cols as $c) {
            $this->assertTrue(
                Schema::hasColumn('project_task_reports', $c),
                "Missing column: $c"
            );
        }
    }

    public function test_hours_v_is_stored_virtual_column()
    {
        $pre = DB::connection()->getTablePrefix();
        $row = DB::selectOne("SHOW COLUMNS FROM {$pre}project_task_reports WHERE Field = 'hours_v'");
        $this->assertNotNull($row, 'hours_v column not found');
        $this->assertStringContainsString('STORED', $row->Extra);
    }

    public function test_idx_hours_v_index_exists()
    {
        $pre = DB::connection()->getTablePrefix();
        $rows = DB::select("SHOW INDEX FROM {$pre}project_task_reports WHERE Key_name = 'idx_hours_v'");
        $this->assertNotEmpty($rows);
    }

    public function test_idx_reporter_date_index_exists()
    {
        $pre = DB::connection()->getTablePrefix();
        $rows = DB::select("SHOW INDEX FROM {$pre}project_task_reports WHERE Key_name = 'idx_reporter_date'");
        $this->assertNotEmpty($rows);
    }

    public function test_idx_project_date_index_exists()
    {
        $pre = DB::connection()->getTablePrefix();
        $rows = DB::select("SHOW INDEX FROM {$pre}project_task_reports WHERE Key_name = 'idx_project_date'");
        $this->assertNotEmpty($rows);
    }
}
