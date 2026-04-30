<?php

// [CUSTOM:report-channel] Sprint 6 Task 6.6
// Spec §3.7 task_report_template_fields N:N pivot Eloquent Model
//
// 用途：模板 ↔ 字段 关联表，含字段级 override（hidden / required / sort / default_value）
// 唯一约束：(template_id, field_id) 由 Sprint 6 Pass 1 migration 落库

namespace App\Models;

class TaskReportTemplateField extends AbstractModel
{
    protected $table = 'task_report_template_fields';

    protected $fillable = [
        'template_id',
        'field_id',
        'override',
        'sort',
    ];

    protected $casts = [
        'template_id' => 'integer',
        'field_id'    => 'integer',
        'override'    => 'array',
        'sort'        => 'integer',
    ];

    public function template()
    {
        return $this->belongsTo(TaskReportTemplate::class, 'template_id', 'id');
    }

    public function field()
    {
        return $this->belongsTo(TaskFieldDefinition::class, 'field_id', 'id');
    }
}
