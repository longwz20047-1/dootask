<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Models\UserDepartment;
use App\Models\UserWecomBinding;
use App\Module\Base;
use App\Module\Doo;
use App\Services\WecomApiClient;
use Cache;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Request;

/**
 * @apiDefine wecom
 *
 * 企业微信
 */
class WecomController extends AbstractController
{
    /**
     * 获取企微配置（内部用）
     */
    private function getWecomSetting(): array
    {
        $setting = Base::setting('thirdAccessSetting');
        if (($setting['wecom_open'] ?? 'close') !== 'open') {
            throw new ApiException('企业微信登录未开启');
        }
        if (empty($setting['wecom_corp_id']) || empty($setting['wecom_secret'])) {
            throw new ApiException('企业微信配置不完整，请联系管理员');
        }
        return $setting;
    }

    /**
     * 创建 OAuth 客户端（使用应用 Secret）
     */
    private function makeOAuthClient(array $setting): WecomApiClient
    {
        return new WecomApiClient($setting['wecom_corp_id'], $setting['wecom_secret'], 'wecom_oauth');
    }

    /**
     * 创建通讯录客户端（使用通讯录同步 Secret）
     */
    private function makeContactClient(array $setting): WecomApiClient
    {
        $secret = $setting['wecom_contact_secret'] ?: $setting['wecom_secret'];
        return new WecomApiClient($setting['wecom_corp_id'], $secret, 'wecom_contact');
    }

    /**
     * 获取前端基地址（兼容子路径部署如 /dootask/）
     */
    private function frontendUrl(string $hashPath): string
    {
        return rtrim(config('app.url'), '/') . "/{$hashPath}";
    }

    // ══════════════════════════════════════
    // OAuth 静默登录
    // ══════════════════════════════════════

    /**
     * @api {get} api/wecom/entry 企微应用入口
     *
     * @apiDescription 企微自建应用首页 URL，检测登录状态后跳转 OAuth
     * @apiVersion 1.0.0
     * @apiGroup wecom
     * @apiName entry
     */
    public function entry()
    {
        if (Doo::userId() > 0) {
            return redirect(config('app.url'));
        }

        try {
            $setting = $this->getWecomSetting();
        } catch (ApiException $e) {
            return redirect($this->frontendUrl("#/login?wecom_error=" . urlencode(Doo::translate($e->getMessage()))));
        }
        $client = $this->makeOAuthClient($setting);

        $state = Str::random(32);
        Cache::put("wecom_state:{$state}", true, 300);

        $redirectUri = url('/api/wecom/callback');
        $agentId = intval($setting['wecom_agent_id']);
        $oauthUrl = $client->buildOAuthUrl($redirectUri, $agentId, $state);

        return redirect($oauthUrl);
    }

    /**
     * @api {get} api/wecom/callback 企微 OAuth 回调
     *
     * @apiDescription 企微静默授权回调，code 换 userid，自动登录/注册
     * @apiVersion 1.0.0
     * @apiGroup wecom
     * @apiName callback
     */
    public function callback()
    {
        $code = trim(Request::input('code'));
        $state = trim(Request::input('state'));

        if (empty($code)) {
            return redirect($this->frontendUrl("#/login?wecom_error=" . urlencode(Doo::translate('授权失败：未获取到 code'))));
        }

        if (!Cache::pull("wecom_state:{$state}")) {
            return redirect($this->frontendUrl("#/login?wecom_error=" . urlencode(Doo::translate('授权失败：state 验证失败'))));
        }

        try {
            $setting = $this->getWecomSetting();
        } catch (ApiException $e) {
            return redirect($this->frontendUrl("#/login?wecom_error=" . urlencode(Doo::translate($e->getMessage()))));
        }
        $client = $this->makeOAuthClient($setting);
        $corpId = $setting['wecom_corp_id'];

        try {
            $userInfo = $client->getUserInfoByCode($code);
        } catch (\Throwable $e) {
            return redirect($this->frontendUrl("#/login?wecom_error=" . urlencode(Doo::translate('授权失败') . ': ' . $e->getMessage())));
        }

        $wecomUserId = $userInfo['userid'] ?? '';
        if (empty($wecomUserId)) {
            return redirect($this->frontendUrl("#/login?wecom_error=" . urlencode(Doo::translate('非企业成员，无法登录'))));
        }

        $binding = UserWecomBinding::findByWecomIncludeInactive($corpId, $wecomUserId);

        if ($binding) {
            // 被标记离职但重新登录 → 自动复活
            if ($binding->unbind_at) {
                $binding->unbind_at = null;
                $binding->save();
                Log::info("[WecomOAuth] 员工复活启用: wecom_userid={$wecomUserId} userid={$binding->userid}");
            }
            $user = User::whereUserid($binding->userid)->first();
            if (!$user) {
                return redirect($this->frontendUrl("#/login?wecom_error=" . urlencode(Doo::translate('账号已停用或不存在'))));
            }
            // 用户被禁用（可能是 disable_at 或 identity='disable'）→ 自动解除 disable_at（管理员显式设 disable 才阻止）
            if ($user->disable_at && !in_array('disable', $user->identity)) {
                $user->disable_at = null;
                $user->save();
                $deptIds = is_array($user->department) ? $user->department : [];
                if (!empty($deptIds)) {
                    UserDepartment::whereIn('id', $deptIds)
                        ->where('owner_userid', 0)
                        ->update(['owner_userid' => $user->userid]);
                }
            }
            if ($user->isDisable()) {
                return redirect($this->frontendUrl("#/login?wecom_error=" . urlencode(Doo::translate('账号已停用或不存在'))));
            }
            $binding->last_login_at = Carbon::now();
            $binding->save();
        } else {
            if (($setting['wecom_auto_reg'] ?? 'close') !== 'open') {
                return redirect($this->frontendUrl("#/login?wecom_error=" . urlencode(Doo::translate('未绑定 DooTask 账号，且未开启自动注册'))));
            }
            try {
                $user = $this->silentRegister($setting, $corpId, $wecomUserId);
            } catch (\Throwable $e) {
                return redirect($this->frontendUrl("#/login?wecom_error=" . urlencode(Doo::translate('注册失败') . ': ' . $e->getMessage())));
            }
        }

        $user->updateInstance([
            'login_num' => $user->login_num + 1,
            'last_ip' => Base::getIp(),
            'last_at' => Carbon::now(),
            'line_ip' => Base::getIp(),
            'line_at' => Carbon::now(),
        ]);
        $user->save();

        $token = User::generateToken($user);

        $ticket = Str::random(64);
        Cache::put("wecom_ticket:{$ticket}", $user->toArray() + ['token' => $token], 60);
        return redirect($this->frontendUrl("#/login?wecom_ticket={$ticket}"));
    }

