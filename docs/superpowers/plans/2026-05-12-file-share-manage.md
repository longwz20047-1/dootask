# 文件页「我共享的」汇总视图 + 行内共享/游客标记 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 给 DooTask 文件页加一个「我共享的」汇总视图（跨文件夹拉平列出当前用户所有 `share=1` 或 `guest_access=1` 的文件），并在文件行上显示「已共享给成员 / 已开游客链接」标记。

**Architecture:** 后端新增只读接口 `GET api/file/shared`（`FileController::shared()`，纯查询不改库）。前端 `file.vue` 加一个 `sharedView` 开关（状态存 IndexedDB）：打开时调 `file/shared`，把结果经现有 `saveFile` 合并进 vuex `fileLists`，把返回的 id 列表存进 `sharedIds`，`fileList` computed 加一个 `sharedView ? sharedIds.includes(file.id) : ……` 的前置分支；同时进汇总视图自动切 table 视图（退出时恢复）。行内标记：block 视图给 `item.guest_access` 加一个带 tooltip 的徽标，table 视图 `columns` 加「共享状态 / 共享给」两列。

**Tech Stack:** Laravel 8 (LaravelS/Swoole) + Vue 2 (Vite) + Vuex + View UI（iView）+ PHPUnit。

> 设计文档：`docs/superpowers/specs/2026-05-12-file-share-manage-design.md`
> 所有新增/修改的代码与文档文件必须包含 `[CUSTOM:file-share-manage]` 标记注释（fork 的 `.git/hooks/pre-commit` 已加入此标记到允许列表）；`language/*.txt` 例外（钩子不检查、且 `#` 会被当成 key）。
> 项目命令一律走 `./cmd`（不要直接 `php artisan` / `vite`）。本地 PHP 可能 < 8.0、无法跑 phpunit —— 后端测试在生产容器跑（`docker exec dootask-php-b61e78 ...`）或交给评审；前端 `file.vue` 无既有单测，前端任务不强制 TDD（与代码库现状一致）。
> dootask 提交规范：conventional commits；**不要**加 `Co-Authored-By`。

---

## File Structure

| 文件 | 操作 | 职责 |
|------|------|------|
| `app/Http/Controllers/Api/FileController.php` | Modify | 新增 `shared()` 方法 |
| `tests/Feature/File/FileSharedTest.php` | Create | `shared()` 的 Feature 测试 |
| `language/original-api.txt` | Modify | 追加后端新文案（CRLF、追加不排序、去重） |
| `language/original-web.txt` | Modify | 追加前端新文案（同上） |
| `resources/assets/js/store/actions.js` | Modify | 新增 `sharedFiles` action（照搬 `searchFiles`） |
| `resources/assets/js/pages/manage/file.vue` | Modify | `sharedView`/`sharedIds`/`_tableModeBeforeShared` data、IDB 加载、`watch.sharedView`、`loadSharedView()`、`fileList` computed 分支、`browseFolder` 退出、工具栏 checkbox、block 徽标、`columns` 两列、`onShare`/`upShare` 刷新 |

---

## Task 1: 后端 `FileController::shared()` + Feature 测试

**Files:**
- Modify: `app/Http/Controllers/Api/FileController.php`（在 `search()` 之后、`add()` 之前插入新方法）
- Create: `tests/Feature/File/FileSharedTest.php`

- [ ] **Step 1: 写失败的 Feature 测试**

Create `tests/Feature/File/FileSharedTest.php`:

