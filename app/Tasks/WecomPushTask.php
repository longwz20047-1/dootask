<?php

namespace App\Tasks;

use App\Models\WecomNotification;
use App\Module\Base;
use App\Module\Ihttp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * M1 企微任务通知 — 推送 Task
 *
 * 工作流（spec v2.1 §6.1 step 5）：
 *   ① 原子 UPDATE 占位 status=pending → processing，防并发（spec §5）
 *   ② 读 row，构造 HMAC 签名（body + timestamp + AS_PUSH_SECRET，spec §8.3.2）
 *   ③ Ihttp::ihttp_request POST 到 AS push endpoint
 *   ④ 根据结果 markSent（成功）或 markFailed（退避重试 / failed）
 *
 * 继承 AbstractTask：自动 TaskWorker 记录 + 异常捕获
 */
class WecomPushTask extends AbstractTask
{
    /**
     * @var int notificationId 由 WecomNotifierService::enqueue() 返回
     */
    private int $notificationId;

    public function __construct(int $notificationId)
    {
        // AbstractTask::__construct(...$params) 自动序列化参数到 task_workers 表便于排障
        parent::__construct($notificationId);
        $this->notificationId = $notificationId;
    }

    public function start()
    {
        // ① 原子 UPDATE 占位：status='pending' → 'processing'
        // 抢不到（被其他 worker 抢走 / 已非 pending）直接返回，不抛错
        $updated = DB::update(
            "UPDATE wecom_notifications
             SET status='processing',
                 processing_at=NOW(),
                 attempts=attempts+1,
                 updated_at=NOW()
             WHERE id=? AND status='pending'",
            [$this->notificationId]
        );
        if ($updated === 0) {
            // 说明已被其他 worker 抢走或已转 sent/failed/skipped，跳过
            return;
        }

        // ② 读 row
        $row = WecomNotification::find($this->notificationId);
        if (!$row) {
            Log::warning('[WecomPushTask] row not found', ['id' => $this->notificationId]);
            return;
        }

        // ③ 构造 HMAC 签名（spec §8.3.2）
        $body = json_encode([
            'a2a_agent_id' => $row->a2a_agent_id,
            'wecom_userid' => $row->wecom_userid,
            'content'      => $row->rendered_markdown,
        ], JSON_UNESCAPED_UNICODE);
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $secret = (string) config('wecom.as_push_secret');
        if ($secret === '') {
            $row->markFailed('AS_PUSH_SECRET not configured');
            return;
        }
        $signature = hash_hmac('sha256', $timestamp . "\n" . $body, $secret);

        // ④ Ihttp HTTP POST（Ihttp 的 $post 传字符串会原样作为 body，不会被 http_build_query 破坏签名）
        $url = (string) config('wecom.as_push_url');
        if ($url === '') {
            $row->markFailed('AS_PUSH_URL not configured');
            return;
        }
        $result = Ihttp::ihttp_request(
            $url,
            $body,  // JSON string，不是数组！否则 Ihttp 会 http_build_query 破坏签名
            [
                'Content-Type'         => 'application/json',
                'X-Internal-Secret'    => $signature,
                'X-Internal-Timestamp' => $timestamp,
                'X-Internal-Nonce'     => $nonce,
            ],
            30  // 30s timeout
        );

        // ⑤ 结果处理
        if (Base::isError($result)) {
            $row->markFailed($result['msg'] ?? 'network error');
            return;
        }

        $response = json_decode($result['data'] ?? 'null', true);
        if (is_array($response) && ($response['ok'] ?? false) === true) {
            $row->markSent();
        } else {
            $errMsg = is_array($response) ? ($response['error'] ?? 'bridge error (no ok)') : 'invalid response';
            $row->markFailed($errMsg);
        }
    }

    public function end()
    {
        // AbstractTask 强制实现，M1 无 end 逻辑（日志/指标交给 Log + task_workers 表）
    }
}
