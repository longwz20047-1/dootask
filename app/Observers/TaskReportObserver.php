<?php

// [CUSTOM:report-channel]
// Sprint 2 Task 2.1 · Spec §3.4 字段不变式 + §6.2.1 v2.6 inherit
//
// 命名注：本 Observer 监听 ProjectTask 模型（不是 TaskReport），命名沿用 plan v1.12 一致性。
// 职责：父任务软删/恢复/移动时，级联同步关联 TaskReport（cascade_deleted 标记 + parent_id/project_id 同步）。

namespace App\Observers;

use App\Models\ProjectTask;
use App\Models\TaskReport;

class TaskReportObserver
{
    /**
     * ProjectTask 软删时，级联软删关联的 TaskReport（cascade_deleted=true 标记）。
     *
     * 用 cascade_deleted=true 标记由 task 删除引发的 report 软删，
     * 与用户主动删 report（cascade_deleted=false）区分，便于 task 恢复时定向 restore。
     */
    public function deleting(ProjectTask $task): void
    {
        // forTaskAndChildren 包含本任务 + 子任务（spec §6.2.1 inherit v2.6 §9.2）
        // 必须用此 scope 兜底，因 dootask ProjectTask::deleteTask() 对子任务用 whereParentId->remove()
        // 批量软删无单条 event，靠父任务删的 forTaskAndChildren 级联抓子任务的 reports
        TaskReport::forTaskAndChildren($task)->each(function (TaskReport $report) {
            $report->cascade_deleted = true;
            $report->save();
            $report->delete();
        });
    }

    /**
     * ProjectTask restored 时，级联恢复 cascade_deleted=true 的 reports；
     * 用户主动删的 reports（cascade_deleted=false）保留软删状态，不牵连恢复。
     */
    public function restored(ProjectTask $task): void
    {
        TaskReport::onlyTrashed()
            ->forTaskAndChildren($task)
            ->where('cascade_deleted', true)
            ->each(function (TaskReport $report) {
                $report->restore();
                $report->cascade_deleted = false;
                $report->save();
            });
    }

    /**
     * ProjectTask parent_id / project_id 改变时（moveTask），同步更新关联 reports，
     * 保证 spec §3.4 的 (task_id, parent_id, project_id) 三元组始终一致。
     */
    public function saved(ProjectTask $task): void
    {
        if ($task->wasChanged('parent_id') || $task->wasChanged('project_id')) {
            TaskReport::where('task_id', $task->id)->update([
                'parent_id'  => (int) ($task->parent_id ?? 0),
                'project_id' => (int) $task->project_id,
            ]);
        }
    }
}
