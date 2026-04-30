<?php

// [CUSTOM:report-channel] Sprint 7-B Pass 1 · Task 7.7
// Spec §21.4 weekly TriggerEngine 调度任务
//
// 调度路径：IndexController::crontab() 每分钟触发 → 本 Task 内 Cache 锁防跨周重复
//
// 设计：
//   - cache key = report:trigger:weekly:Y-W（ISO week year-week），TTL 8 day
//   - 仅扫 7d 内活跃任务（updated_at >= now()-7day）+ 未归档/未删/未完成
//   - chunk(100) 防一次性内存爆

namespace App\Tasks;

use App\Models\ProjectTask;
use App\Services\TaskReport\TriggerEngine;
use Illuminate\Support\Facades\Cache;

class ScheduledWeeklyReportTriggerTask extends AbstractTask
{
    public function __construct()
    {
        parent::__construct();
    }

    public function start()
    {
        // cron idempotency: ISO 周维度去重
        $cacheKey = 'report:trigger:weekly:' . date('o-W');
        if (Cache::has($cacheKey)) {
            return;
        }
        Cache::put($cacheKey, 1, 8 * 24 * 60 * 60);  // 8 day

        $engine = app(TriggerEngine::class);

        ProjectTask::whereNull('archived_at')
            ->whereNull('deleted_at')
            ->whereNull('complete_at')
            ->where('updated_at', '>=', now()->subDays(7))
            ->chunk(100, function ($tasks) use ($engine) {
                foreach ($tasks as $task) {
                    try {
                        $engine->handle('weekly', $task);
                    } catch (\Throwable $e) {
                        // block 模式抛 ApiException → 忽略（cron 不阻断）
                    }
                }
            });
    }

    public function end()
    {
    }
}
