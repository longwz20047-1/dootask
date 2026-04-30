<?php

// [CUSTOM:report-channel] Sprint 7-A Task 7.2
// Spec §21.2 + §21.5 TriggerEngine — 7 events × 3 modes × 4 constraints 决策矩阵
//
// 职责：
//   - handle($event, $task, $context) 主入口（被 ProjectTask::saving 钩子 + cron 调）
//   - 每条 rule 独立 target 过滤（v3.24 P0-V3.24-2）
//   - per_user min_count 过滤（v3.23 P0-V3.23-2，owner 与协助人独立计数）
//   - 7 events: on_complete / on_start / on_status_change / on_flow_change /
//     daily / weekly / manual
//   - 3 modes: block (throw ApiException) / modal (返 modal_props 给前端) /
//     remind (走 v2.6 M1 WecomNotification outbox + WecomPushTask 异步推送)
//   - 4 constraints: min_count / max_count / time_window (v3.3 简化为 PASS) /
//     frequency_limit_min (节流去重)
//
// 关键决策（与 spec / plan 校对）：
//   1. trigger_log 实际 schema 列名 = rule_idx / user_id（migration
//      2026_04_29_100008_create_task_report_trigger_log_table.php），spec §21.2
//      代码段写成 rule_index / triggered_userid 是文档漂移；本服务严格按 migration
//      列名落地。模型同样无 'satisfied' 列（spec 提及但未落 migration）→ 不写。
//   2. resolveTargets 'reporter' = task.userid 单值（spec §21.2 line 5202
//      "task.userid 是任务负责人，dootask 任务模型范式"）— 与 prompt skeleton 的
//      "owner=1" 在 dootask 的范式下等价（创建任务时 owner=1 行 = task.userid），
//      但 spec 的 task.userid 单值是 dootask 真实数据语义，不需要查 ProjectTaskUser。
//   3. resolveTargets 'collaborators' = ProjectTaskUser::whereTaskId 全部 userid
//      （v3.22 明示含 owner+协助人），用直接 query 不依赖 ProjectTask::users() 别名
//      （ProjectTask 仅有 taskUser() 关系，未提供 users()）。
//   4. resolveTargets 'assignees' = 仅 owner=0 协助人。
//   5. pushRemind 走 M1 真实 outbox：insertOrIgnore 防 unique 冲突中断 handle 循环
//      （参 WecomNotifierService::enqueue spec §11 R1）+ rendered_markdown 由
//      WecomMarkdownRenderer::renderReportRemind 渲染（Sprint 5a Task 5a.6 已建）。
//   6. User::auth() 在无登录会抛 ApiException（不是返 null），故内部用
//      User::userid() 安全包装（returns 0 if no auth）。
//
// 下游消费者（Sprint 7-B+）：
//   - ProjectTask::saving 4 处钩子（Task 7.6）
//   - ScheduledDailyReportTriggerTask / ScheduledWeeklyReportTriggerTask（Sprint 7-D）
//   - 用户主动 'manual' event（暂未启用）

namespace App\Services\TaskReport;

