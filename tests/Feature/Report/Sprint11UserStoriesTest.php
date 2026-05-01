<?php

// [CUSTOM:report-channel] Sprint 11 Task 11.8 — 19 用户故事 e2e backend integration
//
// 范围（plan v1.12 Sprint 11 line ~2920-2944）：
//   实施 8 关键故事 backend integration tests，作为 19 故事 e2e 的 backend 子集。
//   余下 11 故事涉及企微 MCP / dashboard UI / cross-tool 聚合（跨 repo + LLM 真调用），
//   推 Sprint 12 buffer 或专项 e2e session。
//
// 8 故事：
//   - Story 1:  modal 完成（owner 路径） — TriggerEngine on_complete modal mode 不抛
//   - Story 2:  block min_count=2 仅 1 次 report 仍 block — TriggerEngine block path
//   - Story 3:  PM 配项目级模板（scope=project）— TemplateResolver project scope 命中
//   - Story 4:  Admin 加聚合索引 — StatisticsService has_index=true 路径无 warning
//   - Story 11: 协助人 block + ApiException template_block — try/catch 数据兼容
//   - Story 12: owner 任务完成后补汇报 — PendingReportService listForUser 含已完成 task
//   - Story 15: per_user min_count owner 满足 / 协助人不满足 — TriggerEngine 独立计数
//   - Story 19: PM 显式 task.template_id 覆盖 flow_item — TemplateResolver 4 档第 0 档
//
// 关键约定（同 TriggerEngineTest / Sprint7DPass1Test）：
//   - DatabaseTransactions trait 隔离测试间数据
//   - 模板用 `new + 直接属性赋值` 绕开 array cast 双编码 bug（commit fef67c720）
//   - Auth 用 RequestContext::save('auth', $user) prime（不能 actingAs）
//   - PendingReportService 必须 Project owner / archived NULL / created_at < 14d 否则被过滤
//
// 既有覆盖说明（commit 内已声明）：
//   Task 11.1-11.7（100 case）由 Sprint 5b（119 case）+ Sprint 6+（98 case）= 217 case
//   完成全覆盖 — 不在本文件重复。本文件仅做用户故事级 e2e 流程契约校验。

namespace Tests\Feature\Report;

