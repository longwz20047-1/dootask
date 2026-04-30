<?php

// [CUSTOM:report-channel] Sprint 7-B Pass 1 · Task 7.7
// Spec §21.4 daily TriggerEngine 调度任务
//
// 调度路径：IndexController::crontab() 每分钟触发 → 本 Task 内 Cache 锁防跨日重复
//
// 设计：
//   - cache key = report:trigger:daily:Y-m-d，TTL 25h（保证跨日切换前不重复）
//   - 仅扫 24h 内活跃任务（updated_at >= now()-1day）+ 未归档/未删/未完成
//   - chunk(100) 防一次性内存爆
//   - 单 task 异常吞掉（cron 不应被某个 task 的 block ApiException 中断整批）

namespace App\Tasks;

use App\Models\ProjectTask;
use App\Services\TaskReport\TriggerEngine;
use Illuminate\Support\Facades\Cache;

class ScheduledDailyReportTriggerTask extends AbstractTask
{
    public function __construct()
    {
        parent::__construct();
    }

    public function start()
    {
        // cron idempotency: 防跨日重启 / 多 worker 重复执行
        $cacheKey = 'report:trigger:daily:' . date('Y-m-d');
        if (Cache::has($cacheKey)) {
            return;
        }
        Cache::put($cacheKey, 1, 25 * 60 * 60);  // 25h

        $engine = app(TriggerEngine::class);

        ProjectTask::whereNull('archived_at')
            ->whereNull('deleted_at')
            ->whereNull('complete_at')
            ->where('updated_at', '>=', now()->subDay())
            ->chunk(100, function ($tasks) use ($engine) {
                foreach ($tasks as $task) {
                    try {
                        $engine->handle('daily', $task);
                    } catch (\Throwable $e) {
                        // block 模式抛 ApiException → 忽略（cron 不阻断整批）
                    }
                }
            });
    }

    public function end()
    {
    }
}
