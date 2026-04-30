<?php

// [CUSTOM:report-channel] Sprint 7-A Task 7.3.5
// Spec §11.8.X dashboard_cache 失效策略
//
// 失效触发：
//   - task_field_definitions 改动（增删字段 / 改类型）
//     → DELETE FROM dashboard_cache WHERE `key` LIKE '%field_id={id}%'
//   - task_report_templates 改动（模板字段引用 override 改）
//     → DELETE FROM dashboard_cache WHERE `key` LIKE '%template_id={id}%'
//   - task_report_template_fields 改动 → 失效对应 template 缓存
//
// 实现位置：
//   - TaskFieldDefinitionObserver::saved/deleted     调用 flush('field', $id)
//   - TaskReportTemplateObserver::saved              调用 flush('template', $id)
//   - TaskReportTemplateFieldObserver::saved/deleted 调用 flush('template_field', $template_id)
//
// 阶段降级策略（plan v1.10）：
//   dashboard_cache 表是 Sprint 9 仪表盘 ECharts 才需要建的预聚合层。
//   Sprint 7-A 阶段表未建，本类用 Schema::hasTable() 守门 → 无表时静默 no-op。
//   等 Sprint 9 建表时本类自动激活，无需改 Observer 调用方。

namespace App\Services\TaskReport;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardCacheInvalidator
{
    /**
     * 失效 dashboard_cache 行（spec §11.8.X）
     *
     * @param string $scope 'field' | 'template' | 'template_field'
     * @param int    $id    对应实体 ID
     */
    public static function flush(string $scope, int $id): void
    {
        // dashboard_cache 表 Sprint 9 才建，本阶段 hasTable 守门 no-op
        if (!Schema::hasTable('dashboard_cache')) {
            return;
        }

        $pre = DB::connection()->getTablePrefix();
        $keyPattern = match ($scope) {
            'field'          => "%field_id={$id}%",
            'template'       => "%template_id={$id}%",
            // template_field 改也失效对应 template 缓存
            'template_field' => "%template_id={$id}%",
            default          => null,
        };

        if ($keyPattern === null) {
            return;
        }

        DB::statement(
            "DELETE FROM {$pre}dashboard_cache WHERE `key` LIKE ?",
            [$keyPattern]
        );
    }

    /**
     * 全清缓存（极端 case：批量导入字段 / 模板时调用）
     */
    public static function flushAll(): void
    {
        if (!Schema::hasTable('dashboard_cache')) {
            return;
        }

        $pre = DB::connection()->getTablePrefix();
        DB::statement("DELETE FROM {$pre}dashboard_cache");
    }
}
