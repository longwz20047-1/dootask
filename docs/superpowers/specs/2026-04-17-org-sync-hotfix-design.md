# 组织同步热修复设计（CDN 缓存 + 部门负责人生命周期）

- 日期：2026-04-17
- 作者：longwz20047-1 / Claude
- 状态：待实施
- 关联：`dootask/docs/superpowers/plans/2026-04-15-dootask-wecom-integration.md`、`2026-04-16-wecom-execution-plan.md`
- 上游 commit：`6af476a feat(wecom): stable org sync with soft-disable lifecycle`

## 背景与问题

稳定版组织同步已部署到生产。跟踪出两个遗留问题：

1. **首次加载极慢 + 图标缺失**：企微自建应用首次打开 DooTask 时，浏览器并发请求约 100 个 JS/CSS chunk + SVG/字体，打到多吉云 CDN 50 QPS 免费套餐上限触发限流，表现为"白屏 / 方框 / 需关闭应用重新进入"。按关闭 → 打开可复现（HTTP 缓存命中避免再请求）。
2. **禁用员工仍挂部门负责人**：`WecomOrgSyncService` 离职检测会把员工 `disable_at` 置非空，但他作为 `UserDepartment.owner_userid` 的部门仍指向他，导致"无活跃负责人"的权限异常。

## 目标

- **非破坏性配置改动**将首次加载请求数对 CDN 的冲击降到最低，图标缺失消失，F5 秒开。
- **Service 改动**让禁用/复活联动部门负责人字段，保持数据语义一致。

## 非目标

- 多吉云套餐升级：本方案下无需升级，缓存命中后 QPS 自然低于 50。
- 重构 DooTask 部门模型或为 `UserDepartment` 加软删字段。
- 处理员工既非禁用也非复活的其他部门调整（交给企微原 `syncUserDepartments`）。
- 其它 P1/P2 清单项（`user.department` 手动部门保留、多 leader、通讯录回调、告警等）不在本次 scope。

## 方案一：多吉云 CDN + dootask-nginx 缓存协作

### 链路回顾

```
浏览器 ─HTTP/2─> 多吉云 CDN ─HTTPS 回源─> 宝塔 nginx (240) ─HTTP─> dootask-nginx (30:2222) ─> Swoole PHP
```

Cache-Control header 由源站 `dootask-nginx` 签发，中间节点透传，浏览器和 CDN 都会根据 max-age / immutable 决定是否命中缓存。

### 改动 1：dootask-nginx 源站签发长缓存 header

**文件**：`dootask/docker/nginx/default.conf`

> 注：`docker/nginx/site/*.conf` 和 `docker/nginx/conf.d/*.conf` 都在 `.gitignore` 里，本地配置无法随代码部署。所以直接改 `default.conf` 加一个 location 块。

在 `location / { try_files ... }` 之前新增：

```nginx
location ~* ^/js/build/.+\.(js|css|woff2?|ttf|svg|png|jpg|ico)$ {
    expires 1y;
    add_header Cache-Control "public, immutable, max-age=31536000" always;
    try_files $uri =404;
}
```

**关键点**：
- 匹配范围限定 `/js/build/` 前缀 — Vite 产物全是 hash 文件名，内容变则 hash 变，可安全 immutable。
- `.html`、`manifest.json`、`/uploads/*` 等不匹配本 location，继续走原 `location /` 逻辑，行为不变。
- `try_files $uri =404`：命中的必须是真文件，避免 404 时被代理到 Swoole 触发误缓存。
- `always`：保证即使 4xx 响应也带 header（部分 nginx 默认只对 2xx 加）。

### 改动 2：多吉云控制台缓存规则（用户手动配）

多吉云控制台 → `mp.smee-china.com` 站点 → **缓存配置**：

