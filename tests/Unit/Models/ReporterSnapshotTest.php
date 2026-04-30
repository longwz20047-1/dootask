<?php

// [CUSTOM:report-channel]
// Sprint 2 Task 2.2 · reporter_userid 离职快照（NF3）
// Spec §3.4 reporter_userid 不漂移：
//   - 创建后不可改（updating 阶段 silent revert）
//   - 上报人离职/disable_at 设置后，report 上的 reporter_userid 仍保留原 userid

namespace Tests\Unit\Models;

use App\Models\ProjectTask;
use App\Models\TaskReport;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ReporterSnapshotTest extends TestCase
{
    use DatabaseTransactions;

    public function test_reporter_userid_immutable_on_update()
    {
        $reporter = User::factory()->create();
        $other    = User::factory()->create();
        $task     = ProjectTask::factory()->create();

        $report = TaskReport::factory()->create([
            'task_id'         => $task->id,
            'parent_id'       => 0,
            'project_id'      => $task->project_id,
            'reporter_userid' => $reporter->userid,
            'cascade_deleted' => false,
        ]);

        $original = $report->reporter_userid;

        // 尝试改 reporter_userid（应被 updating hook silent revert）
        $report->reporter_userid = $other->userid;
        $report->save();

        $this->assertEquals($original, (int) $report->fresh()->reporter_userid);
    }

    /**
     * 即便上报人 disable_at 被设置（dootask "禁用账号" 语义），
     * 既有 report 上的 reporter_userid 仍保留原值——快照不漂移。
     */
    public function test_reporter_userid_persists_after_user_disabled()
    {
        $reporter = User::factory()->create();
        $task     = ProjectTask::factory()->create();

        $report = TaskReport::factory()->create([
            'task_id'         => $task->id,
            'parent_id'       => 0,
            'project_id'      => $task->project_id,
            'reporter_userid' => $reporter->userid,
            'cascade_deleted' => false,
        ]);

        // 禁用上报人账号
        $reporter->disable_at = now();
        $reporter->save();

        $this->assertEquals($reporter->userid, (int) $report->fresh()->reporter_userid);
    }

    /**
     * 同一份 report 在改其他字段（如 values / work_date）时，
     * reporter_userid 不应被附带影响。
     */
    public function test_reporter_userid_unchanged_when_other_fields_updated()
    {
        $reporter = User::factory()->create();
        $task     = ProjectTask::factory()->create();

        $report = TaskReport::factory()->create([
            'task_id'         => $task->id,
            'parent_id'       => 0,
            'project_id'      => $task->project_id,
            'reporter_userid' => $reporter->userid,
            'values'          => ['hours' => 4],
            'cascade_deleted' => false,
        ]);

        $report->values    = ['hours' => 6, 'note' => 'updated'];
        $report->work_date = '2026-04-30';
        $report->save();

        $fresh = $report->fresh();
        $this->assertEquals($reporter->userid, (int) $fresh->reporter_userid);
        $this->assertEquals(6, $fresh->values['hours']);
    }

    // =====================================================================
    // [CUSTOM:report-channel] Sprint 5b.5 gap fill — 离职用户处置补缺
    // 既有 3 case 覆盖：reporter_userid 不漂移本身。本节补"下游路径仍可用"。
    // =====================================================================

    /**
     * Sprint 5b.5 上报人禁用后，TaskReport->reporter 关联仍可解析到原 user
     * （不做 disable_at 过滤，否则 list 端点丢字段）。
     */
    public function test_reporter_relation_still_resolves_after_user_disabled()
    {
        $reporter = User::factory()->create();
        $task     = ProjectTask::factory()->create();

        $report = TaskReport::factory()->create([
            'task_id'         => $task->id,
            'parent_id'       => 0,
            'project_id'      => $task->project_id,
            'reporter_userid' => $reporter->userid,
            'cascade_deleted' => false,
        ]);

        // 禁用账号
        $reporter->disable_at = now();
        $reporter->save();

        // reporter 关联仍能解析到原 user（即使 disable_at 已设）
        $fresh = $report->fresh();
        $this->assertNotNull($fresh->reporter);
        $this->assertEquals($reporter->userid, $fresh->reporter->userid);
        $this->assertNotNull($fresh->reporter->disable_at);
    }

    /**
     * Sprint 5b.5 离职上报人的历史 report 仍可在 forTaskAndChildren scope 查询返回
     * （不被 reporter disable_at 排除）。
     */
    public function test_disabled_reporter_reports_still_returned_by_scope()
    {
        $reporter = User::factory()->create();
        $task     = ProjectTask::factory()->create();

        $report = TaskReport::factory()->create([
            'task_id'         => $task->id,
            'parent_id'       => 0,
            'project_id'      => $task->project_id,
            'reporter_userid' => $reporter->userid,
            'cascade_deleted' => false,
        ]);

        // 离职
        $reporter->disable_at = now();
        $reporter->save();

        // scope 查询不应过滤掉离职用户的历史 report
        $reports = TaskReport::forTaskAndChildren($task->id)->get();
        $this->assertCount(1, $reports);
        $this->assertEquals($report->id, $reports->first()->id);
        $this->assertEquals($reporter->userid, (int) $reports->first()->reporter_userid);
    }
}