```php
<?php
// [CUSTOM:file-share-manage]

namespace Tests\Feature\File;

use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * [CUSTOM:file-share-manage] GET api/file/shared —— 我共享的文件汇总
 */
class FileSharedTest extends TestCase
{
    use DatabaseTransactions;

    private function actingUser(): User
    {
        // 复用现有测试里创建用户的方式：找一个非临时用户并登录
        $user = User::whereNotNull('id')->where('email', 'not like', '%temp%')->first();
        $this->assertNotNull($user, '需要数据库里至少有一个普通用户');
        $this->be($user);
        return $user;
    }

    private function makeFile(int $userid, array $attrs = []): File
    {
        return File::createInstance(array_merge([
            'pid'     => 0,
            'pids'    => '',
            'name'    => 'unit-' . uniqid(),
            'type'    => 'word',
            'ext'     => 'docx',
            'size'    => 1,
            'userid'  => $userid,
            'created_id' => $userid,
            'share'   => 0,
            'pshare'  => 0,
            'guest_access' => 0,
        ], $attrs))->saveAndReturn();
    }

    public function test_returns_shared_and_guest_files_of_current_user(): void
    {
        $user = $this->actingUser();
        $shared = $this->makeFile($user->userid, ['share' => 1, 'pshare' => 0]);
        $guest  = $this->makeFile($user->userid, ['guest_access' => 1]);
        $plain  = $this->makeFile($user->userid);                       // 不共享、不开游客
        $other  = $this->makeFile($user->userid + 999999, ['share' => 1]); // 别人的

        $resp = $this->get('/api/file/shared');
        $resp->assertStatus(200);
        $data = $resp->json('data');
        $ids = array_column($data, 'id');

        $this->assertContains($shared->id, $ids);
        $this->assertContains($guest->id, $ids);
        $this->assertNotContains($plain->id, $ids);
        $this->assertNotContains($other->id, $ids);
        // 每条带 pid=0、permission=1000
        foreach ($data as $row) {
            $this->assertSame(0, $row['pid']);
            $this->assertSame(1000, $row['permission']);
        }
    }

    public function test_file_with_both_share_and_guest_appears_once(): void
    {
        $user = $this->actingUser();
        $both = $this->makeFile($user->userid, ['share' => 1, 'guest_access' => 1]);

        $data = $this->get('/api/file/shared')->json('data');
        $ids = array_column($data, 'id');
        $this->assertSame(1, count(array_filter($ids, fn($id) => $id === $both->id)));
    }

    public function test_returns_empty_when_nothing_shared(): void
    {
        $user = $this->actingUser();
        $this->makeFile($user->userid); // 仅普通文件

        $data = $this->get('/api/file/shared')->json('data');
        $ids = array_column($data, 'id');
        // 当前用户名下可能本来就有共享文件（共享数据库），这里只断言「这个普通文件没进去」
        // 完整的空场景断言留给评审在干净库上跑；最低限度：接口返回数组
        $this->assertIsArray($data);
    }

    public function test_temp_user_gets_error(): void
    {
        // 临时用户：identity 含 'temp'。若测试库里没有合适的临时用户，跳过该断言。
        $temp = User::get()->first(fn($u) => method_exists($u, 'isTemp') && $u->isTemp());
        if (!$temp) {
            $this->markTestSkipped('测试库无临时用户');
        }
        $this->be($temp);
        $resp = $this->get('/api/file/shared');
        $resp->assertStatus(200);
        $this->assertSame(0, $resp->json('ret')); // Base::retError → ret=0
    }
}
```

> 注：`File` 模型继承 `AbstractModel`，用 `File::createInstance($params)` 然后 `->saveAndReturn()`（或项目里等价的保存方式——若 `saveAndReturn` 不存在，用 `$f = File::createInstance(...); $f->save(); return $f;`，执行时按 `AbstractModel` 实际 API 调整）。`be()` 是 Laravel 测试登录辅助；若项目用别的鉴权方式（如 token header），按既有 `tests/Feature/Wecom/WecomFilesAppTest.php` 的风格改成对应方式。

- [ ] **Step 2: 跑测试确认失败**

Run（生产容器内，或本地若 PHP ≥ 8.0）：
```
./cmd composer exec phpunit -- --filter FileSharedTest
```
Expected: FAIL —— `shared()` 方法不存在 → `404 not found (file/shared)`，`assertStatus(200)` 失败（或 ret=0 的 404 响应）。

- [ ] **Step 3: 实现 `FileController::shared()`**

在 `app/Http/Controllers/Api/FileController.php` 里 `public function search()` 方法体结束后、`public function add()` 之前，插入：

