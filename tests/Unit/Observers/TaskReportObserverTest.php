<?php

// [CUSTOM:report-channel]
// Sprint 2 Task 2.1 · TaskReportObserver 单元测试
// Spec §3.4 字段不变式 + §6.2.1 v2.6 inherit。

namespace Tests\Unit\Observers;

use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\TaskReport;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class TaskReportObserverTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 帮手：创建 task + 关联的一份 report，便于断言级联行为。
     */
    private function setupTaskWithReport(array $taskOverrides = [], array $reportOverrides = []): array
    {
        $task = ProjectTask::factory()->create($taskOverrides);
        $report = TaskReport::factory()->create(array_merge([
            'task_id'         => $task->id,
            'parent_id'       => (int) ($task->parent_id ?? 0),
            'project_id'      => (int) $task->project_id,
            'cascade_deleted' => false,
        ], $reportOverrides));

        return compact('task', 'report');
    }

    public function test_task_soft_delete_cascades_to_reports()
    {
        ['task' => $task, 'report' => $report] = $this->setupTaskWithReport();

        $task->delete();

        $this->assertSoftDeleted('project_task_reports', ['id' => $report->id]);
        $this->assertTrue((bool) TaskReport::withTrashed()->find($report->id)->cascade_deleted);
    }

    public function test_task_restore_cascades_to_cascade_deleted_reports()
    {
        ['task' => $task, 'report' => $report] = $this->setupTaskWithReport();
        $task->delete();
        $this->assertSoftDeleted('project_task_reports', ['id' => $report->id]);

        $task->restore();

        $this->assertNotSoftDeleted('project_task_reports', ['id' => $report->id]);
        $this->assertFalse((bool) TaskReport::find($report->id)->cascade_deleted);
    }

    /**
     * NF: 用户主动删的 report (cascade_deleted=false) 在 task 恢复时不应被牵连恢复。
     */
    public function test_user_deleted_report_not_restored_on_task_restore()
    {
        ['task' => $task, 'report' => $report] = $this->setupTaskWithReport();

        // 用户主动删 report，cascade_deleted 保持 false
        $report->delete();
        $this->assertFalse((bool) TaskReport::withTrashed()->find($report->id)->cascade_deleted);

        // 紧接着 task 软删（observer 不应触碰已软删的 report）+ 恢复
        $task->delete();
        $task->restore();

        // 该 report 仍是软删状态（用户意图保留）
        $this->assertSoftDeleted('project_task_reports', ['id' => $report->id]);
    }

    public function test_task_parent_id_change_cascades_to_reports()
    {
        ['task' => $task, 'report' => $report] = $this->setupTaskWithReport();

        $newParent = ProjectTask::factory()->create(['project_id' => $task->project_id]);
        $task->parent_id = $newParent->id;
        $task->save();

        $this->assertEquals($newParent->id, (int) $report->fresh()->parent_id);
    }

    public function test_task_project_id_change_cascades_to_reports()
    {
        ['task' => $task, 'report' => $report] = $this->setupTaskWithReport();

        $newProject = Project::factory()->create();
        $task->project_id = $newProject->id;
        $task->save();

        $this->assertEquals($newProject->id, (int) $report->fresh()->project_id);
    }
}
