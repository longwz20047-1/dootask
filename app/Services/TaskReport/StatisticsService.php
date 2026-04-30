<?php

// [CUSTOM:report-channel] Sprint 7-A Task 7.3
// Spec §11.8 + §11.8.Y 仪表盘 SQL 生成器（has_index 动态分流）
//
// 职责：
//   1. aggregate(params) 按 dimensions/metric/filters 生成聚合 SQL 并执行
//   2. has_index 分流（v3.10 §11.8.Y）：
//      - has_index=true  → 用 `<code>_v` STORED 虚拟列（命中 BTREE 索引，O(log n)）
//      - has_index=false → JSON_VALUE 全表扫 + 返 warning（性能预警）
//   3. dimColMap 双别名（plan v1.4 P0-V3.24-1 / v1.12 修）：
//      - time/day                       → DATE(created_at) AS day
//      - user/reporter_userid           → reporter_userid
//      - project/project_id             → project_id
//      - task/task_id                   → task_id
//      - template/template_id           → template_id
//
// 使用说明：
//   - hours_v 虚拟列在 Sprint 1 Pass 1 migration 已建（默认仅 hours 字段预置索引）
//   - 其他字段需 admin 通过 IndexBuilder 异步 ALTER 才会有 <code>_v 列
//   - 故 has_index=false 路径必须 fallback 到 JSON_VALUE 表达式

namespace App\Services\TaskReport;

use App\Models\TaskFieldDefinition;
use Illuminate\Support\Facades\DB;

class StatisticsService
{
    /**
     * v3.23/v1.12 双别名映射（前端传 dim 名 → 真实 SQL 列表达式）
     *
     * 'XX as YY' 形态会被解析：SELECT 用整体表达式，GROUP BY 用别名 YY
     * 否则 SELECT 与 GROUP BY 都用同一列名
     */
    private array $dimColMap = [
        'time'             => 'DATE(created_at) as day',
        'day'              => 'DATE(created_at) as day',
        'user'             => 'reporter_userid',
        'reporter_userid'  => 'reporter_userid',
        'project'          => 'project_id',
        'project_id'       => 'project_id',
        'task'             => 'task_id',
        'task_id'          => 'task_id',
        'template'         => 'template_id',
        'template_id'      => 'template_id',
    ];

