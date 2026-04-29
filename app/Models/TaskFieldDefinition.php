<?php

// [CUSTOM:report-channel]
// Spec §3.2 task_field_definitions minimal Eloquent model
//
// Pass 2 范围：仅供 FieldDefinitionCache::get() 查询使用，未实现完整业务方法
// （如 v3.18 has_index ALTER 联动、§22 IndexBuilder 接入、Observer 等）。
// 后续 Sprint 按需扩展。

namespace App\Models;

use App\Models\AbstractModel;

class TaskFieldDefinition extends AbstractModel
{
    protected $table = 'task_field_definitions';

    protected $fillable = [
        'scope',
        'project_id',
        'flow_item_id',
        'code',
        'name',
        'type',
        'options',
        'default_value',
        'required',
        'sort',
        'enabled',
        'is_builtin',
        'aggregatable',
        'aggregate_strategy',
        'has_index',
    ];

    protected $casts = [
        'options'        => 'array',
        'default_value'  => 'array',
        'required'       => 'boolean',
        'enabled'        => 'boolean',
        'is_builtin'     => 'boolean',
        'aggregatable'   => 'boolean',
        'has_index'      => 'boolean',
    ];
}
