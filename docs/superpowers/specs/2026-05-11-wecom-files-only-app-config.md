# 企微"文件管理"自建应用 — 配置操作指导

> [CUSTOM:wecom-files-app] 配套 plan: `2026-05-11-wecom-files-only-app.md`
>
> **谁来操作**：企微管理员 + DooTask 系统管理员（可同一人）
> **耗时**：约 15 分钟
> **前提**：DooTask 主应用（"DooTask"）已配好企微登录，`mp.smee-china.com` 域名已在企微 OAuth 可信域名列表
>
> ⚠️ **域名约束**（与 `docs/server-deployment-guide.v3.md §4C.14` 对齐）：
> - Dootask 生产域名是 `mp.smee-china.com`，**不是** `main.smee-china.com/dootask`
> - `main.smee-china.com/dootask` 是历史反代别名，**不在企微 OAuth 可信域名**，用它配应用主页会白屏
> - 主应用现状如何，文件应用也必须沿用 `mp.smee-china.com`

---

## 阶段总览（注意先后依赖）

| 阶段 | 内容 | 依赖 | 谁做 |
|------|------|------|------|
| A | 企微后台：创建第 2 自建应用 + 配域名 | **无依赖，现在就能做** | 企微管理员 |
| B | 代码部署到生产 | 等 plan 的 Task 1-6 实现完 | 开发/运维 |
| C | DooTask 后台：填新应用配置 | **依赖 B**（B 部署后才有配置输入框） | DooTask 管理员 |
| D | 验证 | 依赖 A+B+C 全部完成 | 任意 |

---

## 阶段 A：企微管理后台创建自建应用（不依赖代码，可先做）

### A1. 登录企微管理后台

打开 https://work.weixin.qq.com/wework_admin/ → 用管理员账号扫码登录。

### A2. 创建自建应用

左侧菜单：**应用管理** → **应用** → 点 **「创建应用」** 按钮（在"自建"区域）。

填写：
| 字段 | 填什么 |
|------|--------|
| 应用 Logo | 上传一个文件管理相关的图标（可选，建议传，方便用户区分） |
| 应用名称 | `DooTask 文件` |
| 应用介绍 | `DooTask 文件管理`（可选） |
| 可见范围 | 选需要用文件管理的部门/成员（**注意**：不在可见范围内的人看不到这个应用图标） |

点 **「创建应用」**。

### A3. 记下关键参数

创建后进入应用详情页，记下两个值（后面阶段 C 要用）：

- **AgentId**：在应用详情页顶部，一串数字，如 `1000005`
- **Secret**：点 **「Secret」** 旁边的 **「查看」**，会发到企微"应用管理"小助手，复制下来

> ⚠️ Secret 是敏感凭据，不要发到聊天群、不要提交到 git。

### A4. 配置「应用主页」

在应用详情页找到 **「应用主页」**（有的版本叫"工作台应用主页"），点编辑，填：

```
https://mp.smee-china.com/api/wecom/entry?app=files
```

> 注意末尾的 `?app=files` 不能漏 —— 这是区分"文件应用"和"主应用"的关键参数。
> 主应用填的是 `https://mp.smee-china.com/api/wecom/entry`（不带 `?app=files`），两者不冲突。
>
> ⚠️ **必须用 `mp.smee-china.com`**，不要写成 `main.smee-china.com/dootask`（后者不在企微可信域名，OAuth 会白屏）。

保存。

### A5. 配置「网页授权及 JS-SDK」可信域名

在应用详情页往下找 **「网页授权及 JS-SDK」**，点 **「设置可信域名」**，填：

```
mp.smee-china.com
```

（**只填域名，不带 `https://`、不带路径**）

> 因为主应用已经验证过这个域名（部署文档 §4C.14 / §4A.8 两侧 `main` + `mp` 都登记），这里通常会显示"已验证"，直接保存即可。
> 如果提示要重新验证：下载企微给的 `WW_verify_xxxx.txt` 文件，放到服务器 `https://mp.smee-china.com/WW_verify_xxxx.txt` 能访问到的位置，再点验证。（一般不会遇到，因为主应用配过了）

### A6. 配置「企业可信 IP」（如果有这一项且为必填）