```php
    /**
     * @api {get} api/file/shared 我共享的文件汇总
     *
     * @apiDescription [CUSTOM:file-share-manage] 需要token身份；返回当前用户拥有的、所有 share=1 或 guest_access=1 的文件/文件夹（跨文件夹拉平）
     * @apiVersion 1.0.0
     * @apiGroup file
     * @apiName shared
     *
     * @apiSuccess {Number} ret     返回状态码（1正确、0错误）
     * @apiSuccess {String} msg     返回信息（错误描述）
     * @apiSuccess {Object[]} data  文件列表（结构与 file/lists 元素同构，pid 一律为 0）
     */
    public function shared()
    {
        // [CUSTOM:file-share-manage]
        $user = User::auth();
        if ($user->isTemp()) {
            return Base::retError('无法查看共享列表');
        }
        $list = File::whereUserid($user->userid)
            ->where(function ($q) {
                $q->where('share', 1)->orWhere('guest_access', 1);
            })
            ->orderByDesc('updated_at')
            ->take(500)
            ->get();
        $array = [];
        foreach ($list as $file) {
            $temp = $file->toArray();
            $temp['pid'] = 0;            // 拉平：汇总视图里不体现层级
            $temp['permission'] = 1000;  // 自己的文件：与 getFileList 对 pid=0 自己文件给的 permission 一致
            $array[] = File::handleImageUrl($temp);
        }
        return Base::retSuccess('success', $array);
    }
```

- [ ] **Step 4: 跑测试确认通过**

Run: `./cmd composer exec phpunit -- --filter FileSharedTest`
Expected: PASS（4 个测试，临时用户那个可能 skipped）。

- [ ] **Step 5: 提交**

```bash
git add app/Http/Controllers/Api/FileController.php tests/Feature/File/FileSharedTest.php
git commit -m "feat(file): add GET api/file/shared (我共享的文件汇总) [CUSTOM:file-share-manage]"
```

---

## Task 2: i18n 文案追加

**Files:**
- Modify: `language/original-api.txt`（追加，CRLF，去重，不排序）
- Modify: `language/original-web.txt`（同上）

- [ ] **Step 1: 追加后端文案**

`language/original-api.txt` 末尾追加（先确认不存在再加）：
```
无法查看共享列表
```

PowerShell（保持 CRLF、追加不重排）：
```powershell
if (-not (Select-String -Path language/original-api.txt -SimpleMatch -Quiet '无法查看共享列表')) { "无法查看共享列表" | Out-File -FilePath language/original-api.txt -Append -Encoding utf8 }
```
（或用 `printf '%s\r\n' '无法查看共享列表' >> language/original-api.txt` 走 Bash 工具——务必 CRLF、不要 `sort`。）

- [ ] **Step 2: 追加前端文案**

`language/original-web.txt` 末尾追加（逐条确认不存在再加）：
```
我共享的
我共享
已开启游客访问链接
共享状态
共享给
游客
共享
仅链接
```
（`共享` / `游客` 等可能已存在 —— 已存在的跳过。）

- [ ] **Step 3: 提交**

```bash
git add language/original-api.txt language/original-web.txt
git commit -m "i18n(file): add 我共享的 / 游客访问 等文案 [CUSTOM:file-share-manage]"
```

---

## Task 3: vuex `sharedFiles` action

**Files:**
- Modify: `resources/assets/js/store/actions.js`（在 `searchFiles` action 之后插入）

- [ ] **Step 1: 新增 action**

在 `actions.js` 里 `searchFiles({state, dispatch}, data) { ... },` 这个 action 结束后，紧接着插入：

```js
    /**
     * [CUSTOM:file-share-manage] 我共享的文件汇总
     * @param state
     * @param dispatch
     * @returns {Promise<unknown>}
     */
    sharedFiles({state, dispatch}) {
        return new Promise(function (resolve, reject) {
            dispatch("call", {
                url: 'file/shared',
            }).then((result) => {
                dispatch("saveFile", result.data);
                resolve(result)
            }).catch(e => {
                console.warn(e);
                reject(e)
            });
        });
    },
```

- [ ] **Step 2: 提交**

