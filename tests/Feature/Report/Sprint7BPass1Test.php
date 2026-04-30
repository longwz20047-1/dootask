<?php

// [CUSTOM:report-channel] Sprint 7-B Pass 1 测试
// 覆盖 ProjectController 6 新增 endpoints：
//   - report_template__list (global / project / both / non-member)
//   - report_template__save (create global admin / reject non-admin / edit builtin rejected)
//   - report_template__resolve
//   - report_template__delete
//   - report_template__clone
//   - trigger_log__list
//
// 测试模式：直接 controller 调用 + RequestContext prime auth（与 ReportFieldListTest 一致）
//
// 关键约定：
//   - DatabaseTransactions trait 隔离测试间数据
//   - 模板用 `new + 直接属性赋值` 绕开 array cast 双编码（Sprint 6 Pass 2 fef67c720 范式）

namespace Tests\Feature\Report;

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\ProjectController;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectUser;
use App\Models\TaskFieldDefinition;
use App\Models\TaskReportTemplate;
use App\Models\TaskReportTemplateField;
use App\Models\TaskReportTriggerLog;
use App\Models\User;
use App\Services\RequestContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class Sprint7BPass1Test extends TestCase
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

    private function makeTemplate(array $attrs): TaskReportTemplate
    {
        $tpl = new TaskReportTemplate();
        $tpl->name        = $attrs['name'] ?? ('Tpl ' . uniqid());
        $tpl->scope       = $attrs['scope'] ?? 'project';
        $tpl->scope_id    = $attrs['scope_id'] ?? 0;
        $tpl->is_default  = $attrs['is_default'] ?? false;
        $tpl->is_builtin  = $attrs['is_builtin'] ?? false;
        $tpl->enabled     = $attrs['enabled'] ?? true;
        $tpl->description = $attrs['description'] ?? null;
        if (array_key_exists('trigger_rules', $attrs)) {
            $tpl->trigger_rules = $attrs['trigger_rules'];
        }
        $tpl->save();
        return $tpl;
    }

    // ======================================================================
    // report_template__list
    // ======================================================================

    public function test_template_list_global_returns_seeded_default(): void
    {
        $user = User::factory()->create();
        $resp = $this->callController($user, 'report_template__list', ['scope' => 'global']);
        $this->assertSame(1, $resp['ret']);
        // Sprint 6 Pass 1 seed 至少建了 1 个 builtin global default 模板
        $this->assertGreaterThan(0, count($resp['data']));
        $names = array_column($resp['data'], 'scope');
        $this->assertContains('global', $names);
    }

    public function test_template_list_project_filters_by_scope_id(): void
    {
        $owner = User::factory()->create();
        $project = $this->createProjectWithOwner($owner);
        $tpl = $this->makeTemplate([
            'name'     => 'Project Tpl ' . uniqid(),
            'scope'    => 'project',
            'scope_id' => $project->id,
        ]);

        $resp = $this->callController($owner, 'report_template__list', [
            'scope'    => 'project',
            'scope_id' => $project->id,
        ]);

        $this->assertSame(1, $resp['ret']);
        $ids = array_column($resp['data'], 'id');
        $this->assertContains($tpl->id, $ids);
    }

    public function test_template_list_both_combines_global_and_project(): void
    {
        $owner = User::factory()->create();
        $project = $this->createProjectWithOwner($owner);
        $tpl = $this->makeTemplate([
            'name'     => 'Project Tpl ' . uniqid(),
            'scope'    => 'project',
            'scope_id' => $project->id,
        ]);

        $resp = $this->callController($owner, 'report_template__list', [
            'scope_id' => $project->id,
        ]);

        $this->assertSame(1, $resp['ret']);
        $scopes = array_column($resp['data'], 'scope');
        $this->assertContains('global', $scopes);
        $this->assertContains('project', $scopes);
    }

    public function test_template_list_project_rejects_non_member(): void
    {
        $owner = User::factory()->create();
        $project = $this->createProjectWithOwner($owner);
        $outsider = User::factory()->create();

        $this->expectException(ApiException::class);
        $this->callController($outsider, 'report_template__list', [
            'scope'    => 'project',
            'scope_id' => $project->id,
        ]);
    }

    // ======================================================================
    // report_template__save
    // ======================================================================

    public function test_template_save_create_global_admin(): void
    {
        $admin = User::factory()->create(['identity' => ',admin,']);
        $resp = $this->callController($admin, 'report_template__save', [
            'scope'         => 'global',
            'name'          => '测试全局模板 ' . uniqid(),
            'description'   => 'desc',
            'trigger_rules' => [['event' => 'on_complete', 'mode' => 'modal']],
        ]);
        $this->assertSame(1, $resp['ret']);
        $this->assertArrayHasKey('template', $resp['data']);
        $this->assertSame('global', $resp['data']['template']['scope']);
        // 验证 trigger_rules 反序列化为数组（无双编码）
        $tpl = TaskReportTemplate::find($resp['data']['template']['id']);
        $this->assertIsArray($tpl->trigger_rules);
        $this->assertSame('on_complete', $tpl->trigger_rules[0]['event']);
    }

    public function test_template_save_reject_non_admin_global(): void
    {
        $user = User::factory()->create();  // 非 admin
        $resp = $this->callController($user, 'report_template__save', [
            'scope' => 'global',
            'name'  => 'should fail',
        ]);
        $this->assertSame(0, $resp['ret']);
        $this->assertStringContainsString('管理员', $resp['msg']);
    }

    public function test_template_save_edit_builtin_rejected(): void
    {
        // builtin global default 由 Sprint 6 seed 提供
        $tpl = TaskReportTemplate::where('is_builtin', true)
            ->where('scope', 'global')
            ->where('is_default', true)
            ->first();
        $this->assertNotNull($tpl, 'Sprint 6 Pass 1 seed missing builtin default template');

        $admin = User::factory()->create(['identity' => ',admin,']);

        // Observer 拒改 builtin 关键属性 → 抛 ApiException
        $this->expectException(ApiException::class);
        $this->callController($admin, 'report_template__save', [
            'id'         => $tpl->id,
            'name'       => '改名尝试 ' . uniqid(),
            'is_default' => false,  // 触发 builtin guard（改 is_default）
            'enabled'    => false,
        ]);
    }

    // ======================================================================
    // report_template__resolve
    // ======================================================================

    public function test_template_resolve_returns_template_for_task(): void
    {
        $owner = User::factory()->create();
        $project = $this->createProjectWithOwner($owner);
        $tpl = $this->makeTemplate([
            'name'     => 'Project Tpl ' . uniqid(),
            'scope'    => 'project',
            'scope_id' => $project->id,
        ]);
        $task = ProjectTask::factory()->create([
            'project_id' => $project->id,
            'userid'     => $owner->userid,
        ]);

        $resp = $this->callController($owner, 'report_template__resolve', [
            'task_id' => $task->id,
        ]);

        $this->assertSame(1, $resp['ret']);
        $this->assertNotNull($resp['data']['template']);
        $this->assertSame($tpl->id, $resp['data']['template']['id']);
        $this->assertIsArray($resp['data']['fields']);
    }

    // ======================================================================
    // report_template__delete
    // ======================================================================

    public function test_template_delete_non_builtin_succeeds(): void
    {
        $owner = User::factory()->create();
        $project = $this->createProjectWithOwner($owner);
        $tpl = $this->makeTemplate([
            'name'     => 'Delete Me ' . uniqid(),
            'scope'    => 'project',
            'scope_id' => $project->id,
        ]);
        $tplId = $tpl->id;

        $resp = $this->callController($owner, 'report_template__delete', ['id' => $tplId]);
        $this->assertSame(1, $resp['ret']);
        // SoftDeletes：原 query 已查不到
        $this->assertNull(TaskReportTemplate::find($tplId));
    }

    // ======================================================================
    // report_template__clone
    // ======================================================================

    public function test_template_clone_creates_copy_without_builtin(): void
    {
        $owner = User::factory()->create();
        $project = $this->createProjectWithOwner($owner);
        $source = $this->makeTemplate([
            'name'          => 'Source ' . uniqid(),
            'scope'         => 'project',
            'scope_id'      => $project->id,
            'description'   => '原始描述',
            'trigger_rules' => [['event' => 'on_complete', 'mode' => 'remind']],
        ]);

        $resp = $this->callController($owner, 'report_template__clone', [
            'id'              => $source->id,
            'target_scope'    => 'project',
            'target_scope_id' => $project->id,
        ]);

        $this->assertSame(1, $resp['ret']);
        $clone = TaskReportTemplate::find($resp['data']['template']['id']);
        $this->assertNotNull($clone);
        $this->assertNotEquals($source->id, $clone->id);
        $this->assertFalse((bool) $clone->is_builtin);
        $this->assertFalse((bool) $clone->is_default);
        $this->assertStringContainsString('副本', $clone->name);
        // trigger_rules 也复制了
        $this->assertIsArray($clone->trigger_rules);
        $this->assertSame('on_complete', $clone->trigger_rules[0]['event']);
    }

    // ======================================================================
    // trigger_log__list
    // ======================================================================

    public function test_trigger_log_list_filters_by_task(): void
    {
        $owner = User::factory()->create();
        $project = $this->createProjectWithOwner($owner);
        $task = ProjectTask::factory()->create([
            'project_id' => $project->id,
            'userid'     => $owner->userid,
        ]);
        // 直插 1 条 trigger log
        TaskReportTriggerLog::create([
            'task_id'      => $task->id,
            'template_id'  => null,
            'rule_idx'     => 0,
            'event'        => 'on_complete',
            'mode'         => 'modal',
            'triggered_at' => now(),
            'user_id'      => $owner->userid,
        ]);

        $resp = $this->callController($owner, 'trigger_log__list', [
            'task_id' => $task->id,
        ]);
        $this->assertSame(1, $resp['ret']);
        $this->assertCount(1, $resp['data']);
        $this->assertSame($task->id, (int) $resp['data'][0]['task_id']);
    }
}
