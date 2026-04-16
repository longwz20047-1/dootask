# DooTask 企微集成 — 执行计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 将 DooTask 改造为企业微信自建应用，实现静默登录、静默注册、组织架构同步，并完成首次部署到 192.168.100.30:2222，通过 192.168.100.240 宝塔 Nginx 反代对外暴露为 `https://main.smee-china.com/dootask/`。

**Architecture:** 在 DooTask 现有认证体系旁新增企微 OAuth 通道（参照 LDAP 集成模式），通过一次性 ticket 机制安全传递 token。组织同步通过管理员手动触发拉取企微通讯录 API 实现。7 个新增文件 + 3 个修改文件，零新依赖。

**Tech Stack:** PHP 8 / Laravel 8 / LaravelS(Swoole) / MariaDB / Vue 2 / 企微 OAuth2 API / 企微通讯录 API

**设计方案（代码源）:** `docs/superpowers/plans/2026-04-15-dootask-wecom-integration.md`
- 经过 4 轮 14 专家审查，37 项问题已修复
- 所有代码块可直接 copy 到源文件

---

## 前置条件

- [ ] Docker Desktop 已启动
- [ ] PHP 8.0+（Docker 容器内自带，本地开发需确认）
- [ ] Node.js 20+（前端编译需要）
- [ ] DooTask 已通过 `./cmd install --port 2222` 安装（见 Phase 4 Task 12）
- [ ] 企微管理后台已创建自建应用（见 Phase 3 Task 10）

---

## Phase 1: 数据层（无依赖，可并行）

### Task 1: 企微绑定表迁移

**Files:** Create `database/migrations/2026_04_15_000001_create_user_wecom_bindings_table.php`

- [ ] 从设计方案 Task 1 复制迁移代码到文件
- [ ] `./cmd artisan migrate` 执行迁移
- [ ] `./cmd artisan migrate:status` 验证表已创建
- [ ] `git add && git commit -m "feat(wecom): add user_wecom_bindings migration"`

### Task 2: 部门映射表迁移

**Files:** Create `database/migrations/2026_04_15_000002_create_wecom_department_mappings_table.php`

- [ ] 从设计方案 Task 2 复制迁移代码到文件
- [ ] `./cmd artisan migrate` 执行迁移
- [ ] 验证 `user_wecom_bindings` 和 `wecom_department_mappings` 两表都存在
- [ ] `git commit -m "feat(wecom): add wecom_department_mappings migration"`

---

## Phase 2: 模型 + 服务层（依赖 Phase 1）

### Task 3: Eloquent 模型

**Files:**
- Create `app/Models/UserWecomBinding.php`
- Create `app/Models/WecomDepartmentMapping.php`

- [ ] 从设计方案 Task 3 复制两个模型代码
- [ ] 关键方法验证：`UserWecomBinding::findByWecom()`, `WecomDepartmentMapping::getDooTaskDeptId()`
- [ ] `git commit -m "feat(wecom): add UserWecomBinding and WecomDepartmentMapping models"`

### Task 4: 企微 API 客户端

**Files:** Create `app/Services/WecomApiClient.php`

- [ ] 从设计方案 Task 4 复制代码
- [ ] **关键检查点（Round-3 修复）：**
  - `Ihttp::ihttp_get($url)` 返回值用 `$result['data']`（不是 `['content']`）
  - 每个方法都有 `Base::isError($result)` 前置检查
  - import 使用 `App\Module\Ihttp`（不是 `Illuminate\Support\Facades\Http`）
- [ ] `git commit -m "feat(wecom): add WecomApiClient with Ihttp integration"`

### Task 5: SystemController 配置扩展

**Files:** Modify `app/Http/Controllers/Api/SystemController.php`

- [ ] 在 `setting__thirdaccess()` 方法的白名单数组中追加 7 个 `wecom_*` 字段
- [ ] 在默认值数组中追加对应默认值
- [ ] **验证：** 修改行数约 17 行（7 白名单 + 7 默认值 + 3 注释），不改动任何现有逻辑
- [ ] `git commit -m "feat(wecom): extend thirdAccessSetting whitelist for wecom config"`