use App\Exceptions\ApiException;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectTaskUser;
use App\Models\ProjectUser;
use App\Models\TaskReport;
use App\Models\TaskReportTemplate;
use App\Models\TaskReportTriggerLog;
use App\Models\User;
use App\Models\UserWecomBinding;
use App\Models\WecomNotification;
use App\Services\Wecom\WecomMarkdownRenderer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TriggerEngine
{
    /** @var TemplateResolver */
    private $resolver;

    public function __construct(TemplateResolver $resolver)
    {
        $this->resolver = $resolver;
    }

    /**
     * 主入口：触发 task 的 event 对应所有 rules
     *
     * @param string $event   on_complete/on_start/on_status_change/on_flow_change/daily/weekly/manual
     * @param ProjectTask $task 任务实例
     * @param array $context  额外上下文（如 status_old/status_new for on_status_change）
     * @throws ApiException 当 mode='block' rule 命中且 reports 不满足 min_count 时
     */
    public function handle(string $event, ProjectTask $task, array $context = []): void
    {
        $template = $this->resolver->resolveForTask($task);
        if (!$template || empty($template->trigger_rules)) {
            return; // 无模板或无规则，不触发
        }

        $userid = $this->currentUserid();

        foreach ($template->trigger_rules as $idx => $rule) {
            // 1. event 匹配
            if (($rule['event'] ?? '') !== $event) {
                continue;
            }

            // 2. v3.24 P0-V3.24-2: 每条 rule 独立 target 过滤
            //    （协助人完成 owner 专属 target=reporter 任务时跳过 block 死锁）
            $targetUserIds = $this->resolveTargets($task, $rule['target'] ?? 'reporter');
            if ($userid && !in_array($userid, $targetUserIds, true)) {
                continue;
            }

            // 3. matchConstraint 节流（frequency_limit_min / max_count）
            //    返 false → 跳过本 rule（节流期内 / 已达 max_count）
            if (!$this->matchConstraint($rule, $task, $idx)) {
                continue;
            }

            // 4. 按 mode 处理（block 可抛 / modal 入 ws / remind 入 outbox）
            $mode = $rule['mode'] ?? 'block';
            switch ($mode) {
                case 'block':
                    $satisfied = $this->checkBlockSatisfied($rule, $task);
                    if (!$satisfied) {
                        // 写 trigger_log 后抛 ApiException 阻断 save（Eloquent saving 钩子事务自动 rollback）
                        $this->logTrigger($task, $template->id, $idx, $event, 'block', $userid);
                        $messageText = $rule['message']
                            ?? $rule['_hint']
                            ?? '需先完成本任务上报后再继续';
                        throw new ApiException(
                            $messageText,
                            ['template_block' => $this->buildModalProps($task, $template, $rule, $idx)],
                            -4001,
                            true
                        );
                    }
                    // 满足，仅记 log 不抛（v3.5 satisfied 标记落地为：log 存在 => 该次评估已发生）
                    $this->logTrigger($task, $template->id, $idx, $event, 'block', $userid);
                    break;

                case 'modal':
                    // modal 不抛 / 不入 outbox，仅 log 一次 + 由前端在 catch 后自取
                    // （Sprint 7-B Controller layer 把 modal 规则的 modal_props 注入到响应里）
                    $this->logTrigger($task, $template->id, $idx, $event, 'modal', $userid);
                    break;

                case 'remind':
                    // 走 M1 outbox 异步推送（节流已在 matchConstraint 拦截）
                    $this->logTrigger($task, $template->id, $idx, $event, 'remind', $userid);
                    $this->pushRemind($task, $template, $rule, $idx, $targetUserIds);
                    break;
            }
        }
    }

    /**
     * resolveTargets: target enum → user id 数组
     *
     * 4 档 target（spec §21.2 v3.4 P0-3 真实 dootask 数据模型）：
     *   - reporter:        task.userid 单值（任务负责人 — dootask 任务模型范式）
     *   - collaborators:   ProjectTaskUser 全部（含 owner=1 + owner=0 协助人）
     *   - assignees:       仅 owner=0 协助人（与 reporter 互补）
     *   - project_leader:  ProjectUser.owner=1（项目负责人，可能多人）
     *   - all_members:     Project::relationUserids() 项目全员
     *
     * v3.24: public（被 §21.2.1 saving 钩子 shouldTriggerForCurrentUser 跨类调用）
     *
     * @param string $target reporter / collaborators / assignees / project_leader / all_members
     * @return int[] userid 数组（去重）
     */
    public function resolveTargets(ProjectTask $task, string $target): array
    {
        switch ($target) {
            case 'reporter':
                // task.userid 单值（dootask 任务负责人）
                return $task->userid ? [(int) $task->userid] : [];

            case 'collaborators':
                // 全部 task users（含 owner=1 主负责人 + owner=0 协助人，v3.22 明示）
                return ProjectTaskUser::whereTaskId($task->id)
                    ->pluck('userid')
                    ->map(fn ($v) => (int) $v)
                    ->unique()
                    ->values()
                    ->toArray();

            case 'assignees':
                // 仅 owner=0 协助人
                return ProjectTaskUser::whereTaskId($task->id)
                    ->whereOwner(0)
                    ->pluck('userid')
                    ->map(fn ($v) => (int) $v)
                    ->unique()
                    ->values()
                    ->toArray();

            case 'project_leader':
                if (!$task->project_id) return [];
                return ProjectUser::whereProjectId($task->project_id)
                    ->whereOwner(1)
                    ->pluck('userid')
                    ->map(fn ($v) => (int) $v)
                    ->toArray();

            case 'all_members':
                $project = $task->project;
                return $project ? array_map('intval', $project->relationUserids()) : [];

            default:
                // 未知 target 视同 reporter（保守兜底）
                return $task->userid ? [(int) $task->userid] : [];
        }
    }

    /**
     * constraint 节流校验（spec §21.2 matchConstraint）
     *
     * @return bool true=允许进入 mode 处理；false=被节流/限额拦截，跳过本 rule
     */
    private function matchConstraint(array $rule, ProjectTask $task, int $ruleIdx): bool
    {
        $c = $rule['constraint'] ?? [];

        // frequency_limit_min: 同 task 同 rule_idx 上次触发距今 < N 分钟则跳过
        if (isset($c['frequency_limit_min']) && (int) $c['frequency_limit_min'] > 0) {
            $last = TaskReportTriggerLog::where('task_id', $task->id)
                ->where('rule_idx', $ruleIdx)
                ->orderByDesc('triggered_at')
                ->first();
            if ($last && $last->triggered_at && $last->triggered_at->diffInMinutes(now()) < (int) $c['frequency_limit_min']) {
                return false;
            }
        }

        // max_count: 累计触发次数达上限则跳过
        if (isset($c['max_count']) && (int) $c['max_count'] > 0) {
            $count = TaskReportTriggerLog::where('task_id', $task->id)
                ->where('rule_idx', $ruleIdx)
                ->count();
            if ($count >= (int) $c['max_count']) {
                return false;
            }
        }

        // time_window: v3.3 简化为 PASS（task/day/week 三档由后续 cron 路径承载）
        return true;
    }

    /**
     * block 模式：min_count 是否已满足
     *
     * v3.23 P0-V3.23-2: per_user 隔离 — 用 reporter_userid 过滤当前 user，
     * owner 与协助人独立计数（避免 owner 已填 N 次但协助人 0 次时被误放行）
     */
    private function checkBlockSatisfied(array $rule, ProjectTask $task): bool
    {
        $minCount = (int) ($rule['constraint']['min_count'] ?? 1);
        if ($minCount <= 0) {
            return true;
        }

        $userid = $this->currentUserid();
        if (!$userid) {
            return false; // 无 auth user 视为不满足（保守）
        }

        $existing = TaskReport::where('task_id', $task->id)
            ->where('reporter_userid', $userid)
            ->where('cascade_deleted', false)
            ->whereNull('deleted_at')
            ->count();

        return $existing >= $minCount;
    }

    /**
     * 写 trigger_log（unique 约束防同 timestamp 重复）
     *
     * 唯一约束: (task_id, rule_idx, triggered_at, event, mode)
     * try/catch 吞掉 unique 冲突，避免破坏 handle 循环
     */
    private function logTrigger(ProjectTask $task, ?int $templateId, int $ruleIdx, string $event, string $mode, int $userid): void
    {
        try {
            TaskReportTriggerLog::create([
                'task_id'      => $task->id,
                'template_id'  => $templateId,
                'rule_idx'     => $ruleIdx,
                'event'        => $event,
                'mode'         => $mode,
                'triggered_at' => now(),
                'user_id'      => $userid,
            ]);
        } catch (\Throwable $e) {
            // unique constraint violation = 同秒重入，已记录，忽略
            Log::debug('[TriggerEngine] logTrigger dedup', [
                'task_id'  => $task->id,
                'rule_idx' => $ruleIdx,
                'event'    => $event,
                'mode'     => $mode,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * pushRemind: 推送 wecom 提醒（spec §21.2 v3.4 P0-2）
     *
     * 走 v2.6 M1 已上线 outbox 链路：
     *   1) 渲染 markdown via WecomMarkdownRenderer::renderReportRemind
     *   2) 反查每个 target 的 UserWecomBinding（无 binding / 已离职跳过）
     *   3) DB::insertOrIgnore wecom_notifications（参 WecomNotifierService::enqueue
     *      spec §11 R1：防 unique 冲突中断循环）
     *   4) 投递 WecomPushTask 异步执行（taskDeliver 在无 swoole 时 no-op，单测安全）
     */
    private function pushRemind(ProjectTask $task, TaskReportTemplate $template, array $rule, int $ruleIdx, array $targetUserIds): void
    {
        if (empty($targetUserIds)) {
            return;
        }

        $messageText = $rule['message'] ?? $rule['_hint'] ?? '请尽快完成本次任务上报';

        // 渲染 markdown 一次（同一 task / rule 各 user 共享同样文案 — 渲染依赖 task / project，不依赖 user）
        $renderer = app(WecomMarkdownRenderer::class);
        $renderedMarkdown = $renderer->renderReportRemind([
            'task_id' => $task->id,
            'message' => $messageText,
        ]);
        if (empty($renderedMarkdown)) {
            // task 不存在（极端 race） → 整批跳过
            return;
        }

        $eventType = 'report_remind';
        $defaultA2aAgentId = (string) config('wecom.default_a2a_agent_id');

        $newNotificationIds = [];
        foreach ($targetUserIds as $targetUserid) {
            $targetUserid = (int) $targetUserid;
            if ($targetUserid <= 0) continue;

            // 反查 active binding（无 binding / 已离职跳过 — spec §11 R9）
            $binding = UserWecomBinding::query()
                ->where('userid', $targetUserid)
                ->whereNull('unbind_at')
                ->first();
            if (!$binding) {
                continue;
            }

            // event_hash 含 ruleIdx 让同 task 不同规则可独立去重
            $eventHash = md5("report_remind:{$task->id}:{$ruleIdx}:{$targetUserid}");

            // payload 快照（重试不重渲染依赖此快照；亦便于排障）
            $payload = [
                'task_id'     => (int) $task->id,
                'template_id' => (int) $template->id,
                'rule_index'  => $ruleIdx,
                'message'     => $messageText,
            ];

            // insertOrIgnore 防 (event_hash, target_userid) 唯一冲突中断 handle 循环（spec §11 R1）
            $affected = DB::table('wecom_notifications')->insertOrIgnore([
                'event_hash'        => $eventHash,
                'event_type'        => $eventType,
                'task_id'           => (int) $task->id,
                'target_userid'     => $targetUserid,
                'wecom_corp_id'     => $binding->wecom_corp_id,
                'wecom_userid'      => $binding->wecom_userid,
                'a2a_agent_id'      => $defaultA2aAgentId,
                'rendered_markdown' => $renderedMarkdown,
                'status'            => 'pending',
                'payload'           => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'next_retry_at'     => now(),
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);

            if ($affected > 0) {
                $newNotificationIds[] = (int) DB::getPdo()->lastInsertId();
            }
        }

        // 异步投递 WecomPushTask（无 swoole 单测环境 no-op，安全）
        foreach ($newNotificationIds as $nid) {
            try {
                \App\Observers\AbstractObserver::taskDeliver(new \App\Tasks\WecomPushTask($nid));
            } catch (\Throwable $e) {
                // 单测无 swoole 不会抛；如真抛了不要中断 handle 循环
                Log::debug('[TriggerEngine] taskDeliver suppressed', [
                    'notification_id' => $nid,
                    'error'           => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * 构造 modal_props（块 mode 时塞进 ApiException data；modal mode 时由前端订阅）
     */
    private function buildModalProps(ProjectTask $task, TaskReportTemplate $template, array $rule, int $ruleIdx): array
    {
        return [
            'task_id'        => (int) $task->id,
            'template_id'    => (int) $template->id,
            'rule_index'     => $ruleIdx,
            'fields'         => $this->getTemplateFields($template),
            'message'        => $rule['message'] ?? $rule['_hint'] ?? '需先完成本任务上报后再继续',
            'min_count'      => (int) ($rule['constraint']['min_count'] ?? 1),
            'existing_count' => TaskReport::where('task_id', $task->id)
                ->where('reporter_userid', $this->currentUserid())
                ->where('cascade_deleted', false)
                ->whereNull('deleted_at')
                ->count(),
        ];
    }

    /**
     * 拉模板字段（含 pivot override + sort，供前端弹窗渲染）
     */
    private function getTemplateFields(TaskReportTemplate $template): array
    {
        return $template->templateFields()
            ->with('field')
            ->get()
            ->map(function ($pivot) {
                $field = $pivot->field;
                if (!$field) return null;
                return [
                    'id'            => (int) $field->id,
                    'code'          => $field->code,
                    'name'          => $field->name,
                    'type'          => $field->type,
                    'options'       => $field->options,
                    'required'      => (bool) $field->required,
                    'default_value' => $field->default_value,
                    'sort'          => (int) $pivot->sort,
                    'override'      => $pivot->override,
                ];
            })
            ->filter()
            ->values()
            ->toArray();
    }

    /**
     * 安全获取当前 userid（User::auth() 在无登录时抛异常，本服务统一用 userid() 返 0 兜底）
     */
    private function currentUserid(): int
    {
        try {
            return (int) User::userid();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
