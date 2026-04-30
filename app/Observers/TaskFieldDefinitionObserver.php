<?php

// [CUSTOM:report-channel] Sprint 7-A Task 7.3.5
// Spec §11.8.X TaskFieldDefinition 改动 → 失效 dashboard_cache + per-request 字段定义缓存

namespace App\Observers;

use App\Models\TaskFieldDefinition;
use App\Services\TaskReport\DashboardCacheInvalidator;
use App\Services\TaskReport\FieldDefinitionCache;

class TaskFieldDefinitionObserver
{
    /**
     * 任意保存（创建/更新）后失效缓存
     */
    public function saved(TaskFieldDefinition $field): void
    {
        // 1. 失效 per-request FieldDefinitionCache（cleaner 已注册，但同 request 内立刻失效更稳）
        FieldDefinitionCache::flushAll();

        // 2. 失效 dashboard_cache（hasTable 守门，Sprint 9 建表前 no-op）
        DashboardCacheInvalidator::flush('field', $field->id);
    }

    /**
     * 删除（含软删）后失效缓存
     */
    public function deleted(TaskFieldDefinition $field): void
    {
        FieldDefinitionCache::flushAll();
        DashboardCacheInvalidator::flush('field', $field->id);
    }
}