### Task 6: 路由注册

**Files:** Modify `routes/web.php`

- [ ] 在路由组内追加 2 行（与 web.php 现有路由格式一致，使用 class 直接引用）：
  ```php
  // 企业微信
  Route::any('wecom/{method}',                    \App\Http\Controllers\Api\WecomController::class);
  Route::any('wecom/{method}/{action}',           \App\Http\Controllers\Api\WecomController::class);
  ```
- [ ] `git commit -m "feat(wecom): add wecom routes"`

---

## Phase 3: 控制器 + 前端（依赖 Phase 2）

### Task 7: WecomController — OAuth + ticket exchange

**Files:** Create `app/Http/Controllers/Api/WecomController.php`

- [ ] 从设计方案 Task 7 复制完整控制器代码
- [ ] **关键检查点（5 轮审查修复）：**
  - `entry()` 有 try/catch 包裹 `getWecomSetting()`（Round-2 P1-9）
  - `callback()` 用 ticket 机制，不在 URL 传 token（Round-2 P0-1/2）
  - `exchange()` 方法存在，用 `Cache::pull` 一次性消费 ticket
  - `silentRegister()` 用 try/catch `QueryException` 防并发（Round-5 修复，替代 Cache::lock）
  - `doSilentRegister()` 中命名空间正确：`\App\Observers\AbstractObserver`，`\App\Module\Apps`（Round-5 修复）
  - 所有错误消息用 `Doo::translate()` 包装（Round-2 P1-5）
  - `reg_identity='temp'` 用 `Base::arrayImplode()`（Round-5 修复，与 User::reg() 一致）
  - 全员群加入 + ManticoreSyncTask + user_onboard hook 三个副作用完整
- [ ] `git commit -m "feat(wecom): add WecomController with OAuth, ticket exchange, silent registration"`

### Task 8: WecomOrgSyncService — 组织同步

**Files:** Create `app/Services/WecomOrgSyncService.php`

- [ ] 从设计方案 Task 8 复制完整服务代码
- [ ] **关键检查点：**
  - `syncDepartments()` 用 BFS 层序遍历排序（Round-4 R4-3），不是 usort
  - catch 后 `continue` 跳过失败部门，不降级 save（Round-2 P0-4）
  - 部门名 `mb_substr($deptName, 0, 100)` 截断（Round-2 P2-12）
  - `createUserFromWecom()` 有全员群 + ManticoreSyncTask + user_onboard（Round-3 R3-3）
  - `createUserFromWecom()` 有 `reg_identity` 策略（Round-3 R3-4）
  - 所有 `Log::warning` 已改为 `Log::info`（Round-4 R4-4）
- [ ] `git commit -m "feat(wecom): add WecomOrgSyncService with BFS dept sync and user provisioning"`

### Task 9: WecomController — 组织同步管理接口

**Files:** Modify `app/Http/Controllers/Api/WecomController.php`

- [ ] 从设计方案 Task 9 复制 `org__sync()` 和 `org__status()` 方法，追加到 WecomController 类末尾
- [ ] 确认两个方法都有 `User::auth('admin')` 权限检查
- [ ] `git commit -m "feat(wecom): add org sync admin endpoints"`

### Task 10: 企微管理后台配置

- [ ] 按设计方案 Task 10 在企微管理后台创建自建应用
- [ ] 应用主页填 `https://main.smee-china.com/dootask/api/wecom/entry`
- [ ] 可信域名填 `main.smee-china.com`（与 WeKnora OAuth 共用）
- [ ] 记录 CorpID / AgentId / Secret / 通讯录同步 Secret
- [ ] 在 DooTask 管理后台（`https://main.smee-china.com/dootask/`）→ 系统设置 → 第三方帐号 填入配置

### Task 11: 前端 login.vue — ticket exchange + wecom_error

**Files:**
- Modify `resources/assets/js/pages/login.vue`
- Modify `language/original-api.txt`