| 规则 | 匹配 | TTL | 优先级 |
|------|------|-----|------|
| 1 | 后缀 `.js;.css;.woff2;.woff;.ttf;.svg;.png;.jpg;.ico` | **365 天** | 高 |
| 2 | 后缀 `.html` | **10 分钟** | 中 |
| 3 | 路径 `/` | 不缓存 | 低 |

其它开关：
- **HTTP/2** 开启
- **Gzip / Brotli** 开启
- **遵守源站 Cache-Control** 开启（如有此选项，让 CDN 和浏览器语义一致）

### 改动 3：验证

部署后执行以下命令确认 header 生效：

```bash
curl -sI https://mp.smee-china.com/js/build/app.<hash>.js | grep -iE 'cache-control|expires'
# 预期：
# Cache-Control: public, immutable, max-age=31536000
# Expires: <one year later>
```

浏览器 DevTools → Network → 刷新页面，静态资源应显示 `(disk cache)` 或 `(memory cache)`，不产生新请求。

---

## 方案二：部门负责人生命周期（owner_userid）

### 时序

```
员工 A 担任部门 D 的 owner (D.owner_userid = A.userid)

T1 企微离职：
  └─ syncUsers 差集检测 → A.disable_at=now, binding.unbind_at=now
  └─ 新增：UserDepartment.where(owner_userid=A.userid).update(owner_userid=0)

T2 员工复活（同步或 OAuth 登录）：
  └─ A.disable_at=null, binding.unbind_at=null
  └─ 新增：部门 D 若 owner_userid 仍为 0 且 A 归属 D → owner_userid=A.userid
  └─ 若 D.owner_userid 已被其它活跃用户接管 → 不覆盖
```

### 改动点

**文件 1**：`dootask/app/Services/WecomOrgSyncService.php`

- `syncUsers()` 离职分支（`$leavers` 循环）：`$user->save()` 之后追加：

```php
UserDepartment::where('owner_userid', $user->userid)
    ->update(['owner_userid' => 0]);
```

- `syncUsers()` 复活分支（`$resurrects` 循环）：`$user->save()` 之后追加：

```php
$deptIds = is_array($user->department) ? $user->department : [];
if (!empty($deptIds)) {
    UserDepartment::whereIn('id', $deptIds)
        ->where('owner_userid', 0)
        ->update(['owner_userid' => $user->userid]);
}
```

- `recycleWecomQuota()` 禁用路径（`$user->disable_at = $now` 之后）追加相同的置 0 逻辑。

**文件 2**：`dootask/app/Http/Controllers/Api/WecomController.php`

- `callback()` 的复活分支（`$user->disable_at = null` 之后）追加同上"复活回填"逻辑。
- `silentRegister()` 的复活分支同样处理。

### 边界条件

| 场景 | 预期行为 |
|------|--------|
| 禁用员工后，管理员手动把部门 owner 改成 B | syncAll 运行；若 A 复活，`owner != 0` → 不覆盖，B 保留 |
| 部门被软删（lost_at）| `UserDepartment` 表保留（lost_at 在 mapping 层），回填逻辑仍按 owner=0 判断，无副作用 |
| 员工有多个部门都曾是 owner | 离职时全部置 0；复活时只回填仍属于他 (`user.department` 含的) 且 owner=0 的部门 |
| 管理员（`isAdmin()=true`）被离职 | 现有代码已跳过管理员禁用，owner 不变 |
| 复活时员工已不再归属某部门（企微里调走了） | `whereIn(user.department)` 自动过滤掉，不会回到原部门 |

### 不改动

- `UserDepartment::saveDepartment()` 内部 `$oldUser/$newUser` 的部门成员联动逻辑（DooTask 原生行为），批量 `update` 不走该路径，也不需要触发群组通知。
- 部门关联的群组 `WebSocketDialog.owner_id` 同步：现有 `saveDepartment` 也是在 owner 变更时推送；批量 update 不触发，考虑到这是"被动禁用"事件，不强推群主更换消息，避免在全员群里反复通知；如需严格同步，单独作为 P1 项处理。

---

## 风险与回滚

