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
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class WecomDepartmentMapping extends AbstractModel
{
    protected $table = 'wecom_department_mappings';

    /**
     * 通过企微部门ID查找映射
     */
    public static function findByWecomDept(string $corpId, int $wecomDeptId): ?self
    {
        return self::where('wecom_corp_id', $corpId)
            ->where('wecom_dept_id', $wecomDeptId)
            ->first();
    }

    /**
     * 获取 DooTask 部门ID，不存在返回 null
     */
    public static function getDooTaskDeptId(string $corpId, int $wecomDeptId): ?int
    {
        $mapping = self::findByWecomDept($corpId, $wecomDeptId);
        return $mapping?->dootask_dept_id;
    }
}