- [ ] 在 `login.vue` 的 `mounted()` 中添加企微回调处理代码
- [ ] **关键检查点（Round-5 修复）：**
  - `$A.modalError({content: msg, language: false})`（对象形式，不是两参数）
  - `store.dispatch("call", {url: "wecom/exchange", data: {ticket}})` 标准调用
  - 成功后用 `handleClearCache(data).then(this.goNext)`（与 QR 码/账号登录一致）
  - ❌ 不要用 `commit("setUserInfo")` — mutations.js 中不存在
  - ❌ 不要手动 `localStorage.setItem` — `handleClearCache` 内部统一处理
  - URL 清理用 `window.history.replaceState`
- [ ] 追加 17 条中文原文到 `language/original-api.txt`（Round-5 新增 3 条 API 错误消息）
- [ ] `git commit -m "feat(wecom): add ticket exchange and wecom_error handling in login page"`

---

## Phase 4: 部署（依赖 Phase 1-3 代码完成）

### Task 12: 克隆源码 + 一键安装

```bash
SSH="ssh -o StrictHostKeyChecking=no -i ~/.ssh/bt_key root@192.168.100.30"
```

- [ ] `$SSH "cd /opt && git clone --depth=1 -b pro https://github.com/kuaifan/dootask.git"`
- [ ] `$SSH "cd /opt/dootask && chmod +x cmd && ./cmd install --port 2222"`
- [ ] 验证 5 个容器全部 `Up (healthy)`
- [ ] `curl -s -o /dev/null -w '%{http_code}' http://192.168.100.30:2222/` → 预期 `200`
- [ ] **FFI 黑盒验证（Round-2 R2-10）：** 企微虚拟邮箱能否通过 `doo.so` 校验
  ```bash
  $SSH "cd /opt/dootask && ./cmd php artisan tinker --execute=\"var_dump(\App\Module\Doo::userCreate('test_wecom_001@dootask.local', 'TestPass123!@#456'));\""
  ```
  预期：返回 User 对象（非 null/异常）。验证后删除测试用户：
  ```bash
  $SSH "cd /opt/dootask && ./cmd php artisan tinker --execute=\"\App\Models\User::whereEmail('test_wecom_001@dootask.local')->first()?->delete();\""
  ```
  如果 FFI 拒绝 `.local` 邮箱，需改用 `wecom_{userid}@wecom.dootask.com` 格式，并同步修改设计方案中的邮箱生成逻辑。

### Task 13: 初始配置 + 反代模式

- [ ] `$SSH "cd /opt/dootask && ./cmd env APP_URL https://main.smee-china.com/dootask"`
- [ ] `$SSH "cd /opt/dootask && ./cmd https agent"` 开启反代模式
- [ ] `$SSH "cd /opt/dootask && ./cmd repassword"` 重置管理员密码
- [ ] 直连验证: `$SSH "curl -s -o /dev/null -w '%{http_code}' http://localhost:2222/"` → `200`

### Task 14: 宝塔 Nginx 反代（192.168.100.240，必做）

> 反代服务器是 192.168.100.240（宝塔 Nginx），不是发布服务器 192.168.100.30。
> 参考 `server-deployment-guide.md` §5，在 `main.smee-china.com` 站点配置中添加 `/dootask/` location。

- [ ] 在 192.168.100.240 宝塔面板 `main.smee-china.com` 站点配置中添加 `/dootask/` location（见设计方案 Task 14）
- [ ] `ssh -i ~/.ssh/bt_key root@192.168.100.240 "nginx -t && nginx -s reload"`
- [ ] `curl -s -o /dev/null -w '%{http_code}' https://main.smee-china.com/dootask/` → `200`
- [ ] 浏览器访问 `https://main.smee-china.com/dootask/`，登录验证 + WebSocket 消息验证

### Task 15: AI 助手配置（可选）

- [ ] DooTask 管理后台 → 系统设置 → AI 助手
- [ ] 配置 API Key + Base URL
- [ ] 群聊中 `@AI` 验证响应

---

## Phase 5: 集成验证

### 验证清单

