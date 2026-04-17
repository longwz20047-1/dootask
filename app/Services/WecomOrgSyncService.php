<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserDepartment;
use App\Models\UserWecomBinding;
use App\Models\WecomDepartmentMapping;
use App\Module\Base;
use App\Module\Doo;
use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Log;

class WecomOrgSyncService
{
    private WecomApiClient $client;
    private string $corpId;

    public function __construct(WecomApiClient $contactClient, string $corpId)
    {
        $this->client = $contactClient;
        $this->corpId = $corpId;
    }

    /**
     * 从系统配置创建实例
     */
    public static function fromSetting(): ?self
    {
        $setting = Base::setting('thirdAccessSetting');
        if (($setting['wecom_org_sync'] ?? 'close') !== 'open') {
            return null;
        }
        $secret = $setting['wecom_contact_secret'] ?: $setting['wecom_secret'];
        $client = new WecomApiClient($setting['wecom_corp_id'], $secret, 'wecom_contact');
        return new self($client, $setting['wecom_corp_id']);
    }

    // ══════════════════════════════════════
    // 部门同步
    // ══════════════════════════════════════

    /**
     * 全量同步部门
     */
    public function syncDepartments(): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => 0];

        $wecomDepts = $this->client->getDepartmentList();
        if (empty($wecomDepts)) {
            return $stats;
        }

        // BFS 层序遍历，保证父部门先于子部门
        $byParent = [];
        foreach ($wecomDepts as $dept) {
            $byParent[$dept['parentid'] ?? 0][] = $dept;
        }
        $sorted = [];
        $queue = [1]; // 企微根部门 id=1
        while ($queue) {
            $id = array_shift($queue);
            foreach ($byParent[$id] ?? [] as $dept) {
                $sorted[] = $dept;
                $queue[] = $dept['id'];
            }
        }
        $wecomDepts = $sorted;

        $activeWecomDeptIds = [];

        foreach ($wecomDepts as $dept) {
            $wecomDeptId = $dept['id'];

            // 企微根部门(id=1)不映射到 DooTask 部门
            if ($wecomDeptId === 1) {
                continue;
            }

            $activeWecomDeptIds[] = $wecomDeptId;
            $existing = WecomDepartmentMapping::findByWecomDept($this->corpId, $wecomDeptId);

            $wecomParentId = $dept['parentid'] ?? 1;
            $dootaskParentId = 0;
            if ($wecomParentId > 1) {
                $dootaskParentId = WecomDepartmentMapping::getDooTaskDeptId($this->corpId, $wecomParentId) ?? 0;
            }

            $deptName = mb_substr($dept['name'] ?? '', 0, 100);
            $leader = $dept['department_leader'][0] ?? '';
            $ownerUserid = 0;
            if ($leader) {
                $binding = UserWecomBinding::findByWecom($this->corpId, $leader);
                $ownerUserid = $binding->userid ?? 0;
            }

            if ($existing) {
                $dootaskDept = UserDepartment::find($existing->dootask_dept_id);
                if ($dootaskDept) {
                    $changed = false;
                    if ($dootaskDept->name !== $deptName) {
                        $dootaskDept->name = $deptName;
                        $changed = true;
                    }
                    if ($dootaskDept->parent_id !== $dootaskParentId) {
                        $dootaskDept->parent_id = $dootaskParentId;
                        $changed = true;
                    }
                    if ($ownerUserid > 0 && $dootaskDept->owner_userid !== $ownerUserid) {
                        $dootaskDept->owner_userid = $ownerUserid;
                        $changed = true;
                    }
                    if ($changed) {
                        $dootaskDept->saveDepartment([
                            'name' => $deptName,
                            'parent_id' => $dootaskParentId,
                            'owner_userid' => $ownerUserid ?: $dootaskDept->owner_userid,
                        ], 0);
                        $stats['updated']++;
                    } else {
                        $stats['skipped']++;
                    }
                }
                $existing->wecom_dept_name = $deptName;
                $existing->wecom_parent_id = $wecomParentId;
                $existing->save();
            } else {
                $dootaskDept = UserDepartment::createInstance([
                    'name' => $deptName,
                    'parent_id' => $dootaskParentId,
                    'owner_userid' => $ownerUserid ?: 0,
                ]);
                try {
                    $dootaskDept->saveDepartment([
                        'name' => $deptName,
                        'parent_id' => $dootaskParentId,
                        'owner_userid' => $ownerUserid ?: 0,
                    ], 0);
                } catch (\Throwable $e) {
                    Log::info("[WecomOrgSync] 部门创建失败，跳过: {$deptName}", ['error' => $e->getMessage()]);
                    $stats['errors'] = ($stats['errors'] ?? 0) + 1;
                    continue;
                }

                $mapping = WecomDepartmentMapping::createInstance([
                    'wecom_corp_id' => $this->corpId,
                    'wecom_dept_id' => $wecomDeptId,
                    'dootask_dept_id' => $dootaskDept->id,
                    'wecom_dept_name' => $deptName,
                    'wecom_parent_id' => $wecomParentId,
                ]);
                $mapping->save();

                $stats['created']++;
            }
        }

        // 差集处理：企微已删除的部门 → 软标记 lost_at（不删 mapping，可恢复）
        // 企微重新添加同 id 部门 → 清除 lost_at 复活
        $now = \Carbon\Carbon::now();
        $lostCount = WecomDepartmentMapping::where('wecom_corp_id', $this->corpId)
            ->whereNotIn('wecom_dept_id', $activeWecomDeptIds ?: [0])
            ->whereNull('lost_at')
            ->update(['lost_at' => $now]);
        $recoveredCount = WecomDepartmentMapping::where('wecom_corp_id', $this->corpId)
            ->whereIn('wecom_dept_id', $activeWecomDeptIds ?: [0])
            ->whereNotNull('lost_at')
            ->update(['lost_at' => null]);
        $stats['lost'] = $lostCount;
        $stats['recovered'] = $recoveredCount;

        Log::info('[WecomOrgSync] 部门同步完成', $stats);
        return $stats;
    }

    // ══════════════════════════════════════
    // 成员部门归属同步
    // ══════════════════════════════════════

    /**
     * 同步已绑定用户的部门归属
     */
    public function syncUserDepartments(): array
    {
        $stats = ['updated' => 0, 'skipped' => 0, 'errors' => 0];

        $bindings = UserWecomBinding::where('wecom_corp_id', $this->corpId)->get();

        foreach ($bindings as $binding) {
            try {
                $wecomUser = $this->client->getUser($binding->wecom_userid);
            } catch (\Throwable $e) {
                Log::info("[WecomOrgSync] 获取成员失败: {$binding->wecom_userid}", ['error' => $e->getMessage()]);
                $stats['errors']++;
                continue;
            }

            $user = User::whereUserid($binding->userid)->first();
            if (!$user) {
                $stats['skipped']++;
                continue;
            }

            if (!empty($wecomUser['name']) && $user->nickname !== $wecomUser['name']) {
                $user->nickname = $wecomUser['name'];
            }
            if (!empty($wecomUser['avatar']) && $user->userimg !== $wecomUser['avatar']) {
                $user->userimg = $wecomUser['avatar'];
            }

            $wecomDeptIds = $wecomUser['department'] ?? [];
            $dootaskDeptIds = [];
            foreach ($wecomDeptIds as $wecomDeptId) {
                $dootaskDeptId = WecomDepartmentMapping::getDooTaskDeptId($this->corpId, $wecomDeptId);
                if ($dootaskDeptId) {
                    $dootaskDeptIds[] = $dootaskDeptId;
                }
            }

            sort($dootaskDeptIds);
            $currentDeptIds = $user->department;
            sort($currentDeptIds);
            if ($dootaskDeptIds !== $currentDeptIds) {
                $user->department = !empty($dootaskDeptIds) ? "," . implode(",", $dootaskDeptIds) . "," : "";
                $stats['updated']++;
            } else {
                $stats['skipped']++;
            }

            $user->save();

            $binding->wecom_name = $wecomUser['name'] ?? $binding->wecom_name;
            $binding->wecom_avatar = $wecomUser['avatar'] ?? $binding->wecom_avatar;
            $binding->save();
        }

        Log::info('[WecomOrgSync] 成员部门同步完成', $stats);
        return $stats;
    }

    // ══════════════════════════════════════
    // 批量预创建用户
    // ══════════════════════════════════════

    /**
     * 批量导入企微成员为 DooTask 用户
     */
    public function syncUsers(): array
    {
        $stats = ['created' => 0, 'skipped' => 0, 'errors' => 0];

        $mappings = WecomDepartmentMapping::where('wecom_corp_id', $this->corpId)->get();

        foreach ($mappings as $mapping) {
            try {
                $members = $this->client->getDepartmentUsersDetail($mapping->wecom_dept_id);
            } catch (\Throwable $e) {
                Log::info("[WecomOrgSync] 获取部门成员失败: dept={$mapping->wecom_dept_id}", ['error' => $e->getMessage()]);
                $stats['errors']++;
                continue;
            }

            foreach ($members as $member) {
                $wecomUserId = $member['userid'] ?? '';
                if (empty($wecomUserId)) continue;

                $existing = UserWecomBinding::findByWecom($this->corpId, $wecomUserId);
                if ($existing) {
                    $stats['skipped']++;
                    continue;
                }

                try {
                    $this->createUserFromWecom($member);
                    $stats['created']++;
                } catch (\Throwable $e) {
                    Log::info("[WecomOrgSync] 创建用户失败: {$wecomUserId}", ['error' => $e->getMessage()]);
                    $stats['errors']++;
                }
            }
        }

        // 离职检测：binding 在库但企微已无此人 → 标记 unbind + 禁用 user
        $stats['disabled'] = 0;
        $stats['resurrected'] = 0;
        $activeWecomUserIds = $this->collectAllWecomUserIds();
        if (!empty($activeWecomUserIds)) {
            $now = \Carbon\Carbon::now();

            $leavers = UserWecomBinding::where('wecom_corp_id', $this->corpId)
                ->whereNotIn('wecom_userid', $activeWecomUserIds)
                ->whereNull('unbind_at')
                ->get();
            foreach ($leavers as $binding) {
                $binding->unbind_at = $now;
                $binding->save();
                $user = User::whereUserid($binding->userid)->first();
                if ($user && !$user->isAdmin() && empty($user->disable_at)) {
                    $user->disable_at = $now;
                    $user->save();
                    $stats['disabled']++;
                    Log::info("[WecomOrgSync] 员工离职禁用: wecom_userid={$binding->wecom_userid} userid={$binding->userid}");
                }
            }

            // 复活检测：binding 已 unbind 但企微重新出现 → 清 unbind + 清 disable
            $resurrects = UserWecomBinding::where('wecom_corp_id', $this->corpId)
                ->whereIn('wecom_userid', $activeWecomUserIds)
                ->whereNotNull('unbind_at')
                ->get();
            foreach ($resurrects as $binding) {
                $binding->unbind_at = null;
                $binding->save();
                $user = User::whereUserid($binding->userid)->first();
                if ($user && $user->disable_at) {
                    $user->disable_at = null;
                    $user->save();
                    $stats['resurrected']++;
                    Log::info("[WecomOrgSync] 员工复活启用: wecom_userid={$binding->wecom_userid} userid={$binding->userid}");
                }
            }
        }

        Log::info('[WecomOrgSync] 批量用户同步完成', $stats);
        return $stats;
    }

    /**
     * 汇总企微全员 userid（用于差集检测）
     */
    private function collectAllWecomUserIds(): array
    {
        $all = [];
        $mappings = WecomDepartmentMapping::where('wecom_corp_id', $this->corpId)
            ->whereNull('lost_at')
            ->get();
        foreach ($mappings as $mapping) {
            try {
                $members = $this->client->getDepartmentUsers($mapping->wecom_dept_id);
            } catch (\Throwable $e) {
                Log::info("[WecomOrgSync] 收集成员失败: dept={$mapping->wecom_dept_id}", ['error' => $e->getMessage()]);
                continue;
            }
            foreach ($members as $m) {
                $uid = $m['userid'] ?? '';
                if ($uid) $all[$uid] = true;
            }
        }
        return array_keys($all);
    }

    /**
     * 补跑部门负责人（在 syncUsers 建好 binding 后调用，回填 owner_userid）
     */
    public function syncDepartmentLeaders(): array
    {
        $stats = ['updated' => 0, 'skipped' => 0];

        $wecomDepts = $this->client->getDepartmentList();
        if (empty($wecomDepts)) return $stats;

        $deptLeaderMap = [];
        foreach ($wecomDepts as $d) {
            $deptLeaderMap[$d['id']] = $d['department_leader'][0] ?? '';
        }

        $mappings = WecomDepartmentMapping::where('wecom_corp_id', $this->corpId)
            ->whereNull('lost_at')
            ->get();
        foreach ($mappings as $mapping) {
            $leaderWecomId = $deptLeaderMap[$mapping->wecom_dept_id] ?? '';
            if (!$leaderWecomId) {
                $stats['skipped']++;
                continue;
            }
            $binding = UserWecomBinding::findByWecom($this->corpId, $leaderWecomId);
            if (!$binding) {
                $stats['skipped']++;
                continue;
            }
            $dept = UserDepartment::find($mapping->dootask_dept_id);
            if ($dept && (int)$dept->owner_userid !== (int)$binding->userid) {
                $dept->owner_userid = $binding->userid;
                $dept->save();
                $stats['updated']++;
            } else {
                $stats['skipped']++;
            }
        }
        Log::info('[WecomOrgSync] 部门负责人补跑完成', $stats);
        return $stats;
    }

    /**
     * 从企微成员信息创建 DooTask 用户 + 绑定
     */
    private function createUserFromWecom(array $wecomUser): User
    {
        $wecomUserId = $wecomUser['userid'];
        $name = $wecomUser['name'] ?? $wecomUserId;
        $email = $wecomUser['biz_mail'] ?? $wecomUser['email'] ?? '';
        if (empty($email)) {
            $email = "wecom_{$wecomUserId}@dootask.local";
        }

        $existingUser = User::whereEmail($email)->first();
        if ($existingUser) {
            $binding = UserWecomBinding::createInstance([
                'userid' => $existingUser->userid,
                'wecom_corp_id' => $this->corpId,
                'wecom_userid' => $wecomUserId,
                'wecom_name' => $name,
                'wecom_avatar' => $wecomUser['avatar'] ?? '',
            ]);
            $binding->save();
            return $existingUser;
        }

        $password = \Illuminate\Support\Str::random(16) . '!@#' . rand(100, 999);
        $user = self::createUserWithQuotaRetry($email, $password, $this->corpId);

        $user->nickname = $name;
        $user->az = Base::getFirstCharter($name);
        $user->pinyin = Base::cn2pinyin($name);
        $user->email_verity = 1;
        $user->created_ip = '0.0.0.0';
        if (!empty($wecomUser['avatar'])) $user->userimg = $wecomUser['avatar'];
        if (!empty($wecomUser['mobile'])) $user->tel = $wecomUser['mobile'];
        if (!empty($wecomUser['position'])) $user->profession = $wecomUser['position'];

        $deptIds = $wecomUser['department'] ?? [];
        if (!empty($deptIds)) {
            $dootaskDeptIds = [];
            foreach ($deptIds as $wecomDeptId) {
                $dootaskDeptId = WecomDepartmentMapping::getDooTaskDeptId($this->corpId, $wecomDeptId);
                if ($dootaskDeptId) $dootaskDeptIds[] = $dootaskDeptId;
            }
            if (!empty($dootaskDeptIds)) {
                $user->department = "," . implode(",", $dootaskDeptIds) . ",";
            }
        }

        $user->save();

        $regIdentity = Base::settingFind('system', 'reg_identity') ?: 'normal';
        if ($regIdentity === 'temp') {
            $user->identity = Base::arrayImplode(array_merge(array_diff($user->identity, ['temp']), ['temp']));
            $user->save();
        }

        $all_group_autoin = Base::settingFind('system', 'all_group_autoin') ?: 'yes';
        if ($all_group_autoin === 'yes') {
            $allDialog = \App\Models\WebSocketDialog::whereGroupType('all')->orderByDesc('id')->first();
            if ($allDialog) {
                $allDialog->joinGroup($user->userid, 0);
            }
        }

        \App\Observers\AbstractObserver::taskDeliver(new \App\Tasks\ManticoreSyncTask('user_sync', $user->toArray()));
        \App\Module\Apps::dispatchUserHook($user, 'user_onboard', 'onboard');

        $binding = UserWecomBinding::createInstance([
            'userid' => $user->userid,
            'wecom_corp_id' => $this->corpId,
            'wecom_userid' => $wecomUserId,
            'wecom_name' => $name,
            'wecom_avatar' => $wecomUser['avatar'] ?? '',
        ]);
        $binding->save();

        return $user;
    }

    // ══════════════════════════════════════
    // 完整同步
    // ══════════════════════════════════════

    /**
     * 执行完整同步：先部门，再批量预创建用户，最后更新部门归属
     */
    public function syncAll(): array
    {
        // 顺序：建部门 → 批量建用户（含离职/复活） → 更新已有用户部门归属 → 补跑部门负责人
        $deptStats = $this->syncDepartments();
        $userCreateStats = $this->syncUsers();
        $userDeptStats = $this->syncUserDepartments();
        $leaderStats = $this->syncDepartmentLeaders();

        $lastSyncAt = \Carbon\Carbon::now()->toDateTimeString();
        \Cache::put("wecom_last_sync_at:{$this->corpId}", $lastSyncAt, 60 * 60 * 24 * 30);

        return [
            'departments' => $deptStats,
            'users_created' => $userCreateStats,
            'users_dept_updated' => $userDeptStats,
            'leaders' => $leaderStats,
            'last_sync_at' => $lastSyncAt,
        ];
    }

    // ══════════════════════════════════════
    // License 名额管理
    // ══════════════════════════════════════

    /**
     * 创建用户，License 超限时尝试禁用最久不登录的企微员工让位
     */
    public static function createUserWithQuotaRetry(string $email, string $password, string $corpId): User
    {
        try {
            $user = Doo::userCreate($email, $password);
            if (!$user) {
                throw new ApiException(Doo::translate('企微用户创建失败'));
            }
            return $user;
        } catch (\Throwable $e) {
            $licenseInfo = Doo::license();
            $maxPeople = $licenseInfo['people'] ?? 0;
            if ($maxPeople <= 0) {
                throw new ApiException(Doo::translate('License 人数已达上限，请联系管理员升级或在后台禁用离职员工'));
            }

            if (!self::recycleWecomQuota($corpId, $maxPeople)) {
                throw new ApiException(Doo::translate('License 人数已达上限，请联系管理员升级或在后台禁用离职员工'));
            }

            $user = Doo::userCreate($email, $password);
            if (!$user) {
                throw new ApiException(Doo::translate('企微用户创建失败'));
            }
            return $user;
        }
    }

    /**
     * 回收企微用户名额（禁用 + 软解绑，保留数据可恢复）
     */
    private static function recycleWecomQuota(string $corpId, int $maxPeople): bool
    {
        $activeCount = User::whereBot(0)->whereNull('disable_at')->count();
        if ($activeCount < $maxPeople) {
            return true;
        }

        $needed = $activeCount - $maxPeople + 1;

        $candidates = UserWecomBinding::where('wecom_corp_id', $corpId)
            ->whereNull('unbind_at')
            ->orderByRaw('last_login_at IS NULL DESC, last_login_at ASC')
            ->orderBy('id', 'asc')
            ->take($needed + 5)
            ->get();

        $now = \Carbon\Carbon::now();
        $recycled = 0;
        foreach ($candidates as $binding) {
            if ($recycled >= $needed) break;

            $user = User::whereUserid($binding->userid)->first();
            if (!$user || $user->isAdmin() || $user->disable_at) {
                continue;
            }

            $binding->unbind_at = $now;
            $binding->save();
            $user->disable_at = $now;
            $user->save();
            $recycled++;

            Log::info("[WecomQuota] 回收名额（禁用）: {$user->email} userid={$user->userid}");
        }

        return $recycled > 0;
    }
}
