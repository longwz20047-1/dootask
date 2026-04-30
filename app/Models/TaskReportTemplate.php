<?php

// [CUSTOM:report-channel] Sprint 6 Task 6.6
// Spec §3.6 task_report_templates Eloquent Model
//
// 关联：
//   - templateFields() → task_report_template_fields hasMany (pivot model)
//   - fields()         → task_field_definitions belongsToMany (through pivot)
//   - triggerLogs()    → task_report_trigger_log hasMany
//
// Cast 注解：
//   - trigger_rules array：JSON 数组 [{event, mode, target, constraint, _hint?}, ...]
//   - is_default / is_builtin / enabled boolean
//
// 守护：Observer 拦截删除 builtin global default + 改 builtin 关键字段
//      （TaskReportTemplateObserver Sprint 6 Task 6.7）

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;

class TaskReportTemplate extends AbstractModel
{
    use SoftDeletes;

    protected $table = 'task_report_templates';

    protected $fillable = [
        'name',
        'scope',
        'scope_id',
        'is_default',
        'is_builtin',
        'description',
        'enabled',
        'trigger_rules',
    ];

    protected $casts = [
        'scope_id'      => 'integer',
        'is_default'    => 'boolean',
        'is_builtin'    => 'boolean',
        'enabled'       => 'boolean',
        'trigger_rules' => 'array',
    ];

    /**
     * 关联 N:N pivot fields（含 override + sort）
     */
    public function templateFields()
    {
        return $this->hasMany(TaskReportTemplateField::class, 'template_id', 'id')
            ->orderBy('sort');
    }

    /**
     * 关联 fields（through pivot），便于直接读取 TaskFieldDefinition 集合
     */
    public function fields()
    {
        return $this->belongsToMany(
            TaskFieldDefinition::class,
            'task_report_template_fields',
            'template_id',
            'field_id'
        )->withPivot(['override', 'sort'])->orderBy('task_report_template_fields.sort');
    }

    /**
     * 关联 trigger logs
     */
    public function triggerLogs()
    {
        return $this->hasMany(TaskReportTriggerLog::class, 'template_id', 'id');
    }

    /**
     * Scope: 全局默认模板（spec §6.5 4 档优先级最末档兜底）
     */
    public function scopeGlobalDefault(Builder $q): Builder
    {
        return $q->where('scope', 'global')
            ->where('is_default', true)
            ->where('enabled', true);
    }
}
