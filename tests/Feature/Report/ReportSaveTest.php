<?php

// [CUSTOM:report-channel]
// Sprint 1 Pass 3 · Task 1.7 · Feature 测试：ProjectController::report__save
//
// 测试策略：直接调用 controller 方法（非 HTTP），通过 RequestContext::save('auth', $user)
// 跳过 dootask 真 token + Doo Swoole FFI 链路（Doo::tokenEncode 依赖 so 扩展，PHPUnit 进程下不可用）。
// User::auth() → User::authInfo() 第一句即 `if (RequestContext::has('auth')) return get('auth')`，
// 故 prime 后 controller 走分支无副作用。
//
// 同时用 Request::merge([...]) 注入入参（dootask 风格 `Request::input(...)` 直接读全局 Request facade）。

namespace Tests\Feature\Report;

use App\Http\Controllers\Api\ProjectController;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectUser;
use App\Models\TaskReport;
use App\Models\User;
use App\Services\RequestContext;
use App\Services\TaskReport\FieldDefinitionCache;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Tests\TestCase;

class ReportSaveTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        // 防 per-request 静态缓存跨 case 串：FieldDefinitionCache 是静态属性
        FieldDefinitionCache::flushAll();
    }

    /**
     * 创建项目 + 任务 + project_users 绑定（owner=1 = 项目负责人）
     */
    private function setupProjectAndTask(): array
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['userid' => $user->userid]);
        ProjectUser::createInstance([
            'project_id' => $project->id,
            'userid'     => $user->userid,
            'owner'      => 1,
        ])->save();
        $task = ProjectTask::factory()->create([
            'project_id' => $project->id,
            'userid'     => $user->userid,
        ]);
        return compact('user', 'project', 'task');
    }

    /**
     * 调 controller 方法的统一帮手：
     *   1. 强绑 request 的 request_id（防 RequestContext 在不同调用点 request() 返不同实例时 id 漂移）
     *   2. RequestContext::save('auth', $user) 让 User::auth() 返我们的 user
     *   3. Request::merge([...]) 注入入参
     *   4. 直接 new + 调方法（与 dootask Service 测试同范式）
     */
    private function callReportSave(User $user, array $input): array
    {
        // 显式钉死 request_id，确保 save / has / get 三步走同一 ClientContext bucket
        $rid = 'req_test_' . uniqid();
        request()->attributes->set('request_id', $rid);
        RequestContext::save('auth', $user, $rid);
        // 用 replace 而非 merge，避免不同 case 间 input 累积串
        request()->replace($input);
        $controller = new ProjectController();
        // Base::retSuccess / retError 直接返 array {ret, msg, data}
        return $controller->report__save();
    }

    public function test_save_creates_new_report(): void
    {
        ['user' => $user, 'task' => $task] = $this->setupProjectAndTask();

        $resp = $this->callReportSave($user, [
            'task_id' => $task->id,
            'values'  => ['hours' => 4, 'note' => 'test'],
        ]);

        $this->assertSame(1, $resp['ret']);
        $this->assertDatabaseHas('project_task_reports', [
            'task_id'         => $task->id,
            'reporter_userid' => $user->userid,
        ]);
    }

    public function test_save_rejects_invalid_hours(): void
    {
        ['user' => $user, 'task' => $task] = $this->setupProjectAndTask();

        $resp = $this->callReportSave($user, [
            'task_id' => $task->id,
            'values'  => ['hours' => 25],   // hours options.max=24（migration seed）
        ]);

        $this->assertSame(0, $resp['ret']);
        $this->assertStringContainsString('字段校验失败', $resp['msg']);
    }

    public function test_save_rejects_missing_required_hours(): void
    {
        ['user' => $user, 'task' => $task] = $this->setupProjectAndTask();

        $resp = $this->callReportSave($user, [
            'task_id' => $task->id,
            'values'  => [],   // 缺 hours required
        ]);

        $this->assertSame(0, $resp['ret']);
        $this->assertStringContainsString('字段校验失败', $resp['msg']);
    }

    public function test_save_rejects_non_member(): void
    {
        ['task' => $task] = $this->setupProjectAndTask();
        $outsider = User::factory()->create();

        $resp = $this->callReportSave($outsider, [
            'task_id' => $task->id,
            'values'  => ['hours' => 4],
        ]);

        $this->assertSame(0, $resp['ret']);
        $this->assertStringContainsString('您不是该项目成员', $resp['msg']);
    }

    public function test_save_rejects_archived_task(): void
    {
        ['user' => $user, 'project' => $project] = $this->setupProjectAndTask();
        $archivedTask = ProjectTask::factory()->create([
            'project_id' => $project->id,
            'userid'     => $user->userid,
            'archived_at' => now(),
        ]);

        $resp = $this->callReportSave($user, [
            'task_id' => $archivedTask->id,
            'values'  => ['hours' => 4],
        ]);

        $this->assertSame(0, $resp['ret']);
        $this->assertStringContainsString('任务不存在或已归档', $resp['msg']);
    }

    public function test_save_rejects_invalid_task_id(): void
    {
        ['user' => $user] = $this->setupProjectAndTask();

        $resp = $this->callReportSave($user, [
            'task_id' => 0,
            'values'  => ['hours' => 4],
        ]);

        $this->assertSame(0, $resp['ret']);
        $this->assertStringContainsString('参数错误', $resp['msg']);
    }

    public function test_save_edits_existing_report(): void
    {
        ['user' => $user, 'task' => $task] = $this->setupProjectAndTask();
        $report = TaskReport::createInstance([
            'task_id'         => $task->id,
            'parent_id'       => 0,
            'project_id'      => $task->project_id,
            'reporter_userid' => $user->userid,
            'work_date'       => '2026-04-29',
            'values'          => ['hours' => 2],
            'cascade_deleted' => false,
        ]);
        $report->save();

        $resp = $this->callReportSave($user, [
            'task_id'   => $task->id,
            'report_id' => $report->id,
            'values'    => ['hours' => 8],
        ]);

        $this->assertSame(1, $resp['ret']);
        $fresh = $report->fresh();
        $this->assertEquals(8, $fresh->values['hours']);
    }

    public function test_save_rejects_non_array_values(): void
    {
        ['user' => $user, 'task' => $task] = $this->setupProjectAndTask();

        $resp = $this->callReportSave($user, [
            'task_id' => $task->id,
            'values'  => 'not-an-array',
        ]);

        $this->assertSame(0, $resp['ret']);
        $this->assertStringContainsString('JSON 对象', $resp['msg']);
    }

    /**
     * Sprint 2 final reviewer I-1: audit logging 零测试覆盖
     * 断言 report__save 写 pre_project_logs（task_id/userid + record JSON 含 report_id/work_date/values）
     */
    public function test_save_writes_audit_log_with_record(): void
    {
        ['user' => $user, 'task' => $task] = $this->setupProjectAndTask();

        $resp = $this->callReportSave($user, [
            'task_id' => $task->id,
            'values'  => ['hours' => 2.5, 'note' => 'progress note'],
        ]);
        $this->assertSame(1, $resp['ret']);

        // 断言 project_logs 写入
        $this->assertDatabaseHas('project_logs', [
            'task_id' => $task->id,
            'userid'  => $user->userid,
        ]);

        $log = \App\Models\ProjectLog::where('task_id', $task->id)
            ->orderBy('id', 'desc')->first();
        $this->assertNotNull($log);

        // 验证 detail 含"提交"或"更新"
        $this->assertMatchesRegularExpression('/提交|更新/', $log->detail);

        // I-2 修复后 detail 仅含 key 列表（不拼 values）
        $this->assertStringContainsString('hours', $log->detail);
        $this->assertStringContainsString('note', $log->detail);
        // 反向断言：detail 中绝不应出现 user value（textarea 字符串）
        $this->assertStringNotContainsString('progress note', $log->detail);

        // 验证 record JSON 结构
        $record = is_string($log->record) ? json_decode($log->record, true) : $log->record;
        $this->assertArrayHasKey('report_id', $record);
        $this->assertArrayHasKey('work_date', $record);
        $this->assertArrayHasKey('values', $record);
        $this->assertEquals(2.5, $record['values']['hours']);
        $this->assertEquals('progress note', $record['values']['note']);
    }

    /**
     * Sprint 2 final reviewer I-1: detail 应区分创建 vs 编辑
     */
    public function test_save_audit_log_distinguishes_create_vs_update(): void
    {
        ['user' => $user, 'task' => $task] = $this->setupProjectAndTask();

        // 创建
        $resp = $this->callReportSave($user, [
            'task_id' => $task->id,
            'values'  => ['hours' => 2],
        ]);
        $this->assertSame(1, $resp['ret']);
        $reportId = $resp['data']['report']['id'] ?? null;
        $this->assertNotNull($reportId);

        $createLog = \App\Models\ProjectLog::where('task_id', $task->id)
            ->orderBy('id', 'desc')->first();
        $this->assertStringContainsString('提交', $createLog->detail);

        // 编辑
        $resp2 = $this->callReportSave($user, [
            'task_id'   => $task->id,
            'report_id' => $reportId,
            'values'    => ['hours' => 4],
        ]);
        $this->assertSame(1, $resp2['ret']);

        $updateLog = \App\Models\ProjectLog::where('task_id', $task->id)
            ->orderBy('id', 'desc')->first();
        $this->assertStringContainsString('更新', $updateLog->detail);
        $this->assertNotEquals($createLog->id, $updateLog->id);
    }
}
