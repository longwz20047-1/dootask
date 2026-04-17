<?php

namespace App\Models;

/**
 * App\Models\UserWecomBinding
 *
 * @property int $id
 * @property int $userid DooTask userid
 * @property string $wecom_corp_id 企业 CorpID
 * @property string $wecom_userid 企微成员 UserId
 * @property string|null $wecom_name 企微姓名
 * @property string|null $wecom_avatar 企微头像
 * @property \Illuminate\Support\Carbon|null $last_login_at
 * @property \Illuminate\Support\Carbon|null $unbind_at 企微已离职解绑时间（null 表示活跃）
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class UserWecomBinding extends AbstractModel
{
    protected $table = 'user_wecom_bindings';

    protected $dates = ['last_login_at', 'unbind_at'];

    /**
     * 通过企微身份查找活跃绑定（排除已离职）
     */
    public static function findByWecom(string $corpId, string $wecomUserId): ?self
    {
        return self::where('wecom_corp_id', $corpId)
            ->where('wecom_userid', $wecomUserId)
            ->whereNull('unbind_at')
            ->first();
    }

    /**
     * 通过企微身份查找绑定（含已离职）
     */
    public static function findByWecomIncludeInactive(string $corpId, string $wecomUserId): ?self
    {
        return self::where('wecom_corp_id', $corpId)
            ->where('wecom_userid', $wecomUserId)
            ->first();
    }

    /**
     * 查询活跃绑定（排除已离职）
     */
    public function scopeActive($query)
    {
        return $query->whereNull('unbind_at');
    }
}
