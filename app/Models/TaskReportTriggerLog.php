<?php

// [CUSTOM:report-channel] Sprint 6 Task 6.6
// Spec §3.8 task_report_trigger_log Eloquent Model
//
// 用途：触发去重日志（防同一规则同一时刻重复触发）
// 唯一约束：(task_id, rule_idx, triggered_at, event, mode)
//
// User FK 注：dootask User 主键是 userid（非 id），故 belongsTo 显式指 owner='userid'

namespace App\Models;

class TaskReportTriggerLog extends AbstractModel
{
    protected $table = 'task_report_trigger_log';

    protected $fillable = [
        'task_id',
        'template_id',
        'rule_idx',
        'event',
        'mode',
        'triggered_at',
        'user_id',
    ];

    protected $casts = [
        'task_id'      => 'integer',
        'template_id'  => 'integer',
        'rule_idx'     => 'integer',
        'user_id'      => 'integer',
        'triggered_at' => 'datetime',
    ];

    public function task()
    {
        return $this->belongsTo(ProjectTask::class, 'task_id', 'id');
    }

    public function template()
    {
        return $this->belongsTo(TaskReportTemplate::class, 'template_id', 'id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'userid');
    }
}
