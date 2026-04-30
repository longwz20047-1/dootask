<?php

// [CUSTOM:report-channel] Sprint 7-A Task 7.1
// Spec §6.5 v3.26 TemplateResolver 单元测试
//
// 覆盖 4 档优先级 + 2 边界 case：
//   1. test 0：task.template_id 显式胜过 flow_item
//   2. test 1：flow_item 命中（task.template_id null）
//   3. test 2：project 命中（无 flow_item 模板）
//   4. test 3：global default 兜底（前 3 档全空）
//   5. test 4：连 global default 都没 → null（极端边界）
//   6. test 5：task.template_id 指向 disabled 模板 → 跳过该档
//
// 重要约定：
//   - DatabaseTransactions trait 隔离测试间数据（dootask 既有惯例）
//   - test 4 forceDelete 在事务内执行，rollback 自动恢复 builtin default seed
//   - 模板创建用 `new + 直接属性赋值` 绕开 AbstractModel::updateInstance 的
//     array2json 双编码 bug（参 commit fef67c720）

namespace Tests\Unit\Services\TaskReport;

use App\Models\ProjectTask;
use App\Models\TaskReportTemplate;
use App\Services\TaskReport\TemplateResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class TemplateResolverTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 第 0 档：task.template_id 显式（最高优先级）
     */
    public function test_resolve_prefers_task_template_id_over_flow_item()
    {
        $taskTpl     = $this->createTemplate(['scope' => 'global', 'scope_id' => 0, 'name' => 'Task Tpl ' . uniqid()]);
        $flowItemTpl = $this->createTemplate(['scope' => 'flow_item', 'scope_id' => 1, 'name' => 'Flow Tpl ' . uniqid()]);

        $task = ProjectTask::factory()->create([
            'template_id'  => $taskTpl->id,
            'flow_item_id' => 1,
        ]);

        $resolved = app(TemplateResolver::class)->resolveForTask($task);
        $this->assertNotNull($resolved);
        $this->assertEquals($taskTpl->id, $resolved->id);
    }

    /**
     * 第 1 档：flow_item 命中（task.template_id 留空）
     */
    public function test_resolve_falls_back_to_flow_item_when_task_template_id_null()
    {
        $flowItemTpl = $this->createTemplate(['scope' => 'flow_item', 'scope_id' => 1, 'name' => 'Flow Tpl ' . uniqid()]);
        // 同时存在 project 模板，验证 flow_item 优先级高于 project
        $projectTpl = $this->createTemplate(['scope' => 'project', 'scope_id' => 100, 'name' => 'Proj Tpl ' . uniqid()]);

        $task = ProjectTask::factory()->create([
            'template_id'  => null,
            'flow_item_id' => 1,
        ]);
        // 单独覆盖 project_id（factory 默认会建一个新 Project，这里改成与 projectTpl 关联的 id）
        $task->project_id = 100;
        $task->save();

        $resolved = app(TemplateResolver::class)->resolveForTask($task);
        $this->assertNotNull($resolved);
        $this->assertEquals($flowItemTpl->id, $resolved->id);
    }

    /**
     * 第 2 档：fallback project（flow_item 无对应模板）
     */
    public function test_resolve_falls_back_to_project_when_no_flow_item_template()
    {
        $projectTpl = $this->createTemplate(['scope' => 'project', 'scope_id' => 100, 'name' => 'Proj Tpl ' . uniqid()]);

        $task = ProjectTask::factory()->create([
            'template_id'  => null,
            'flow_item_id' => 999,  // 无对应 flow_item 模板
        ]);
        $task->project_id = 100;
        $task->save();

        $resolved = app(TemplateResolver::class)->resolveForTask($task);
        $this->assertNotNull($resolved);
        $this->assertEquals($projectTpl->id, $resolved->id);
    }

    /**
     * 第 3 档：global default 兜底（前 3 档全空）
     */
    public function test_resolve_falls_back_to_global_default()
    {
        // builtin global default 在 Sprint 6 Pass 1 migration seed 时已存在
        $defaultTpl = TaskReportTemplate::where('is_builtin', true)
            ->where('scope', 'global')
            ->where('is_default', true)
            ->first();
        $this->assertNotNull($defaultTpl, 'Sprint 6 Pass 1 builtin global default seed 缺失');

        $task = ProjectTask::factory()->create([
            'template_id'  => null,
            'flow_item_id' => 999,
        ]);
        $task->project_id = 999;  // 无对应 project 模板
        $task->save();

        $resolved = app(TemplateResolver::class)->resolveForTask($task);
        $this->assertNotNull($resolved);
        $this->assertEquals($defaultTpl->id, $resolved->id);
    }

    /**
     * 极端 case：连 global default 都没（v3.8 双护栏理论上不可能发生，但测试覆盖该路径）
     */
    public function test_resolve_returns_null_when_no_template_found()
    {
        // 硬删全部模板（含 builtin default）— DatabaseTransactions trait 自动 rollback
        // 不会污染其他测试。注意必须 forceDelete 才会真删（SoftDeletes 默认软删）。
        TaskReportTemplate::query()->forceDelete();

        $task = ProjectTask::factory()->create([
            'template_id'  => null,
            'flow_item_id' => 999,
        ]);
        $task->project_id = 999;
        $task->save();

        $resolved = app(TemplateResolver::class)->resolveForTask($task);
        $this->assertNull($resolved);
    }

    /**
     * 第 0 档：task.template_id 指向 disabled 模板时跳过该档，落到下一档（project）
     */
    public function test_resolve_skips_disabled_task_template()
    {
        $disabledTpl = $this->createTemplate([
            'scope'    => 'global',
            'scope_id' => 999,
            'name'     => 'Disabled ' . uniqid(),
            'enabled'  => false,
        ]);
        $projectTpl = $this->createTemplate([
            'scope'    => 'project',
            'scope_id' => 100,
            'name'     => 'Proj ' . uniqid(),
        ]);

        $task = ProjectTask::factory()->create([
            'template_id'  => $disabledTpl->id,
            'flow_item_id' => 0,
        ]);
        $task->project_id = 100;
        $task->save();

        $resolved = app(TemplateResolver::class)->resolveForTask($task);
        $this->assertNotNull($resolved);
        $this->assertEquals($projectTpl->id, $resolved->id);
    }

    /**
     * Helper: 创建测试用模板（绕开 AbstractModel::updateInstance + array cast 双编码 bug，
     * 参 Sprint 6 Pass 2 commit fef67c720 范式）
     */
    private function createTemplate(array $attrs): TaskReportTemplate
    {
        $tpl = new TaskReportTemplate();
        $tpl->name        = $attrs['name'] ?? ('Test Tpl ' . uniqid());
        $tpl->scope       = $attrs['scope'] ?? 'global';
        $tpl->scope_id    = $attrs['scope_id'] ?? 0;
        $tpl->is_default  = $attrs['is_default'] ?? false;
        $tpl->is_builtin  = $attrs['is_builtin'] ?? false;
        $tpl->enabled     = $attrs['enabled'] ?? true;
        if (array_key_exists('trigger_rules', $attrs)) {
            $tpl->trigger_rules = $attrs['trigger_rules'];
        } else {
            $tpl->trigger_rules = [];
        }
        $tpl->save();
        return $tpl;
    }
}
