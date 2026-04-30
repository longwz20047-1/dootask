<?php

// [CUSTOM:report-channel]
// Sprint 6 Pass 2 测试：3 Eloquent Model + Observer + V33 数据迁移命令
//
// 注：trigger_rules / override 等 array-cast 字段必须用直接属性赋值（$tpl->trigger_rules = [...]），
//     不能走 createInstance(array) — 因 AbstractModel::updateInstance 对数组先 json_encode，
//     再触发 Eloquent array cast 二次 encode，导致读出时为 string（与 dootask 既有
//     ProjectController::report 写法一致：line 4132 `$report->values = $arr` 直接赋值）。
//
// 覆盖：
//   - TaskReportTemplate cast trigger_rules → array
//   - Observer 拒删 builtin global default
//   - Observer 允许删 non-builtin
//   - Observer validateTriggerRules 84 状态机（event/mode/constraint 4 case）
//   - TaskReportTemplateField pivot model 工作正常
//   - TaskReportTriggerLog 唯一约束去重（5 字段组合）
//   - migrate:report --dry-run 不动数据
//   - migrate:report --execute 真实创建 hours+note pivot

namespace Tests\Feature\Report;

use App\Exceptions\ApiException;
use App\Models\TaskFieldDefinition;
use App\Models\TaskReportTemplate;
use App\Models\TaskReportTemplateField;
use App\Models\TaskReportTriggerLog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class Sprint6Pass2Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * 创建 non-builtin TaskReportTemplate 的统一 helper。
     * 用直接属性赋值绕开 AbstractModel::updateInstance 的 array2json 双编码。
     */
    private function makeTemplate(array $attrs): TaskReportTemplate
    {
        $tpl = new TaskReportTemplate();
        $tpl->name        = $attrs['name'] ?? ('Tpl ' . uniqid());
        $tpl->scope       = $attrs['scope'] ?? 'project';
        $tpl->scope_id    = $attrs['scope_id'] ?? 9000;
        $tpl->is_default  = $attrs['is_default'] ?? false;
        $tpl->is_builtin  = $attrs['is_builtin'] ?? false;
        $tpl->enabled     = $attrs['enabled'] ?? true;
        $tpl->description = $attrs['description'] ?? null;
        if (array_key_exists('trigger_rules', $attrs)) {
            $tpl->trigger_rules = $attrs['trigger_rules'];
        }
        return $tpl;
    }

    public function test_template_model_casts_trigger_rules_to_array()
    {
        $tpl = $this->makeTemplate([
            'name'          => 'Test Template ' . uniqid(),
            'scope_id'      => 9001,
            'trigger_rules' => [['event' => 'on_complete', 'mode' => 'modal']],
        ]);
        $tpl->save();

        $fresh = TaskReportTemplate::find($tpl->id);
        $this->assertIsArray($fresh->trigger_rules);
        $this->assertEquals('on_complete', $fresh->trigger_rules[0]['event']);
        $this->assertEquals('modal', $fresh->trigger_rules[0]['mode']);
    }

    public function test_observer_rejects_deleting_builtin_global_default()
    {
        $tpl = TaskReportTemplate::where('is_builtin', true)
            ->where('scope', 'global')
            ->where('is_default', true)
            ->first();
        $this->assertNotNull($tpl, 'Sprint 6 Pass 1 seed missing');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('内建默认模板不可删除');
        $tpl->delete();
    }

    public function test_observer_allows_deleting_non_builtin()
    {
        $tpl = $this->makeTemplate([
            'name'          => 'Disposable ' . uniqid(),
            'scope_id'      => 9002,
            'trigger_rules' => [],
        ]);
        $tpl->save();
        $id = $tpl->id;

        $tpl->delete();
        $this->assertSoftDeleted('task_report_templates', ['id' => $id]);
    }

    public function test_observer_validates_trigger_rules_event()
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('event 非法');
        $tpl = $this->makeTemplate([
            'name'          => 'Bad Event ' . uniqid(),
            'scope_id'      => 9003,
            'trigger_rules' => [['event' => 'INVALID_EVENT', 'mode' => 'modal']],
        ]);
        $tpl->save();
    }

    public function test_observer_validates_trigger_rules_mode()
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('mode 非法');
        $tpl = $this->makeTemplate([
            'name'          => 'Bad Mode ' . uniqid(),
            'scope_id'      => 9004,
            'trigger_rules' => [['event' => 'on_complete', 'mode' => 'INVALID_MODE']],
        ]);
        $tpl->save();
    }

    public function test_observer_rejects_block_with_max_count_constraint()
    {
        // block 模式仅允许 min_count / time_window；max_count 应被拒
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('max_count');
        $tpl = $this->makeTemplate([
            'name'          => 'Bad Constraint ' . uniqid(),
            'scope_id'      => 9005,
            'trigger_rules' => [[
                'event'      => 'on_complete',
                'mode'       => 'block',
                'constraint' => ['max_count' => 5],
            ]],
        ]);
        $tpl->save();
    }

    public function test_observer_accepts_remind_with_frequency_limit()
    {
        $tpl = $this->makeTemplate([
            'name'          => 'Valid Remind ' . uniqid(),
            'scope_id'      => 9006,
            'trigger_rules' => [[
                'event'      => 'daily',
                'mode'       => 'remind',
                'constraint' => ['frequency_limit_min' => 60],
            ]],
        ]);
        $tpl->save();
        $this->assertDatabaseHas('task_report_templates', ['id' => $tpl->id]);
    }

    public function test_template_field_pivot_model_works()
    {
        $tpl = $this->makeTemplate([
            'name'          => 'Pivot Test ' . uniqid(),
            'scope_id'      => 9007,
            'trigger_rules' => [],
        ]);
        $tpl->save();

        $hours = TaskFieldDefinition::where('code', 'hours')->where('is_builtin', true)->first();
        $this->assertNotNull($hours, 'hours builtin field not seeded');

        // 直接属性赋值绕开 updateInstance array2json 双编码
        $pivot = new TaskReportTemplateField();
        $pivot->template_id = $tpl->id;
        $pivot->field_id    = $hours->id;
        $pivot->override    = ['hidden' => false, 'required' => true];
        $pivot->sort        = 1;
        $pivot->save();

        $fresh = TaskReportTemplateField::find($pivot->id);
        $this->assertIsArray($fresh->override);
        $this->assertTrue($fresh->override['required']);
        $this->assertEquals($hours->id, $fresh->field_id);
        $this->assertEquals($tpl->id, $fresh->template_id);
    }

    public function test_trigger_log_model_dedup_by_unique_constraint()
    {
        $now = now();
        $log = new TaskReportTriggerLog();
        $log->task_id      = 999991;
        $log->template_id  = 1;
        $log->rule_idx     = 0;
        $log->event        = 'on_complete';
        $log->mode         = 'block';
        $log->triggered_at = $now;
        $log->user_id      = 1;
        $log->save();

        // 同一 5 字段组合（task_id, rule_idx, triggered_at, event, mode）应被 unique 拒
        $this->expectException(\Illuminate\Database\QueryException::class);
        $dup = new TaskReportTriggerLog();
        $dup->task_id      = 999991;
        $dup->template_id  = 1;
        $dup->rule_idx     = 0;
        $dup->event        = 'on_complete';
        $dup->mode         = 'block';
        $dup->triggered_at = $now;
        $dup->user_id      = 1;
        $dup->save();
    }

    public function test_v33_command_dry_run_does_not_create_pivots()
    {
        $defaultTpl = TaskReportTemplate::where('is_builtin', true)
            ->where('scope', 'global')
            ->where('is_default', true)
            ->first();
        $this->assertNotNull($defaultTpl);

        // 清理 default tpl 的既有 pivots（如有），保证 dry-run 起点为 0
        TaskReportTemplateField::where('template_id', $defaultTpl->id)->delete();

        $countBefore = TaskReportTemplateField::where('template_id', $defaultTpl->id)->count();
        $this->assertEquals(0, $countBefore);

        $exitCode = $this->artisan('migrate:report', ['--dry-run' => true])->run();
        $this->assertEquals(0, $exitCode);

        $countAfter = TaskReportTemplateField::where('template_id', $defaultTpl->id)->count();
        $this->assertEquals(0, $countAfter, 'dry-run 不应改 DB');
    }

    public function test_v33_command_execute_creates_pivots_for_hours_note()
    {
        $defaultTpl = TaskReportTemplate::where('is_builtin', true)
            ->where('scope', 'global')
            ->where('is_default', true)
            ->first();
        $this->assertNotNull($defaultTpl);

        // 清理既有 pivots
        TaskReportTemplateField::where('template_id', $defaultTpl->id)->delete();

        $exitCode = $this->artisan('migrate:report', ['--execute' => true])->run();
        $this->assertEquals(0, $exitCode);

        $pivots = TaskReportTemplateField::where('template_id', $defaultTpl->id)->get();
        $this->assertEquals(2, $pivots->count(), 'V33 应创建 hours+note 两个 pivot');

        $fieldIds = $pivots->pluck('field_id')->toArray();

        $hours = TaskFieldDefinition::where('code', 'hours')->where('is_builtin', true)->first();
        $note  = TaskFieldDefinition::where('code', 'note')->where('is_builtin', true)->first();
        $this->assertContains($hours->id, $fieldIds);
        $this->assertContains($note->id, $fieldIds);
    }

    public function test_v33_command_rejects_no_options()
    {
        $exitCode = $this->artisan('migrate:report')->run();
        $this->assertEquals(1, $exitCode, '无 --dry-run / --execute 参数应退出 1');
    }
}
