<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Module\Base;
use App\Module\Doo;
use App\Module\Ihttp;
use Cache;

class WecomApiClient
{
    private string $corpId;
    private string $secret;
    private string $cachePrefix;

    private const BASE_URL = 'https://qyapi.weixin.qq.com/cgi-bin';

    public function __construct(string $corpId, string $secret, string $cachePrefix = 'wecom')
    {
        $this->corpId = $corpId;
        $this->secret = $secret;
        $this->cachePrefix = $cachePrefix;
    }

    /**
     * 获取 access_token（缓存 7000 秒，提前 200 秒刷新）
     */
    public function getAccessToken(): string
    {
        $cacheKey = "{$this->cachePrefix}_token_{$this->corpId}_" . md5($this->secret);
        return Cache::remember($cacheKey, 7000, function () {
            $url = self::BASE_URL . '/gettoken?' . http_build_query([
                'corpid' => $this->corpId,
                'corpsecret' => $this->secret,
            ]);
            $result = Ihttp::ihttp_get($url);
            if (Base::isError($result)) {
                throw new ApiException(Doo::translate('企微获取 token 失败') . ': ' . ($result['msg'] ?? 'network error'));
            }
            $resp = json_decode($result['data'], true);
            if (($resp['errcode'] ?? -1) !== 0) {
                throw new ApiException(Doo::translate('企微获取 token 失败') . ': ' . ($resp['errmsg'] ?? 'unknown'));
            }
            return $resp['access_token'];
        });
    }

    /**
     * GET 请求
     */
    public function get(string $path, array $params = []): array
    {
        $params['access_token'] = $this->getAccessToken();
        $url = self::BASE_URL . $path . '?' . http_build_query($params);
        $result = Ihttp::ihttp_get($url);
        if (Base::isError($result)) {
            throw new ApiException(Doo::translate('企微 API 请求失败') . " [{$path}]: " . ($result['msg'] ?? 'network error'));
        }
        $resp = json_decode($result['data'], true);
        if (($resp['errcode'] ?? -1) !== 0) {
            throw new ApiException(Doo::translate('企微 API 错误') . " [{$path}]: {$resp['errcode']} {$resp['errmsg']}");
        }
        return $resp;
    }

    /**
     * POST 请求
     */
    public function post(string $path, array $data = []): array
    {
        $token = $this->getAccessToken();
        $url = self::BASE_URL . $path . '?access_token=' . $token;
        $headers = ['Content-Type' => 'application/json'];
        $result = Ihttp::ihttp_request($url, json_encode($data), $headers);
        if (Base::isError($result)) {
            throw new ApiException(Doo::translate('企微 API 请求失败') . " [{$path}]: " . ($result['msg'] ?? 'network error'));
        }
        $resp = json_decode($result['data'], true);
        if (($resp['errcode'] ?? -1) !== 0) {
            throw new ApiException(Doo::translate('企微 API 错误') . " [{$path}]: {$resp['errcode']} {$resp['errmsg']}");
        }
        return $resp;
    }

    // ── OAuth ──

    /**
     * 构造静默登录 URL
     */
    public function buildOAuthUrl(string $redirectUri, int $agentId, string $state = ''): string
    {
        $params = http_build_query([
            'appid' => $this->corpId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'snsapi_base',
            'agentid' => $agentId,
            'state' => $state,
        ]);
        return "https://open.weixin.qq.com/connect/oauth2/authorize?{$params}#wechat_redirect";
    }

    /**
     * code 换取用户身份
     */
    public function getUserInfoByCode(string $code): array
    {
        return $this->get('/auth/getuserinfo', ['code' => $code]);
    }

    // ── 通讯录 ──

    /**
     * 获取成员详情
     */
    public function getUser(string $userid): array
    {
        return $this->get('/user/get', ['userid' => $userid]);
    }

    /**
     * 获取部门列表（全量）
     */
    public function getDepartmentList(int $id = 0): array
    {
        $params = [];
        if ($id > 0) {
            $params['id'] = $id;
        }
        $resp = $this->get('/department/list', $params);
        return $resp['department'] ?? [];
    }

    /**
     * 获取部门直属成员（简要信息）
     */
    public function getDepartmentUsers(int $departmentId): array
    {
        $resp = $this->get('/user/simplelist', ['department_id' => $departmentId]);
        return $resp['userlist'] ?? [];
    }

    /**
     * 获取部门直属成员（详细信息）
     */
    public function getDepartmentUsersDetail(int $departmentId): array
    {
        $resp = $this->get('/user/list', ['department_id' => $departmentId]);
        return $resp['userlist'] ?? [];
    }
}
