<?php

// [CUSTOM:report-channel] Sprint 7-A Task 7.1
// Spec §6.5 v3.26 4 档优先级模板查找服务
//
// 4 档优先级（自顶向下，命中即返回）：
//   0. task.template_id 显式（v3.26 §3.9bis 最高优先级，留空跳过本档）
//   1. flow_item_id 命中（v3.3 原档）
//   2. project_id 命中（v3.3 原档）
//   3. global default（v3.8 双护栏：is_default=true + builtin seed 兜底）
//
// 下游消费者（统一调用 resolveForTask，命名锁定 v3.24 P0-V3.24-1）：
//   - TriggerEngine（Sprint 7-A Task 7.2）
//   - ProjectTask::saving 钩子 shouldTriggerForCurrentUser（Sprint 7-A Task 7.6）
//   - PendingReportService（Sprint 7-D Task 7-D.2）评估 block on_complete 规则
//
// 设计选择：
//   - 不引入 cache 层（spec §6.5 未要求；4 个 query 单次调用性能可接受；
//     FieldDefinitionCache 是字段级 cache，本服务为模板级，作用域不同）
//   - 所有档位附加 enabled=true 过滤（被禁用模板视为不存在，跳到下一档）

namespace App\Services\TaskReport;

use App\Models\ProjectTask;
use App\Models\TaskReportTemplate;

class TemplateResolver
{
    /**
     * 4 档优先级查找模板（spec §6.5 v3.26）
     *
     * @param ProjectTask $task 任务实例（含 template_id / flow_item_id / project_id）
     * @return TaskReportTemplate|null 命中的模板；极端 case（连 global default 都没）返回 null
     */
    public function resolveForTask(ProjectTask $task): ?TaskReportTemplate
    {
        // 第 0 档：task.template_id 显式（v3.26 §3.9bis 最高优先级）
        if (!empty($task->template_id)) {
            $tpl = TaskReportTemplate::query()
                ->where('id', $task->template_id)
                ->where('enabled', true)
                ->first();
            if ($tpl) {
                return $tpl;
            }
        }

        // 第 1 档：flow_item 命中
        if (!empty($task->flow_item_id)) {
            $tpl = TaskReportTemplate::query()
                ->where('scope', 'flow_item')
                ->where('scope_id', $task->flow_item_id)
                ->where('enabled', true)
                ->first();
            if ($tpl) {
                return $tpl;
            }
        }

        // 第 2 档：project 命中
        if (!empty($task->project_id)) {
            $tpl = TaskReportTemplate::query()
                ->where('scope', 'project')
                ->where('scope_id', $task->project_id)
                ->where('enabled', true)
                ->first();
            if ($tpl) {
                return $tpl;
            }
        }

        // 第 3 档：global default 兜底（spec §6.5 双护栏 is_default=true + builtin seed）
        // 注：实际查询仅需 is_default=true 即可（Observer 守护 builtin global default
        //     不可删，且 Sprint 6 migration seed 保证至少 1 行）。is_builtin 由 Observer
        //     语义保证，不在 query 条件里强制以避免被人为修改 builtin 后无兜底。
        return TaskReportTemplate::query()
            ->where('scope', 'global')
            ->where('is_default', true)
            ->where('enabled', true)
            ->first();
    }
}
