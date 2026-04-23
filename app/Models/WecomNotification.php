<?php

namespace App\Models;

use Illuminate\Support\Carbon;

class WecomNotification extends AbstractModel
{
    protected $table = 'wecom_notifications';

    protected $fillable = [
        'event_hash', 'event_type', 'task_id', 'target_userid',
        'wecom_corp_id', 'wecom_userid', 'a2a_agent_id',
        'rendered_markdown', 'status', 'attempts', 'max_attempts',
        'last_error', 'payload', 'sent_at', 'next_retry_at', 'processing_at',
    ];

    protected $casts = [
        'payload'       => 'array',
        'sent_at'       => 'datetime',
        'next_retry_at' => 'datetime',
        'processing_at' => 'datetime',
    ];

    /**
     * 退避秒数映射（attempts → 推后秒数）
     * 对齐 spec §6.3 退避策略表
     */
    private const BACKOFF_SECONDS = [
        1 => 300,     //  5 min
        2 => 900,     // 15 min
        3 => 3600,    //  1 h
        4 => 14400,   //  4 h
        5 => 86400,   // 24 h
    ];

    /**
     * 统一的 event hash 算法。Observer 写入和 Retry 扫描必须用同一个算法，
     * 否则 (event_hash, target_userid) 唯一键会出现 hash 不一致的重复行（spec §11 R7）。
     */
    public static function computeEventHash(string $eventType, int $taskId, int $userid): string
    {
        return md5("{$eventType}:{$taskId}:{$userid}");
    }

    public function markSent(): void
    {
        $this->update([
            'status'     => 'sent',
            'sent_at'    => Carbon::now(),
            'last_error' => null,
        ]);
    }

    /**
     * 标记失败。attempts 已在 Task 入口的原子 UPDATE 里 +1 过，这里只决定下一步状态：
     * - 达到 max_attempts 则永久 failed
     * - 否则按 BACKOFF_SECONDS 推后 next_retry_at，状态回 pending 等重试
     */
    public function markFailed(string $error): void
    {
        $attempts = (int) $this->attempts;
        if ($attempts >= $this->max_attempts) {
            $this->update([
                'status'     => 'failed',
                'last_error' => $error,
            ]);
            return;
        }
        $delay = self::BACKOFF_SECONDS[$attempts] ?? 86400;
        $this->update([
            'status'        => 'pending',
            'last_error'    => $error,
            'next_retry_at' => Carbon::now()->addSeconds($delay),
            'processing_at' => null,
        ]);
    }

    public function markSkipped(string $reason): void
    {
        $this->update([
            'status'     => 'skipped',
            'last_error' => $reason,
        ]);
    }
}