    /**
     * 主入口：生成聚合查询并执行
     *
     * @param array $params {
     *   dimensions: string[]                     必填，至少 1 个，按 dimColMap 解析
     *   metric: string                           必填，'count' / 'sum_<code>' / 'avg_<code>' / 'max_<code>' / 'min_<code>' / 'count_distinct_<code>'
     *   filters?: {
     *     user_ids?: int[],
     *     project_ids?: int[],
     *     task_ids?: int[],
     *     template_ids?: int[],
     *     date_range?: [start, end]              YYYY-MM-DD 字符串
     *   },
     *   sort?: [{ field: 'metric_value'|<col>, order: 'asc'|'desc' }],
     *   top_n?: int                              limit 行数
     * }
     * @return array { rows: array, warning: ?string }
     */
    public function aggregate(array $params): array
    {
        $dimensions = $params['dimensions'] ?? [];
        $metric     = $params['metric'] ?? 'count';
        $filters    = $params['filters'] ?? [];
        $sort       = $params['sort'] ?? [];
        $topN       = $params['top_n'] ?? null;

        if (empty($dimensions)) {
            return ['rows' => [], 'warning' => 'dimensions empty'];
        }

        // 1. 解析 dimensions → SELECT 列 + GROUP BY 列
        $selectParts  = [];
        $groupByParts = [];
        foreach ($dimensions as $dim) {
            $col = $this->dimColMap[$dim] ?? null;
            if (!$col) {
                continue;  // 未知 dim 跳过
            }
            $selectParts[] = $col;
            // 'DATE(created_at) as day' → GROUP BY day
            $groupByParts[] = stripos($col, ' as ') !== false
                ? trim(preg_split('/ as /i', $col)[1])
                : $col;
        }

        // 2. 解析 metric → 聚合表达式 + warning（has_index 分流）
        $metricResult = $this->buildMetricExpr($metric);
        $selectParts[] = $metricResult['expr'];
        $warning = $metricResult['warning'];

        // 3. 构建查询
        $query = DB::table('project_task_reports')
            ->whereNull('deleted_at')
            ->where('cascade_deleted', false);

        if (!empty($filters['user_ids'])) {
            $query->whereIn('reporter_userid', (array) $filters['user_ids']);
        }
        if (!empty($filters['project_ids'])) {
            $query->whereIn('project_id', (array) $filters['project_ids']);
        }
        if (!empty($filters['task_ids'])) {
            $query->whereIn('task_id', (array) $filters['task_ids']);
        }
        if (!empty($filters['template_ids'])) {
            $query->whereIn('template_id', (array) $filters['template_ids']);
        }
        if (!empty($filters['date_range'])
            && is_array($filters['date_range'])
            && count($filters['date_range']) === 2
        ) {
            $query->whereBetween('created_at', [
                $filters['date_range'][0],
                $filters['date_range'][1],
            ]);
        }

        // 4. SELECT 拼装 + GROUP BY
        foreach ($selectParts as $part) {
            $query->selectRaw($part);
        }
        $query->groupBy($groupByParts);

        // 5. sort + top_n
        foreach ($sort as $sortItem) {
            $field = $sortItem['field'] ?? null;
            $order = strtolower($sortItem['order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
            if ($field === 'metric_value') {
                $query->orderByRaw("metric_value {$order}");
            } elseif ($field) {
                $query->orderBy($field, $order);
            }
        }
        if ($topN && $topN > 0) {
            $query->limit($topN);
        }

        $rows = $query->get()->map(fn($r) => (array) $r)->all();

        return [
            'rows'    => $rows,
            'warning' => $warning,
        ];
    }

    /**
     * 构建 metric 聚合表达式 + has_index 分流
     *
     * metric 形态：
     *   - 'count'                              → COUNT(*) as metric_value
     *   - 'sum_<code>' / 'avg_<code>' / 'max_<code>' / 'min_<code>' / 'count_distinct_<code>'
     *     → 按 task_field_definitions.has_index 分流：
     *        true  → `<code>_v` 虚拟列（无 warning）
     *        false → CAST(JSON_VALUE(...)) + 返 warning
     *
     * @return array{expr: string, warning: ?string}
     */
    private function buildMetricExpr(string $metric): array
    {
        if ($metric === 'count') {
            return ['expr' => 'COUNT(*) as metric_value', 'warning' => null];
        }

        // 解析 sum_<code> / avg_<code> / max_<code> / min_<code> / count_distinct_<code>
        if (preg_match('/^(sum|avg|max|min|count_distinct)_(.+)$/', $metric, $m)) {
            $aggFunc = strtoupper($m[1]);
            $code    = $m[2];

            // 查 task_field_definitions 看 has_index
            $field    = TaskFieldDefinition::where('code', $code)->first();
            $hasIndex = $field && $field->has_index;

            // count_distinct → COUNT(DISTINCT ...)
            $sqlOpen  = $aggFunc === 'COUNT_DISTINCT' ? 'COUNT(DISTINCT ' : "{$aggFunc}(";
            $sqlClose = ')';

            if ($hasIndex) {
                $valueExpr = "`{$code}_v`";
                $warning   = null;
            } else {
                // 退化：JSON_VALUE 全表扫（性能预警）
                $valueExpr = "CAST(JSON_VALUE(`values`, '$.{$code}') AS DECIMAL(15, 4))";
                $warning   = "字段 '{$code}' 未建聚合索引，全表扫描可能慢（建议管理员启用聚合索引以提升性能）";
            }

            return [
                'expr'    => "{$sqlOpen}{$valueExpr}{$sqlClose} as metric_value",
                'warning' => $warning,
            ];
        }

        // 不识别的 metric → 退化 COUNT(*)
        return [
            'expr'    => 'COUNT(*) as metric_value',
            'warning' => "未知 metric '{$metric}'，已退化为 COUNT(*)",
        ];
    }

    /**
     * 公开方法：列出所有可聚合字段（前端 dashboard 选 metric 用）
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAggregableFields(): array
    {
        return TaskFieldDefinition::where('aggregatable', true)
            ->where('enabled', true)
            ->get(['id', 'code', 'name', 'type', 'aggregate_strategy', 'has_index', 'scope', 'project_id'])
            ->map(fn($r) => $r->toArray())
            ->all();
    }

    /**
     * 公开方法：dim 双别名 map（暴露给 controller / MCP zod 校验）
     *
     * @return array<string, string>
     */
    public function getDimColMap(): array
    {
        return $this->dimColMap;
    }
}
