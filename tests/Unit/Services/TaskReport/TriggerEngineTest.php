<?php

// [CUSTOM:report-channel] Sprint 7-A Task 7.2
// 覆盖 TriggerEngine 7×3×4 决策矩阵核心路径：
//   - handle() 无模板 / event mismatch / target 过滤 / per-rule 独立处理
//   - block 模式 满足 / 不满足（per_user 隔离）
//   - modal 模式 仅 log 不抛
//   - remind 模式 frequency_limit 节流 / 写 outbox / no-binding 跳过
//   - resolveTargets reporter / collaborators / assignees 三档区分
//   - logTrigger unique 冲突吞掉
//
// 关键约定（与 TemplateResolverTest / Sprint7APass2Test 一致）：
//   - DatabaseTransactions trait 隔离测试间数据
//   - 模板创建用 `new + 直接属性赋值` 绕开 array cast 双编码 bug（commit fef67c720）
//   - Auth 用 RequestContext::save('auth', $user) prime（参 ReportSaveTest 范式）
//   - WecomNotification outbox 直接断言 DB::table('wecom_notifications') 行存在性

namespace Tests\Unit\Services\TaskReport;

use App\Exceptions\ApiException;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectTaskUser;
use App\Models\TaskReport;
use App\Models\TaskReportTemplate;
use App\Models\TaskReportTriggerLog;
use App\Models\User;
use App\Models\UserWecomBinding;
use App\Services\RequestContext;
use App\Services\TaskReport\TriggerEngine;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TriggerEngineTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Helper: 让 User::auth() / User::userid() 返指定 user
     * （RequestContext::has('auth') 早 return 短路，绕开 Doo SO FFI）
     */
    private function actAsUser(User $user): void
    {
        $rid = 'req_test_' . uniqid();
        request()->attributes->set('request_id', $rid);
        RequestContext::save('auth', $user, $rid);
    }

    /**
     * Helper: 创建测试用模板（绕开 array cast 双编码 bug）
     */
    private function createTemplate(array $attrs): TaskReportTemplate
    {
        $tpl = new TaskReportTemplate();
        $tpl->name        = $attrs['name'] ?? ('Test Tpl ' . uniqid());
        $tpl->scope       = $attrs['scope'] ?? 'global';
        $tpl->scope_id    = $attrs['scope_id'] ?? 0;
        $tpl->is_default  = $attrs['is_default'] ?? false;
        $tpl->is_builtin  = $attrs['is_builtin'] ?? false;
        $tpl->enabled     = $attrs['enabled'] ?? true;
        $tpl->trigger_rules = $attrs['trigger_rules'] ?? [];
        $tpl->save();
        return $tpl;
    }

    /**
     * Helper: 让指定模板成为 global default（兜底命中）
     * Observer 拒绝改 builtin default 关键字段，故先把已有 builtin default 的 is_default 置 false。
     */
    private function makeGlobalDefault(TaskReportTemplate $tpl): void
    {
        DB::table('task_report_templates')
            ->where('is_default', true)
            ->where('id', '!=', $tpl->id)
            ->update(['is_default' => false]);
        $tpl->is_default = true;
        $tpl->scope = 'global';
        $tpl->save();
    }

    /**
     * Helper: 创建一条 task_report 记录（per_user 计数测试用）
     */
    private function createReport(int $taskId, int $reporterUserid, int $projectId): void
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

    // ======================================================================
    // 1. handle() 基础路径
    // ======================================================================

    /**
     * 1. resolver 返 null（前 3 档全空 + global default 也被删）→ 不触发 / 不抛
     */
    public function test_handle_no_template_skips()
    {
        TaskReportTemplate::query()->forceDelete(); // 含 builtin default 一并清

        $task = ProjectTask::factory()->create();

        $engine = app(TriggerEngine::class);
        $engine->handle('on_complete', $task);
        $this->assertTrue(true); // 走到这儿 = 没抛
    }

    /**
     * 2. event 不匹配（rule.event=on_complete，触发 on_start）→ 跳过
     */
    public function test_handle_event_mismatch_skips()
    {
        $owner = User::factory()->create();
        $task = ProjectTask::factory()->create(['userid' => $owner->userid]);
        $this->actAsUser($owner);

        $tpl = $this->createTemplate([
            'trigger_rules' => [
                ['event' => 'on_complete', 'mode' => 'block', 'target' => 'reporter',
                 'constraint' => ['min_count' => 1]],
            ],
        ]);
        $this->makeGlobalDefault($tpl);

        $engine = app(TriggerEngine::class);
        $engine->handle('on_start', $task); // event 不匹配
        $this->assertTrue(true);

        // 不应写 trigger_log
        $this->assertEquals(0, TaskReportTriggerLog::where('task_id', $task->id)->count());
    }

    // ======================================================================
    // 2. block mode
    // ======================================================================

    /**
     * 3. block mode 不满足 → throw ApiException 含 template_block
     */
    public function test_block_mode_throws_when_unsatisfied()
    {
        $owner = User::factory()->create();
        $task = ProjectTask::factory()->create(['userid' => $owner->userid]);
        $this->actAsUser($owner);

        $tpl = $this->createTemplate([
            'trigger_rules' => [
                ['event' => 'on_complete', 'mode' => 'block', 'target' => 'reporter',
                 'constraint' => ['min_count' => 1],
                 'message' => '需先完成上报后再继续'],
            ],
        ]);
        $this->makeGlobalDefault($tpl);

        try {
            app(TriggerEngine::class)->handle('on_complete', $task);
            $this->fail('Expected ApiException not thrown');
        } catch (ApiException $e) {
            $this->assertEquals('需先完成上报后再继续', $e->getMessage());
            $data = $e->getData();
            $this->assertArrayHasKey('template_block', $data);
            $this->assertEquals($task->id, $data['template_block']['task_id']);
            $this->assertEquals(1, $data['template_block']['min_count']);
            $this->assertEquals(0, $data['template_block']['existing_count']);
        }

        // trigger_log 应有一条 mode=block
        $this->assertEquals(1, TaskReportTriggerLog::where('task_id', $task->id)
            ->where('mode', 'block')->count());
    }

    /**
     * 4. block mode 满足（per_user 计数 ≥ min_count）→ 不抛
     */
    public function test_block_mode_passes_when_satisfied()
    {
        $owner = User::factory()->create();
        $project = Project::factory()->create();
        $task = ProjectTask::factory()->create([
            'userid' => $owner->userid,
            'project_id' => $project->id,
        ]);
        $this->actAsUser($owner);

        // 预填 1 条 report（满足 min_count=1）
        $this->createReport($task->id, $owner->userid, $project->id);

        $tpl = $this->createTemplate([
            'trigger_rules' => [
                ['event' => 'on_complete', 'mode' => 'block', 'target' => 'reporter',
                 'constraint' => ['min_count' => 1]],
            ],
        ]);
        $this->makeGlobalDefault($tpl);

        // 不抛
        app(TriggerEngine::class)->handle('on_complete', $task);
        $this->assertTrue(true);

        // trigger_log 仍应记录一次（满足也要 log，方便 §21.5 状态机审计）
        $this->assertEquals(1, TaskReportTriggerLog::where('task_id', $task->id)
            ->where('mode', 'block')->count());
    }

    // ======================================================================
    // 3. v3.24 P0-V3.24-2: 每条 rule 独立 target 过滤
    // ======================================================================

    /**
     * 5. 协助人完成 owner 专属任务：reporter 规则跳过（不触发 block 死锁），
     *    collaborators 规则命中（remind/log 一条）
     */
    public function test_handle_filters_each_rule_independently_by_target()
    {
        $owner = User::factory()->create();
        $assistant = User::factory()->create();
        $project = Project::factory()->create();
        $task = ProjectTask::factory()->create([
            'userid' => $owner->userid,
            'project_id' => $project->id,
        ]);
        // owner=1 主负责人 + owner=0 协助人
        ProjectTaskUser::createInstance([
            'project_id' => $project->id,
            'task_id' => $task->id,
            'task_pid' => $task->id,
            'userid' => $owner->userid,
            'owner' => 1,
        ])->save();
        ProjectTaskUser::createInstance([
            'project_id' => $project->id,
            'task_id' => $task->id,
            'task_pid' => $task->id,
            'userid' => $assistant->userid,
            'owner' => 0,
        ])->save();

        // 协助人当前操作（无 binding → remind 不写 outbox 但仍 log）
        $this->actAsUser($assistant);

        $tpl = $this->createTemplate([
            'trigger_rules' => [
                // rule 0: target=reporter（仅 owner.userid 命中）→ 协助人应被跳过
                ['event' => 'on_complete', 'mode' => 'block', 'target' => 'reporter',
                 'constraint' => ['min_count' => 1]],
                // rule 1: target=collaborators（含协助人）→ 应触发 remind
                ['event' => 'on_complete', 'mode' => 'remind', 'target' => 'collaborators',
                 'message' => '请协助补上报'],
            ],
        ]);
        $this->makeGlobalDefault($tpl);

        // rule 0 (block, target=reporter) 协助人不在 target 内 → 跳过（不抛）
        // rule 1 (remind, target=collaborators) 命中 → log 一条
        app(TriggerEngine::class)->handle('on_complete', $task);
        $this->assertTrue(true);

        $logs = TaskReportTriggerLog::where('task_id', $task->id)->get();
        $this->assertCount(1, $logs, 'reporter 规则应被跳过，仅 collaborators 规则一条 log');
        $this->assertEquals('remind', $logs->first()->mode);
        $this->assertEquals(1, $logs->first()->rule_idx);
    }

    // ======================================================================
    // 4. v3.23 P0-V3.23-2: per_user min_count
    // ======================================================================

    /**
     * 6. per_user 计数：A 已填 2 次满足 / B 0 次不满足（独立计数，互不污染）
     */
    public function test_check_block_satisfied_counts_per_user_not_globally()
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $project = Project::factory()->create();
        $task = ProjectTask::factory()->create([
            'userid' => $userA->userid, // A 是 reporter
            'project_id' => $project->id,
        ]);

        // A 预填 2 次（满足 min_count=2）
        $this->createReport($task->id, $userA->userid, $project->id);
        $this->createReport($task->id, $userA->userid, $project->id);

        $tpl = $this->createTemplate([
            'trigger_rules' => [
                ['event' => 'on_complete', 'mode' => 'block', 'target' => 'reporter',
                 'constraint' => ['min_count' => 2]],
            ],
        ]);
        $this->makeGlobalDefault($tpl);

        // 视角 A：在 target=reporter 内 + 已填 2 次 ≥ min_count → 不抛
        $this->actAsUser($userA);
        app(TriggerEngine::class)->handle('on_complete', $task);
        $this->assertTrue(true);

        // 视角 B：B 不是 reporter（task.userid=A），target=reporter 跳过 → 不抛（且不应被全表 count 污染）
        // 即便有 task_id 全表 count 已 ≥ 2，per_user 隔离也保证不会误判
        // 此处更直接的断言：换 B 为 reporter，B 自己 0 次 → 应抛
        $task2 = ProjectTask::factory()->create([
            'userid' => $userB->userid, // 让 B 成为 reporter
            'project_id' => $project->id,
        ]);
        // 给 task2 上塞 A 的 3 条 report → 全局 count=3，但 B 的 reporter_userid count=0
        $this->createReport($task2->id, $userA->userid, $project->id);
        $this->createReport($task2->id, $userA->userid, $project->id);
        $this->createReport($task2->id, $userA->userid, $project->id);

        $this->actAsUser($userB);
        $this->expectException(ApiException::class);
        app(TriggerEngine::class)->handle('on_complete', $task2);
    }

    // ======================================================================
    // 5. resolveTargets 区分
    // ======================================================================

    /**
     * 7. resolveTargets reporter 仅 task.userid 单值
     */
    public function test_resolve_targets_reporter_returns_task_userid_only()
    {
        $owner = User::factory()->create();
        $assistant = User::factory()->create();
        $task = ProjectTask::factory()->create(['userid' => $owner->userid]);
        ProjectTaskUser::createInstance([
            'project_id' => $task->project_id, 'task_id' => $task->id, 'task_pid' => $task->id,
            'userid' => $owner->userid, 'owner' => 1,
        ])->save();
        ProjectTaskUser::createInstance([
            'project_id' => $task->project_id, 'task_id' => $task->id, 'task_pid' => $task->id,
            'userid' => $assistant->userid, 'owner' => 0,
        ])->save();

        $engine = app(TriggerEngine::class);
        $userIds = $engine->resolveTargets($task, 'reporter');

        $this->assertCount(1, $userIds);
        $this->assertEquals($owner->userid, $userIds[0]);
    }

    /**
     * 8. resolveTargets collaborators 含 owner+协助人全部
     */
    public function test_resolve_targets_collaborators_returns_all_users()
    {
        $owner = User::factory()->create();
        $assistant = User::factory()->create();
        $task = ProjectTask::factory()->create(['userid' => $owner->userid]);
        ProjectTaskUser::createInstance([
            'project_id' => $task->project_id, 'task_id' => $task->id, 'task_pid' => $task->id,
            'userid' => $owner->userid, 'owner' => 1,
        ])->save();
        ProjectTaskUser::createInstance([
            'project_id' => $task->project_id, 'task_id' => $task->id, 'task_pid' => $task->id,
            'userid' => $assistant->userid, 'owner' => 0,
        ])->save();

        $engine = app(TriggerEngine::class);
        $userIds = $engine->resolveTargets($task, 'collaborators');

        $this->assertCount(2, $userIds);
        $this->assertContains($owner->userid, $userIds);
        $this->assertContains($assistant->userid, $userIds);
    }

    /**
     * 9. resolveTargets assignees 仅 owner=0 协助人
     */
    public function test_resolve_targets_assignees_returns_collaborators_only()
    {
        $owner = User::factory()->create();
        $assistant = User::factory()->create();
        $task = ProjectTask::factory()->create(['userid' => $owner->userid]);
        ProjectTaskUser::createInstance([
            'project_id' => $task->project_id, 'task_id' => $task->id, 'task_pid' => $task->id,
            'userid' => $owner->userid, 'owner' => 1,
        ])->save();
        ProjectTaskUser::createInstance([
            'project_id' => $task->project_id, 'task_id' => $task->id, 'task_pid' => $task->id,
            'userid' => $assistant->userid, 'owner' => 0,
        ])->save();

        $engine = app(TriggerEngine::class);
        $userIds = $engine->resolveTargets($task, 'assignees');

        $this->assertCount(1, $userIds);
        $this->assertEquals($assistant->userid, $userIds[0]);
        $this->assertNotContains($owner->userid, $userIds);
    }

    // ======================================================================
    // 6. modal mode
    // ======================================================================

    /**
     * 10. modal mode 不抛但写 trigger_log（前端在 catch 流程之外另行收 modal_props）
     */
    public function test_modal_mode_logs_without_throw()
    {
        $owner = User::factory()->create();
        $task = ProjectTask::factory()->create(['userid' => $owner->userid]);
        $this->actAsUser($owner);

        $tpl = $this->createTemplate([
            'trigger_rules' => [
                ['event' => 'on_complete', 'mode' => 'modal', 'target' => 'reporter',
                 'message' => '建议补全上报'],
            ],
        ]);
        $this->makeGlobalDefault($tpl);

        // 不抛
        app(TriggerEngine::class)->handle('on_complete', $task);
        $this->assertTrue(true);

        $log = TaskReportTriggerLog::where('task_id', $task->id)->first();
        $this->assertNotNull($log);
        $this->assertEquals('modal', $log->mode);
    }

    // ======================================================================
    // 7. remind mode + frequency_limit_min
    // ======================================================================

    /**
     * 11. remind 节流：上次同 rule 触发距今 < frequency_limit_min → 跳过本次
     */
    public function test_remind_frequency_limit_throttles()
    {
        $owner = User::factory()->create();
        $task = ProjectTask::factory()->create(['userid' => $owner->userid]);
        $this->actAsUser($owner);

        $tpl = $this->createTemplate([
            'trigger_rules' => [
                ['event' => 'on_complete', 'mode' => 'remind', 'target' => 'reporter',
                 'constraint' => ['frequency_limit_min' => 60],
                 'message' => '请补汇报'],
            ],
        ]);
        $this->makeGlobalDefault($tpl);

        // 预先植入 30 分钟前的 trigger_log（应被节流）
        TaskReportTriggerLog::create([
            'task_id'      => $task->id,
            'template_id'  => $tpl->id,
            'rule_idx'     => 0,
            'event'        => 'on_complete',
            'mode'         => 'remind',
            'triggered_at' => now()->subMinutes(30),
            'user_id'      => $owner->userid,
        ]);

        // 触发 → 应被节流跳过（不写第二条 log，不入 outbox）
        app(TriggerEngine::class)->handle('on_complete', $task);

        $this->assertEquals(1, TaskReportTriggerLog::where('task_id', $task->id)->count(),
            'frequency_limit_min=60 + 上次 30min 前 → 应节流跳过');

        $this->assertEquals(0, DB::table('wecom_notifications')
            ->where('task_id', $task->id)
            ->where('event_type', 'report_remind')
            ->count(), '节流期内不应写 outbox');
    }

    /**
     * 12. remind 无节流且有 binding → 写 outbox 一条
     */
    public function test_remind_writes_outbox_when_user_has_binding()
    {
        $owner = User::factory()->create();
        $task = ProjectTask::factory()->create(['userid' => $owner->userid]);
        $this->actAsUser($owner);

        // 给 owner 配 wecom binding
        UserWecomBinding::createInstance([
            'userid'        => $owner->userid,
            'wecom_userid'  => 'wecom_' . $owner->userid,
            'wecom_corp_id' => 'corp_test',
        ])->save();

        $tpl = $this->createTemplate([
            'trigger_rules' => [
                ['event' => 'on_complete', 'mode' => 'remind', 'target' => 'reporter',
                 'message' => '请补汇报'],
            ],
        ]);
        $this->makeGlobalDefault($tpl);

        app(TriggerEngine::class)->handle('on_complete', $task);

        // outbox 应有一条 pending 记录
        $row = DB::table('wecom_notifications')
            ->where('task_id', $task->id)
            ->where('event_type', 'report_remind')
            ->first();
        $this->assertNotNull($row, 'remind 模式应写一条 wecom_notifications outbox 行');
        $this->assertEquals('pending', $row->status);
        $this->assertEquals($owner->userid, $row->target_userid);
        $this->assertNotEmpty($row->rendered_markdown);
    }

    /**
     * 13. remind 无 binding 用户 → 跳过 outbox（不抛）
     */
    public function test_remind_skips_users_without_binding()
    {
        $owner = User::factory()->create();
        $task = ProjectTask::factory()->create(['userid' => $owner->userid]);
        $this->actAsUser($owner);

        // 不配 binding

        $tpl = $this->createTemplate([
            'trigger_rules' => [
                ['event' => 'on_complete', 'mode' => 'remind', 'target' => 'reporter'],
            ],
        ]);
        $this->makeGlobalDefault($tpl);

        app(TriggerEngine::class)->handle('on_complete', $task);

        // outbox 0 行（无 binding 跳过）
        $this->assertEquals(0, DB::table('wecom_notifications')
            ->where('task_id', $task->id)
            ->count());

        // log 仍应记一条（matchConstraint 通过 + target 命中）
        $this->assertEquals(1, TaskReportTriggerLog::where('task_id', $task->id)
            ->where('mode', 'remind')->count());
    }

    // ======================================================================
    // 8. logTrigger 防重复
    // ======================================================================

    /**
     * 14. logTrigger 同 (task_id, rule_idx, triggered_at, event, mode) unique 冲突 → 吞掉不抛
     */
    public function test_log_trigger_swallows_unique_conflict()
    {
        $owner = User::factory()->create();
        $task = ProjectTask::factory()->create(['userid' => $owner->userid]);
        $this->actAsUser($owner);

        // 第 1 次手动写一条 log（占住 unique key）
        $now = now();
        TaskReportTriggerLog::create([
            'task_id'      => $task->id,
            'template_id'  => null,
            'rule_idx'     => 0,
            'event'        => 'on_complete',
            'mode'         => 'modal',
            'triggered_at' => $now,
            'user_id'      => $owner->userid,
        ]);

        // 用反射强制时间冻结（模拟同秒重入）— 简化：直接在 handle 内同秒入库会冲突
        // 这里改用断言：调 handle 后即使触发也不会因为 unique 冲突抛
        $tpl = $this->createTemplate([
            'trigger_rules' => [
                ['event' => 'on_complete', 'mode' => 'modal', 'target' => 'reporter'],
            ],
        ]);
        $this->makeGlobalDefault($tpl);

        // 把 now() 锁死成同一秒（防 triggered_at 自然漂移导致 unique key 不冲突）
        \Illuminate\Support\Carbon::setTestNow($now);
        try {
            app(TriggerEngine::class)->handle('on_complete', $task);
            $this->assertTrue(true);  // 没抛 = 通过
        } finally {
            \Illuminate\Support\Carbon::setTestNow();
        }

        // 仍只有 1 条（unique 冲突被吞）
        $this->assertEquals(1, TaskReportTriggerLog::where('task_id', $task->id)
            ->where('triggered_at', $now)->count());
    }
}