- [ ] **部署验证：** 浏览器访问 `https://main.smee-china.com/dootask/`，登录成功
- [ ] **静默登录：** 企微工作台点击应用 → 自动跳转 DooTask 首页（已登录）
- [ ] **静默注册：** 新企微用户首次点击 → 自动创建 DooTask 账号 + 登录
- [ ] **ticket 安全：** 浏览器地址栏不出现 token，仅短暂出现 ticket
- [ ] **错误展示：** 未配置时点击企微登录 → login 页面弹出错误提示
- [ ] **组织同步：** 管理员调用 `POST /api/wecom/org/sync` → 部门和用户同步成功
- [ ] **搜索可见：** 企微同步的用户在 DooTask 搜索中可找到
- [ ] **全员群：** 企微同步的用户自动加入全员群
- [ ] **WebSocket：** 群聊消息实时收发正常

---

## FFI 黑盒验证（部署后第一件事）

在执行 Phase 3 代码部署前，必须先在 staging 验证 `doo.so` FFI 是否接受虚拟邮箱：

```bash
$SSH "cd /opt/dootask && ./cmd php artisan tinker --execute=\"
  var_dump(\App\Module\Doo::userCreate('test_wecom_001@dootask.local', 'TestPass123!@#456'));
\""
```

- 如果返回 User 对象 → ✅ 继续实施
- 如果抛异常 → 需要改用真实域名邮箱格式（如 `wecom_xxx@{corpid}.wecom.work`）

---

## 3 人注册限制验证（FFI 验证之后执行）

验证 `doo.so` 对 `disable_at` 用户的计数行为，决定回收策略。详见设计方案"3 人注册限制突破方案"章节。

- [ ] 确保系统有 admin + 2 个普通用户 = 3 人（满额状态）
- [ ] 实验 1: 停用一个用户 (`disable_at = now()`) 后调 `Doo::userCreate()`
  - SUCCESS → `doo.so` 排除 disable 用户 → 回收策略用 soft-disable
  - FAILED → 进入实验 2
- [ ] 实验 2: 硬删除一个用户 (`forceDelete()`) 后调 `Doo::userCreate()`
  - SUCCESS → 回收策略用 `forceDelete`
  - FAILED → `doo.so` 有其他计数机制，需要排查或购买 License
- [ ] 清理测试数据，恢复被停用的用户
- [ ] 根据实验结果，在设计方案的 `recycleQuota()` 中选择对应的删除方式

---

## 待实施项（非阻断，后续版本）

| # | 内容 | 来源 |
|---|------|------|
| 1 | `wecom_secret` / `wecom_contact_secret` 加密存储（`Crypt::encryptString`）| Round-2 R2-7 |
| 2 | 组织同步改 Swoole Task 异步执行 | Round-2 R2-8 |
| 3 | 删除部门处理逻辑（增量同步清理僵尸映射）| Round-4 R4-6 |
| 4 | exchange 端点加 `throttle` middleware | Round-3 R3-8 |

---

## 回滚方案

### 代码回滚（企微集成代码有问题）

```bash
cd D:/workspace/agent-weknora/dootask
git log --oneline -10                    # 找到企微集成前的 commit
git revert <commit>..HEAD               # 逐个 revert，保留历史
# 或者硬回退（慎用）
git reset --hard <commit-before-wecom>
```

数据库回滚：
```bash
$SSH "cd /opt/dootask && ./cmd artisan migrate:rollback --step=2"
# 回滚最近 2 个迁移（user_wecom_bindings + wecom_department_mappings）
```

### 部署回滚（DooTask 整体有问题）

```bash
SSH="ssh -o StrictHostKeyChecking=no -i ~/.ssh/bt_key root@192.168.100.30"

# 备份后完全卸载
$SSH "cd /opt/dootask && ./cmd mysql backup"
$SSH "cd /opt/dootask && ./cmd uninstall"
$SSH "rm -rf /opt/dootask"

# 仅停止（不删数据，不占 CPU/内存）
$SSH "cd /opt/dootask && ./cmd down"
```
