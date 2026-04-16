# DooTask 企业微信集成实施方案

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 将 DooTask 改造为企业微信自建应用，实现静默登录、静默注册、组织架构同步三大能力，并完成首次部署到生产服务器 192.168.100.30（端口 2222）。

**Architecture:** 在 DooTask 现有认证体系旁新增企微 OAuth 通道（参照已有 LDAP 集成模式），通过 `snsapi_base` scope 实现完全静默登录。组织同步通过管理员手动触发拉取企微通讯录 API 实现（回调事件为后续扩展项）。全部改动为新增文件 + 极少量现有文件修改，不破坏原有认证逻辑。部署采用 Docker 一键安装（`./cmd install`），5 个容器（php/nginx/mariadb/redis/appstore），主机仅暴露 2222 端口。

**Tech Stack:** PHP 8 / Laravel 8 / MariaDB / 企微 OAuth2 API / 企微通讯录 API

---

## 代码事实基础

以下所有设计均基于已验证的源码事实，不含假设：

### DooTask 认证体系（已验证）

| 事实 | 源码位置 |
|------|---------|
| Token 系统使用 C FFI (`doo.so`)，非 JWT/Sanctum | `app/Module/Interface/DooSo.php:22-43` |
| Token 通过 `dootask-token` header 传输 | `app/Module/Base.php:88-91` |
| 路由定义在 `routes/web.php`（非 `api.php`）| `routes/web.php:33-70` |
| 使用 InvokeController 模式：URL segment → 方法名 | `routes/web.php:35` |
| 注册方法：`User::reg($email, $password, $other=[])` | `app/Models/User.php:378-426` |
| Token 生成：`User::generateToken($user)` | `app/Models/User.php:535-554` |
| 认证验证三要素：`userid + email + encrypt` | `app/Models/User.php:502` |
| 唯一第三方登录：LDAP（`thirdAccessSetting`）| `app/Http/Controllers/Api/SystemController.php:578-631` |
| 用户主键为 `userid`（bigIncrements）| `database/migrations/..._create_users_table.php:17` |
| `identity` 字段逗号分隔存储角色标识 | `app/Http/Controllers/Api/UsersController.php:130` |

### DooTask 部门体系（已验证）

| 事实 | 源码位置 |
|------|---------|
| `user_departments` 表：id, name, parent_id, owner_userid, dialog_id | `database/migrations/..._create_user_departments_table.php` |
| `users.department` 字段：string(255)，存储 `,1,2,3,` 格式 | `database/migrations/..._add_users_department.php:18` |
| 树形结构，parent_id 表示上级 | `app/Models/UserDepartment.php:42-51` |
| 部门自动关联聊天群（dialog_id）| `app/Models/UserDepartment.php:58-127` |
| 递归获取子部门：`getAllSubDepartmentIds()` | `app/Models/UserDepartment.php:178-191` |

### 企微 API（已验证 via omnisockit MCP）

| 能力 | API | 说明 |
|------|-----|------|
| 静默登录 | OAuth `scope=snsapi_base` | 用户无感知，返回 userid |
| code 换身份 | `GET /auth/getuserinfo?code=CODE` | 一次性，5分钟有效 |
| 获取成员详情 | `GET /user/get?userid=X` | 需通讯录同步 secret |
| 获取部门列表 | `GET /department/list` | 返回全量部门树 |
| 获取部门成员 | `GET /user/simplelist?department_id=X` | 仅直属成员 |
| 通讯录回调 | `Event=change_contact` | 成员/部门增删改实时通知 |

### 权限约束（关键限制）

| 约束 | 说明 |
|------|------|
| 自建应用读成员 | 可见范围内，但 2022.6.20 后**不返回**手机/邮箱/头像等敏感字段 |
| 获取敏感字段 | 需使用**通讯录同步 secret** 或 **OAuth snsapi_privateinfo**（需用户手动授权） |
| redirect_uri | 域名必须与应用可信域名**完全匹配** |
| code | 一次性，5分钟有效 |

---

## 文件结构

### 新增文件（零侵入）

```
app/Http/Controllers/Api/WecomController.php     ← OAuth 入口 + 回调 + 组织同步
app/Models/UserWecomBinding.php                    ← 企微用户绑定模型
app/Models/WecomDepartmentMapping.php              ← 部门映射模型
app/Services/WecomApiClient.php                    ← 企微 API 封装（token 缓存）
app/Services/WecomOrgSyncService.php               ← 组织同步服务
database/migrations/2026_04_15_000001_create_user_wecom_bindings_table.php
database/migrations/2026_04_15_000002_create_wecom_department_mappings_table.php
```

### 修改文件（极小改动）

```
routes/web.php                                     ← 加 2 行路由
app/Http/Controllers/Api/SystemController.php      ← thirdAccessSetting 加企微配置字段
```

---

## Task 1: 数据库迁移 — 企微绑定表

**Files:**
- Create: `database/migrations/2026_04_15_000001_create_user_wecom_bindings_table.php`

- [ ] **Step 1: 创建迁移文件**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUserWecomBindingsTable extends Migration
{
    public function up()
    {
        Schema::create('user_wecom_bindings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('userid')->comment('DooTask userid');
            $table->string('wecom_corp_id', 100)->comment('企业 CorpID');
            $table->string('wecom_userid', 100)->comment('企微成员 UserId');
            $table->string('wecom_name', 100)->nullable()->default('')->comment('企微姓名');
            $table->string('wecom_avatar', 500)->nullable()->default('')->comment('企微头像');
            $table->timestamp('last_login_at')->nullable()->comment('最后企微登录时间');
            $table->timestamps();

            $table->unique(['wecom_corp_id', 'wecom_userid'], 'uk_corp_wecom_user');
            $table->index('userid', 'idx_userid');
        });
    }

    public function down()
    {
        Schema::dropIfExists('user_wecom_bindings');
    }
}
```

- [ ] **Step 2: 验证迁移语法**

Run: `./cmd artisan migrate:status`

如果不在 Docker 内，使用：
```bash
cd D:/workspace/agent-weknora/dootask && ./cmd artisan migrate:status
```

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_04_15_000001_create_user_wecom_bindings_table.php
git commit -m "feat(wecom): add user_wecom_bindings migration"
```

---

## Task 2: 数据库迁移 — 部门映射表

**Files:**
- Create: `database/migrations/2026_04_15_000002_create_wecom_department_mappings_table.php`

