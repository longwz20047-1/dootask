<?php
// [CUSTOM:report-channel]
namespace Tests\Feature\Report;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class Sprint1Pass1FixTest extends TestCase
{
    public function test_m1_idx_task_reporter_exists()
    {
        $pre = DB::connection()->getTablePrefix();
        $rows = DB::select("SHOW INDEX FROM {$pre}project_task_reports WHERE Key_name = 'idx_task_reporter'");
        $this->assertNotEmpty($rows, 'idx_task_reporter index missing');

        // 验证索引列：task_id + reporter_userid
        $cols = array_column($rows, 'Column_name');
        $this->assertContains('task_id', $cols);
        $this->assertContains('reporter_userid', $cols);
    }

    public function test_m5_hours_has_index_is_false()
    {
        $hours = DB::table('task_field_definitions')->where('code', 'hours')->first();
        $this->assertNotNull($hours);
        $this->assertEquals(0, $hours->has_index, 'hours seed has_index should be 0 (false), set true only after §22 IndexBuilder completes');
    }

    public function test_m6_aggregate_strategy_is_none()
    {
        $rows = DB::table('task_field_definitions')->whereIn('code', ['hours', 'note'])->get();
        foreach ($rows as $row) {
            // hours 用 'sum'，note 用 'none'（非空）
            $this->assertNotEquals('', $row->aggregate_strategy,
                "Field {$row->code}: aggregate_strategy should not be empty string");
            if ($row->code === 'note') {
                $this->assertEquals('none', $row->aggregate_strategy,
                    'note aggregate_strategy should be "none" not ""');
            }
        }
    }

    public function test_m6_column_default_is_none()
    {
        $pre = DB::connection()->getTablePrefix();
        $col = DB::selectOne("SHOW COLUMNS FROM {$pre}task_field_definitions WHERE Field = 'aggregate_strategy'");
        $this->assertEquals('none', $col->Default, 'aggregate_strategy column default should be "none"');
    }
}
