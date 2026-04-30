<?php

// [CUSTOM:report-channel] Sprint 7-D Pass 1 Task 7-D.2
// Spec §6.6 v3.22 + v3.26 我的未汇报任务列表服务
//
// 4 边界过滤：
//   1. 用户范围（owner / 协助人 project_task_users 含 owner=0 实时）
//   2. 软删 task （whereNull project_tasks.deleted_at）
//   3. 归档 task （whereNull project_tasks.archived_at unless includeArchived）
//   4. 项目结束 （whereExists projects.archived_at + deleted_at 双 null）
//
// 3 特性：
//   - 时间筛选 date_from / date_to （任务 created_at；默认近 14 天）
//   - 紧急排序：end_at NULL 最后 / end_at 最近优先 / complete_at 已完成在后 / created_at 倒序
//   - 计算字段：my_role (owner/collaborator) + my_report_count + is_urgent (deadline < 24h)
//
// 性能：
//   - my_report_count 用 LEFT JOIN + COUNT(CASE) 聚合（依赖 idx_task_reporter
//     索引，Sprint 1 Pass 1 M1 fix 已建）
//   - block min_count 评估在 PHP 层（resolver + filter()），避免 SQL 内 JSON_EXTRACT
//     全表扫；max 100 条上限保证内存可控
//
// 下游消费者：
//   - ProjectController::report__pending_list (Sprint 7-D Pass 1 Task 7-D.1)
//   - MyPendingReports.vue (Sprint 7-D Pass 2)
//   - MCP list_pending tool (Sprint 7-D Pass 3)

namespace App\Services\TaskReport;

use App\Models\ProjectTask;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PendingReportService
{
    /**
     * 列出 user 未汇报任务（spec §6.6 v3.22 + v3.26）
     *
     * @param int         $userid          目标用户 id
     * @param int|null    $projectId       可选项目过滤
     * @param int         $limit           默认 50（最大由 controller 钳制 100）
     * @param string|null $dateFrom        默认近 14 天
     * @param string|null $dateTo
     * @param bool        $includeArchived 默认 false（不含归档 task）
     * @return Collection<ProjectTask>     含 my_report_count + is_urgent + my_role 计算字段
     */
    public function listForUser(
        int $userid,
        ?int $projectId = null,
        int $limit = 50,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        bool $includeArchived = false
    ): Collection {
        // 默认时间窗口：近 14 天
        if (!$dateFrom && !$dateTo) {
            $dateFrom = now()->subDays(14)->toDateString();
        }

        $tasks = ProjectTask::query()
            ->select('project_tasks.*')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN r.id IS NOT NULL THEN 1 ELSE 0 END), 0) as my_report_count'
            )
            ->leftJoin('project_task_reports as r', function ($join) use ($userid) {
                $join->on('r.task_id', '=', 'project_tasks.id')
                    ->where('r.reporter_userid', $userid)
                    ->whereNull('r.deleted_at');
            })
            // 边界 1: 用户范围（owner / 协助人 project_task_users 实时含 owner=0）
            ->where(function ($q) use ($userid) {
                $q->where('project_tasks.userid', $userid)
                    ->orWhereExists(function ($sub) use ($userid) {
                        $sub->select(DB::raw(1))
                            ->from('project_task_users as pu')
                            ->whereColumn('pu.task_id', 'project_tasks.id')
                            ->where('pu.userid', $userid);
                    });
            })
            // 边界 2: 软删 task 过滤
            ->whereNull('project_tasks.deleted_at')
            // 边界 3: 归档 task 过滤（默认 false）
            ->when(!$includeArchived, fn($q) => $q->whereNull('project_tasks.archived_at'))
            // 边界 4: 项目结束过滤（projects 归档 / 软删任一即排除）
            ->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('projects')
                    ->whereColumn('projects.id', 'project_tasks.project_id')
                    ->whereNull('projects.archived_at')
                    ->whereNull('projects.deleted_at');
            })
            // 项目筛选（如指定）
            ->when($projectId, fn($q) => $q->where('project_tasks.project_id', $projectId))
            // 时间筛选（任务 created_at 落在区间内）
            ->when($dateFrom, fn($q) => $q->where('project_tasks.created_at', '>=', $dateFrom))
            ->when($dateTo, fn($q) => $q->where('project_tasks.created_at', '<=', $dateTo))
            ->groupBy('project_tasks.id')
            // 紧急排序：end_at NULL 最后 / end_at 最近优先 / complete_at 已完成在后 / 创建时间倒序
            ->orderByRaw(
                'project_tasks.end_at IS NULL ASC, '
                . 'project_tasks.end_at ASC, '
                . 'project_tasks.complete_at DESC, '
                . 'project_tasks.created_at DESC'
            )
            ->get();

        // PHP 层 TemplateResolver 评估每 task 的 block on_complete 规则 min_count，
        // 过滤已满足 reports 的任务（避免 SQL 内 JSON_EXTRACT 全表扫）
        $resolver = app(TemplateResolver::class);

        $pending = $tasks->filter(function ($task) use ($resolver) {
            $minCount = $this->resolveMinCount($task, $resolver);
            return ((int) $task->my_report_count) < $minCount;
        })->take($limit);

        // 计算字段：is_urgent + my_role
        $now = now();
        $urgentBoundary = $now->copy()->addDay();

        $pending->each(function ($task) use ($urgentBoundary, $userid) {
            // is_urgent: end_at 存在且 < 24h
            $task->is_urgent = $task->end_at
                ? Carbon::parse($task->end_at)->lt($urgentBoundary)
                : false;

            // my_role: owner / collaborator
            $task->my_role = ((int) $task->userid === $userid) ? 'owner' : 'collaborator';
        });

        return $pending->values();
    }

    /**
     * 解析 task 对应模板的 block on_complete min_count（默认 1）
     */
    private function resolveMinCount(ProjectTask $task, TemplateResolver $resolver): int
    {
        $tpl = $resolver->resolveForTask($task);
        $minCount = 1;
        if ($tpl && is_array($tpl->trigger_rules)) {
            foreach ($tpl->trigger_rules as $rule) {
                if (($rule['event'] ?? '') === 'on_complete'
                    && ($rule['mode'] ?? '') === 'block') {
                    $minCount = max($minCount, (int) ($rule['constraint']['min_count'] ?? 1));
                }
            }
        }
        return $minCount;
    }
}
