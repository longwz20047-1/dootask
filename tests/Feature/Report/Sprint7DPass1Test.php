<?php

// [CUSTOM:report-channel] Sprint 7-D Pass 1 测试
// 覆盖：
//   - PendingReportService::listForUser 4 边界过滤 + 3 特性
//   - report__pending_list endpoint 权限/参数
//
// 测试模式：直接 controller 调用 + RequestContext prime auth（与 Sprint7BPass2Test 一致）
//
// 关键约定：
//   - DatabaseTransactions trait 隔离测试间数据
//   - 模板用 `new + 直接属性赋值` 绕开 array cast 双编码（Sprint 6 Pass 2 fef67c720 范式）

namespace Tests\Feature\Report;

use App\Http\Controllers\Api\ProjectController;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectTaskUser;
use App\Models\ProjectUser;
use App\Models\TaskReport;
use App\Models\TaskReportTemplate;
use App\Models\User;
use App\Services\RequestContext;
use App\Services\TaskReport\PendingReportService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Sprint7DPass1Test extends TestCase
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

    private function makeReport(int $projectId, int $taskId, int $userId): TaskReport
    {
        return TaskReport::factory()->create([
            'task_id'         => $taskId,
            'project_id'      => $projectId,
            'reporter_userid' => $userId,
            'cascade_deleted' => false,
        ]);
    }

    /**
     * 把 Sprint 6 seed 的 builtin global default 模板的 trigger_rules 替换为指定规则集，
     * 避免对 PendingReportService PHP 层 block min_count 评估造成默认副作用。
     * （Observer 拒改 builtin 关键属性，但 trigger_rules 不在守护范围内）
     */
    private function rewriteBuiltinDefaultTriggerRules(array $rules): void
    {
        DB::table('task_report_templates')
            ->where('is_builtin', true)
            ->where('scope', 'global')
            ->where('is_default', true)
            ->update(['trigger_rules' => json_encode($rules, JSON_UNESCAPED_UNICODE)]);
    }

    /**
     * 默认场景：清空 builtin global default 的 trigger_rules（min_count 默认 1，
     * 已汇报 1 次即视为已满足，未汇报视为待办）。
     */
    private function clearBuiltinTriggerRules(): void
    {
        $this->rewriteBuiltinDefaultTriggerRules([]);
    }

    // ======================================================================
    // PendingReportService: 4 边界过滤
    // ======================================================================

    public function test_pending_list_excludes_soft_deleted_tasks(): void
    {
        $this->clearBuiltinTriggerRules();
        $owner = User::factory()->create();
        $project = $this->createProjectWithOwner($owner);
        $kept = ProjectTask::factory()->create([
            'project_id' => $project->id,
            'userid'     => $owner->userid,
        ]);
        $deleted = ProjectTask::factory()->create([
            'project_id' => $project->id,
            'userid'     => $owner->userid,
        ]);
        // 软删
        DB::table('project_tasks')->where('id', $deleted->id)->update(['deleted_at' => now()]);

        $svc = app(PendingReportService::class);
        $result = $svc->listForUser($owner->userid, $project->id);

        $ids = $result->pluck('id')->all();
        $this->assertContains($kept->id, $ids);
        $this->assertNotContains($deleted->id, $ids);
    }

    public function test_pending_list_filter_archived_unless_include_flag(): void
    {
        $this->clearBuiltinTriggerRules();
        $owner = User::factory()->create();
        $project = $this->createProjectWithOwner($owner);
        $live = ProjectTask::factory()->create([
            'project_id' => $project->id,
            'userid'     => $owner->userid,
        ]);
        $archived = ProjectTask::factory()->create([
            'project_id'  => $project->id,
            'userid'      => $owner->userid,
            'archived_at' => now(),
        ]);

        $svc = app(PendingReportService::class);

        // 默认：不含归档
        $defaultResult = $svc->listForUser($owner->userid, $project->id);
        $defaultIds = $defaultResult->pluck('id')->all();
        $this->assertContains($live->id, $defaultIds);
        $this->assertNotContains($archived->id, $defaultIds);

        // include_archived=true：含归档
        $includedResult = $svc->listForUser(
            $owner->userid,
            $project->id,
            50,
            null,
            null,
            true
        );
        $includedIds = $includedResult->pluck('id')->all();
        $this->assertContains($live->id, $includedIds);
        $this->assertContains($archived->id, $includedIds);
    }

    public function test_pending_list_filter_archived_project(): void
    {
        $this->clearBuiltinTriggerRules();
        $owner = User::factory()->create();
        $liveProject = $this->createProjectWithOwner($owner);
        $archivedProject = $this->createProjectWithOwner($owner);
        DB::table('projects')->where('id', $archivedProject->id)->update(['archived_at' => now()]);

        $liveTask = ProjectTask::factory()->create([
            'project_id' => $liveProject->id,
            'userid'     => $owner->userid,
        ]);
        $deadTask = ProjectTask::factory()->create([
            'project_id' => $archivedProject->id,
            'userid'     => $owner->userid,
        ]);

        $svc = app(PendingReportService::class);
        $result = $svc->listForUser($owner->userid);

        $ids = $result->pluck('id')->all();
        $this->assertContains($liveTask->id, $ids);
        $this->assertNotContains($deadTask->id, $ids);
    }

    public function test_pending_list_collaborator_realtime_via_project_task_users(): void
    {
        $this->clearBuiltinTriggerRules();
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

        // 协助人加入 project_task_users 含 owner=0
        ProjectTaskUser::createInstance([
            'project_id' => $project->id,
            'task_id'    => $task->id,
            'task_pid'   => 0,
            'userid'     => $collaborator->userid,
            'owner'      => 0,
        ])->save();

        $svc = app(PendingReportService::class);
        $result = $svc->listForUser($collaborator->userid, $project->id);

        $ids = $result->pluck('id')->all();
        $this->assertContains($task->id, $ids);
    }

    // ======================================================================
    // PendingReportService: 3 特性
    // ======================================================================

    public function test_pending_list_filters_by_project_id(): void
    {
        $this->clearBuiltinTriggerRules();
        $owner = User::factory()->create();
        $projectA = $this->createProjectWithOwner($owner);
        $projectB = $this->createProjectWithOwner($owner);

        $taskA = ProjectTask::factory()->create([
            'project_id' => $projectA->id,
            'userid'     => $owner->userid,
        ]);
        $taskB = ProjectTask::factory()->create([
            'project_id' => $projectB->id,
            'userid'     => $owner->userid,
        ]);

        $svc = app(PendingReportService::class);
        $result = $svc->listForUser($owner->userid, $projectA->id);

        $ids = $result->pluck('id')->all();
        $this->assertContains($taskA->id, $ids);
        $this->assertNotContains($taskB->id, $ids);
    }

    public function test_pending_list_excludes_already_reported_tasks(): void
    {
        // 默认 min_count=1：已汇报 1 次即视为满足
        $this->clearBuiltinTriggerRules();
        $owner = User::factory()->create();
        $project = $this->createProjectWithOwner($owner);

        $unreported = ProjectTask::factory()->create([
            'project_id' => $project->id,
            'userid'     => $owner->userid,
        ]);
        $reported = ProjectTask::factory()->create([
            'project_id' => $project->id,
            'userid'     => $owner->userid,
        ]);
        $this->makeReport($project->id, $reported->id, $owner->userid);

        $svc = app(PendingReportService::class);
        $result = $svc->listForUser($owner->userid, $project->id);

        $ids = $result->pluck('id')->all();
        $this->assertContains($unreported->id, $ids);
        $this->assertNotContains($reported->id, $ids);
    }

    public function test_pending_list_includes_partial_reported_tasks_with_block_min_count_2(): void
    {
        // 模板要求 min_count=2，已汇报 1 次仍视为待办
        $this->rewriteBuiltinDefaultTriggerRules([
            ['event' => 'on_complete', 'mode' => 'block', 'target' => 'reporter',
             'constraint' => ['min_count' => 2]],
        ]);

        $owner = User::factory()->create();
        $project = $this->createProjectWithOwner($owner);

        $task = ProjectTask::factory()->create([
            'project_id' => $project->id,
            'userid'     => $owner->userid,
        ]);
        // 仅汇报 1 次（< min_count 2）
        $this->makeReport($project->id, $task->id, $owner->userid);

        $svc = app(PendingReportService::class);
        $result = $svc->listForUser($owner->userid, $project->id);

        $ids = $result->pluck('id')->all();
        $this->assertContains($task->id, $ids);

        // 再汇报 1 次（凑满 2 次）→ 移出 pending
        $this->makeReport($project->id, $task->id, $owner->userid);
        $result2 = $svc->listForUser($owner->userid, $project->id);
        $this->assertNotContains($task->id, $result2->pluck('id')->all());
    }

    public function test_pending_list_calculates_is_urgent_for_tasks_with_deadline_lt_24h(): void
    {
        $this->clearBuiltinTriggerRules();
        $owner = User::factory()->create();
        $project = $this->createProjectWithOwner($owner);

        $urgent = ProjectTask::factory()->create([
            'project_id' => $project->id,
            'userid'     => $owner->userid,
            'end_at'     => now()->addHours(6), // < 24h
        ]);
        $faraway = ProjectTask::factory()->create([
            'project_id' => $project->id,
            'userid'     => $owner->userid,
            'end_at'     => now()->addDays(10), // > 24h
        ]);
        $noDeadline = ProjectTask::factory()->create([
            'project_id' => $project->id,
            'userid'     => $owner->userid,
            'end_at'     => null,
        ]);

        $svc = app(PendingReportService::class);
        $result = $svc->listForUser($owner->userid, $project->id);
        $byId = $result->keyBy('id');

        $this->assertTrue((bool) $byId[$urgent->id]->is_urgent);
        $this->assertFalse((bool) $byId[$faraway->id]->is_urgent);
        $this->assertFalse((bool) $byId[$noDeadline->id]->is_urgent);
    }

    public function test_pending_list_calculates_my_role_owner_vs_collaborator(): void
    {
        $this->clearBuiltinTriggerRules();
        $owner = User::factory()->create();
        $collaborator = User::factory()->create();
        $project = $this->createProjectWithOwner($owner);
        ProjectUser::createInstance([
            'project_id' => $project->id,
            'userid'     => $collaborator->userid,
            'owner'      => 0,
        ])->save();

        $ownedTask = ProjectTask::factory()->create([
            'project_id' => $project->id,
            'userid'     => $collaborator->userid,
        ]);
        $assistedTask = ProjectTask::factory()->create([
            'project_id' => $project->id,
            'userid'     => $owner->userid,
        ]);
        ProjectTaskUser::createInstance([
            'project_id' => $project->id,
            'task_id'    => $assistedTask->id,
            'task_pid'   => 0,
            'userid'     => $collaborator->userid,
            'owner'      => 0,
        ])->save();

        $svc = app(PendingReportService::class);
        $result = $svc->listForUser($collaborator->userid, $project->id);
        $byId = $result->keyBy('id');

        $this->assertSame('owner', $byId[$ownedTask->id]->my_role);
        $this->assertSame('collaborator', $byId[$assistedTask->id]->my_role);
    }

    // ======================================================================
    // report__pending_list endpoint
    // ======================================================================

    public function test_pending_list_endpoint_returns_data(): void
    {
        $this->clearBuiltinTriggerRules();
        $owner = User::factory()->create();
        $project = $this->createProjectWithOwner($owner);
        $task = ProjectTask::factory()->create([
            'project_id' => $project->id,
            'userid'     => $owner->userid,
        ]);

        $resp = $this->callController($owner, 'report__pending_list', [
            'project_id' => $project->id,
        ]);

        $this->assertSame(1, $resp['ret']);
        $ids = array_column($resp['data'], 'id');
        $this->assertContains($task->id, $ids);
    }

    public function test_pending_list_endpoint_rejects_query_others_unless_admin(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $resp = $this->callController($owner, 'report__pending_list', [
            'userid' => $other->userid,
        ]);
        $this->assertSame(0, $resp['ret']);
        $this->assertStringContainsString('管理员', $resp['msg']);
    }

    public function test_pending_list_endpoint_admin_can_query_others(): void
    {
        $this->clearBuiltinTriggerRules();
        $admin = User::factory()->create(['identity' => ',admin,']);
        $target = User::factory()->create();
        $project = $this->createProjectWithOwner($target);
        $task = ProjectTask::factory()->create([
            'project_id' => $project->id,
            'userid'     => $target->userid,
        ]);

        $resp = $this->callController($admin, 'report__pending_list', [
            'userid' => $target->userid,
        ]);

        $this->assertSame(1, $resp['ret']);
        $ids = array_column($resp['data'], 'id');
        $this->assertContains($task->id, $ids);
    }
}
