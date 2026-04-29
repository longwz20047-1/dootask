<?php

// [CUSTOM:report-channel]
// Spec §6.4.2 LaravelS 协程隔离 cleaner：每请求结束时清空 FieldDefinitionCache 静态属性，
// 避免上一个请求的字段定义脏数据污染下一个 worker 复用。
//
// 注册：config/laravels.php['cleaners'] 数组追加本类完全限定名。

namespace App\Cleaners;

use App\Services\TaskReport\FieldDefinitionCache;
use Hhxsv5\LaravelS\Illuminate\Cleaners\BaseCleaner;

class TaskReportCacheCleaner extends BaseCleaner
{
    public function clean()
    {
        FieldDefinitionCache::flushAll();
    }
}
