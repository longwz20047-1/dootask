<?php

// [CUSTOM:report-channel] Sprint 7-A Pass 2 测试
// 覆盖 StatisticsService / DashboardCacheInvalidator / IndexBuilder
//
// 关键约定（与 TemplateResolverTest / Sprint6Pass2Test 一致）：
//   - DatabaseTransactions trait 隔离测试间数据
//   - 模板/字段创建用 `new + 直接属性赋值` 绕开 AbstractModel::updateInstance + array cast 双编码 bug
//     （参 commit fef67c720 范式）

namespace Tests\Unit\Services\TaskReport;

use App\Models\TaskFieldDefinition;
use App\Models\TaskReport;
use App\Services\TaskReport\DashboardCacheInvalidator;
use App\Services\TaskReport\IndexBuilder;
use App\Services\TaskReport\StatisticsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class Sprint7APass2Test extends TestCase
{
    use DatabaseTransactions;

    // ======================================================================
    // StatisticsService
    // ======================================================================

    /**
     * StatisticsService::aggregate count metric → COUNT(*) 路径无 warning
     */
    public function test_aggregate_count_metric_returns_no_warning()
    {
        $svc = new StatisticsService();
        $result = $svc->aggregate([
            'dimensions' => ['user'],
            'metric'     => 'count',
        ]);

        $this->assertIsArray($result['rows']);
        $this->assertNull($result['warning']);
    }

    /**
     * StatisticsService::aggregate sum_hours 走 hours_v 虚拟列（has_index=true 路径）→ 无 warning
     */
    public function test_aggregate_sum_hours_uses_indexed_path_without_warning()
    {
        // 先放点数据（避免空 GROUP BY 失败）
        $this->insertReport(['hours' => 2.5, 'note' => 'a'], 1001, 9001, 7777);

        $svc = new StatisticsService();
        $result = $svc->aggregate([
            'dimensions' => ['user'],
            'metric'     => 'sum_hours',
            'filters'    => ['user_ids' => [1001]],
        ]);

        // hours.has_index=true 由 seed 保证 → 不应有 warning
        $this->assertNull($result['warning']);
        // 应能跑通且返回 1 行
        $this->assertCount(1, $result['rows']);
    }

    /**
     * StatisticsService::aggregate sum_<no_index_field> → 退化 JSON_VALUE + 返 warning
     */
    public function test_aggregate_sum_unindexed_field_returns_warning()
    {
        // 创建一个 has_index=false 的可聚合字段
        $field = new TaskFieldDefinition();
        $field->scope              = 'global';
        $field->project_id         = 0;
        $field->flow_item_id       = 0;
        $field->code               = 'test_unindexed_' . uniqid();
        $field->name               = '未建索引字段';
        $field->type               = 'number';
        $field->aggregatable       = true;
        $field->aggregate_strategy = 'sum';
        $field->has_index          = false;
        $field->save();

        // 写一行带该字段值的 report，确保 SQL 语法不爆
        $this->insertReport([$field->code => 3.14, 'hours' => 1, 'note' => 'b'], 1002, 9002, 7777);

        $svc = new StatisticsService();
        $result = $svc->aggregate([
            'dimensions' => ['user'],
            'metric'     => "sum_{$field->code}",
            'filters'    => ['user_ids' => [1002]],
        ]);

        $this->assertNotNull($result['warning']);
        $this->assertStringContainsString('未建聚合索引', $result['warning']);
    }

    /**
     * StatisticsService dim='user' / 'reporter_userid' 双别名都映射到 reporter_userid 列
     * （plan v1.4 P0-V3.24-1 双别名）
     */
    public function test_dim_col_map_user_alias_resolves_to_reporter_userid()
    {
        $svc = new StatisticsService();
        $map = $svc->getDimColMap();

        $this->assertEquals('reporter_userid', $map['user']);
        $this->assertEquals('reporter_userid', $map['reporter_userid']);
        $this->assertEquals('DATE(created_at) as day', $map['time']);
        $this->assertEquals('DATE(created_at) as day', $map['day']);
        $this->assertEquals('project_id', $map['project']);
        $this->assertEquals('task_id', $map['task']);
        $this->assertEquals('template_id', $map['template']);
    }

    /**
     * StatisticsService::getAggregableFields 返 hours（builtin seed aggregatable=true）
     */
    public function test_get_aggregable_fields_includes_hours()
    {
        $svc = new StatisticsService();
        $fields = $svc->getAggregableFields();

        $codes = array_column($fields, 'code');
        $this->assertContains('hours', $codes);
        // note.aggregatable=false → 不应包含
        $this->assertNotContains('note', $codes);
    }

    /**
     * StatisticsService 空 dimensions → 返 'dimensions empty' warning + 空 rows
     */
    public function test_aggregate_empty_dimensions_returns_warning()
    {
        $svc = new StatisticsService();
        $result = $svc->aggregate(['dimensions' => [], 'metric' => 'count']);

        $this->assertEquals([], $result['rows']);
        $this->assertEquals('dimensions empty', $result['warning']);
    }

    // ======================================================================
    // DashboardCacheInvalidator
    // ======================================================================

    /**
     * DashboardCacheInvalidator::flush 在 dashboard_cache 表不存在时不报错（hasTable 守门）
     * Sprint 7-A 阶段表未建，本测试验证降级 no-op 路径
     */
    public function test_flush_no_op_when_dashboard_cache_table_missing()
    {
        // 验证前提：dashboard_cache 表确实未建（plan v1.10 降级策略前置）
        $this->assertFalse(
            Schema::hasTable('dashboard_cache'),
            'dashboard_cache 表应在 Sprint 9 才建，本阶段不应存在'
        );

        // 不抛异常 = 通过
        DashboardCacheInvalidator::flush('field', 1);
        DashboardCacheInvalidator::flush('template', 999);
        DashboardCacheInvalidator::flush('template_field', 42);
        DashboardCacheInvalidator::flushAll();

        $this->assertTrue(true);  // 走到这里说明没异常
    }

    /**
     * DashboardCacheInvalidator::flush 未知 scope → no-op 不报错
     */
    public function test_flush_unknown_scope_no_op()
    {
        DashboardCacheInvalidator::flush('unknown_scope', 1);
        $this->assertTrue(true);
    }

    // ======================================================================
    // IndexBuilder
    // ======================================================================

    /**
     * IndexBuilder::enable 字段不存在 → 抛 InvalidArgumentException
     */
    public function test_enable_throws_when_field_not_found()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('字段 #99999999 不存在');
        IndexBuilder::enable(99999999);
    }

    /**
     * IndexBuilder::enable 拒非 number/date 类型
     */
    public function test_enable_rejects_unsupported_type()
    {
        $field = new TaskFieldDefinition();
        $field->scope        = 'global';
        $field->project_id   = 0;
        $field->flow_item_id = 0;
        $field->code         = 'test_text_' . uniqid();
        $field->name         = '文本字段';
        $field->type         = 'textarea';
        $field->has_index    = false;
        $field->save();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('不支持聚合索引');
        IndexBuilder::enable($field->id);
    }

    /**
     * IndexBuilder::enable idempotent：has_index=true 时静默跳过（不抛异常）
     */
    public function test_enable_idempotent_when_already_indexed()
    {
        $hours = TaskFieldDefinition::where('code', 'hours')->first();
        $this->assertNotNull($hours, 'builtin hours seed missing');
        $this->assertTrue((bool) $hours->has_index, 'builtin hours.has_index should be true');

        // 不抛 / 不 dispatch（taskDeliver 在无 swoole 时本来就 no-op，但 has_index=true 提前 return）
        IndexBuilder::enable($hours->id);
        $this->assertTrue(true);
    }

    /**
     * IndexBuilder::enable 合法字段在测试环境（无 swoole）下 no-op，不抛
     * （taskDeliver 通过 app()->bound('swoole') 守门 → 单测环境直接 return）
     */
    public function test_enable_dispatches_via_task_deliver_no_op_in_test()
    {
        $field = new TaskFieldDefinition();
        $field->scope        = 'global';
        $field->project_id   = 0;
        $field->flow_item_id = 0;
        $field->code         = 'test_num_' . uniqid();
        $field->name         = '测试数字';
        $field->type         = 'number';
        $field->has_index    = false;
        $field->save();

        // 不抛 = 通过；async dispatch 在测试环境 no-op
        IndexBuilder::enable($field->id);

        // 验证 has_index 仍为 false（任务未真正执行）
        $field->refresh();
        $this->assertFalse((bool) $field->has_index);
    }

    /**
     * IndexBuilder::disable 在 has_index=false 时 idempotent no-op
     */
    public function test_disable_idempotent_when_not_indexed()
    {
        $note = TaskFieldDefinition::where('code', 'note')->first();
        $this->assertNotNull($note, 'builtin note seed missing');
        $this->assertFalse((bool) $note->has_index, 'builtin note.has_index should be false');

        // 不抛 / 不动 schema
        IndexBuilder::disable($note->id);

        $note->refresh();
        $this->assertFalse((bool) $note->has_index);
    }

    /**
     * IndexBuilder::disable 字段不存在 → 静默跳过（不抛）
     */
    public function test_disable_silent_when_field_not_found()
    {
        IndexBuilder::disable(99999998);
        $this->assertTrue(true);
    }

    // ======================================================================
    // Helpers
    // ======================================================================

    /**
     * 直接 INSERT 一行 project_task_reports（绕开 Eloquent + factory 复杂度）
     * 用 DB::table 不触 Observer / 无 array cast 双编码问题
     */
    private function insertReport(array $values, int $reporterUserid, int $taskId, int $projectId): int
    {
        return DB::table('project_task_reports')->insertGetId([
            'task_id'         => $taskId,
            'parent_id'       => 0,
            'project_id'      => $projectId,
            'reporter_userid' => $reporterUserid,
            'work_date'       => now()->toDateString(),
            'values'          => json_encode($values, JSON_UNESCAPED_UNICODE),
            'cascade_deleted' => false,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }
}
