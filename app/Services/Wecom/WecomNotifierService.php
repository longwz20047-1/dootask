<?php

namespace App\Services\Wecom;

use App\Models\ProjectTask;
use App\Models\User;
use App\Models\UserWecomBinding;
use App\Models\WecomNotification;
use Illuminate\Support\Facades\DB;

class WecomNotifierService
{
    /**
     * 判断接收者是否应收企微推送（过滤无 binding / 已离职）
     *
     * 在 ProjectTask::taskPush(_, 0) 循环内被调，$receiver 是 User 实例
     * （taskPush 通过 User::whereIn(...)->get() 取到）
     */
    public static function shouldPush(User $receiver): bool
    {
        return UserWecomBinding::query()
            ->where('userid', $receiver->userid)
            ->whereNull('unbind_at')   // 关键：过滤已离职员工（spec §11 R9）
            ->exists();
    }

    /**
     * 选 bot：按 corp_id 动态映射（M1 单 bot 直接返默认值；M5 扩展多 corp）
     */
    public function getDefaultA2aAgentId(string $corpId): string
    {
        return (string) config('wecom.default_a2a_agent_id');
    }

    /**
     * 写 outbox + 渲染 markdown，返 notificationId
     *
     * 返回：
     *   int      — 新建成功，返 lastInsertId
     *   null     — binding 无效 / 已存在同 (event_hash, target_userid) 行
     *              （insertOrIgnore 静默吞，防 ProjectTask::taskPush 循环被
     *               QueryException 中断破坏站内 sendMsg — spec §11 R1）
     *
     * @param ProjectTask $task        调用处的 $this（ProjectTask 实例）
     * @param User        $receiver    taskPush 循环内的接收者 User 实例
     * @param string      $eventType   M1 固定 'task_assigned'
     */
    public function enqueue(ProjectTask $task, User $receiver, string $eventType = 'task_assigned'): ?int
    {
        // 1. 反查 active binding
        $binding = UserWecomBinding::query()
            ->where('userid', $receiver->userid)
            ->whereNull('unbind_at')
            ->first();
        if (!$binding) {
            return null;   // shouldPush() 已挡，兜底：未绑定 / 已离职
        }

        // 2. 构造 payload 快照（重试不重渲染依赖此快照）
        $payload = [
            'task_name'        => $task->name,
            'project_id'       => $task->project_id,
            'project_name'     => optional($task->project)->name ?? '',
            'end_at'           => optional($task->end_at)->toDateTimeString(),
            'creator_userid'   => $task->userid,
            'creator_nickname' => optional(User::find($task->userid))->nickname ?? '',
            // p_name 是优先级名称（"高/中/低"），不是 p_color 颜色值也不是 p_level 数值
            // Renderer (Task 3) 模板显示 **优先级**：{priority} 直接作为文字渲染
            'priority'         => $task->p_name ?? '',
        ];

        // 3. 渲染 markdown（Service 实例化 Renderer；渲染异常让上层冒泡不吞）
        $markdown = app(WecomMarkdownRenderer::class)
            ->renderTaskAssigned($payload, (int) $task->id);

        // 4. 计算 event_hash（调 Model 静态方法，全仓单一算法 — spec §11 R7）
        $eventHash = WecomNotification::computeEventHash(
            $eventType,
            (int) $task->id,
            (int) $receiver->userid
        );

        // 5. insertOrIgnore 防唯一冲突中断循环（spec §11 R1）
        //    — 不能用 Model::createInstance，会抛 QueryException 破坏 taskPush 循环
        $affected = DB::table('wecom_notifications')->insertOrIgnore([
            'event_hash'        => $eventHash,
            'event_type'        => $eventType,
            'task_id'           => (int) $task->id,
            'target_userid'     => (int) $receiver->userid,
            'wecom_corp_id'     => $binding->wecom_corp_id,
            'wecom_userid'      => $binding->wecom_userid,
            'a2a_agent_id'      => $this->getDefaultA2aAgentId($binding->wecom_corp_id),
            'rendered_markdown' => $markdown,
            'status'            => 'pending',
            'payload'           => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'next_retry_at'     => now(),
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        if ($affected > 0) {
            return (int) DB::getPdo()->lastInsertId();
        }
        // 既有行（event_hash + target_userid 已存在）— 返 null
        // ProjectTask::taskPush 拿到 null 后不派发 Task，避免重复推送；既有行由 RetryTask 或已 sent
        return null;
    }
}
