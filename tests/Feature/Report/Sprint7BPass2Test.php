<?php

// [CUSTOM:report-channel] Sprint 7-B Pass 2 测试
// 覆盖 ProjectController 7 新增 endpoints：
//   - report_dashboard__data        (admin 跨项目 / 项目成员 / 拒非成员)
//   - report_dashboard__drill
//   - report_dashboard__export      (简化返 rows)
//   - report_dashboard__charts      (Sprint 9 占位返空)
//   - report_dashboard__save_chart  (占位返错)
//   - report_dashboard__delete_chart(占位返错)
//   - report_field__build_index     (isAdmin 双闸 + 类型校验)
//
// 测试模式：直接 controller 调用 + RequestContext prime auth（与 Sprint7BPass1Test 一致）

namespace Tests\Feature\Report;

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\ProjectController;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectUser;
use App\Models\TaskFieldDefinition;
use App\Models\TaskReport;
use App\Models\User;
use App\Services\RequestContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class Sprint7BPass2Test extends TestCase
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

    private function makeReport(int $projectId, int $taskId, int $userId, array $extra = []): TaskReport
    {
        return TaskReport::factory()->create(array_merge([
            'task_id'         => $taskId,
            'project_id'      => $projectId,
            'reporter_userid' => $userId,
            'cascade_deleted' => false,
        ], $extra));
    }

    // ======================================================================
    // report_dashboard__data
    // ======================================================================

    public function test_dashboard_data_with_admin_no_project_filter(): void
    {
        $admin = User::factory()->create(['identity' => ',admin,']);
        $resp = $this->callController($admin, 'report_dashboard__data', [
            'dimensions' => ['user'],
            'metric'     => 'count',
            'filters'    => [],  // 跨项目，仅 admin 可调
        ]);
        $this->assertSame(1, $resp['ret']);
        $this->assertArrayHasKey('rows', $resp['data']);
        $this->assertArrayHasKey('warning', $resp['data']);
    }

    public function test_dashboard_data_rejects_non_admin_no_project_filter(): void
    {
        $user = User::factory()->create();  // 非 admin
        $resp = $this->callController($user, 'report_dashboard__data', [
            'dimensions' => ['user'],
            'metric'     => 'count',
            'filters'    => [],
        ]);
        $this->assertSame(0, $resp['ret']);
        $this->assertStringContainsString('管理员', $resp['msg']);
    }

    public function test_dashboard_data_with_project_member(): void
    {
        $owner = User::factory()->create();
        $project = $this->createProjectWithOwner($owner);
        $task = ProjectTask::factory()->create([
            'project_id' => $project->id,
            'userid'     => $owner->userid,
        ]);
        $this->makeReport($project->id, $task->id, $owner->userid);

        $resp = $this->callController($owner, 'report_dashboard__data', [
            'dimensions' => ['user'],
            'metric'     => 'count',
            'filters'    => ['project_ids' => [$project->id]],
        ]);
        $this->assertSame(1, $resp['ret']);
        $this->assertGreaterThan(0, count($resp['data']['rows']));
    }

    public function test_dashboard_data_rejects_non_member_in_project_filter(): void
    {
        $owner = User::factory()->create();
        $project = $this->createProjectWithOwner($owner);
        $outsider = User::factory()->create();

        $this->expectException(ApiException::class);
        $this->callController($outsider, 'report_dashboard__data', [
            'dimensions' => ['user'],
            'metric'     => 'count',
            'filters'    => ['project_ids' => [$project->id]],
        ]);
    }

    // ======================================================================
    // report_dashboard__drill
    // ======================================================================

    public function test_dashboard_drill_returns_reports(): void
    {
        $owner = User::factory()->create();
        $project = $this->createProjectWithOwner($owner);
        $task = ProjectTask::factory()->create([
            'project_id' => $project->id,
            'userid'     => $owner->userid,
        ]);
        $this->makeReport($project->id, $task->id, $owner->userid);

        $resp = $this->callController($owner, 'report_dashboard__drill', [
            'filters'  => ['project_ids' => [$project->id]],
            'drill_by' => ['user_id' => $owner->userid],
            'limit'    => 50,
        ]);
        $this->assertSame(1, $resp['ret']);
        $this->assertArrayHasKey('reports', $resp['data']);
        $this->assertGreaterThan(0, count($resp['data']['reports']));
    }

    public function test_dashboard_drill_rejects_non_admin_cross_project(): void
    {
        $user = User::factory()->create();  // 非 admin
        $resp = $this->callController($user, 'report_dashboard__drill', [
            'filters'  => [],
            'drill_by' => ['user_id' => $user->userid],
        ]);
        $this->assertSame(0, $resp['ret']);
        $this->assertStringContainsString('管理员', $resp['msg']);
    }

    // ======================================================================
    // report_dashboard__export
    // ======================================================================

    public function test_dashboard_export_returns_rows_simple(): void
    {
        $admin = User::factory()->create(['identity' => ',admin,']);
        $resp = $this->callController($admin, 'report_dashboard__export', [
            'dimensions' => ['user'],
            'metric'     => 'count',
            'filters'    => [],
            'format'     => 'csv',
        ]);
        $this->assertSame(1, $resp['ret']);
        $this->assertSame('csv', $resp['data']['format']);
        $this->assertArrayHasKey('rows', $resp['data']);
        $this->assertArrayHasKey('note', $resp['data']);
    }

    // ======================================================================
    // report_dashboard__charts / save_chart / delete_chart（占位）
    // ======================================================================

    public function test_dashboard_charts_returns_empty_placeholder(): void
    {
        $user = User::factory()->create();
        $resp = $this->callController($user, 'report_dashboard__charts', []);
        $this->assertSame(1, $resp['ret']);
        $this->assertSame([], $resp['data']['charts']);
        $this->assertStringContainsString('Sprint 9', $resp['data']['note']);
    }

    public function test_dashboard_save_chart_returns_placeholder_error(): void
    {
        $user = User::factory()->create();
        $resp = $this->callController($user, 'report_dashboard__save_chart', []);
        $this->assertSame(0, $resp['ret']);
        $this->assertStringContainsString('Sprint 9', $resp['msg']);
    }

    public function test_dashboard_delete_chart_returns_placeholder_error(): void
    {
        $user = User::factory()->create();
        $resp = $this->callController($user, 'report_dashboard__delete_chart', []);
        $this->assertSame(0, $resp['ret']);
        $this->assertStringContainsString('Sprint 9', $resp['msg']);
    }

    // ======================================================================
    // report_field__build_index（isAdmin 双闸）
    // ======================================================================

    public function test_build_index_rejects_non_admin(): void
    {
        $user = User::factory()->create();  // 非 admin
        $resp = $this->callController($user, 'report_field__build_index', [
            'id' => 1,
            'op' => 'enable',
        ]);
        $this->assertSame(0, $resp['ret']);
        $this->assertStringContainsString('管理员', $resp['msg']);
        $this->assertStringContainsString('DDL', $resp['msg']);
    }

    public function test_build_index_admin_only(): void
    {
        // 建一个 number 类型字段
        $field = TaskFieldDefinition::createInstance([
            'scope'              => 'global',
            'project_id'         => 0,
            'flow_item_id'       => 0,
            'code'               => 'test_idx_' . uniqid(),
            'name'               => '测试索引字段',
            'type'               => 'number',
            'options'            => [],
            'default_value'      => null,
            'required'           => false,
            'sort'               => 0,
            'enabled'            => true,
            'is_builtin'         => false,
            'aggregatable'       => true,
            'aggregate_strategy' => 'sum',
            'has_index'          => false,
        ]);
        $field->save();

        $admin = User::factory()->create(['identity' => ',admin,']);
        $resp = $this->callController($admin, 'report_field__build_index', [
            'id' => $field->id,
            'op' => 'enable',
        ]);
        // taskDeliver 在无 swoole 环境（单测）静默 no-op，仍返回成功
        $this->assertSame(1, $resp['ret']);
        $this->assertStringContainsString('索引', $resp['msg']);
    }

    public function test_build_index_rejects_invalid_op(): void
    {
        $admin = User::factory()->create(['identity' => ',admin,']);
        $resp = $this->callController($admin, 'report_field__build_index', [
            'id' => 1,
            'op' => 'foo',
        ]);
        $this->assertSame(0, $resp['ret']);
        $this->assertStringContainsString('enable', $resp['msg']);
    }

    public function test_build_index_rejects_missing_id(): void
    {
        $admin = User::factory()->create(['identity' => ',admin,']);
        $resp = $this->callController($admin, 'report_field__build_index', [
            'op' => 'enable',
        ]);
        $this->assertSame(0, $resp['ret']);
        $this->assertStringContainsString('id 必填', $resp['msg']);
    }
}