某些企微版本要求填可信 IP。填生产服务器出口 IP（`192.168.100.30` 或反代服务器 `192.168.100.240` 的公网出口 IP，按实际网络拓扑）。如果不是必填项可跳过。

### ✅ 阶段 A 完成检查

- [ ] 自建应用 "DooTask 文件" 已创建
- [ ] 记下了 AgentId（数字）和 Secret（字符串）
- [ ] 应用主页 = `https://mp.smee-china.com/api/wecom/entry?app=files`（**带 `?app=files`，域名必须 `mp` 不能 `main`**）
- [ ] 可信域名 = `mp.smee-china.com`
- [ ] 可见范围已设置

> 此时在企微 App 的工作台已经能看到 "DooTask 文件" 图标了，但**点进去会报错**（因为代码还没部署）—— 这是正常的，等阶段 B+C 完成后就好了。

---

## 阶段 B：代码部署到生产（开发/运维做）

> 前提：plan 的 Task 1-6 已实现并通过测试。

```bash
SSH="ssh -o StrictHostKeyChecking=no -i ~/.ssh/bt_key root@192.168.100.30"
$SSH "cd /opt/dootask && git fetch origin custom/wecom-integration && git pull origin custom/wecom-integration"
$SSH "cd /opt/dootask && ./cmd prod"        # 重新构建前端（含 manage.vue 改动）
$SSH "cd /opt/dootask && ./cmd php restart"  # Swoole 重载后端代码
```

### ✅ 阶段 B 完成检查

- [ ] `git log -1` 显示包含 `[CUSTOM:wecom-files-app]` 的 commit
- [ ] `./cmd php restart` 无报错
- [ ] 浏览器访问 `https://mp.smee-china.com/` 正常打开（主应用没坏）

---

## 阶段 C：DooTask 后台填新应用配置（DooTask 管理员做，依赖阶段 B）

### C1. 登录 DooTask

打开 https://mp.smee-china.com/ → 用**管理员**账号登录。

### C2. 进入第三方账号设置

右上角头像 → **系统设置** → 左侧 **「第三方帐号」**（与配主应用企微登录的是同一个页面）。

往下滚动到企业微信配置区域。**主应用的配置（`wecom_open` / `wecom_corp_id` / `wecom_agent_id` / `wecom_secret` / `wecom_contact_secret`）保持原样不动**。

找到新增的 3 个字段（前提：Task 2.5 已合并部署，`SystemThirdAccess.vue` 表单已含 `wecom_files_*` 行）：

| 字段 | 填什么 |
|------|--------|
| `wecom_files_open` | 选 **开启 / open** |
| `wecom_files_agent_id` | 阶段 A3 记下的 **AgentId**（数字，如 `1000005`） |
| `wecom_files_secret` | 阶段 A3 记下的 **Secret** |

点 **「保存」**。

> `wecom_corp_id`（企业 CorpID）和 `wecom_contact_secret`（通讯录同步 Secret）**复用主应用的，不需要重填**。文件应用和主应用是同一个企业（同 CorpID），只是不同的自建应用（不同 AgentId/Secret）。

### ✅ 阶段 C 完成检查

- [ ] `wecom_files_open` = open
- [ ] `wecom_files_agent_id` = 阶段 A 记下的 AgentId
- [ ] `wecom_files_secret` = 阶段 A 记下的 Secret
- [ ] 保存成功（页面提示"保存成功"）

---

## 阶段 D：验证

### D1. PC 浏览器快速验证（最快）

浏览器**先退出 DooTask 登录**（或用无痕窗口），访问：

```
https://mp.smee-china.com/api/wecom/entry?app=files
```

预期：
1. 自动跳转到企微 OAuth 授权页（实际域名 `open.work.weixin.qq.com/wwopen/oauth2/...` 或 `open.weixin.qq.com/connect/oauth2/authorize?...`，URL 含 `agentid=<你的AgentId>`）
   - 如果你在 PC 上没登企微，会显示扫码授权页
2. 授权后跳回 `https://mp.smee-china.com/manage/file?app=files`
3. 页面**只显示文件管理**：
   - 左侧只有「文件」一个图标，没有仪表盘/日历/消息/应用
   - 没有项目列表
   - 没有「新建任务」按钮
   - 文件的上传、下载、新建文件夹、共享等功能正常