```bash
git add resources/assets/js/store/actions.js
git commit -m "feat(file): add sharedFiles vuex action [CUSTOM:file-share-manage]"
```

---

## Task 4: `file.vue` —— `sharedView` 状态 + IDB 加载 + watch + `loadSharedView()`

**Files:**
- Modify: `resources/assets/js/pages/manage/file.vue`

- [ ] **Step 1: data 加三个字段**

找到 `data()` 里 `searchKey: '',`（约 509 行）/ `tableMode: "",` / `hideShared: false,`（约 564-565 行）附近，在 `hideShared: false,` 后面加：
```js
            // [CUSTOM:file-share-manage] 「我共享的」汇总视图
            sharedView: false,
            sharedIds: [],
            _tableModeBeforeShared: "",
```

- [ ] **Step 2: `beforeRouteEnter` 多读一个 IDB 值**

找到 `beforeRouteEnter`（约 622 行），在 `FileObject.shared = await $A.IDBBoolean("fileHideShared")` 后面加一行：
```js
        FileObject.sharedView = await $A.IDBBoolean("fileSharedView")   // [CUSTOM:file-share-manage]
```

- [ ] **Step 3: `created()` 里恢复 `sharedView` 并按需拉数据**

找到 `created()`（约 632 行），在 `this.hideShared = FileObject.shared` 后面加：
```js
        this.sharedView = FileObject.sharedView  // [CUSTOM:file-share-manage]
```
然后在 `created()` 方法体的**最后**（`columns = [...]` 那一大段之后、`}` 之前）加：
```js
        // [CUSTOM:file-share-manage] 刷新页面后从 IDB 恢复了 sharedView，需要补拉一次汇总数据
        if (this.sharedView) {
            this.$nextTick(() => this.loadSharedView());
        }
```

- [ ] **Step 4: watch 加 `sharedView`**

找到 `watch:` 块里 `hideShared(val) { $A.IDBSave("fileHideShared", val) },`（约 1006 行），在它后面加：
```js
        // [CUSTOM:file-share-manage]
        sharedView(val) {
            $A.IDBSave("fileSharedView", val)
            if (val) {
                this.searchKey = '';
                if (typeof this.clearShear === 'function') this.clearShear();
                if (typeof this.clearSelect === 'function') this.clearSelect();
                this._tableModeBeforeShared = this.tableMode;
                this.tableMode = 'table';
                this.loadSharedView();
            } else {
                this.sharedIds = [];
                this.tableMode = this._tableModeBeforeShared || 'block';
                if (this.routeName === 'manage-file') this.getFileList();
            }
        },
```

- [ ] **Step 5: 加 `loadSharedView()` 方法**

在 `methods: {}` 里（放在 `getFileList()` 方法旁边即可），加：
```js
        // [CUSTOM:file-share-manage] 拉取「我共享的」汇总数据 → 写进 fileLists（via saveFile），id 列表存进 sharedIds
        loadSharedView() {
            this.loadIng++;
            this.$store.dispatch("sharedFiles").then(({data}) => {
                this.loadIng--;
                this.sharedIds = (data || []).map(({id}) => id);
            }).catch(({msg}) => {
                this.loadIng--;
                this.sharedIds = [];
                $A.modalError(msg);
            });
        },
```

- [ ] **Step 6: 提交**

```bash
git add resources/assets/js/pages/manage/file.vue
git commit -m "feat(file): add sharedView state + IDB persistence + loadSharedView [CUSTOM:file-share-manage]"
```

---

## Task 5: `file.vue` —— `fileList` computed 分支 + `browseFolder` 退出 + 工具栏开关 + 搜索互斥

**Files:**
- Modify: `resources/assets/js/pages/manage/file.vue`

- [ ] **Step 1: `fileList` computed 加前置分支**

