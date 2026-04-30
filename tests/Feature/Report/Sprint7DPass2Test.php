<?php

// [CUSTOM:report-channel] Sprint 7-D Pass 2 测试
// 覆盖 ProjectController::task__lists 改造：
//   - report_status 三态（not_reported / partial_reported / all_reported）
//   - has_my_report 过滤（false 仅未汇报 / true 仅已汇报）
//
// 测试模式：直接 controller 调用 + RequestContext prime auth（与 Sprint7DPass1Test 一致）
//
// 关键约定：
//   - DatabaseTransactions trait 隔离测试间数据
//   - task__lists 用 project_id 走 allData 路径，task 默认 visibility=1 通过可见性
//   - 用户为 ProjectUser owner=1，确保通过 Project::userProject 校验

namespace Tests\Feature\Report;

use App\Http\Controllers\Api\ProjectController;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectTaskUser;
use App\Models\ProjectUser;
use App\Models\TaskReport;
use App\Models\User;
use App\Services\RequestContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class Sprint7DPass2Test extends TestCase
{
    use DatabaseTransactions;

    private function primeAuth(User $user): void
    {
        $rid = 'req_test_' . uniqid();
        request()->attributes->set('request_id', $rid);
        RequestContext::save('auth', $user, $rid);
    }

    private function callController(User $user, string $method, array $input): array
    {
        $this->primeAuth($user);
        // task__lists 用 TimeRange::parse(timerange)，缺省为 null 时 explode 返 1 元素，
        // PHP 8 下 $range[1] 抛 "Undefined array key 1"。给个空区间字符串。
        if (!isset($input['timerange'])) {
            $input['timerange'] = ',';
        }
        request()->replace($input);
        $controller = new ProjectController();
        return $controller->$method();
    }

    private function createProjectWithOwner(User $owner): Project
    {
        $project = Project::factory()->create(['userid' => $owner->userid]);
        ProjectUser::createInstance([
            'project_id' => $project->id,
            'userid'     => $owner->userid,
            'owner'      => 1,
        ])->save();
        return $project;
    }

    private function attachTaskUser(int $projectId, int $taskId, int $userid, int $owner = 1): void
    {
        ProjectTaskUser::createInstance([
            'project_id' => $projectId,
            'task_id'    => $taskId,
            'task_pid'   => 0,
            'userid'     => $userid,
            'owner'      => $owner,
        ])->save();
    }

    private function makeReport(int $projectId, int $taskId, int $userid): TaskReport
    {
        return TaskReport::factory()->create([
            'task_id'         => $taskId,
            'project_id'      => $projectId,
            'reporter_userid' => $userid,
            'cascade_deleted' => false,
        ]);
    }

    /**
     * 在 task__lists 返回数据中按 id 找一行，断言失败即返 null
     */
    private function findRowById(array $resp, int $taskId): ?array
    {
        $rows = $resp['data']['data'] ?? [];
        foreach ($rows as $row) {
            if ((int) $row['id'] === $taskId) {
                return $row;
            }
        }
        return null;
    }

    // ======================================================================
    // report_status 三态
    // ======================================================================

    public function test_task_lists_includes_report_status_field(): void
    {
        $owner = User::factory()->create();
        $project = $this->createProjectWithOwner($owner);
        $task = ProjectTask::factory()->create([
            'project_id' => $project->id,
            'userid'     => $owner->userid,
        ]);
        $this->attachTaskUser($project->id, $task->id, $owner->userid);

        $resp = $this->callController($owner, 'task__lists', [
            'project_id' => $project->id,
            'keys'       => [],
        ]);

        $this->assertSame(1, $resp['ret']);
        $row = $this->findRowById($resp, $task->id);
        $this->assertNotNull($row, '应包含目标任务行');
        $this->assertArrayHasKey('report_status', $row);
    }

    public function test_task_lists_report_status_not_reported_for_no_reports(): void
    {
        $owner = User::factory()->create();
        $project = $this->createProjectWithOwner($owner);
        $task = ProjectTask::factory()->create([
            'project_id' => $project->id,
            'userid'     => $owner->userid,
        ]);
        $this->attachTaskUser($project->id, $task->id, $owner->userid);

        $resp = $this->callController($owner, 'task__lists', [
            'project_id' => $project->id,
            'keys'       => [],
        ]);
        $row = $this->findRowById($resp, $task->id);
        $this->assertNotNull($row);
        $this->assertSame('not_reported', $row['report_status']);
    }

    public function test_task_lists_report_status_partial_reported(): void
    {
        // expected_user_count = 2 (owner + collaborator)
        // 仅 owner 汇报 1 次 → reporter_count=1 < 2 → partial_reported
        $owner = User::factory()->create();
        $collaborator = User::factory()->create();
        $project = $this->createProjectWithOwner($owner);
        ProjectUser::createInstance([
            'project_id' => $project->id,
            'userid'     => $collaborator->userid,
            'owner'      => 0,
        ])->save();

        $task = ProjectTask::factory()->create([
            'project_id' => $project->id,
            'userid'     => $owner->userid,
        ]);
        $this->attachTaskUser($project->id, $task->id, $owner->userid, 1);
        $this->attachTaskUser($project->id, $task->id, $collaborator->userid, 0);

        $this->makeReport($project->id, $task->id, $owner->userid);

        $resp = $this->callController($owner, 'task__lists', [
            'project_id' => $project->id,
            'keys'       => [],
        ]);
        $row = $this->findRowById($resp, $task->id);
        $this->assertNotNull($row);
        $this->assertSame('partial_reported', $row['report_status']);
    }

    public function test_task_lists_report_status_all_reported(): void
    {
        // expected_user_count = 2 (owner + collaborator)
        // 两人各汇报 1 次 → DISTINCT reporter_count=2 >= 2 → all_reported
        $owner = User::factory()->create();
        $collaborator = User::factory()->create();
        $project = $this->createProjectWithOwner($owner);
        ProjectUser::createInstance([
            'project_id' => $project->id,
            'userid'     => $collaborator->userid,
            'owner'      => 0,
        ])->save();

        $task = ProjectTask::factory()->create([
            'project_id' => $project->id,
            'userid'     => $owner->userid,
        ]);
        $this->attachTaskUser($project->id, $task->id, $owner->userid, 1);
        $this->attachTaskUser($project->id, $task->id, $collaborator->userid, 0);

        $this->makeReport($project->id, $task->id, $owner->userid);
        $this->makeReport($project->id, $task->id, $collaborator->userid);

        $resp = $this->callController($owner, 'task__lists', [
            'project_id' => $project->id,
            'keys'       => [],
        ]);
        $row = $this->findRowById($resp, $task->id);
        $this->assertNotNull($row);
        $this->assertSame('all_reported', $row['report_status']);
    }

    // ======================================================================
    // has_my_report 过滤
    // ======================================================================

    public function test_task_lists_filter_has_my_report_false_excludes_my_reported(): void
    {
        $owner = User::factory()->create();
        $project = $this->createProjectWithOwner($owner);

        $unreported = ProjectTask::factory()->create([
            'project_id' => $project->id,
            'userid'     => $owner->userid,
        ]);
        $this->attachTaskUser($project->id, $unreported->id, $owner->userid);

        $reported = ProjectTask::factory()->create([
            'project_id' => $project->id,
            'userid'     => $owner->userid,
        ]);
        $this->attachTaskUser($project->id, $reported->id, $owner->userid);
        $this->makeReport($project->id, $reported->id, $owner->userid);

        $resp = $this->callController($owner, 'task__lists', [
            'project_id'     => $project->id,
            'keys'           => [],
            'has_my_report'  => '0',
        ]);
        $rows = $resp['data']['data'] ?? [];
        $ids = array_column($rows, 'id');
        $this->assertContains($unreported->id, $ids);
        $this->assertNotContains($reported->id, $ids);
    }

    public function test_task_lists_filter_has_my_report_true_includes_only_my_reported(): void
    {
        $owner = User::factory()->create();
        $project = $this->createProjectWithOwner($owner);

        $unreported = ProjectTask::factory()->create([
            'project_id' => $project->id,
            'userid'     => $owner->userid,
        ]);
        $this->attachTaskUser($project->id, $unreported->id, $owner->userid);

        $reported = ProjectTask::factory()->create([
            'project_id' => $project->id,
            'userid'     => $owner->userid,
        ]);
        $this->attachTaskUser($project->id, $reported->id, $owner->userid);
        $this->makeReport($project->id, $reported->id, $owner->userid);

        $resp = $this->callController($owner, 'task__lists', [
            'project_id'     => $project->id,
            'keys'           => [],
            'has_my_report'  => '1',
        ]);
        $rows = $resp['data']['data'] ?? [];
        $ids = array_column($rows, 'id');
        $this->assertContains($reported->id, $ids);
        $this->assertNotContains($unreported->id, $ids);
    }
}