    /**
     * @api {post} api/wecom/exchange ticket 换 token
     *
     * @apiDescription 前端用一次性 ticket 换取真正的 userid + token
     * @apiVersion 1.0.0
     * @apiGroup wecom
     * @apiName exchange
     */
    public function exchange()
    {
        $ticket = trim(Request::input('ticket'));
        if (empty($ticket)) {
            throw new ApiException(Doo::translate('ticket 不能为空'));
        }
        $payload = Cache::pull("wecom_ticket:{$ticket}");
        if (!$payload || empty($payload['userid']) || empty($payload['token'])) {
            throw new ApiException(Doo::translate('ticket 无效或已过期'));
        }
        return Base::retSuccess('success', $payload);
    }

    /**
     * 静默注册
     */
    private function silentRegister(array $setting, string $corpId, string $wecomUserId): User
    {
        // 优先使用已有 binding（含已离职）→ 避免产生重复绑定
        $existingBinding = UserWecomBinding::findByWecomIncludeInactive($corpId, $wecomUserId);
        if ($existingBinding) {
            if ($existingBinding->unbind_at) {
                $existingBinding->unbind_at = null;
                $existingBinding->save();
            }
            $user = User::whereUserid($existingBinding->userid)->first();
            if ($user) {
                if ($user->disable_at && !in_array('disable', $user->identity)) {
                    $user->disable_at = null;
                    $user->save();
                    $deptIds = is_array($user->department) ? $user->department : [];
                    if (!empty($deptIds)) {
                        UserDepartment::whereIn('id', $deptIds)
                            ->where('owner_userid', 0)
                            ->update(['owner_userid' => $user->userid]);
                    }
                }
                return $user;
            }
        }

        try {
            return $this->doSilentRegister($setting, $corpId, $wecomUserId);
        } catch (\Illuminate\Database\QueryException $e) {
            $binding = UserWecomBinding::findByWecomIncludeInactive($corpId, $wecomUserId);
            if ($binding) {
                $user = User::whereUserid($binding->userid)->first();
                if ($user) {
                    return $user;
                }
            }
            throw $e;
        }
    }

