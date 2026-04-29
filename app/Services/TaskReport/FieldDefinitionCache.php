<?php

// [CUSTOM:report-channel]
// Spec §6.4 FieldDefinitionCache: per-request 静态属性缓存。
//
// 与 spec line 1982 差异：spec 用 `scope_id` 单列，本实现按真实 schema
// （migration 2026_04_29_100002）拆为 project_id / flow_item_id 两列。
//
// 跨请求清缓存：通过 LaravelS Cleaner（App\Cleaners\TaskReportCacheCleaner，
// 注册于 config/laravels.php['cleaners']）调用 flushAll()。

namespace App\Services\TaskReport;

use App\Models\TaskFieldDefinition;
use Illuminate\Support\Collection;

class FieldDefinitionCache
{
    /**
     * 按 cacheKey 索引的字段定义集合。
     *
     * @var array<string, Collection>
     */
    private static array $cache = [];

    /**
     * 获取启用字段定义。
     *
     * @param string   $scope        'global' | 'project' | 'flow_item'
     * @param int      $scopeId      project_id（scope=project 时）；其余传 0
     * @param int|null $flowItemId   flow_item_id（scope=flow_item 时必传）
     */
    public static function get(string $scope, int $scopeId = 0, ?int $flowItemId = null): Collection
    {
        $key = self::cacheKey($scope, $scopeId, $flowItemId);

        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $query = TaskFieldDefinition::query()->where('scope', $scope);

        if ($scope === 'project') {
            $query->where('project_id', $scopeId);
        } elseif ($scope === 'flow_item') {
            $query->where('flow_item_id', (int) $flowItemId);
        }

        $rows = $query->where('enabled', true)
            ->orderBy('sort')
            ->get();

        return self::$cache[$key] = $rows;
    }

    /**
     * 单个 scope 缓存清除（字段定义保存/删除时调用）。
     */
    public static function flush(string $scope, int $scopeId = 0, ?int $flowItemId = null): void
    {
        unset(self::$cache[self::cacheKey($scope, $scopeId, $flowItemId)]);
    }

    /**
     * 清空所有缓存。请求结束时由 LaravelS Cleaner 调用。
     */
    public static function flushAll(): void
    {
        self::$cache = [];
    }

    /**
     * 仅供测试访问内部缓存大小（不应被业务代码调用）。
     *
     * @internal
     */
    public static function size(): int
    {
        return count(self::$cache);
    }

    private static function cacheKey(string $scope, int $scopeId, ?int $flowItemId): string
    {
        return "{$scope}:{$scopeId}:" . ($flowItemId ?? 0);
    }
}
