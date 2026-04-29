<?php

// [CUSTOM:report-channel]
// Spec §3.5 TaskReport Eloquent Model 单元测试
//
// 使用 DatabaseTransactions（不用 RefreshDatabase）：
// dootask Swoole 常驻 + migrate:fresh 触发 Config Facade 静态污染（spec §9.1 已踩）。
// 既有 WecomMarkdownRendererTest / WecomInternalTokenTest 均沿用此范式。

namespace Tests\Unit\Models;

use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\TaskReport;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class TaskReportTest extends TestCase
{
    use DatabaseTransactions;

    public function test_values_cast_to_array()
    {
        $report = $this->createReport([
            'values' => ['hours' => 4, 'note' => 'test'],
        ]);

        $fresh = TaskReport::find($report->id);
        $this->assertIsArray($fresh->values);
        $this->assertEquals(4, $fresh->values['hours']);
        $this->assertEquals('test', $fresh->values['note']);
    }

    public function test_work_date_cast_to_date()
    {
        $report = $this->createReport(['work_date' => '2026-04-29']);
        $fresh = TaskReport::find($report->id);
        $this->assertInstanceOf(Carbon::class, $fresh->work_date);
        $this->assertEquals('2026-04-29', $fresh->work_date->format('Y-m-d'));
    }

    public function test_cascade_deleted_cast_to_bool()
    {
        $report = $this->createReport(['cascade_deleted' => 1]);
        $fresh = TaskReport::find($report->id);
        $this->assertTrue($fresh->cascade_deleted);
        $this->assertIsBool($fresh->cascade_deleted);
    }

    public function test_reporter_relation_resolves_user()
    {
        $user = User::factory()->create();
        $report = $this->createReport(['reporter_userid' => $user->userid]);

        $this->assertNotNull($report->reporter);
        $this->assertEquals($user->userid, $report->reporter->userid);
    }

    public function test_task_relation_resolves_project_task()
    {
        $task = ProjectTask::factory()->create();
        $report = $this->createReport(['task_id' => $task->id]);

        $this->assertNotNull($report->task);
        $this->assertEquals($task->id, $report->task->id);
    }

    public function test_scope_for_task_and_children_returns_parent_and_subtasks()
    {
        $parent = ProjectTask::factory()->create();
        $child  = ProjectTask::factory()->create([
            'parent_id'  => $parent->id,
            'project_id' => $parent->project_id,
        ]);

        $this->createReport(['task_id' => $parent->id]);
        $this->createReport(['task_id' => $child->id, 'parent_id' => $parent->id]);

        $reports = TaskReport::forTaskAndChildren($parent->id)->get();
        $this->assertCount(2, $reports);
    }

    public function test_soft_delete_marks_deleted_at()
    {
        $report = $this->createReport();
        $report->delete();

        $this->assertSoftDeleted('project_task_reports', ['id' => $report->id]);
        $this->assertNull(TaskReport::find($report->id));
        $this->assertNotNull(TaskReport::withTrashed()->find($report->id));
    }

    /**
     * 创建一份 TaskReport，可覆盖任意字段。
     */
    private function createReport(array $overrides = []): TaskReport
    {
        return TaskReport::factory()->create($overrides);
    }
}
