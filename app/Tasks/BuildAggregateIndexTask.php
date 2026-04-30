<?php

// [CUSTOM:report-channel] Sprint 7-A Task 7.4
// Spec §22.3 异步 ALTER TABLE 加 STORED 虚拟列 + CREATE INDEX
//
// 关键设计：
//   - 继承 AbstractTask → 必须实现 start() 和 end()（不是 finish()，dootask 真实范式）
//   - 必须调 parent::__construct(...func_get_args())（v3.5 P0-V3.5-4 守门）
//     否则 TaskWorker::createInstance 不触发 → twid=0 → handle/finish 全对 0 操作
//   - 失败回滚顺序（v3.4 P0-9）：先 DROP INDEX 再 DROP COLUMN（依赖列，反向不行）
//   - 完成后 update has_index = true / 失败时回滚 has_index = false

namespace App\Tasks;

use App\Models\TaskFieldDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BuildAggregateIndexTask extends AbstractTask
{
    private int $fieldId;
    private string $op;

    /**
     * @param int    $fieldId task_field_definitions.id
     * @param string $op      'enable' | 'disable'（disable 罕用，IndexBuilder::disable 主要走同步路径）
     */
    public function __construct(int $fieldId, string $op = 'enable')
    {
        // v3.5 P0-V3.5-4 修：必须 parent::__construct 才能注册 TaskWorker（否则 twid=0）
        parent::__construct(...func_get_args());
        $this->fieldId = $fieldId;
        $this->op      = $op;
    }

    /**
     * 异步执行入口（AbstractTask::handle() → start()）
     */
    public function start()
    {
        $field = TaskFieldDefinition::find($this->fieldId);
        if (!$field) {
            Log::warning("[BuildAggregateIndex] field #{$this->fieldId} not found");
            return;
        }
        if ($field->has_index && $this->op === 'enable') {
            return;  // idempotent
        }
        if (!in_array($field->type, ['number', 'date'], true)) {
            Log::warning("[BuildAggregateIndex] field #{$this->fieldId} type {$field->type} not supported");
            return;
        }

        $pre     = DB::connection()->getTablePrefix();
        $code    = $field->code;
        $colName = "`{$code}_v`";
        $idxName = "`idx_{$code}_v`";

        // type → 虚拟列定义
        if ($field->type === 'number') {
            $colDef = "DECIMAL(15, 4) AS (CAST(JSON_VALUE(`values`, '$.{$code}') AS DECIMAL(15, 4))) STORED";
        } else {
            // date
            $colDef = "DATE AS (DATE(JSON_VALUE(`values`, '$.{$code}'))) STORED";
        }

        try {
            // Step 1: ALTER TABLE 加 STORED 虚拟列
            DB::statement("ALTER TABLE {$pre}project_task_reports ADD COLUMN {$colName} {$colDef}");

            // Step 2: CREATE INDEX
            DB::statement("CREATE INDEX {$idxName} ON {$pre}project_task_reports ({$colName})");

            // Step 3: 标记 has_index = true（绕过 Eloquent Observer，避免无关 dashboard_cache 失效）
            DB::table('task_field_definitions')
                ->where('id', $this->fieldId)
                ->update(['has_index' => true, 'updated_at' => now()]);

            Log::info("[BuildAggregateIndex] field #{$this->fieldId} ({$code}) index built");
        } catch (\Throwable $e) {
            // 失败回滚（v3.4 P0-9）：先 DROP INDEX 再 DROP COLUMN
            try {
                DB::statement("DROP INDEX {$idxName} ON {$pre}project_task_reports");
            } catch (\Throwable $rollbackErr) {
                // index 可能从未建成 → 忽略
            }
            try {
                DB::statement("ALTER TABLE {$pre}project_task_reports DROP COLUMN {$colName}");
            } catch (\Throwable $rollbackErr) {
                // column 可能从未建成 → 忽略
            }

            Log::error("[BuildAggregateIndex] field #{$this->fieldId} failed: " . $e->getMessage());
        }
    }

    /**
     * AbstractTask::finish() → end()（任务完成事件，DDL 已 commit / 失败已回滚，无清理）
     */
    public function end()
    {
        // no-op
    }
}