    /**
     * 实际注册逻辑
     */
    private function doSilentRegister(array $setting, string $corpId, string $wecomUserId): User
    {
        $contactClient = $this->makeContactClient($setting);
        try {
            $wecomUser = $contactClient->getUser($wecomUserId);
        } catch (\Throwable $e) {
            $wecomUser = ['userid' => $wecomUserId, 'name' => $wecomUserId];
        }

        $name = $wecomUser['name'] ?? $wecomUserId;
        $email = $wecomUser['biz_mail'] ?? $wecomUser['email'] ?? '';
        if (empty($email)) {
            $email = "wecom_{$wecomUserId}@dootask.local";
        }

        $existingUser = User::whereEmail($email)->first();
        if ($existingUser) {
            $binding = UserWecomBinding::createInstance([
                'userid' => $existingUser->userid,
                'wecom_corp_id' => $corpId,
                'wecom_userid' => $wecomUserId,
                'wecom_name' => $name,
                'wecom_avatar' => $wecomUser['avatar'] ?? '',
                'last_login_at' => Carbon::now(),
            ]);
            $binding->save();
            return $existingUser;
        }

        $password = Str::random(16) . '!@#' . rand(100, 999);
        $user = $this->createUserWithQuotaRetry($email, $password, $corpId);

        $user->nickname = $name;
        $user->az = Base::getFirstCharter($name);
        $user->pinyin = Base::cn2pinyin($name);
        $user->email_verity = 1;
        $user->created_ip = Base::getIp();
        if (!empty($wecomUser['avatar'])) {
            $user->userimg = $wecomUser['avatar'];
        }
        if (!empty($wecomUser['mobile'])) {
            $user->tel = $wecomUser['mobile'];
        }
        if (!empty($wecomUser['position'])) {
            $user->profession = $wecomUser['position'];
        }

        $deptIds = $wecomUser['department'] ?? [];
        if (!empty($deptIds)) {
            $dootaskDeptIds = [];
            foreach ($deptIds as $wecomDeptId) {
                $mapping = \App\Models\WecomDepartmentMapping::findByWecomDept($corpId, $wecomDeptId);
                if ($mapping) {
                    $dootaskDeptIds[] = $mapping->dootask_dept_id;
                }
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
            $dialog = \App\Models\WebSocketDialog::whereGroupType('all')->orderByDesc('id')->first();
            $dialog?->joinGroup($user->userid, 0);
        }

        $createdUser = User::find($user->userid);
        \App\Observers\AbstractObserver::taskDeliver(
            new \App\Tasks\ManticoreSyncTask('user_sync', $createdUser->toArray())
        );
        \App\Module\Apps::dispatchUserHook($createdUser, 'user_onboard', 'onboard');

        $binding = UserWecomBinding::createInstance([
            'userid' => $user->userid,
            'wecom_corp_id' => $corpId,
            'wecom_userid' => $wecomUserId,
            'wecom_name' => $name,
            'wecom_avatar' => $wecomUser['avatar'] ?? '',
            'last_login_at' => Carbon::now(),
        ]);
        $binding->save();

        return $createdUser;
    }

    // ══════════════════════════════════════
    // API 接口（需登录）
    // ══════════════════════════════════════

    /**
     * @api {get} api/wecom/status 企微绑定状态
     *
     * @apiDescription 查询当前用户的企微绑定信息
     * @apiVersion 1.0.0
     * @apiGroup wecom
     * @apiName status
     */
    public function status()
    {
        $user = User::auth();
        $setting = Base::setting('thirdAccessSetting');
        $corpId = $setting['wecom_corp_id'] ?? '';

        $binding = UserWecomBinding::where('userid', $user->userid)->first();

        return Base::retSuccess('success', [
            'wecom_open' => ($setting['wecom_open'] ?? 'close') === 'open',
            'bound' => !empty($binding),
            'wecom_name' => $binding->wecom_name ?? '',
            'wecom_userid' => $binding->wecom_userid ?? '',
        ]);
    }

    // ══════════════════════════════════════
    // 组织同步管理接口（限管理员）
    // ══════════════════════════════════════

    /**
     * @api {post} api/wecom/org/sync 手动触发组织同步（限管理员）
     *
     * @apiDescription 从企微拉取部门和成员信息，同步到 DooTask
     * @apiVersion 1.0.0
     * @apiGroup wecom
     * @apiName org__sync
     */
    public function org__sync()
    {
        User::auth('admin');

        $service = \App\Services\WecomOrgSyncService::fromSetting();
        if (!$service) {
            return Base::retError('组织同步未开启');
        }

        $result = $service->syncAll();
        return Base::retSuccess('同步完成', $result);
    }

    /**
     * @api {get} api/wecom/org/status 组织同步状态（限管理员）
     *
     * @apiDescription 查看部门映射和用户绑定统计
     * @apiVersion 1.0.0
     * @apiGroup wecom
     * @apiName org__status
     */
    public function org__status()
    {
        User::auth('admin');

        $setting = Base::setting('thirdAccessSetting');
        $corpId = $setting['wecom_corp_id'] ?? '';

        return Base::retSuccess('success', [
            'wecom_org_sync'        => ($setting['wecom_org_sync'] ?? 'close') === 'open',
            'department_mappings'   => \App\Models\WecomDepartmentMapping::where('wecom_corp_id', $corpId)->whereNull('lost_at')->count(),
            'department_lost'       => \App\Models\WecomDepartmentMapping::where('wecom_corp_id', $corpId)->whereNotNull('lost_at')->count(),
            'user_bindings'         => UserWecomBinding::where('wecom_corp_id', $corpId)->whereNull('unbind_at')->count(),
            'user_unbound'          => UserWecomBinding::where('wecom_corp_id', $corpId)->whereNotNull('unbind_at')->count(),
            'total_users'           => User::count(),
            'total_departments'     => \App\Models\UserDepartment::count(),
            'last_sync_at'          => Cache::get("wecom_last_sync_at:{$corpId}") ?: '',
        ]);
    }

    // ══════════════════════════════════════
    // License 名额管理（3 人限制突破）
    // ══════════════════════════════════════

    /**
     * 创建用户，License 超限时自动回收名额重试
     */
    private function createUserWithQuotaRetry(string $email, string $password, string $corpId): User
    {
        return \App\Services\WecomOrgSyncService::createUserWithQuotaRetry($email, $password, $corpId);
    }
}