### D2. 企微移动端验证

1. 手机企微 → 工作台 → 找到 **「DooTask 文件」** 图标 → 点开
2. 应该静默登录（不弹账号密码），直接进文件管理页
3. 同 D1 的页面检查

### D3. 边界 case

- 手动把地址栏改成 `https://mp.smee-china.com/manage/dashboard?app=files` → 应该**自动跳回** `/manage/file?app=files`（前端路由守卫）

### D4. 主应用回归（重要！别把老应用搞坏了）

1. 手机企微 → 工作台 → 找到原来的 **「DooTask」** 图标 → 点开
2. 应该正常静默登录，**侧栏完整**（仪表盘/日历/消息/文件/应用都在），项目列表正常，能新建任务
3. 确认两个应用登录后是**同一个 DooTask 账号**（看右上角昵称一致）

### ✅ 阶段 D 完成检查

- [ ] D1 PC 浏览器：entry?app=files → OAuth → 落到文件页，UI 精简正确
- [ ] D2 移动端：「DooTask 文件」图标点开正常
- [ ] D3 边界：手改 URL 到 dashboard 被跳回
- [ ] D4 回归：「DooTask」主应用完全正常，未受影响
- [ ] 文件管理本身功能正常（上传/下载/新建/共享/搜索）

---

## 常见问题排查

| 现象 | 可能原因 | 怎么查 |
|------|---------|--------|
| 点应用图标白屏/报错 "redirect_uri 参数错误" | 应用主页的可信域名没配对，或域名没验证，或误用 `main.smee-china.com/dootask` | 检查阶段 A4/A5，确认可信域名是 `mp.smee-china.com`（不带路径，**不是 main**）|
| 跳到登录页提示 "企业微信文件应用未开启" | `wecom_files_open` 没设成 open，或代码没部署 | 检查阶段 B 部署、阶段 C2 配置 |
| 跳到登录页提示 "企业微信文件应用配置不完整" | `wecom_files_agent_id` 或 `wecom_files_secret` 填错/为空 | 重新核对阶段 A3 的值，重新填阶段 C2 |
| OAuth 后跳回的是完整侧栏（不是精简的） | 前端没重新构建，或 URL 丢了 `?app=files` | 确认阶段 B 跑了 `./cmd prod`；检查应用主页 URL 末尾有 `?app=files` |
| 提示 "非企业成员，无法登录" | 当前企微用户不在该应用的可见范围内 | 阶段 A2 把该用户加进可见范围 |
| "DooTask 文件" 能进但 "DooTask" 主应用坏了 | 改动影响了 default 分支 | 看 plan Task 3 的实现，`makeOAuthClientForApp` 的 default 分支应原样调 `makeOAuthClient` |
| 同一人在两个应用看到不同账号 | 不应该发生（同 CorpID + 同 wecom_userid → 同 UserWecomBinding） | 查 `user_wecom_bindings` 表是否有该 wecom_userid 的重复绑定 |

---

## 安全说明（务必知悉）

本期 "files-only" 是 **UI 级隐藏 + 前端路由软约束**，**不是后端权限隔离**：

- 用户通过 "DooTask 文件" 登录后拿到的是**普通 DooTask token**（和主应用一样的 token）
- 这个 token 存在浏览器里，理论上用户可以手动改 URL / 用 Postman 直接调 `/api/project/*`、`/api/task/*` 等接口
- 前端的 `enforceFilesOnlyRoute` 守卫只是把界面跳回文件页，禁用 JS 就能绕过
- 后端**没有**针对 "files app" 的 API 权限检查

**适用场景**：企业内部信任用户，分应用入口只为简化 UX（"我只想用文件，不想看一堆任务菜单"）。

**不适用**：B2B 客户、外协人员等需要真正"只能访问文件"的场景。如需真隔离，要另起 plan 加：
1. token 签发时嵌入 `app_scope`（或单独 `Cache::put("token_app_scope:{token}", 'files')`）
2. 后端中间件：`files` scope 的 token 只放行 `/api/file/*` 及必要辅助端点（用户信息、文件预览等），其余 403
3. 前端守卫保留作 UX 优化

预估额外工作量：1-2 个 Task。
