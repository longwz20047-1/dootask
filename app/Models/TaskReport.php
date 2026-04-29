<?php

// [CUSTOM:report-channel]
// Spec §3.5 TaskReport Eloquent Model
// 注：spec line 1283 含 attachments() / scopeReportVisible() / bootSyncHoursColumn()，
// Pass 2 暂不实现下列内容（推后到对应 Sprint）：
//   - attachments()  → Sprint 5a Task 5a.4 同步 TaskFieldAttachment model 后启用
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

    // public function attachments() { ... }  // TODO Sprint 5a Task 5a.4: bind to TaskFieldAttachment

    /**
     * Scope: 包含本任务和子任务（parent_id = $taskId）的 reports
     *
     * 用法：TaskReport::forTaskAndChildren($parentTaskId)->get()
     *
     * 注：spec §3.5 line 1303 签名为 (Builder $q, ProjectTask $task)，本 Pass 2
     * 收窄为 int $taskId 以避免提前依赖 ProjectTask 实例化语义；Sprint 3 List
     * 路径如需 ProjectTask 入参再补 overload。
     */
    public function scopeForTaskAndChildren(Builder $query, int $taskId): Builder
    {
        return $query->where(function (Builder $q) use ($taskId) {
            $q->where('task_id', $taskId)
              ->orWhere('parent_id', $taskId);
        });
    }
}
