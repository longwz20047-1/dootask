<?php

// [CUSTOM:report-channel]
// Spec §3.5 TaskReport Eloquent Model
// 注：spec line 1283 含 attachments() / scopeReportVisible() / bootSyncHoursColumn()，
// Pass 2 暂不实现下列内容（推后到对应 Sprint）：
//   - attachments()  → Sprint 5a Task 5a.4 已启用（关联 TaskFieldAttachment）
//   - scopeReportVisible() → Sprint 3 Task 3.5（List 路径接入时）
//   - bootSyncHoursColumn() → §16 P1-V3-3 fallback 决策时
//   - template_id fillable → Sprint 6 Task 6.4 同步 ALTER 加列后追加

namespace App\Models;

use App\Models\AbstractModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;

class TaskReport extends AbstractModel
{
    use SoftDeletes;

    protected $table = 'project_task_reports';

    /**
     * Mass-assignment 白名单（与 §3.1 主表 DDL 一致，不含 hours_v 虚拟列、不含 template_id 推后列）。
     */
    protected $fillable = [
        'task_id',
        'parent_id',
        'project_id',
        'reporter_userid',
        'work_date',
        'values',
        'cascade_deleted',
    ];

    protected $casts = [
        'values'          => 'array',
        'work_date'       => 'date',
        'cascade_deleted' => 'boolean',
    ];

    /**
     * NF3 离职快照：reporter_userid 一旦写入即为快照，不允许再被改写。
     * 即便上报人后续离职/被改名/被软删，本字段仍保留原 userid，保证审计可追溯。
     *
     * 实现策略：在 updating 阶段 silent revert，避免业务代码无意改写。
     */
    protected static function boot()
    {
        parent::boot();

        static::updating(function (self $report) {
            if ($report->isDirty('reporter_userid')) {
                $report->reporter_userid = $report->getOriginal('reporter_userid');
            }
        });
    }

    /**
     * 关联：上报人（User，pre_users.userid 主键）
     */
    public function reporter()
    {
        return $this->belongsTo(User::class, 'reporter_userid', 'userid');
    }

    /**
     * 关联：所属任务
     */
    public function task()
    {
        return $this->belongsTo(ProjectTask::class, 'task_id', 'id');
    }

    /**
     * 关联：附件（Sprint 5a Task 5a.4 解锁，绑定 TaskFieldAttachment model）
     * spec §3.3 + §3.5
     */
    public function attachments()
    {
        return $this->hasMany(TaskFieldAttachment::class, 'report_id', 'id');
    }

    /**
     * Scope: 包含本任务和子任务（parent_id = $taskId）的 reports
     *
     * 用法：
     *   TaskReport::forTaskAndChildren($parentTaskId)->get()      // int 入参
     *   TaskReport::forTaskAndChildren($projectTaskInstance)->get() // ProjectTask 入参
     *
     * R-3 fix: 接受 ProjectTask|int 与 spec §3.5 line 1303 签名 `(ProjectTask $task)` 兼容，
     * 避免 Sprint 3 List 路径接入时再改签名（PHP 无方法重载）。
     *
     * @param  \App\Models\ProjectTask|int  $task
     */
    public function scopeForTaskAndChildren(Builder $query, $task): Builder
    {
        $taskId = $task instanceof \App\Models\ProjectTask ? $task->id : (int) $task;
        return $query->where(function (Builder $q) use ($taskId) {
            $q->where('task_id', $taskId)
              ->orWhere('parent_id', $taskId);
        });
    }
}