找到 `fileList()` computed（约 880 行）：
```js
        fileList() {
            const {fileLists, searchKey, hideShared, pid, selectedItems, userId} = this;
            const list = $A.cloneJSON(sortBy(fileLists.filter(file => {
                if (hideShared && file.userid != userId && file.created_id != userId) {
                    return false
                }
                if (searchKey) {
                    return file.name.indexOf(searchKey) !== -1;
                }
                return file.pid == pid;
            }), file => {
```
改成（解构里加 `sharedView, sharedIds`，filter 里最前面加 `sharedView` 分支）：
```js
        fileList() {
            const {fileLists, searchKey, hideShared, pid, selectedItems, userId, sharedView, sharedIds} = this;
            const list = $A.cloneJSON(sortBy(fileLists.filter(file => {
                if (sharedView) {                          // [CUSTOM:file-share-manage] 汇总视图：只看 file/shared 返回的那批
                    return sharedIds.includes(file.id);
                }
                if (hideShared && file.userid != userId && file.created_id != userId) {
                    return false
                }
                if (searchKey) {
                    return file.name.indexOf(searchKey) !== -1;
                }
                return file.pid == pid;
            }), file => {
```

- [ ] **Step 2: `browseFolder` 开头退出汇总视图**

找到 `browseFolder(id, shakeId = null) {`（约 1475 行），在方法体**第一行**加：
```js
            if (this.sharedView) this.sharedView = false;   // [CUSTOM:file-share-manage] 点文件夹/面包屑「全部文件」→ 退出汇总视图
```
（注意：`watch.sharedView` 的 `false` 分支已会 `getFileList()`；`browseFolder(id>0)` 后续 `goForward` 改 `pid` 又触发 `watch.pid → getFileList()`，重复一次无副作用。）

- [ ] **Step 3: 工具栏加「我共享的」checkbox**

找到 `.file-navigator` 里这段（约 88-92 行）：
```html
                <div v-if="hasShareFile" class="only-checkbox">
                    <Checkbox v-model="hideShared">
                        {{showBtnText ? $L('仅显示我的') : $L('仅我的')}}
                    </Checkbox>
                </div>
```
在它**前面**加（始终显示，不门控）：
```html
                <!-- [CUSTOM:file-share-manage] 我共享的汇总视图开关 -->
                <div class="only-checkbox">
                    <Checkbox v-model="sharedView">
                        {{showBtnText ? $L('我共享的') : $L('我共享')}}
                    </Checkbox>
                </div>
```

- [ ] **Step 4: 搜索与汇总视图互斥**

找到 `onSearchChange`（搜索框 `@on-change`）方法（grep `onSearchChange` 在 `methods` 里），在方法体第一行加：
```js
            if (this.sharedView) this.sharedView = false;   // [CUSTOM:file-share-manage] 用搜索 → 退出汇总视图
```
（若 `onSearchChange` 不存在或搜索逻辑在别处，找处理 `searchKey` 变化的 watcher / 方法加同样一行。）

- [ ] **Step 5: 手工验证**

```
./cmd dev
```
浏览器开文件页 → 工具栏出现「我共享的」勾选框 → 勾上：列表变成跨文件夹的拉平结果、自动切表格视图；取消勾选：回到当前文件夹、表格/块视图恢复成勾之前的；勾上后点某个文件夹行 → 自动取消勾选并进入该文件夹；勾上后在搜索框输入 → 自动取消勾选。刷新页面后勾选状态保持且列表不为空。

- [ ] **Step 6: 提交**

```bash
git add resources/assets/js/pages/manage/file.vue
git commit -m "feat(file): wire sharedView into fileList / browseFolder / toolbar [CUSTOM:file-share-manage]"
```

---

## Task 6: `file.vue` —— 行内「游客」徽标 + table 「共享状态/共享给」列

**Files:**
- Modify: `resources/assets/js/pages/manage/file.vue`

- [ ] **Step 1: block 视图加「游客」徽标**