| 风险 | 概率 | 缓解 |
|------|------|------|
| Cache-Control immutable 后用户刷新看不到新版本 | 低 | hash 文件名变更即新 URL；`.html` 10 分钟窗口足够覆盖前端发布周期 |
| Safari 不支持 immutable | 中 | `max-age=31536000` 作为兜底在 Safari 正常生效，表现为普通长缓存（含 revalidate）；不影响正确性，仅略慢于 Chrome |
| 批量 `UserDepartment::update(owner_userid=0)` 绕过模型事件导致群主未更新 | 中 | 接受此权衡（上面"不改动"里说明）；如有投诉，加后续异步任务补推 |
| 复活回填覆盖了管理员手动改的 owner | 低 | 代码已用 `where('owner_userid', 0)` 约束，只改 owner=0 的部门 |
| CDN 缓存了错误响应（如 4xx） | 低 | 多吉云默认不缓存 4xx；`try_files =404` 防止源站误走代理 |

**回滚**：

1. `dootask-nginx` 配置：`git revert <commit> && docker exec dootask-nginx-b61e78 nginx -s reload`（秒级；不同部署容器名可能带不同 hash 后缀）
2. `WecomOrgSyncService` / `WecomController` 代码：`git revert <commit> && ./cmd php restart`（约 30 秒）
3. 多吉云 CDN 缓存规则：控制台直接删除对应规则（秒级）

## 测试计划

### 自动（如有余力）

无单元测试；本修复以集成 + 手动验证为主。

### 手动验证清单

**A. CDN / nginx 缓存**

1. `curl -sI https://mp.smee-china.com/js/build/app.<hash>.js` → 应返回 `Cache-Control: public, immutable, max-age=31536000`
2. `curl -sI https://mp.smee-china.com/js/build/<hash>.svg` → 同上
3. `curl -sI https://mp.smee-china.com/index.html` → 应返回 `max-age=600` 左右（来自 CDN 规则 2）
4. 浏览器 DevTools Network → 刷新 → 静态资源显示 `(disk cache)` 或 `304`
5. 企微 PC 客户端 → 关闭应用重新进入 → 图标正常显示、首屏 < 10 秒

**B. 部门 owner_userid 生命周期**

1. 企微后台将某部门负责人员工 A（非管理员）从通讯录移除
2. DooTask 管理 → 立即同步组织架构
3. 查数据库：
   - `SELECT disable_at FROM pre_users WHERE userid=<A>;` → 非 null
   - `SELECT owner_userid FROM pre_user_departments WHERE id=<D>;` → 0
4. 企微后台恢复员工 A
5. 同步或员工 A 在企微客户端登录 DooTask
6. 查数据库：
   - `disable_at` → null
   - `owner_userid` → `<A.userid>`（仅当 T2 时点该部门 owner_userid=0）

## 发布步骤

1. 本地修改 + 构建前端（本次无前端改动，跳过）
2. `git commit` + `git push origin custom/wecom-integration`
3. 服务器 `cd /opt/dootask && git pull`
4. 重启 nginx 容器让新 conf 生效：`docker exec dootask-nginx-b61e78 nginx -s reload`（优先尝试 reload，失败再 `docker restart dootask-nginx-b61e78`；容器名若带不同 hash 后缀请替换）
5. `./cmd php restart` 重启 PHP 让 Service 改动生效
6. 用户登录多吉云控制台配置 CDN 缓存规则 + 开 HTTP/2/Brotli
7. 执行上文手动验证清单

## 时间估算

| 任务 | 预计 |
|------|------|
| nginx conf 修改 + 构建验证 | 10 分钟 |
| Service / Controller 代码修改 | 30 分钟 |
| 本地 commit + push + 服务器 pull + 重启 | 10 分钟 |
| 多吉云控制台规则配置 | 10 分钟（用户操作） |
| 手动验证 A + B | 20 分钟 |
| **合计** | **约 80 分钟** |