use App\Exceptions\ApiException;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectTaskUser;
use App\Models\ProjectUser;
use App\Models\TaskFieldDefinition;
use App\Models\TaskReport;
use App\Models\TaskReportTemplate;
use App\Models\User;
use App\Services\RequestContext;
use App\Services\TaskReport\PendingReportService;
use App\Services\TaskReport\StatisticsService;
use App\Services\TaskReport\TemplateResolver;
use App\Services\TaskReport\TriggerEngine;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Sprint11UserStoriesTest extends TestCase
{
    use DatabaseTransactions;

    // ======================================================================
    // Story 1: modal 完成（owner 路径）
    // ======================================================================

    /**
     * 用户故事 1: 员工完成任务，modal mode rule 触发，仅 log 不抛（前端 catch 后弹窗）
     */
    public function test_story_1_modal_complete_owner_path_does_not_throw()
    {
        $owner = User::factory()->create();
        $task = ProjectTask::factory()->create(['userid' => $owner->userid]);
        $this->primeAuth($owner);

        $tpl = $this->createTemplate([
            'trigger_rules' => [
                [
                    'event'      => 'on_complete',
                    'mode'       => 'modal',
                    'target'     => 'reporter',
                    'constraint' => [],
                    '_hint'      => '请完成本次上报',
                ],
            ],
        ]);
        $this->makeGlobalDefault($tpl);

        // modal mode 不抛；仅写 trigger_log 一条
        app(TriggerEngine::class)->handle('on_complete', $task);

        $this->assertEquals(
            1,
            DB::table('task_report_trigger_log')
                ->where('task_id', $task->id)
                ->where('mode', 'modal')
                ->count(),
            'modal mode 应写 trigger_log 一条'
        );
    }

    // ======================================================================
    // Story 2: block 硬性 min_count=2，仅 1 次 report 仍 block
    // ======================================================================

    /**
     * 用户故事 2: 模板要求 2 次 report，已填 1 次时仍抛 ApiException 阻断 save
     */
    public function test_story_2_block_min_count_2_one_report_still_blocks()
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create(['userid' => $owner->userid]);
        $task = ProjectTask::factory()->create([
            'userid'     => $owner->userid,
            'project_id' => $project->id,
        ]);
        $this->primeAuth($owner);

        // 已 1 次 report（< min_count=2）
        $this->createRawReport($task->id, $owner->userid, $project->id);

        $tpl = $this->createTemplate([
            'trigger_rules' => [
                [
                    'event'      => 'on_complete',
                    'mode'       => 'block',
                    'target'     => 'reporter',
                    'constraint' => ['min_count' => 2],
                    'message'    => '需 2 次上报方可完成',
                ],
            ],
        ]);
        $this->makeGlobalDefault($tpl);

        try {
            app(TriggerEngine::class)->handle('on_complete', $task);
            $this->fail('Expected ApiException not thrown (1 < 2 应 block)');
        } catch (ApiException $e) {
            $data = $e->getData();
            $this->assertArrayHasKey('template_block', $data);
            $this->assertEquals(2, $data['template_block']['min_count']);
            $this->assertEquals(1, $data['template_block']['existing_count']);
        }
    }

    // ======================================================================
    // Story 3: PM 配项目级模板（scope=project）
    // ======================================================================

    /**
     * 用户故事 3: PM 创建 scope=project 模板后，TemplateResolver 应优先返回项目模板（无 flow_item / task.template_id）
     */
    public function test_story_3_pm_project_scope_template_resolves_for_project_tasks()
    {
        $project = Project::factory()->create();

        $projectTpl = $this->createTemplate([
            'name'     => 'PM 项目模板 ' . uniqid(),
            'scope'    => 'project',
            'scope_id' => $project->id,
        ]);

        $task = ProjectTask::factory()->create([
            'project_id'   => $project->id,
            'flow_item_id' => 0,
            'template_id'  => null,
        ]);

        $resolved = app(TemplateResolver::class)->resolveForTask($task);
        $this->assertNotNull($resolved);
        $this->assertEquals(
            $projectTpl->id,
            $resolved->id,
            'project scope 应胜过 global default 兜底'
        );
    }

    // ======================================================================
    // Story 4: Admin 加聚合索引（has_index=true 路径无 warning）
    // ======================================================================

    /**
     * 用户故事 4: Admin 启用 hours 聚合索引后，sum_hours 走虚拟列无性能 warning
     */
    public function test_story_4_admin_enabled_aggregate_index_yields_no_warning()
    {
        $hours = TaskFieldDefinition::where('code', 'hours')->first();
        $this->assertNotNull($hours, 'hours seed 缺失（migration seed 失败）');

        // 显式置 has_index=true（覆盖 legacy 库 + Sprint 1 fix 默认 false 漂移）
        // DatabaseTransactions rollback 自动还原
        DB::table('task_field_definitions')
            ->where('id', $hours->id)
            ->update(['has_index' => true]);

        $svc = app(StatisticsService::class);
        $result = $svc->aggregate([
            'dimensions' => ['user'],
            'metric'     => 'sum_hours',
            'filters'    => [],
        ]);

        $this->assertNull($result['warning'], 'has_index=true 应走 hours_v 虚拟列，无性能 warning');
    }

    // ======================================================================
    // Story 11: 协助人 block + ApiException template_block payload
    // ======================================================================

    /**
     * 用户故事 11: 协助人完成任务，target=collaborators 的 block rule 命中且未满足 → ApiException 含 template_block
     * （Sprint 8 try/catch + emitter §11.10 路径之核心异常 payload 校验）
     */
    public function test_story_11_collaborator_block_throws_api_exception_with_template_block()
    {
        $owner = User::factory()->create();
        $col   = User::factory()->create();
        $project = Project::factory()->create(['userid' => $owner->userid]);
        $task = ProjectTask::factory()->create([
            'userid'     => $owner->userid,
            'project_id' => $project->id,
        ]);

        // 加入双方为 task users
        ProjectTaskUser::createInstance([
            'project_id' => $project->id,
            'task_id'    => $task->id,
            'task_pid'   => $task->id,
            'userid'     => $owner->userid,
            'owner'      => 1,
        ])->save();
        ProjectTaskUser::createInstance([
            'project_id' => $project->id,
            'task_id'    => $task->id,
            'task_pid'   => $task->id,
            'userid'     => $col->userid,
            'owner'      => 0,
        ])->save();

        $this->primeAuth($col);

        $tpl = $this->createTemplate([
            'trigger_rules' => [
                [
                    'event'      => 'on_complete',
                    'mode'       => 'block',
                    'target'     => 'collaborators',
                    'constraint' => ['min_count' => 1],
                    'message'    => '协助人需先完成上报',
                ],
            ],
        ]);
        $this->makeGlobalDefault($tpl);

        try {
            app(TriggerEngine::class)->handle('on_complete', $task);
            $this->fail('Expected ApiException not thrown for collaborator block');
        } catch (ApiException $e) {
            $data = $e->getData();
            $this->assertArrayHasKey('template_block', $data, 'data 必须含 template_block 供前端弹窗渲染');
            $this->assertEquals($task->id, $data['template_block']['task_id']);
            $this->assertEquals($tpl->id, $data['template_block']['template_id']);
            $this->assertEquals(1, $data['template_block']['min_count']);
            $this->assertEquals(0, $data['template_block']['existing_count']);
        }
    }

    // ======================================================================
    // Story 12: owner 任务完成后补汇报（PendingReportService 含已完成 task）
    // ======================================================================

    /**
     * 用户故事 12: owner 完成任务后未达 min_count，仍出现在 pending list（spec §6.6 v3.22 owner 可补汇报）
     */
    public function test_story_12_owner_can_supplement_report_after_task_completion()
    {
        $owner = User::factory()->create();
        $project = $this->createProjectWithOwner($owner);

        $task = ProjectTask::factory()->create([
            'userid'      => $owner->userid,
            'project_id'  => $project->id,
            'complete_at' => now(),  // 已完成
        ]);

        // 默认 builtin tpl trigger_rules 清空（min_count=1，0 次 report 视为待办）
        $this->clearBuiltinTriggerRules();

        $tasks = app(PendingReportService::class)->listForUser($owner->userid, $project->id);

        $taskIds = $tasks->pluck('id')->toArray();
        $this->assertContains(
            $task->id,
            $taskIds,
            '已完成 task（complete_at != null）但 0 次 report 应仍出现在 pending list'
        );
    }

    // ======================================================================
    // Story 15: per_user min_count owner 满足 / 协助人不满足
    // ======================================================================

    /**
     * 用户故事 15: target=collaborators + min_count=2，owner 已 2 次满足 / 协助人 0 次不满足（独立计数）
     */
    public function test_story_15_per_user_min_count_isolates_owner_and_collaborator()
    {
        $owner = User::factory()->create();
        $col   = User::factory()->create();
        $project = Project::factory()->create(['userid' => $owner->userid]);
        $task = ProjectTask::factory()->create([
            'userid'     => $owner->userid,
            'project_id' => $project->id,
        ]);

        ProjectTaskUser::createInstance([
            'project_id' => $project->id,
            'task_id'    => $task->id,
            'task_pid'   => $task->id,
            'userid'     => $owner->userid,
            'owner'      => 1,
        ])->save();
        ProjectTaskUser::createInstance([
            'project_id' => $project->id,
            'task_id'    => $task->id,
            'task_pid'   => $task->id,
            'userid'     => $col->userid,
            'owner'      => 0,
        ])->save();

        // owner 已 2 次 report
        $this->createRawReport($task->id, $owner->userid, $project->id);
        $this->createRawReport($task->id, $owner->userid, $project->id);

        $tpl = $this->createTemplate([
            'trigger_rules' => [
                [
                    'event'      => 'on_complete',
                    'mode'       => 'block',
                    'target'     => 'collaborators',
                    'constraint' => ['min_count' => 2],
                ],
            ],
        ]);
        $this->makeGlobalDefault($tpl);

        $engine = app(TriggerEngine::class);

        // owner 视角：reporter_userid=owner 计数=2 >= 2 → 满足 → 不抛
        $this->primeAuth($owner);
        $engine->handle('on_complete', $task);  // 不抛
        $this->assertTrue(true, 'owner 已满足 min_count，不应抛异常');

        // 协助人视角：reporter_userid=col 计数=0 < 2 → 不满足 → 抛
        $this->primeAuth($col);
        $this->expectException(ApiException::class);
        $engine->handle('on_complete', $task);
    }

    // ======================================================================
    // Story 19: PM 显式 task.template_id 覆盖 flow_item（4 档第 0 档命中）
    // ======================================================================

    /**
     * 用户故事 19: PM 创建任务时手选模板（task.template_id），优先级高于 flow_item 命中（spec §6.5 v3.26 §3.9bis）
     */
    public function test_story_19_pm_explicit_template_id_overrides_flow_item_resolution()
    {
        $taskTpl     = $this->createTemplate(['scope' => 'global', 'scope_id' => 0, 'name' => 'Task Tpl ' . uniqid()]);
        $flowItemTpl = $this->createTemplate(['scope' => 'flow_item', 'scope_id' => 1, 'name' => 'Flow Tpl ' . uniqid()]);

        $task = ProjectTask::factory()->create([
            'template_id'  => $taskTpl->id,  // PM 显式选（v3.26 §3.9bis）
            'flow_item_id' => 1,             // 该 flow_item 也有模板
        ]);

        $resolved = app(TemplateResolver::class)->resolveForTask($task);
        $this->assertNotNull($resolved);
        $this->assertEquals(
            $taskTpl->id,
            $resolved->id,
            '第 0 档 task.template_id 应覆盖 flow_item（spec §6.5 4 档优先级）'
        );
    }

    // ======================================================================
    // Story 17: trigger remind collaborators 推送（Sprint 12 buffer 补足）
    // ======================================================================

    /**
     * 用户故事 17: target=collaborators + mode=remind 触发后，wecom_notifications outbox 写行
     *
     * Sprint 12 buffer: Sprint 11 推后 11 故事中 backend 可独立验证的一项
     *   - TriggerEngine::pushRemind 走 v2.6 M1 outbox 链路（spec §21.2 v3.4 P0-2）
     *   - WecomMarkdownRenderer::renderReportRemind 渲染 markdown（Sprint 5a Task 5a.6）
     *   - target=collaborators 含 owner=1 + owner=0 全部 task users（v3.22 明示）
     *   - 仅有 active UserWecomBinding 的 user 入 outbox（spec §11 R9）
     */
    public function test_story_17_trigger_remind_collaborators_pushes_to_outbox()
    {
        $owner = User::factory()->create();
        $col   = User::factory()->create();
        $task  = ProjectTask::factory()->create(['userid' => $owner->userid]);

        // 建 task users（含 owner + 协助人）
        ProjectTaskUser::createInstance([
            'task_id' => $task->id,
            'userid'  => $owner->userid,
            'owner'   => 1,
        ])->save();
        ProjectTaskUser::createInstance([
            'task_id' => $task->id,
            'userid'  => $col->userid,
            'owner'   => 0,
        ])->save();

        // 建 active binding（无 binding 则 pushRemind 跳过 — spec §11 R9）
        DB::table('user_wecom_bindings')->insert([
            [
                'userid'        => $owner->userid,
                'wecom_corp_id' => 'test_corp_' . uniqid(),
                'wecom_userid'  => 'test_owner_' . uniqid(),
                'created_at'    => now(),
                'updated_at'    => now(),
            ],
            [
                'userid'        => $col->userid,
                'wecom_corp_id' => 'test_corp_' . uniqid(),
                'wecom_userid'  => 'test_col_' . uniqid(),
                'created_at'    => now(),
                'updated_at'    => now(),
            ],
        ]);

        $tpl = $this->createTemplate([
            'trigger_rules' => [
                [
                    'event'      => 'on_complete',
                    'mode'       => 'remind',
                    'target'     => 'collaborators',
                    'constraint' => [],
                    '_hint'      => '请尽快上报',
                ],
            ],
        ]);
        $this->makeGlobalDefault($tpl);

        // 记当前 outbox 基线（DatabaseTransactions 隔离不保证清表）
        $countBefore = DB::table('wecom_notifications')
            ->where('task_id', $task->id)
            ->count();

        // owner 完成任务（auth 设为 owner，在 collaborators 中）
        $this->primeAuth($owner);
        app(TriggerEngine::class)->handle('on_complete', $task);

        // collaborators = owner + col → 2 行 outbox（每个 active binding user 1 行）
        $countAfter = DB::table('wecom_notifications')
            ->where('task_id', $task->id)
            ->count();
        $this->assertEquals(
            $countBefore + 2,
            $countAfter,
            'target=collaborators 含 owner+col 各 1 行（spec §21.2 v3.22）'
        );

        // 验证 rendered_markdown 非空（renderReportRemind 已渲染 — spec §10A）
        $rows = DB::table('wecom_notifications')
            ->where('task_id', $task->id)
            ->where('event_type', 'report_remind')
            ->get();
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertNotEmpty($row->rendered_markdown, 'rendered_markdown 应由 WecomMarkdownRenderer 填充');
            $this->assertEquals('pending', $row->status);
            $this->assertContains((int) $row->target_userid, [$owner->userid, $col->userid]);
        }
    }

    // ======================================================================
    // Helpers
    // ======================================================================

    /**
     * 让 User::auth() / User::userid() 返指定 user
     * （RequestContext::has('auth') 早 return 短路，绕开 Doo SO FFI）
     */
    private function primeAuth(User $user): void
    {
        $rid = 'req_test_' . uniqid();
        request()->attributes->set('request_id', $rid);
        RequestContext::save('auth', $user, $rid);
    }

    /**
     * 创建测试用模板（绕开 array cast 双编码 bug — commit fef67c720）
     */
    private function createTemplate(array $attrs): TaskReportTemplate
    {
        $tpl = new TaskReportTemplate();
        $tpl->name          = $attrs['name'] ?? ('Test Tpl ' . uniqid());
        $tpl->scope         = $attrs['scope'] ?? 'global';
        $tpl->scope_id      = $attrs['scope_id'] ?? 0;
        $tpl->is_default    = $attrs['is_default'] ?? false;
        $tpl->is_builtin    = $attrs['is_builtin'] ?? false;
        $tpl->enabled       = $attrs['enabled'] ?? true;
        $tpl->trigger_rules = $attrs['trigger_rules'] ?? [];
        $tpl->save();
        return $tpl;
    }

    /**
     * 让指定模板成为 global default 兜底（取消既有 default + 设当前 default）
     */
    private function makeGlobalDefault(TaskReportTemplate $tpl): void
    {
        DB::table('task_report_templates')
            ->where('is_default', true)
            ->where('id', '!=', $tpl->id)
            ->update(['is_default' => false]);
        $tpl->is_default = true;
        $tpl->scope      = 'global';
        $tpl->save();
    }

    /**
     * 直接 INSERT raw report（绕开 factory 关联建立 Project / Task 链以共享上层实体）
     */
    private function createRawReport(int $taskId, int $reporterUserid, int $projectId): void
    {
        DB::table('project_task_reports')->insert([
            'task_id'         => $taskId,
            'parent_id'       => 0,
            'project_id'      => $projectId,
            'reporter_userid' => $reporterUserid,
            'work_date'       => now()->toDateString(),
            'values'          => json_encode(['hours' => 1, 'note' => 'x'], JSON_UNESCAPED_UNICODE),
            'cascade_deleted' => false,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    /**
     * 创建带 owner 的 Project（PendingReportService 走 ProjectUser 实时关系，
     * archived_at / deleted_at 双 NULL 是其边界过滤的硬约束）
     */
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

    /**
     * 清空 builtin global default 模板的 trigger_rules（min_count=1 视已无 report 任务为待办）
     * — 同 Sprint7DPass1Test::clearBuiltinTriggerRules 范式
     */
    private function clearBuiltinTriggerRules(): void
    {
        DB::table('task_report_templates')
            ->where('is_builtin', true)
            ->where('scope', 'global')
            ->where('is_default', true)
            ->update(['trigger_rules' => json_encode([], JSON_UNESCAPED_UNICODE)]);
    }
}