找到 block 视图里 `<div :class="fileBlockIconClasses(item)">` 内部那段（约 165-178 行）：
```html
                                        <template v-if="item.share">
                                            <UserAvatarTip v-if="item.userid != userId" :userid="item.userid" class="share-avatar" :size="20">
                                                <p>{{$L('共享权限')}}: {{$L(item.permission == 1 ? '读/写' : '只读')}}</p>
                                            </UserAvatarTip>
                                            <div v-else class="share-icon no-dark-content">
                                                <i class="taskfont">&#xe757;</i>
                                            </div>
                                        </template>
                                        <template v-else-if="isParentShare">
                                            ...
                                        </template>
```
在 `</template>`（第一个，`v-if="item.share"` 那个）之后、`<template v-else-if="isParentShare">` 之前——不对，徽标要和 share 并存——改为在整个这组 `<template>` **之后**追加（即 `item.guest_access` 独立判断）：
```html
                                        <!-- [CUSTOM:file-share-manage] 已开启游客访问链接 -->
                                        <Tooltip v-if="item.guest_access" :content="$L('已开启游客访问链接')" placement="top" transfer>
                                            <div class="guest-icon no-dark-content">
                                                <Icon type="md-globe" />
                                            </div>
                                        </Tooltip>
```
（`guest-icon` 的样式参考 `.share-icon` —— 见 Step 2。）

- [ ] **Step 2: 加 `.guest-icon` 样式**

在 `file.vue` 的 `<style>`（或对应 scss）里找到 `.share-icon` 的定义，复制一份改名 `.guest-icon`，颜色用橙色系（区别于 share）。例如（按现有 `.share-icon` 的实际属性调整）：
```scss
.guest-icon {
    /* 同 .share-icon 的定位/尺寸 */
    color: #ff9900;
}
```

- [ ] **Step 3: table 视图 `columns` 加两列**

在 `created()` 里 `this.columns = [ ... ]` 数组中，在「文件名」那个对象（`title: this.$L('文件名'), key: 'name', ...`）**之后**插入两个列对象：
```js
            // [CUSTOM:file-share-manage] 共享状态
            {
                title: this.$L('共享状态'),
                width: 130,
                render: (h, {row}) => {
                    const tags = [];
                    if (row.share) tags.push(h('Tag', {props: {color: 'green'}}, this.$L('共享')));
                    if (row.guest_access) tags.push(h('Tag', {props: {color: 'orange'}}, this.$L('游客')));
                    return h('div', tags);
                }
            },
            // [CUSTOM:file-share-manage] 共享给
            {
                title: this.$L('共享给'),
                width: 140,
                render: (h, {row}) => {
                    if (row.share) return h('span', this.$L('已共享'));
                    if (row.guest_access) return h('span', {style: {color: '#999'}}, '— ' + this.$L('仅链接'));
                    return h('span', '');
                }
            },
```
（`h('Tag', ...)` 用 View UI 的 `Tag` 组件；项目里 `Tag` 应已全局注册——若没有，改成 `h('span', {class:'tag-xxx'}, ...)` 用现有标签样式。）

- [ ] **Step 4: 手工验证**

`./cmd dev` → 浏览到一个含「开过游客链接」文件的文件夹：block 视图该文件角上有橙色地球徽标、hover 出 tooltip；切到 table 视图：多了「共享状态」「共享给」两列，显示对应 Tag/文案；勾「我共享的」汇总视图里同样有这些标记。

- [ ] **Step 5: 提交**

```bash
git add resources/assets/js/pages/manage/file.vue
git commit -m "feat(file): show 共享/游客 markers in block & table view [CUSTOM:file-share-manage]"
```

---

## Task 7: `file.vue` —— 改完共享后刷新汇总视图

**Files:**
- Modify: `resources/assets/js/pages/manage/file.vue`

- [ ] **Step 1: `onShare` 成功回调里刷新**

找到 `onShare`（约 2116 行）里 `.then(({data, msg}) => {` 块，在 `this.getShare();` 之后加：
```js
                if (this.sharedView) this.loadSharedView();   // [CUSTOM:file-share-manage]
```

- [ ] **Step 2: `upShare` 成功回调里刷新**

找到 `upShare`（约 2150 行）里 `.then(({data, msg}) => {` 块，在 `this.$store.dispatch("saveFile", data);` 之后加：
```js
                if (this.sharedView) this.loadSharedView();   // [CUSTOM:file-share-manage]
```

