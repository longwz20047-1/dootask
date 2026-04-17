<?php

namespace App\Tasks;

use App\Module\Base;
use App\Services\WecomOrgSyncService;
use Cache;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * 企微组织架构定时同步
 *
 * 每分钟由 IndexController::crontab() 投递一次
 * 内部 Cache 锁保证每日凌晨 2-3 点 窗口内仅执行 1 次
 */
class WecomOrgSyncTask extends AbstractTask
{
    private const CACHE_KEY = 'WecomOrgSyncTask:lastRunDate';

    public function __construct()
    {
        parent::__construct();
    }

    public function start()
    {
        $setting = Base::setting('thirdAccessSetting');
        if (($setting['wecom_org_sync'] ?? 'close') !== 'open') {
            return;
        }

        // 每日一次，凌晨 2-3 点窗口
        $hour = (int)date('H');
        if ($hour < 2 || $hour > 3) {
            return;
        }

        $today = date('Y-m-d');
        if (Cache::get(self::CACHE_KEY) === $today) {
            return;
        }
        Cache::put(self::CACHE_KEY, $today, Carbon::now()->addDays(2));

        $service = WecomOrgSyncService::fromSetting();
        if (!$service) {
            return;
        }

        try {
            $result = $service->syncAll();
            Log::info('[WecomOrgSyncTask] 定时同步完成', $result);
        } catch (\Throwable $e) {
            Log::warning('[WecomOrgSyncTask] 定时同步失败', ['error' => $e->getMessage()]);
            Cache::forget(self::CACHE_KEY);
        }
    }

    public function end()
    {
    }
}