- [ ] **Step 1: 创建迁移文件**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateWecomDepartmentMappingsTable extends Migration
{
    public function up()
    {
        Schema::create('wecom_department_mappings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('wecom_corp_id', 100)->comment('企业 CorpID');
            $table->bigInteger('wecom_dept_id')->comment('企微部门ID');
            $table->bigInteger('dootask_dept_id')->comment('DooTask user_departments.id');
            $table->string('wecom_dept_name', 100)->nullable()->default('');
            $table->bigInteger('wecom_parent_id')->nullable()->default(0)->comment('企微父部门ID');
            $table->timestamps();

            $table->unique(['wecom_corp_id', 'wecom_dept_id'], 'uk_corp_wecom_dept');
            $table->index('dootask_dept_id', 'idx_dootask_dept');
        });
    }

    public function down()
    {
        Schema::dropIfExists('wecom_department_mappings');
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add database/migrations/2026_04_15_000002_create_wecom_department_mappings_table.php
git commit -m "feat(wecom): add wecom_department_mappings migration"
```

---

## Task 3: 绑定模型

**Files:**
- Create: `app/Models/UserWecomBinding.php`
- Create: `app/Models/WecomDepartmentMapping.php`

- [ ] **Step 1: 创建 UserWecomBinding 模型**

```php
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
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class UserWecomBinding extends AbstractModel
{
    protected $dates = ['last_login_at'];

    /**
     * 通过企微身份查找绑定
     */
    public static function findByWecom(string $corpId, string $wecomUserId): ?self
    {
        return self::where('wecom_corp_id', $corpId)
            ->where('wecom_userid', $wecomUserId)
            ->first();
    }
}
```

- [ ] **Step 2: 创建 WecomDepartmentMapping 模型**

```php
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
```

- [ ] **Step 3: Commit**

```bash
git add app/Models/UserWecomBinding.php app/Models/WecomDepartmentMapping.php
git commit -m "feat(wecom): add binding and mapping models"
```

---

## Task 4: 企微 API 客户端

**Files:**
- Create: `app/Services/WecomApiClient.php`

- [ ] **Step 1: 实现 API 客户端（含 token 缓存）**

```php
<?php

namespace App\Services;

use App\Exceptions\ApiException;
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
        $cacheKey = "{$this->cachePrefix}_token_{$this->corpId}_{$this->secret}";
        return Cache::remember($cacheKey, 7000, function () {
            $url = self::BASE_URL . '/gettoken?' . http_build_query([
                'corpid' => $this->corpId,
                'corpsecret' => $this->secret,
            ]);
            // [Round-3 R3-1/R3-2 修复] Ihttp 返回 ['data'] 非 ['content']，需先 isError 检查
            // 参照 AI.php:115-119 的正确用法
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
            throw new ApiException("企微 API 请求失败 [{$path}]: " . ($result['msg'] ?? 'network error'));
        }
        $resp = json_decode($result['data'], true);
        if (($resp['errcode'] ?? -1) !== 0) {
            throw new ApiException("企微 API 错误 [{$path}]: {$resp['errcode']} {$resp['errmsg']}");
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
            throw new ApiException("企微 API 请求失败 [{$path}]: " . ($result['msg'] ?? 'network error'));
        }
        $resp = json_decode($result['data'], true);
        if (($resp['errcode'] ?? -1) !== 0) {
            throw new ApiException("企微 API 错误 [{$path}]: {$resp['errcode']} {$resp['errmsg']}");
        }
        return $resp;
    }

    // ── OAuth ──

    /**
     * 构造静默登录 URL
     * snsapi_base: 完全静默，用户无感知，仅返回 userid
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
     * 返回: ['userid' => 'zhangsan'] 或 ['openid' => 'xxx']（非企业成员）
     */
    public function getUserInfoByCode(string $code): array
    {
        return $this->get('/auth/getuserinfo', ['code' => $code]);
    }

    // ── 通讯录 ──

    /**
     * 获取成员详情
     * 注意：自建应用 2022.6.20 后不返回敏感字段，需用通讯录同步 secret
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
     * 注意：需要通讯录同步 secret 才能获取完整信息
     */
    public function getDepartmentUsersDetail(int $departmentId): array
    {
        $resp = $this->get('/user/list', ['department_id' => $departmentId]);
        return $resp['userlist'] ?? [];
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Services/WecomApiClient.php
git commit -m "feat(wecom): add WecomApiClient with token caching"
```

---

## Task 5: 系统设置扩展

**Files:**
- Modify: `app/Http/Controllers/Api/SystemController.php:578-631`

- [ ] **Step 1: 扩展 save 白名单（⛔ 关键：不加则配置存不进去）**

在 `SystemController.php` 的 `setting__thirdaccess()` 方法中，找到 save 分支的白名单数组（约第 608-616 行）：

```php
            foreach ($all as $key => $value) {
                if (!in_array($key, [
                    'ldap_open',
                    'ldap_host',
                    'ldap_port',
                    'ldap_password',
                    'ldap_user_dn',
                    'ldap_base_dn',
                    'ldap_sync_local'
                ])) {
                    unset($all[$key]);
                }
            }
```

**替换为**（在 `ldap_sync_local` 后追加企微字段）：

```php
            foreach ($all as $key => $value) {
                if (!in_array($key, [
                    'ldap_open',
                    'ldap_host',
                    'ldap_port',
                    'ldap_password',
                    'ldap_user_dn',
                    'ldap_base_dn',
                    'ldap_sync_local',
                    // 企业微信
                    'wecom_open',
                    'wecom_corp_id',
                    'wecom_agent_id',
                    'wecom_secret',
                    'wecom_contact_secret',
                    'wecom_auto_reg',
                    'wecom_org_sync',
                ])) {
                    unset($all[$key]);
                }
            }
```

- [ ] **Step 2: 在读取返回前追加企微字段默认值**

找到最后返回 setting 前的字段初始化块（约第 626-628 行）：

```php
        $setting['ldap_open'] = $setting['ldap_open'] ?: 'close';
        $setting['ldap_port'] = intval($setting['ldap_port']) ?: 389;
        $setting['ldap_sync_local'] = $setting['ldap_sync_local'] ?: 'close';
```

在其后追加：

```php
        // 企业微信配置
        $setting['wecom_open'] = $setting['wecom_open'] ?: 'close';
        $setting['wecom_corp_id'] = $setting['wecom_corp_id'] ?: '';
        $setting['wecom_agent_id'] = $setting['wecom_agent_id'] ?: '';
        $setting['wecom_secret'] = $setting['wecom_secret'] ?: '';
        $setting['wecom_contact_secret'] = $setting['wecom_contact_secret'] ?: '';
        $setting['wecom_auto_reg'] = $setting['wecom_auto_reg'] ?: 'open';
        $setting['wecom_org_sync'] = $setting['wecom_org_sync'] ?: 'close';
```

字段说明：

| 字段 | 用途 |
|------|------|
| `wecom_open` | 是否开启企微登录（open/close）|
| `wecom_corp_id` | 企业 CorpID |
| `wecom_agent_id` | 自建应用 AgentId |
| `wecom_secret` | 自建应用 Secret（用于 OAuth） |
| `wecom_contact_secret` | 通讯录同步 Secret（用于获取成员详情和组织同步） |
| `wecom_auto_reg` | 静默注册开关 |
| `wecom_org_sync` | 组织同步开关 |

- [ ] **Step 2: 同步更新 apiDoc 注释**

在同方法的 `@apiParam` 注释中（约第 571 行），追加：

```php
     * - save: 保存设置（参数：['ldap_open', 'ldap_host', ...
     *   'wecom_open', 'wecom_corp_id', 'wecom_agent_id', 'wecom_secret',
     *   'wecom_contact_secret', 'wecom_auto_reg', 'wecom_org_sync']）
```

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/Api/SystemController.php
git commit -m "feat(wecom): extend thirdAccessSetting with wecom config fields"
```

---

## Task 6: 路由注册

**Files:**
- Modify: `routes/web.php:33-70`

- [ ] **Step 1: 在 api prefix group 中添加企微路由**

在 `routes/web.php` 的 `Route::prefix('api')->middleware(['webapi'])->group(function () {` 块内（约第 65 行，搜索 `SearchController` 附近），追加：

```php
    // 企业微信
    Route::any('wecom/{method}',                    \App\Http\Controllers\Api\WecomController::class);
    Route::any('wecom/{method}/{action}',           \App\Http\Controllers\Api\WecomController::class);
```

- [ ] **Step 2: Commit**

```bash
git add routes/web.php
git commit -m "feat(wecom): register wecom controller routes"
```

---

## Task 7: OAuth 控制器 — 静默登录 + 静默注册

**Files:**
- Create: `app/Http/Controllers/Api/WecomController.php`

- [ ] **Step 1: 创建完整控制器**

```php
<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Models\UserWecomBinding;
use App\Module\Base;
use App\Module\Doo;
use App\Services\WecomApiClient;
use Cache;
use Carbon\Carbon;
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
        // 已登录则直接进入首页
        // 注意：不能用 User::auth()，未登录会抛 ApiException
        // 使用 Doo::userId() 判断（不抛异常，返回 0）
        if (Doo::userId() > 0) {
            return redirect('/');
        }

        // [Round-2 P1-9 修复] entry() 加 try/catch
        // getWecomSetting() 未配置时抛 ApiException → 用户看到 JSON 而非友好页面
        try {
            $setting = $this->getWecomSetting();
        } catch (ApiException $e) {
            return redirect("/#/login?wecom_error=" . urlencode(Doo::translate($e->getMessage())));
        }
        $client = $this->makeOAuthClient($setting);

        // 生成 state 防 CSRF
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

        // [Round-2 P1-6] 所有用户可见错误消息用 Doo::translate() 包装
        if (empty($code)) {
            return redirect("/#/login?wecom_error=" . urlencode(Doo::translate('授权失败：未获取到 code')));
        }

        // 验证 state 防 CSRF
        if (!Cache::pull("wecom_state:{$state}")) {
            return redirect("/#/login?wecom_error=" . urlencode(Doo::translate('授权失败：state 验证失败')));
        }

        try {
            $setting = $this->getWecomSetting();
        } catch (ApiException $e) {
            return redirect("/#/login?wecom_error=" . urlencode(Doo::translate($e->getMessage())));
        }
        $client = $this->makeOAuthClient($setting);
        $corpId = $setting['wecom_corp_id'];

        // 1. code 换 userid（一次性，5分钟有效）
        try {
            $userInfo = $client->getUserInfoByCode($code);
        } catch (\Throwable $e) {
            return redirect("/#/login?wecom_error=" . urlencode(Doo::translate('授权失败') . ': ' . $e->getMessage()));
        }

        $wecomUserId = $userInfo['userid'] ?? '';
        if (empty($wecomUserId)) {
            return redirect("/#/login?wecom_error=" . urlencode(Doo::translate('非企业成员，无法登录')));
        }

        // 2. 查绑定关系
        $binding = UserWecomBinding::findByWecom($corpId, $wecomUserId);

        if ($binding) {
            // 已绑定 → 直接登录
            $user = User::whereUserid($binding->userid)->first();
            if (!$user || $user->isDisable()) {
                return redirect("/#/login?wecom_error=" . urlencode(Doo::translate('账号已停用或不存在')));
            }
            $binding->last_login_at = Carbon::now();
            $binding->save();
        } else {
            // 未绑定 → 静默注册
            if (($setting['wecom_auto_reg'] ?? 'close') !== 'open') {
                return redirect("/#/login?wecom_error=" . urlencode(Doo::translate('未绑定 DooTask 账号，且未开启自动注册')));
            }
            try {
                $user = $this->silentRegister($setting, $corpId, $wecomUserId);
            } catch (\Throwable $e) {
                return redirect("/#/login?wecom_error=" . urlencode(Doo::translate('注册失败') . ': ' . $e->getMessage()));
            }
        }

        // 3. 更新登录信息
        $user->updateInstance([
            'login_num' => $user->login_num + 1,
            'last_ip' => Base::getIp(),
            'last_at' => Carbon::now(),
            'line_ip' => Base::getIp(),
            'line_at' => Carbon::now(),
        ]);
        $user->save();

        // 4. 生成 DooTask token
        $token = User::generateToken($user);

        // 5. [Round-2 P0-1/P0-2 修复] 改用一次性 ticket，禁止 token 走 URL
        // 原方案：redirect("/#/?userid=X&token=Y")
        // 问题（多专家审查发现）:
        //   - token 30天有效，URL 明文传输 → 浏览器历史/地址栏/截屏/推屏永久残留
        //   - common.js:664 removeURLParameter 用 URL.searchParams.delete()
        //     不操作 hash 内的 query → 生产 history mode 下"自动清理"完全失效
        //   - urlParameterAll 不调 decodeURIComponent → token 含 +/=/& 时失真
        // 修复：服务端生成 64 位 ticket → Cache 60 秒 → redirect 仅带 ticket
        //       前端 login.vue mounted() 调 POST /api/wecom/exchange 换 token
        //       ticket 一次性消费（Cache::pull），即使泄露窗口也仅 60 秒
        $ticket = Str::random(64);
        Cache::put("wecom_ticket:{$ticket}", [
            'userid' => $user->userid,
            'token' => $token,
        ], 60);
        return redirect("/#/login?wecom_ticket={$ticket}");
    }

    /**
     * @api {post} api/wecom/exchange ticket 换 token
     *
     * @apiDescription 前端用一次性 ticket 换取真正的 userid + token。
     *                 ticket 仅 60 秒有效且单次消费，避免 token 在 URL 暴露。
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
     * [FIX] 不使用 User::reg() — 原因：
     *   1. reg() 调用 Base::isEmail() 校验，虚拟邮箱 xxx@wecom.local 可能不通过
     *   2. reg() 调用 passwordPolicy() 执行复杂密码策略（管理员可能要求字母+数字+特殊字符），
     *      Str::random(32) 纯字母数字，不满足策略会抛 ApiException
     *   3. reg() 邮箱已存在会直接抛异常，无法控制绑定流程
     *
     * 改用 Doo::userCreate() 直接创建用户（与 reg() 内部调用的同一个底层方法），
     * 然后手动处理后续逻辑（全员群、Manticore 索引、user_onboard hook）
     */
    private function silentRegister(array $setting, string $corpId, string $wecomUserId): User
    {
        // 从企微获取成员信息（使用通讯录同步 secret 获取完整信息）
        $contactClient = $this->makeContactClient($setting);
        try {
            $wecomUser = $contactClient->getUser($wecomUserId);
        } catch (\Throwable $e) {
            // 通讯录 API 失败时，使用最小信息创建
            $wecomUser = ['userid' => $wecomUserId, 'name' => $wecomUserId];
        }

        $name = $wecomUser['name'] ?? $wecomUserId;
        // 邮箱优先级：企业邮箱 > 个人邮箱 > 虚拟邮箱
        $email = $wecomUser['biz_mail'] ?? $wecomUser['email'] ?? '';
        if (empty($email)) {
            $email = "wecom_{$wecomUserId}@dootask.local";
        }

        // 检查邮箱是否已存在 → 绑定已有账号
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

        // [FIX] 使用 Doo::userCreate() 绕过 User::reg() 的邮箱校验和密码策略
        // Doo::userCreate() 是 doo.so FFI 底层调用，直接创建用户记录
        $password = Str::random(16) . '!@#' . rand(100, 999); // 确保满足复杂密码策略
        $user = Doo::userCreate($email, $password);
        if (!$user) {
            throw new ApiException('企微用户创建失败');
        }

        // 更新用户信息
        $user->nickname = $name;
        $user->az = Base::getFirstCharter($name);
        $user->pinyin = Base::cn2pinyin($name);
        $user->email_verity = 1; // 企微用户免邮箱验证
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

        // 同步部门归属
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

        // [Round-3 R3-4 修复] reg_identity='temp' 策略：与 User::reg() 保持一致
        $regIdentity = Base::settingFind('system', 'reg_identity') ?: 'normal';
        if ($regIdentity === 'temp') {
            $identityArr = is_array($user->identity) ? $user->identity : [];
            if (!in_array('temp', $identityArr)) {
                $identityArr[] = 'temp';
                $user->identity = ',' . implode(',', $identityArr) . ',';
                $user->save();
            }
        }

        // 加入全员群组（参照 User::reg() 逻辑）
        $all_group_autoin = Base::settingFind('system', 'all_group_autoin') ?: 'yes';
        if ($all_group_autoin === 'yes') {
            $dialog = \App\Models\WebSocketDialog::whereGroupType('all')->orderByDesc('id')->first();
            $dialog?->joinGroup($user->userid, 0);
        }

        // Manticore 索引同步 + user_onboard hook（参照 User::reg() 逻辑）
        $createdUser = User::find($user->userid);
        \App\Models\AbstractObserver::taskDeliver(
            new \App\Tasks\ManticoreSyncTask('user_sync', $createdUser->toArray())
        );
        \App\Models\Apps::dispatchUserHook($createdUser, 'user_onboard', 'onboard');

        // 写入绑定关系
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
}
```

- [ ] **Step 2: 验证控制器语法**

```bash
cd D:/workspace/agent-weknora/dootask && ./cmd php -l app/Http/Controllers/Api/WecomController.php
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/Api/WecomController.php
git commit -m "feat(wecom): add OAuth controller with silent login and registration"
```

---

## Task 8: 组织同步服务

**Files:**
- Create: `app/Services/WecomOrgSyncService.php`

- [ ] **Step 1: 实现组织同步服务**

同步策略：
- 企微部门树 → DooTask `user_departments` 表
- 企微成员部门归属 → DooTask `users.department` 字段
- 映射关系存 `wecom_department_mappings` 表
- 企微根部门（id=1）不创建 DooTask 部门，其直属子部门作为 DooTask 顶级部门

```php
<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserDepartment;
use App\Models\UserWecomBinding;
use App\Models\WecomDepartmentMapping;
use App\Module\Base;
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
     * 1. 拉取企微全量部门列表
     * 2. 按层级排序（父部门先创建）
     * 3. 对比映射表，新增/更新/标记删除
     */
    public function syncDepartments(): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => 0];

        // 1. 拉取企微部门树
        $wecomDepts = $this->client->getDepartmentList();
        if (empty($wecomDepts)) {
            return $stats;
        }

        // 2. 按 parentid 排序，确保父部门先处理
        usort($wecomDepts, function ($a, $b) {
            // 根部门(id=1)最先，然后按 parentid 升序
            if ($a['id'] === 1) return -1;
            if ($b['id'] === 1) return 1;
            return ($a['parentid'] ?? 0) - ($b['parentid'] ?? 0);
        });

        // 记录本次同步中企微存在的部门ID，用于后续清理僵尸映射
        $activeWecomDeptIds = [];

        // 3. 逐个同步
        foreach ($wecomDepts as $dept) {
            $wecomDeptId = $dept['id'];

            // 企微根部门(id=1)不映射到 DooTask 部门，作为虚拟根
            if ($wecomDeptId === 1) {
                continue;
            }

            $activeWecomDeptIds[] = $wecomDeptId;
            $existing = WecomDepartmentMapping::findByWecomDept($this->corpId, $wecomDeptId);

            // 计算 DooTask 父部门ID
            $wecomParentId = $dept['parentid'] ?? 1;
            $dootaskParentId = 0;
            if ($wecomParentId > 1) {
                // 非根部门的子部门 → 查映射
                $dootaskParentId = WecomDepartmentMapping::getDooTaskDeptId($this->corpId, $wecomParentId) ?? 0;
            }
            // wecomParentId=1 的部门是企微根的直属子部门 → DooTask 顶级部门(parent_id=0)

            // [Round-2 P2-14 修复] user_departments.name 字段 varchar(100)，超长企微部门名需截断
            $deptName = mb_substr($dept['name'] ?? '', 0, 100);
            // [FIX Bug 7] 首次同步时 leader 可能还没通过 OAuth 登录，绑定表为空
            // 此时 ownerUserid 为 0，后续用户登录后可在增量同步中回填
            $leader = $dept['department_leader'][0] ?? '';
            $ownerUserid = 0;
            if ($leader) {
                $binding = UserWecomBinding::findByWecom($this->corpId, $leader);
                $ownerUserid = $binding->userid ?? 0;
            }

            if ($existing) {
                // 已有映射 → 更新名称和层级
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
                        $dootaskDept->save();
                        $stats['updated']++;
                    } else {
                        $stats['skipped']++;
                    }
                }
                // 更新映射元数据
                $existing->wecom_dept_name = $deptName;
                $existing->wecom_parent_id = $wecomParentId;
                $existing->save();
            } else {
                // [FIX Bug 6] 新部门必须通过 saveDepartment() 创建，不能直接 createInstance + save
                // 原因：DooTask 部门必须关联聊天群（dialog_id），saveDepartment() 内部会：
                //   1. 调用 WebSocketDialog::createGroup() 创建部门聊天群
                //   2. 将 dialog_id 写回部门记录
                // 直接 save() 创建的部门没有聊天群，用户进入部门看不到群聊
                $dootaskDept = UserDepartment::createInstance([
                    'name' => $deptName,
                    'parent_id' => $dootaskParentId,
                    'owner_userid' => $ownerUserid ?: 0,
                ]);
                // [Round-2 P0-4 修复] catch 后禁止降级 save() — 那会写入 dialog_id=0 的"僵尸部门"
                // 正确做法：记录失败，跳过此部门 + 跳过映射写入，下次同步重试
                // saveDepartment 内部 transaction 会自行 rollBack，无残留
                try {
                    $dootaskDept->saveDepartment([
                        'name' => $deptName,
                        'parent_id' => $dootaskParentId,
                        'owner_userid' => $ownerUserid ?: 0,
                    ], 0);
                } catch (\Throwable $e) {
                    Log::warning("[WecomOrgSync] 部门创建失败，跳过: {$deptName}", ['error' => $e->getMessage()]);
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

        // 4. 清理僵尸映射：企微中已删除但映射表中仍存在的部门
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
     * 遍历所有绑定关系，从企微获取成员当前部门，更新 DooTask users.department
     */
    public function syncUserDepartments(): array
    {
        $stats = ['updated' => 0, 'skipped' => 0, 'errors' => 0];

        $bindings = UserWecomBinding::where('wecom_corp_id', $this->corpId)->get();

        foreach ($bindings as $binding) {
            try {
                $wecomUser = $this->client->getUser($binding->wecom_userid);
            } catch (\Throwable $e) {
                Log::warning("[WecomOrgSync] 获取成员失败: {$binding->wecom_userid}", ['error' => $e->getMessage()]);
                $stats['errors']++;
                continue;
            }

            $user = User::whereUserid($binding->userid)->first();
            if (!$user) {
                $stats['skipped']++;
                continue;
            }

            // 更新姓名和头像
            if (!empty($wecomUser['name']) && $user->nickname !== $wecomUser['name']) {
                $user->nickname = $wecomUser['name'];
            }
            if (!empty($wecomUser['avatar']) && $user->userimg !== $wecomUser['avatar']) {
                $user->userimg = $wecomUser['avatar'];
            }

            // 映射企微部门到 DooTask 部门
            $wecomDeptIds = $wecomUser['department'] ?? [];
            $dootaskDeptIds = [];
            foreach ($wecomDeptIds as $wecomDeptId) {
                $dootaskDeptId = WecomDepartmentMapping::getDooTaskDeptId($this->corpId, $wecomDeptId);
                if ($dootaskDeptId) {
                    $dootaskDeptIds[] = $dootaskDeptId;
                }
            }

            // [FIX] $user->department 通过 getDepartmentAttribute 返回 int[]，不是字符串
            // 比较时必须用数组比较，写入时用 ",1,2,3," 字符串格式
            // 参照 UserDepartment.php:143-144 的写法
            sort($dootaskDeptIds);
            $currentDeptIds = $user->department; // int[] via accessor
            sort($currentDeptIds);
            if ($dootaskDeptIds !== $currentDeptIds) {
                $user->department = !empty($dootaskDeptIds) ? "," . implode(",", $dootaskDeptIds) . "," : "";
                $stats['updated']++;
            } else {
                $stats['skipped']++;
            }

            $user->save();

            // 同步绑定表信息
            $binding->wecom_name = $wecomUser['name'] ?? $binding->wecom_name;
            $binding->wecom_avatar = $wecomUser['avatar'] ?? $binding->wecom_avatar;
            $binding->save();
        }

        Log::info('[WecomOrgSync] 成员部门同步完成', $stats);
        return $stats;
    }

    // ══════════════════════════════════════
    // [FIX Bug 8] 批量预创建用户
    // ══════════════════════════════════════

    /**
     * 批量导入企微成员为 DooTask 用户
     * 按部门遍历，使用 /user/list 批量拉取（比逐个 /user/get 高效）
     * 已绑定的跳过，未绑定的预创建用户 + 绑定记录
     *
     * 注意：需要通讯录同步 secret 才能获取完整信息（手机、邮箱、头像）
     */
    public function syncUsers(): array
    {
        $stats = ['created' => 0, 'skipped' => 0, 'errors' => 0];

        // 获取所有已映射的部门
        $mappings = WecomDepartmentMapping::where('wecom_corp_id', $this->corpId)->get();

        foreach ($mappings as $mapping) {
            try {
                $members = $this->client->getDepartmentUsersDetail($mapping->wecom_dept_id);
            } catch (\Throwable $e) {
                Log::warning("[WecomOrgSync] 获取部门成员失败: dept={$mapping->wecom_dept_id}", ['error' => $e->getMessage()]);
                $stats['errors']++;
                continue;
            }

            foreach ($members as $member) {
                $wecomUserId = $member['userid'] ?? '';
                if (empty($wecomUserId)) continue;

                // 已绑定则跳过
                $existing = UserWecomBinding::findByWecom($this->corpId, $wecomUserId);
                if ($existing) {
                    $stats['skipped']++;
                    continue;
                }

                try {
                    $this->createUserFromWecom($member);
                    $stats['created']++;
                } catch (\Throwable $e) {
                    Log::warning("[WecomOrgSync] 创建用户失败: {$wecomUserId}", ['error' => $e->getMessage()]);
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

        // 邮箱已存在 → 绑定已有账号
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

        // 创建新用户（使用 Doo::userCreate 绕过密码策略）
        $password = \Illuminate\Support\Str::random(16) . '!@#' . rand(100, 999);
        $user = \App\Module\Doo::userCreate($email, $password);
        if (!$user) {
            throw new \App\Exceptions\ApiException("创建用户失败: {$wecomUserId}");
        }

        $user->nickname = $name;
        $user->az = Base::getFirstCharter($name);
        $user->pinyin = Base::cn2pinyin($name);
        $user->email_verity = 1;
        $user->created_ip = '0.0.0.0'; // 批量同步，无真实 IP
        if (!empty($wecomUser['avatar'])) $user->userimg = $wecomUser['avatar'];
        if (!empty($wecomUser['mobile'])) $user->tel = $wecomUser['mobile'];
        if (!empty($wecomUser['position'])) $user->profession = $wecomUser['position'];

        // 同步部门归属
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

        // [Round-3 R3-4 修复] reg_identity='temp' 策略：与 User::reg() 保持一致
        // 如果管理员设置新用户默认为临时身份，企微用户也应遵守
        $regIdentity = Base::settingFind('system', 'reg_identity') ?: 'normal';
        if ($regIdentity === 'temp') {
            $identityArr = is_array($user->identity) ? $user->identity : [];
            if (!in_array('temp', $identityArr)) {
                $identityArr[] = 'temp';
                $user->identity = ',' . implode(',', $identityArr) . ',';
                $user->save();
            }
        }

        // [Round-3 R3-3 修复] 补全 User::reg() 的三个关键副作用
        // 参照 User.php:413-423

        // 1. 加入全员群
        if (Base::settingFind('system', 'all_group_autoin') === 'yes') {
            $allDialog = \App\Models\WebSocketDialog::whereGroupType('all')->orderByDesc('id')->first();
            if ($allDialog) {
                $allDialog->joinGroup($user->userid, 0);
            }
        }

        // 2. Manticore 搜索索引同步（否则用户在 DooTask 搜索中不可见）
        \App\Observers\AbstractObserver::taskDeliver(new \App\Tasks\ManticoreSyncTask('user_sync', $user->toArray()));

        // 3. user_onboard Hook（通知 appstore 等下游应用）
        \App\Module\Apps::dispatchUserHook($user, 'user_onboard', 'onboard');

        // 绑定
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
    // 完整同步（部门 + 用户预创建 + 成员归属）
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
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Services/WecomOrgSyncService.php
git commit -m "feat(wecom): add organization sync service"
```

---

## Task 9: 组织同步控制器接口

**Files:**
- Modify: `app/Http/Controllers/Api/WecomController.php`

- [ ] **Step 1: 在 WecomController 中追加组织同步接口**

在 `WecomController` 类的最后（`status()` 方法之后）追加：

```php
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

        // [FIX] 组织同步是耗时操作（遍历所有部门+成员+创建群），
        // 在 HTTP 请求中同步执行可能超时。
        // DooTask 使用 LaravelS/Swoole，异步任务必须用 Swoole Task（app/Tasks/），
        // 不能用 Laravel Queue（CLAUDE.md 规范）。
        // 但首版先同步执行，原因：
        //   1. Task 需要继承 AbstractTask + 注册到 TaskWorker，改动较大
        //   2. 首次同步通常部门/成员不多（几十到几百），30秒内能完成
        //   3. 后续版本改为 WecomOrgSyncTask 异步执行 + 前端轮询进度
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
            'wecom_org_sync' => ($setting['wecom_org_sync'] ?? 'close') === 'open',
            'department_mappings' => \App\Models\WecomDepartmentMapping::where('wecom_corp_id', $corpId)->count(),
            'user_bindings' => UserWecomBinding::where('wecom_corp_id', $corpId)->count(),
            'total_users' => User::count(),
            'total_departments' => \App\Models\UserDepartment::count(),
        ]);
    }
```

- [ ] **Step 2: Commit**

```bash
git add app/Http/Controllers/Api/WecomController.php
git commit -m "feat(wecom): add org sync admin endpoints"
```

---

## Task 10: 企微管理后台配置指南

无代码改动，但需要在企微管理后台完成以下配置才能让代码跑通。

- [ ] **Step 1: 确认自建应用配置**

```
企业微信管理后台 (work.weixin.qq.com/wework_admin)
  → 应用管理 → 自建应用（DooTask）

  配置项：
  ├── 应用主页：https://{你的DooTask域名}/api/wecom/entry
  ├── 可信域名：{你的DooTask域名}（需 ICP 备案 + 域名验证）
  ├── 网页授权及 JS-SDK → 可信域名：{你的DooTask域名}
  └── 记录：CorpID / AgentId / Secret
```

- [ ] **Step 2: 开启通讯录同步（用于组织同步 + 获取成员完整信息）**

```
管理后台 → 管理工具 → 通讯录同步
  → 开启 API 接口同步
  → 记录：通讯录同步 Secret
```

- [ ] **Step 3: 在 DooTask 管理后台填入配置**

```
DooTask → 系统设置 → 第三方帐号
  → wecom_open: open
  → wecom_corp_id: {CorpID}
  → wecom_agent_id: {AgentId}
  → wecom_secret: {应用 Secret}
  → wecom_contact_secret: {通讯录同步 Secret}
  → wecom_auto_reg: open
  → wecom_org_sync: open
```

---

## Task 11: 前端 login.vue — ticket exchange + wecom_error 显示

> **[Round-2 新增]** 多专家审查发现：原方案声明"无需额外前端改动"不成立。
> ticket 机制（P0-1/2 修复）需要前端配合 exchange，wecom_error 前端零处理（P0-3）。

**Files:**
- Modify: `resources/assets/js/pages/login.vue`

- [ ] **Step 1: 在 login.vue mounted() 中添加企微 ticket exchange + 错误展示**

```js
// resources/assets/js/pages/login.vue — mounted() 中追加：

// ── 企微 OAuth 回调处理 ──
const urlParams = $A.urlParameterAll();

// 企微错误展示
if (urlParams.wecom_error) {
    $A.modalError(decodeURIComponent(urlParams.wecom_error), {language: false});
    // 清理 URL 中的错误参数
    const cleanUrl = window.location.href.replace(/[?&]wecom_error=[^&]*/, '');
    window.history.replaceState(null, '', cleanUrl);
}

// 企微 ticket 换 token
if (urlParams.wecom_ticket) {
    this.$store.dispatch("call", {
        url: "wecom/exchange",
        data: { ticket: urlParams.wecom_ticket },
    }).then(({data}) => {
        if (data.userid > 0 && data.token) {
            // 写入与 urlParameterAll token 解析相同的存储路径
            window.localStorage.setItem("__system:userToken__", data.token);
            window.localStorage.setItem("__system:userId__", data.userid);
            this.$store.commit("setUserInfo", {userid: data.userid, token: data.token});
            window.location.href = "/";
        }
    }).catch(({msg}) => {
        $A.modalError(msg || "企微登录失败", {language: false});
    });
    // 清理 URL 中的 ticket
    const cleanUrl = window.location.href.replace(/[?&]wecom_ticket=[^&]*/, '');
    window.history.replaceState(null, '', cleanUrl);
}
```

说明：
- `{language: false}` 告诉 `$A.modalError` 不再二次翻译（后端已用 `Doo::translate()` 翻译过）
- `store.dispatch("call", ...)` 是 DooTask 标准 API 调用方式（非 axios 直调）
- 写入 localStorage + Vuex store 后 `location.href = "/"` 跳转首页

- [ ] **Step 2: 追加 i18n 原文到 language/original-api.txt**

```bash
cat >> language/original-api.txt << 'EOF'
企业微信登录未开启
授权失败：未获取到 code
授权失败：state 验证失败
授权失败
非企业成员，无法登录
账号已停用或不存在
未绑定 DooTask 账号，且未开启自动注册
注册失败
企微用户创建失败
组织同步未开启
同步完成
创建用户失败
ticket 不能为空
ticket 无效或已过期
EOF
```

- [ ] **Step 3: Commit**

```bash
git add resources/assets/js/pages/login.vue language/original-api.txt
git commit -m "feat(wecom): add ticket exchange and wecom_error handling in login page"
```

---

## 部署实施（首次部署 192.168.100.30）

### 服务器现状

| 项 | 值 |
|---|---|
| 服务器 | 192.168.100.30 (Ubuntu, root, SSH key `~/.ssh/bt_key`) |
| 磁盘 | 1TB 总量，674GB 可用 |
| 内存 | 94GB 总量，78GB 可用 |
| Docker | 29.1.1 + Compose v2.40.3 |
| 端口 2222 | 空闲 |
| 现有 DooTask | 无（`/opt/dootask` 不存在） |

### 端口规划

| DooTask 服务 | 容器内端口 | 主机映射 | 说明 |
|-------------|-----------|---------|------|
| nginx (Web 入口) | 80 | **2222:80** | 唯一外部端口 |
| php (Swoole) | 20000 | 无 | nginx 容器内反代 |
| MariaDB | 3306 | 无 | Docker 内部网络，与宝塔 MySQL 无冲突 |
| Redis | 6379 | 无 | Docker 内部网络 |
| appstore | 80 | 无 | Docker 内部网络 |

### 外部访问方案

| 方案 | URL | 说明 |
|------|-----|------|
| **A: 直连端口（推荐）** | `http://192.168.100.30:2222` | 最简单可靠 |
| B: 宝塔反代（子路径） | `https://main.smee-china.com/dootask/` | 需 `./cmd https agent`，子路径支持有限 |
| C: 子域名 | `dootask.smee-china.com` | 最干净，需 DNS 配置 |

---

## Task 12: 克隆源码 + 一键安装

**目标：** 将 DooTask pro 分支部署到 `/opt/dootask`，通过 `./cmd install` 完成全部初始化

**Files:** 无代码改动，服务器操作

- [ ] **Step 1: SSH 克隆源码**

```bash
SSH="ssh -o StrictHostKeyChecking=no -i ~/.ssh/bt_key root@192.168.100.30"

$SSH "cd /opt && git clone --depth=1 -b pro https://github.com/kuaifan/dootask.git"
```

- [ ] **Step 2: 执行安装（指定端口 2222）**

```bash
$SSH "cd /opt/dootask && chmod +x cmd && ./cmd install --port 2222"
```

自动执行：`.env` 生成 → `APP_ID`/`DB_ROOT_PASSWORD` 随机生成 → 启动容器 → `composer install` → `migrate --seed` → 全部 5 容器启动。

预期输出末尾：`[OK] 安装完成` + `地址: http://127.0.0.1:2222`

> ⚠️ 首次安装需拉取 Docker 镜像 + composer install，预计 5-15 分钟。超时则后台执行：
> ```bash
> $SSH "cd /opt/dootask && nohup ./cmd install --port 2222 > /tmp/dootask-install.log 2>&1 &"
> $SSH "tail -f /tmp/dootask-install.log"
> ```

- [ ] **Step 3: 验证容器状态**

```bash
$SSH "docker ps --filter 'name=dootask' --format 'table {{.Names}}\t{{.Status}}\t{{.Ports}}'"
```

预期：5 个容器全部 `Up (healthy)`，nginx 映射 `0.0.0.0:2222->80/tcp`

- [ ] **Step 4: 验证 Web 访问**

```bash
$SSH "curl -s -o /dev/null -w '%{http_code}' http://localhost:2222/"
```

预期：`200`

---

## Task 13: 初始配置 — 访问地址 + 管理员密码

**Files:** 无代码改动，服务器操作

- [ ] **Step 1: 设置 APP_URL**

方案 A（直连）:
```bash
$SSH "cd /opt/dootask && ./cmd env APP_URL http://192.168.100.30:2222"
```

- [ ] **Step 2: 验证 .env**

```bash
$SSH "grep -E '^(APP_URL|APP_PORT|APP_ID|TIMEZONE)' /opt/dootask/.env"
```

- [ ] **Step 3: 重置管理员密码**

```bash
$SSH "cd /opt/dootask && ./cmd repassword"
```

默认管理员：`admin@admin.com`，按提示设置新密码。

- [ ] **Step 4: 浏览器验证登录**

打开 `http://192.168.100.30:2222`，使用 `admin@admin.com` + 新密码登录，确认进入主界面。

---

## Task 14: 宝塔 Nginx 反代（可选，方案 B）

> 选择方案 A（直连端口）则跳过此 Task。

- [ ] **Step 1: DooTask 端开启反代模式**

```bash
$SSH "cd /opt/dootask && ./cmd https agent"
```

- [ ] **Step 2: 宝塔面板添加 Nginx location**

编辑 `main.smee-china.com` 站点配置，在 `location /` 之前添加：

```nginx
# DooTask 任务管理
location ^~ /dootask/ {
    proxy_pass http://192.168.100.30:2222/;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-Host $host/dootask;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;

    # WebSocket
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection $connection_upgrade;
    proxy_read_timeout 86400s;
    proxy_send_timeout 86400s;
    proxy_connect_timeout 60s;

    client_max_body_size 1024M;
}
```

- [ ] **Step 3: 重载 Nginx 并验证**

```bash
ssh -i ~/.ssh/bt_key root@192.168.100.240 "nginx -t && nginx -s reload"
curl -s -o /dev/null -w '%{http_code}' https://main.smee-china.com/dootask/
```

预期：`200`。如果静态资源 404，退回方案 A 或改用子域名方案 C。

---

## Task 15: AI 助手配置（可选）

- [ ] **Step 1: DooTask 管理后台 → 系统设置 → AI 助手**

| Provider | 配置项 | 说明 |
|----------|--------|------|
| OpenAI/兼容 | API Key + Base URL | ChatGPT 或兼容 API |
| DeepSeek | API Key | 国产大模型，性价比最高 |
| Ollama | Base URL (`http://localhost:11434`) | 本地部署，数据不出内网 |

- [ ] **Step 2: 验证 AI 功能**

任意项目群聊中 `@AI` + 问题，确认响应。

---

## 日常运维速查

```bash
SSH="ssh -o StrictHostKeyChecking=no -i ~/.ssh/bt_key root@192.168.100.30"

# 状态检查
$SSH "docker ps --filter 'name=dootask' --format 'table {{.Names}}\t{{.Status}}'"
$SSH "curl -s -o /dev/null -w '%{http_code}' http://localhost:2222/"

# 更新
$SSH "cd /opt/dootask && ./cmd update"

# 停止/启动/重启
$SSH "cd /opt/dootask && ./cmd down"
$SSH "cd /opt/dootask && ./cmd up"
$SSH "cd /opt/dootask && ./cmd restart"

# 数据库备份/还原
$SSH "cd /opt/dootask && ./cmd mysql backup"
$SSH "cd /opt/dootask && ./cmd mysql recovery"

# 查看日志
$SSH "docker logs dootask-php-\$(grep APP_ID /opt/dootask/.env | cut -d= -f2) --tail 50"
$SSH "docker logs dootask-nginx-\$(grep APP_ID /opt/dootask/.env | cut -d= -f2) --tail 50"

# 完全重建 / 更换端口
$SSH "cd /opt/dootask && ./cmd reup"
$SSH "cd /opt/dootask && ./cmd port 新端口"
```

## 回滚方案

```bash
# 备份后完全卸载
$SSH "cd /opt/dootask && ./cmd mysql backup"
$SSH "cd /opt/dootask && ./cmd uninstall"
$SSH "rm -rf /opt/dootask"

# 仅停止（不删数据，不占 CPU/内存）
$SSH "cd /opt/dootask && ./cmd down"
```

---

## 完整流程验证清单

### 部署验证

```
1. 浏览器访问 http://192.168.100.30:2222
2. 预期：DooTask 登录页正常加载
3. 使用 admin@admin.com + 新密码登录成功
4. 检查：5 个 Docker 容器全部 Up (healthy)
5. WebSocket 连通：群聊消息实时收发
```

### 静默登录测试

```
1. 在企微中打开 DooTask 应用
2. 预期：0.5秒内自动跳转到 DooTask 首页，已登录
3. 检查：user_wecom_bindings 表有新记录
4. 检查：users 表有新用户（identity 含 'wecom'）
5. 再次打开：直接进入（token 有效期内不走 OAuth）
```

### 组织同步测试

```
1. 调用 POST /api/wecom/org/sync（管理员身份）
2. 预期：返回 departments.created > 0, users_created.created > 0
3. 检查：user_departments 表有企微部门，且每个部门有 dialog_id（聊天群）
4. 检查：wecom_department_mappings 表有映射记录
5. 检查：users 表有批量预创建的用户
6. 检查：user_wecom_bindings 表有对应绑定记录
7. 检查：已绑定用户的 users.department 已更新
```

---

## 与 WeKnora 的用户互通

DooTask 和 WeKnora（weknora-ui）同属一个企业微信，是两个独立的自建应用。
**两个应用各自独立做 OAuth 静默登录，不需要 SSO 网关，不需要共享 session。**

### 互通标识

同一个企微成员在两个系统中通过 `wecom_userid` 关联：

| 系统 | 表 | 字段 | 值（示例） |
|------|---|------|----------|
| DooTask | `user_wecom_bindings` | `wecom_userid` | `zhangsan` |
| WeKnora | `oauth_bindings` | `provider_user_id` | `zhangsan` |

已验证：WeKnora 的 `oauth_bindings` 表和 `provider_user_id` 字段真实存在（`WeKnora/migrations/versioned/000014_wechat_auth.up.sql:35`，Go 结构体 `internal/types/wechat.go:52`）。

### 当前不需要做的

- weknora-ui 不需要任何修改
- 不需要组织同步到 WeKnora（WeKnora 暂不需要组织）
- 不需要跨系统 API 网关或认证互信

### 未来跨系统查询（备忘）

如果后续 AgentStudio AI 需要跨系统操作（如在 A2A Chat 中查 DooTask 任务），可通过 `wecom_userid` 做身份翻译：
- AgentStudio 新增 `DOOTASK_DB_*` 直连配置（与已有的 `WEKNORA_DB_*` 模式一致）
- 或 DooTask 提供 `api/wecom/user/lookup?wecom_userid=X` 内部查询 API

---

## 方案总结

| 维度 | 说明 |
|------|------|
| **新增文件** | 7 个（2 迁移 + 2 模型 + 2 服务 + 1 控制器）|
| **修改文件** | 3 个（`web.php` 加 2 行路由 + `SystemController.php` 加白名单 + `login.vue` 加 ticket exchange）|
| **侵入性** | 极低，不改动任何现有认证逻辑 |
| **依赖** | 零新依赖，使用 Laravel 内置 Cache + `Module/Ihttp.php` |
| **参照模式** | LDAP 集成（`LdapUser::userLogin` + `thirdAccessSetting`）|
| **数据库** | 新增 2 张表，不改现有表结构 |
| **前端** | `login.vue` 新增 ticket exchange + wecom_error 展示（Task 11）|
| **weknora-ui** | 不需要修改，通过 `wecom_userid` 天然互通 |
| **部署** | 192.168.100.30:2222，Docker 5 容器，`./cmd install` 一键安装 |

### 审查修复记录（2026-04-16）

| # | 问题 | 修复 | 验证状态 |
|---|------|------|---------|
| 1 | `User::reg()` 密码策略/邮箱校验会阻断静默注册 | 改用 `Doo::userCreate()` 绕过 `passwordPolicy()`。`Doo::userCreate` 是 `reg()` 的底层方法（`DooSo.php:193`），C FFI 直接写 DB | ✅ 验证: `User.php:396` 调 `Doo::userCreate`，`DooSo.php:193-211` 确认 |
| 2 | `generateToken` 返回值取法有 PHP 8 deprecation 风险 | 直接用返回值 `$token = User::generateToken($user)` | ✅ 验证: `User.php:553` return `$userinfo->token = $token` |
| 3 | callback 错误返回 JSON（用户在 WebView 看到乱码）| 全部改为 `redirect("/#/login?wecom_error=...")` | ✅ 验证: `urlParameterAll` 解析 hash query |
| 4 | `thirdAccessSetting` save 白名单缺企微字段（配置存不进去）| 扩展白名单加 7 个 `wecom_*` 字段 | ✅ 验证: `SystemController.php:608-616` 白名单过滤 |
| 5 | redirect 只传 token 缺 userid（前端需要两者才生效）| ~~redirect 改为 `/#/?userid={}&token={}`~~ → **Round-2 重新修复：改用一次性 ticket，不在 URL 传 token** | ✅→🔄 Round-2 P0-1/2 |
| 6 | 部门创建缺 `dialog_id`（没聊天群）| 改用 `saveDepartment()` 自动创建群 | ✅ 验证: `UserDepartment.php:106` `createGroup`。注意: `owner_userid=0` 时群无成员但不报错 |
| 7 | 首次同步 leader 绑定不存在（owner 全为 0）| 加注释说明，接受首次为 0，增量同步回填 | ✅ 验证: `createGroup` 第 856 行 `if ($value > 0)` 跳过 0 |
| 8 | 只更新已绑定用户，无法批量导入 | 新增 `syncUsers()` 按部门批量预创建用户 | ✅ 新增方法 |
| 9 | 缺与 WeKnora 的互通说明 | 新增章节，`wecom_userid` 天然互通 | ✅ 验证: `oauth_bindings.provider_user_id` 存在 |

### 多维度代码事实验证（2026-04-16 审查补充）

| 维度 | 检查项 | 结果 | 源码依据 |
|------|--------|------|---------|
| **PHP/注册** | `Doo::userCreate` 是否可能内部校验失败 | ⚠️ C FFI 黑盒，失败抛 `ApiException($data['msg'])` | `DooSo.php:196-197` |
| **PHP/注册** | `email_verity` 字段名（非 `verify`）| ✅ 拼写正确 | `User.php:43` `@property int\|null $email_verity` |
| **PHP/注册** | `Base::getFirstCharter` / `cn2pinyin` 存在 | ✅ | `Base.php:2590`, `Base.php:2612` |
| **PHP/注册** | `AbstractObserver::taskDeliver` + `ManticoreSyncTask` | ✅ `User::reg()` 第 421 行同样调用 | `User.php:421` |
| **部门** | `saveDepartment($data, 0)` owner_userid=0 是否报错 | ✅ 不报错，但群无成员 | `createGroup` 第 856 行 `if ($value > 0)` 跳过 |
| **部门** | `AbstractModel` 是否软删除 | ✅ 无 SoftDeletes，硬删除 | `AbstractModel.php:30` extends Model，无 use SoftDeletes |
| **部门** | `users.department` 有 accessor 返回 int[] | ✅ 已修复比较逻辑，用数组比较替代字符串比较 | `User.php:148-154` getDepartmentAttribute |
| **安全** | Cache driver 原子性 | ✅ Docker 用 Redis | `.env.docker:30` `CACHE_DRIVER=redis` |
| **安全** | `isEmail("x@wecom.local")` 是否通过 | ✅ `FILTER_VALIDATE_EMAIL` 接受 `.local` | `Base.php:1035-1042` |
| **安全** | redirect hash fragment 不泄露到 Referer | ✅ `#` 后内容不发送到服务器 | HTTP 规范 |
| **前端** | 已有 URL token 解析 | ⚠️ **Round-2 已改为 ticket 机制，需 login.vue 前端改动** | Task 11 |
| **前端** | 需要同时传 userid + token | 🔄 **改为 ticket exchange，不再直接传 token** | Task 11 |
| **前端** | `$A` 全局对象存在 | ✅ | `common.js:2581` `window.$A = $` |
| **互通** | WeKnora `oauth_bindings.provider_user_id` 存在 | ✅ | `000014_wechat_auth.up.sql:35`, `types/wechat.go:52` |
| **Swoole** | `WecomApiClient` token 缓存用 `Cache`，非静态属性 | ✅ 符合 Swoole 规范 | 方案中 `Cache::remember` |
| **Swoole** | 组织同步应用 Swoole Task 异步执行 | ⚠️ 首版同步，已标注后续改 Task | `CLAUDE.md: 异步任务使用 Swoole Task` |
| **国际化** | 新增中文文本需加入翻译文件 | ⚠️ 需实施时补充 | 见下方国际化清单 |

### CLAUDE.md 合规性检查（2026-04-16）

| 规范 | 方案合规性 | 说明 |
|------|-----------|------|
| LaravelS/Swoole: 不存请求级状态到静态属性 | ✅ | `WecomApiClient` 每次 new，token 存 `Cache` 非静态属性 |
| 路由: `Route::any('{resource}/{method}')` 模式 | ✅ | `wecom/{method}` + `wecom/{method}/{action}` 完全遵循 |
| 响应: `Base::retSuccess` / `Base::retError` | ✅ | API 接口全部使用 |
| 异常: `ApiException` | ✅ | 业务异常全部用 `ApiException` |
| 模型: `createInstance` 创建 | ✅ | 绑定/映射/部门全部用 `createInstance` |
| 认证: `Doo::userId()` / `User::auth()` | ✅ | entry 用 `Doo::userId()`，admin 接口用 `User::auth('admin')` |
| 异步: Swoole Task 而非 Laravel Queue | ⚠️ 首版同步 | 已标注后续版本改为 `WecomOrgSyncTask` |
| 国际化: 中文原文加入翻译文件 | ✅ Round-2 已修复 | Task 11 Step 2 追加到 `original-api.txt` + 代码用 `Doo::translate()` 包装 |
| 表结构: 必须通过 migration | ✅ | 2 个 migration 文件 |

### 国际化：需追加到 `language/original-api.txt` 的文本

实施时将以下中文原文追加到 `language/original-api.txt`（去重）：

```
企业微信登录未开启
授权失败：未获取到 code
授权失败：state 验证失败
授权失败
非企业成员，无法登录
账号已停用或不存在
未绑定 DooTask 账号，且未开启自动注册
注册失败
企微用户创建失败
组织同步未开启
同步完成
创建用户失败
ticket 不能为空
ticket 无效或已过期
```

### Round-2 多专家审查修复记录（2026-04-16）

> 5 专家并行审查（认证/路由/部门/前端/安全），50 项源码事实逐一核验，39 ✅ / 8 ⚠️ / 3 ❌。

| # | 级别 | 问题 | 修复 | 状态 |
|---|------|------|------|------|
| R2-1 | **P0** | Token 30 天有效，通过 URL `/#/?userid=X&token=Y` 明文暴露在浏览器历史/地址栏/截屏 | 改用一次性 ticket：`Cache::put` 60秒 → redirect 仅带 ticket → 前端 POST `/api/wecom/exchange` 换 token | ✅ 已修复 Task 7+11 |
| R2-2 | **P0** | `common.js:664 removeURLParameter` 用 `URL.searchParams.delete()`，不操作 hash query → 生产环境 URL 清理失效 | 与 R2-1 一起修复：不再通过 URL 传 token | ✅ 已修复（ticket 无需清理，60秒自毁） |
| R2-3 | **P0** | `login.vue` 无 `wecom_error` 处理逻辑（`Grep wecom_error` 前端零结果），用户看到空白登录页 | 新增 Task 11：`login.vue mounted()` 读取 `wecom_error` + `$A.modalError()` 展示 | ✅ 已修复 Task 11 |
| R2-4 | **P0** | `syncDepartments` catch 后 `->save()` 写入 `dialog_id=0` 的僵尸部门，违反部门必有群的隐式契约 | catch 后改为 `$stats['errors']++; continue;` 跳过此部门，下次同步重试 | ✅ 已修复 Task 8 |
| R2-5 | P1 | 错误消息中文硬编码，未走 `Doo::translate()`，多语言用户看到中文 | 所有 redirect 中 urlencode 的字符串用 `Doo::translate()` 包装 | ✅ 已修复 Task 7 |
| R2-6 | P1 | `entry()` 缺 try/catch，`getWecomSetting()` 未配置时抛 ApiException → 用户看到 JSON | 加 try/catch，redirect 到 `/#/login?wecom_error=...` | ✅ 已修复 Task 7 |
| R2-7 | P1 | `wecom_contact_secret` 明文存 `settings` 表（LDAP 密码也是明文，但企微 secret 风险更大） | **待修复**：建议在 `setting__thirdaccess` save 时用 `Crypt::encryptString()` 加密 `wecom_secret`/`wecom_contact_secret`，读取时 `Crypt::decryptString()` | ⏳ 建议实施时处理 |
| R2-8 | P1 | 组织同步同步执行违反 `CLAUDE.md` "异步任务必须用 Swoole Task" 规范，>100 部门会超时 | **待修复**：创建 `app/Tasks/WecomOrgSyncTask.php` 继承 `AbstractTask`，HTTP 接口返回 task_id，前端轮询进度 | ⏳ 建议实施时处理 |
| R2-9 | P1 | `Http::get/post`（Laravel HTTP facade）在 DooTask 全项目首次引入，与现有 `Module/Ihttp.php` cURL 风格不一致 | `WecomApiClient` 改用 `Ihttp::ihttp_get/ihttp_request`，移除 `Http` facade import | ✅ 已修复 Task 4 |
| R2-10 | P1 | `doo.so` FFI 黑盒 — `.local` 邮箱和密码是否被接受无法静态验证 | **待验证**：staging 先跑 `Doo::userCreate('test_wecom@dootask.local', 'Test123!@#')` 验证通过再实施 | ⏳ 部署前验证 |
| R2-11 | P2 | `owner_userid=0` 时 `createGroup` 创建无成员空群，部门页 UX 异常 | 建议：延迟创建群或用 system bot 占位。当前"接受首次为 0"的注释仍有效 | 📝 可接受 |
| R2-12 | P2 | `user_departments.name` varchar(100)，超长企微部门名被截断 | 已加 `mb_substr($deptName, 0, 100)` | ✅ 已修复 Task 8 |
| R2-13 | P2 | `saveDepartment` 内嵌 `createGroup` 形成嵌套事务，MySQL savepoint 回滚语义未验证 | 已通过去掉降级 save() 缓解：失败整体跳过，不写半成品 | ✅ 间接修复 |

### Round-3 反问反证审查修复记录（2026-04-16）

> 3 专家魔鬼辩护：silentRegister 副作用完整性、Ihttp 替换正确性、ticket 安全性。

| # | 级别 | 问题 | 修复 | 状态 |
|---|------|------|------|------|
| R3-1 | **P0** | `WecomApiClient` 中 `Ihttp` 返回值用 `['content']`，实际应为 `['data']`（参照 `AI.php:115`）。所有 API 调用 100% 失败 | 三个方法全部改为 `$result['data']` | ✅ 已修复 |
| R3-2 | **P0** | 缺少 `Base::isError($result)` 前置检查，网络失败时 `json_decode([], true)` 产生 PHP Warning + 误导性错误信息 | 三个方法全部加 `Base::isError()` 检查，失败时抛含真实 cURL 错误的 ApiException | ✅ 已修复 |
| R3-3 | **P1** | `syncUsers()` → `createUserFromWecom()` 缺少三个关键副作用：全员群加入、ManticoreSyncTask、user_onboard hook。批量同步的用户搜索不到、不在全员群、appstore 不知道 | 在 `createUserFromWecom()` 的 `$user->save()` 后补全三个副作用 | ✅ 已修复 |
| R3-4 | **P1** | `silentRegister` 和 `createUserFromWecom` 均未执行 `reg_identity='temp'` 策略。当系统配置新用户为临时身份时，企微用户绕过限制直接获得正式权限 | 两条路径均补上 `reg_identity` 检查，与 `User::reg()` 保持一致 | ✅ 已修复 |
| R3-5 | 🛡️ | ticket 机制安全性确认：381 bits 熵不可暴力破解，同源策略阻止跨域读取，60秒+一次性消费有效 | — | ✅ 安全 |
| R3-6 | ⚠️ | `Cache::pull` 非原子（GET+DEL 非 Lua 脚本），理论 double-spend | 风险极低（需同一 ticket 毫秒级并发），可接受 | 📝 已知风险 |
| R3-7 | ⚠️ | `Ihttp` 全局禁用 SSL 验证（`CURLOPT_SSL_VERIFYPEER=false`），比 `Http` facade 安全性低 | DooTask 全局既有行为，非本次引入。与项目风格一致的代价 | 📝 已知风险 |
| R3-8 | ⚠️ | exchange 端点无 rate limit（DoS 向量） | 建议给 wecom 路由加 `throttle` middleware，但 ticket 381 bits 熵使暴力枚举不可能 | 📝 建议实施时处理 |