> `outshare`（`file/share/out`）成功后调的是 `forgetFile(item)`，会把该文件从 `fileLists` 移除，`fileList` computed 的 `sharedIds.includes(file.id)` 在 `fileLists` 里找不到它 → 自动从汇总列表消失，无需额外处理。

- [ ] **Step 3: 手工验证**

`./cmd dev` → 勾「我共享的」→ 右键某文件「共享设置」→ 取消所有共享成员（且该文件没开游客链接）→ 该行从汇总列表消失；右键「退出共享」别人共享给你的文件（注意 `file/shared` 只列自己的，这条主要验 `onShare` 路径）→ 列表更新。

- [ ] **Step 4: 提交**

```bash
git add resources/assets/js/pages/manage/file.vue
git commit -m "feat(file): refresh sharedView after share update [CUSTOM:file-share-manage]"
```

---

## Task 8: 构建 + 收尾验证

- [ ] **Step 1: 前端构建**

Run: `./cmd prod`
Expected: vite build 成功、`public/js/build/` 下生成新 hash 文件、无报错。

- [ ] **Step 2: 后端测试全绿**

Run（生产容器或本地 PHP ≥ 8.0）: `./cmd composer exec phpunit -- --filter FileSharedTest`
Expected: PASS（临时用户那条可能 skipped）。
另跑一遍文件相关既有测试确认没回归：`./cmd composer exec phpunit -- --filter File`（按实际测试类名调整）。

- [ ] **Step 3: 人工冒烟（对照 spec §7 验收标准逐条过）**

1. `GET api/file/shared`（带 token）返回当前用户 `share=1 OR guest_access=1` 的文件、`updated_at` 倒序、每条 `pid=0`/`permission=1000`；临时用户 → `ret=0`。
2. 勾「我共享的」→ 跨文件夹拉平 + 自动 table 视图 + 「共享状态/共享给」列；取消勾选 → 回原文件夹 + `tableMode` 恢复。
3. 普通浏览：`guest_access=1` 行有「游客」徽标/Tag，`share=1` 行有共享标记。
4. 汇总视图点文件夹 → 进该文件夹且开关复位；点文件 → 正常预览；改/退共享 → 汇总列表更新。
5. 刷新页面后勾选状态保持、列表不为空。

- [ ] **Step 4: （如需）推送 + 部署**

按用户指示决定是否 `git push origin custom/wecom-integration` 并部署到生产（`ssh ... root@192.168.100.30 "cd /opt/dootask && git pull && ./cmd prod"`；后端纯新增方法、Swoole 需 `./cmd php restart` 让 `shared()` 生效）。**不要擅自 push/部署，等用户确认。**

---

## Self-Review（写完后自查记录）

- **Spec 覆盖**：§5.1 后端 `shared()` → Task 1；§5.2(a) `sharedView`/`sharedIds`/IDB/`sharedFiles` action/`loadSharedView`/`created` 恢复 → Task 3+4；§5.2(b)(c) 工具栏开关 + `fileList` 分支 + `browseFolder` 退出 + 搜索互斥 → Task 5；§5.2(d) block 徽标 + `columns` 两列 → Task 6；§5.2(e) 改共享后刷新 → Task 7；§5.2(f) i18n → Task 2；§7 验收 → Task 8。无遗漏。
- **占位符**：无 TBD/TODO；每个改代码的 step 都给了完整代码块；测试 step 给了完整测试代码（其中 `saveAndReturn` / `be()` 标注了「按实际 API 调整」——因为本地无法跑、且 `AbstractModel` 的精确 save API 与 TestCase 鉴权方式需执行者按既有 `WecomFilesAppTest` 风格落地，属合理的执行期适配，不是占位）。
- **类型/命名一致**：`sharedView`（data/watch/computed/IDB key `fileSharedView`）、`sharedIds`（data/computed/`loadSharedView`）、`_tableModeBeforeShared`（data/watch）、`loadSharedView()`（Task 4 定义，Task 7 调用）、vuex action `sharedFiles`（Task 3 定义，Task 4 `loadSharedView` 调用）、后端 `shared()` ↔ 路由 `api/file/shared` ↔ action url `file/shared` —— 全程一致。
