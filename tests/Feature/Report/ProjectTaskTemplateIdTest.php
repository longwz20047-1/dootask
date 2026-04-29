<?php

// [CUSTOM:report-channel]

namespace Tests\Feature\Report;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProjectTaskTemplateIdTest extends TestCase
{
    public function test_project_tasks_has_template_id_column()
    {
        $this->assertTrue(Schema::hasColumn('project_tasks', 'template_id'));
    }

    public function test_template_id_is_nullable()
    {
        $pre = DB::connection()->getTablePrefix();
        $row = DB::selectOne("SHOW COLUMNS FROM {$pre}project_tasks WHERE Field = 'template_id'");
        $this->assertNotNull($row, 'template_id column not found');
        $this->assertEquals('YES', $row->Null);
    }

    public function test_template_id_index_exists()
    {
        $pre = DB::connection()->getTablePrefix();
        $rows = DB::select("SHOW INDEX FROM {$pre}project_tasks WHERE Column_name = 'template_id'");
        $this->assertNotEmpty($rows);
    }
}
