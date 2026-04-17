<?php

namespace App\Models;

/**
 * App\Models\WecomDepartmentMapping
 *
 * @property int $id
 * @property string $wecom_corp_id
 * @property int $wecom_dept_id
 * @property int $dootask_dept_id
 * @property string|null $wecom_dept_name
 * @property int|null $wecom_parent_id
 * @property \Illuminate\Support\Carbon|null $lost_at 企微已删除部门的软标记时间（null 表示活跃）
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class WecomDepartmentMapping extends AbstractModel
{
    protected $table = 'wecom_department_mappings';

    protected $dates = ['lost_at'];

    /**
     * 通过企微部门ID查找映射（包含已失联）
     */
    public static function findByWecomDept(string $corpId, int $wecomDeptId): ?self
    {
        return self::where('wecom_corp_id', $corpId)
            ->where('wecom_dept_id', $wecomDeptId)
            ->first();
    }

    /**
     * 查询活跃映射（排除已失联）
     */
    public function scopeActive($query)
    {
        return $query->whereNull('lost_at');
    }

    /**
     * 获取 DooTask 部门ID，不存在或已失联返回 null
     */
    public static function getDooTaskDeptId(string $corpId, int $wecomDeptId): ?int
    {
        $mapping = self::where('wecom_corp_id', $corpId)
            ->where('wecom_dept_id', $wecomDeptId)
            ->whereNull('lost_at')
            ->first();
        return $mapping?->dootask_dept_id;
    }
}
