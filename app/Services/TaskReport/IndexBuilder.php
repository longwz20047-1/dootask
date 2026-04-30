<?php

// [CUSTOM:report-channel] Sprint 7-A Task 7.4
// Spec §22.3 IndexBuilder：异步建虚拟列 + 索引（DDL 锁表风险）
//
// 设计：
//   - enable($fieldId)  → 异步 dispatch BuildAggregateIndexTask（DDL 慢，~3-5min for 22 万行）
//   - disable($fieldId) → 同步 DROP（小 DDL 快返回）
//   - 失败回滚顺序（spec §22.5 + plan v1.10 P0-9）：先 DROP INDEX 再 DROP COLUMN
//     反证：原 v3.3 直接 DROP COLUMN IF EXISTS 但 INDEX 还在 → MariaDB 拒绝（依赖列）
//
// 类型限制（spec §22.2）：
//   仅 number / date 类型支持聚合索引。其他 type 抛 InvalidArgumentException。
//   （spec 表也允许 select/text，但 plan v1.4 收敛到 number/date 两种以匹配
//    Sprint 7-A 的 hours_v 既有范式；select/text 留 Sprint 9 时扩展）
//
// 异步范式：用 dootask 既有 AbstractObserver::taskDeliver()
//   - 自带 swoole 容错（无 swoole 时静默 no-op，便于单测）
//   - 不引入虚构的 Laravel dispatch()

namespace App\Services\TaskReport;

use App\Models\TaskFieldDefinition;
use App\Observers\AbstractObserver;
use App\Tasks\BuildAggregateIndexTask;
use Illuminate\Support\Facades\DB;

class IndexBuilder
{
    /**
     * 异步启用聚合索引（spec §22.3）
     *
     * 流程：
     *   1. dispatch BuildAggregateIndexTask（不阻塞 admin 请求）
     *   2. Task 内 ALTER TABLE 加 STORED 虚拟列 + CREATE INDEX
     *   3. 完成后 UPDATE task_field_definitions SET has_index = true
     *
     * @throws \InvalidArgumentException 字段不存在 / 类型不支持
     */
    public static function enable(int $fieldId): void
    {
        $field = TaskFieldDefinition::find($fieldId);
        if (!$field) {
            throw new \InvalidArgumentException("字段 #{$fieldId} 不存在");
        }
        if ($field->has_index) {
            return;  // idempotent：已建索引时静默跳过
        }
        if (!in_array($field->type, ['number', 'date'], true)) {
            throw new \InvalidArgumentException(
                "字段类型 {$field->type} 不支持聚合索引（仅 number/date）"
            );
        }

        // taskDeliver 自带 swoole 容错：无 swoole（如单测环境）静默 no-op
        AbstractObserver::taskDeliver(new BuildAggregateIndexTask($fieldId, 'enable'));
    }

    /**
     * 同步禁用聚合索引（小 DDL 快返回）
     *
     * 失败回滚顺序：先 DROP INDEX 再 DROP COLUMN（spec §22.5）
     */
    public static function disable(int $fieldId): void
    {
        $field = TaskFieldDefinition::find($fieldId);
        if (!$field || !$field->has_index) {
            return;  // idempotent：未启用时静默跳过
        }

        $pre  = DB::connection()->getTablePrefix();
        $code = $field->code;

        // 先 DROP INDEX（依赖列，必须先 drop）
        try {
            DB::statement("DROP INDEX `idx_{$code}_v` ON {$pre}project_task_reports");
        } catch (\Throwable $e) {
            // 索引不存在 → 忽略（idempotent 保障）
        }
        // 再 DROP COLUMN
        try {
            DB::statement("ALTER TABLE {$pre}project_task_reports DROP COLUMN `{$code}_v`");
        } catch (\Throwable $e) {
            // 列不存在 → 忽略（idempotent 保障）
        }

        // 不通过 Eloquent 触 Observer（避免 dashboard_cache 无关失效）
        DB::table('task_field_definitions')
            ->where('id', $fieldId)
            ->update([
                'has_index'  => false,
                'updated_at' => now(),
            ]);
    }
}
