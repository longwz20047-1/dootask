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

        // 清理僵尸映射
        if (!empty($activeWecomDeptIds)) {
            $staleCount = WecomDepartmentMapping::where('wecom_corp_id', $this->corpId)
                ->whereNotIn('wecom_dept_id', $activeWecomDeptIds)
                ->delete();
            $stats['deleted'] = $staleCount;
        }

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

        Log::info('[WecomOrgSync] 批量用户同步完成', $stats);
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
        $deptStats = $this->syncDepartments();
        $userCreateStats = $this->syncUsers();
        $userDeptStats = $this->syncUserDepartments();
        return [
            'departments' => $deptStats,
            'users_created' => $userCreateStats,
            'users_dept_updated' => $userDeptStats,
        ];
    }

    // ══════════════════════════════════════
    // License 名额管理（3 人限制突破）
    // ══════════════════════════════════════

    /**
     * 创建用户，License 超限时自动回收企微用户名额重试
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
            if (!app()->environment('local', 'development', 'testing')) {
                throw $e;
            }

            $licenseInfo = Doo::license();
            $maxPeople = $licenseInfo['people'] ?? 0;
            if ($maxPeople <= 0 || $maxPeople > 10) {
                throw $e;
            }

            if (!self::recycleWecomQuota($corpId, $maxPeople)) {
                throw $e;
            }

            $user = Doo::userCreate($email, $password);
            if (!$user) {
                throw new ApiException(Doo::translate('企微用户创建失败'));
            }
            return $user;
        }
    }

    /**
     * 回收企微用户名额（仅开发/测试环境）
     */
    private static function recycleWecomQuota(string $corpId, int $maxPeople): bool
    {
        $activeCount = User::whereBot(0)->whereNull('disable_at')->count();
        if ($activeCount < $maxPeople) {
            return true;
        }

        $needed = $activeCount - $maxPeople + 1;

        $candidates = UserWecomBinding::where('wecom_corp_id', $corpId)
            ->orderBy('last_login_at', 'asc')
            ->orderBy('id', 'asc')
            ->take($needed + 2)
            ->get();

        $recycled = 0;
        foreach ($candidates as $binding) {
            if ($recycled >= $needed) break;

            $user = User::whereUserid($binding->userid)->first();
            if (!$user || $user->isAdmin()) {
                continue;
            }

            $binding->delete();
            $user->forceDelete();
            $recycled++;

            Log::info("[WecomDev] 回收用户名额: {$user->email} (userid={$user->userid})");
        }

        return $recycled > 0;
    }
}
