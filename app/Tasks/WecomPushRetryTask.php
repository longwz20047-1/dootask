<?php

namespace App\Tasks;

use App\Models\WecomNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * M1 企微任务通知 — 重试调度 Task
 *
 * 由 IndexController::crontab() 每 1 min 派发一次；内部 Cache 锁节流到 5 min 执行一次：
 *   ① 扫 pending + next_retry_at 到期 → 派发 WecomPushTask
 *   ② 回收僵尸 processing（processing_at > 10 min 未转态，可能 Swoole 重启孤儿）
 *
 * 参考 WecomOrgSyncTask 的 Cache key 节流模式（spec §6.3）
 */
class WecomPushRetryTask extends AbstractTask
{
    /** Cache key — 记录上次 tick 的分钟（(int)(time()/60)）用于节流 */
    private const CACHE_KEY_LAST_MINUTE = 'WecomPushRetryTask:lastMinute';

    /** 节流间隔（分钟） */
    private const THROTTLE_MINUTES = 5;

    /** 单次扫描派发上限（防"重试雪崩"压垮 AS） */
    private const MAX_DISPATCH_PER_TICK = 100;

    /** 僵尸 processing 超时（分钟） */
    private const ZOMBIE_PROCESSING_MINUTES = 10;

    public function __construct()
    {
        parent::__construct();
    }

    public function start()
    {
        // ① Cache 节流：5 分钟一次
        $currentMinute = (int) (time() / 60);
        if ($currentMinute % self::THROTTLE_MINUTES !== 0) {
            return;
        }
        $lastMinute = Cache::get(self::CACHE_KEY_LAST_MINUTE);
        if ($lastMinute === $currentMinute) {
            return;   // 本分钟已执行过
        }
        // 写 Cache（10 min TTL 防时钟抖动重复执行）
        Cache::put(self::CACHE_KEY_LAST_MINUTE, $currentMinute, Carbon::now()->addMinutes(10));

        // ② 扫 pending + next_retry_at 到期 → 派发 WecomPushTask
        $ids = WecomNotification::query()
            ->where('status', 'pending')
            ->where('next_retry_at', '<=', Carbon::now())
            ->orderBy('created_at')
            ->limit(self::MAX_DISPATCH_PER_TICK)
            ->pluck('id');

        $dispatched = 0;
        foreach ($ids as $id) {
            // 直接用 AbstractObserver::taskDeliver（跨类调 public static，自带 app()->bound('swoole') 守卫）
            \App\Observers\AbstractObserver::taskDeliver(new WecomPushTask((int) $id));
            $dispatched++;
        }

        // ③ 回收僵尸 processing
        $zombieCutoff = Carbon::now()->subMinutes(self::ZOMBIE_PROCESSING_MINUTES);
        $zombieRecovered = WecomNotification::query()
            ->where('status', 'processing')
            ->where('processing_at', '<', $zombieCutoff)
            ->update([
                'status'     => 'pending',
                'last_error' => 'zombie recovered',
                'updated_at' => Carbon::now(),
            ]);

        if ($dispatched > 0 || $zombieRecovered > 0) {
            Log::info('[WecomPushRetryTask] tick', [
                'dispatched' => $dispatched,
                'zombie_recovered' => $zombieRecovered,
            ]);
        }
    }

    public function end()
    {
        // AbstractTask 强制实现
    }
}
