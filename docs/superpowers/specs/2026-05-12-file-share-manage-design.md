# 文件页「我共享的」汇总视图 + 行内共享/游客标记 — 设计

> [CUSTOM:file-share-manage] DooTask 二开自定义功能
> 日期：2026-05-12
> 状态：已确认，已对照代码事实复核（2026-05-12）

## 1. 背景与问题

DooTask 文件页只能一层层翻文件夹。用户共享给成员（`files.share = 1`，走 `file_users` 表）或开启游客访问链接（`files.guest_access = 1`）后，**无法集中看到「我共享了哪些文件、哪些允许游客访问」**——共享状态只在浏览到文件所在文件夹时才以一个小图标体现，`guest_access` 状态则只在打开「共享设置」弹窗时才看得到。结果是没法管理（撤销共享、关闭游客链接）。

## 2. 目标

- **汇总视图**：一键看到当前用户拥有的、所有 `share = 1` 或 `guest_access = 1` 的文件/文件夹（跨文件夹拉平）。
- **行内标记**：在普通浏览和汇总视图里，每个文件行都能看到「已共享给成员」「已开游客链接」状态。
- 汇总视图里能直接对每条做现有的管理操作（共享设置 / 复制链接 / 取消共享 —— 复用现有右键菜单，不新增操作）。

## 3. 非目标（YAGNI）

- 不新增路由 / 独立页面，全部在现有文件页（`pages/manage/file.vue`）内实现。
- 不做批量取消共享（现有右键菜单已有「退出共享」/「取消共享」，汇总视图里照样能用）。
- 不递归列出共享文件夹下的子文件——只列「共享根」（用户显式共享/开放的那个文件或文件夹）。`files.pshare != 0 && files.share == 0` 的传递性子文件不进汇总。`share__update()` 里有 `isNnShare()` / `isSubShare()` 校验，保证 `share=1` 的文件总是「共享根」、不会嵌套。
- 不动现有 `file/lists`、`file/search`、`getFiles`/`searchFiles` action、`hideShared`（「仅显示我的」）的行为。

## 4. 数据事实（已对照代码核对）

- `files` 表已有列（无需 migration）：
  - `share` — `tinyInteger nullable default 0`（`2021_06_25_182631_create_files_table.php` + `2023_12_07_..._add_index_some_20231217.php` 改成 `integer`），「是否共享」。
  - `pshare` — `bigInteger nullable default 0 after share`（`2022_07_15_165114_add_files_pshare.php`），「所属分享 ID」（共享根的 id）。
  - `guest_access` — `tinyInteger nullable default 0 after share`（`2025_09_19_175724_add_guest_access_to_files_table.php`），「是否允许游客访问」。
- `App\Models\File` 无 `$hidden` / `$appends` / `$casts`，`$file->toArray()` 返回全部列 —— `share` / `guest_access` / `pshare` / `ext` / `size` / `updated_at` 等都在里头。**意味着前端现有文件列表数据已含 `share` / `guest_access`，只是 `guest_access` 没渲染。**
- `guest_access` 在 `FileController::link()`（`api/file/link`）里写入：生成公开链接时按 `?guest_access=yes/no` 设 `files.guest_access`。所以「允许游客访问」≈「这个文件有过一条允许游客的公开链接」，与 `share`（共享给成员）相互独立，可同时为真。
- `File::handleImageUrl($item)`（`public static`，入参是数组）：当 `in_array($item['ext'], self::imageExt)` 时给数组补 `image_url` / `image_width` / `image_height`。`getFileList()` 对每条都调它，本设计的 `shared()` 同样调。
- 路由：`routes/web.php` 里 `Route::any('file/{method}', FileController::class)`（在 `api/` 前缀组内），`FileController extends AbstractController`，`AbstractController::__invoke($method, $action)` 把 URL 段映射成方法名（带 action 用双下划线）。新增 `shared()` 方法 → `GET api/file/shared` 自动可达，**无需改路由文件**。`api/file/share`（`share()`）、`api/file/share/update`（`share__update()`）已存在，`api/file/shared` 不冲突。
- `User::auth()` 取当前登录用户；`User::isTemp()` = `in_array('temp', $this->identity)`（链接访客）。FileController 各方法各自调 `User::auth()`（`__before` 不做全局 auth），`shared()` 沿用这个模式。
- 前端 `file.vue` 关键事实：
  - `fileLists`（vuex `mapState`，全局共享状态，`$A.IDBSave("fileLists", ..., 600)` 持久化 600s）是「原始扁平文件记录池」；`getFiles(pid)` / `searchFiles(key)` action 把后端结果 `dispatch("saveFile", data)` 合并进 `fileLists`。
  - `fileList`（**computed**，不可直接赋值）：`fileLists` 经过滤+排序+装饰得到 —— 过滤分支：`hideShared && 非自己的 → 排除`、否则 `searchKey ? 按 name 模糊 : file.pid == pid`。block 视图 `<li v-for="item in fileList">` 和 table 视图 `<Table :data="fileList">` 都用它。
  - `columns`（**data**，初值 `[]`，在 `created()` 里 `this.columns = [...]` 赋值，含 `selection` 列 + 「文件名」render 列等）。
  - `hideShared`（data，初值 `false`）：`beforeRouteEnter` 里 `FileObject.shared = await $A.IDBBoolean("fileHideShared")` → `created()` `this.hideShared = FileObject.shared`；`watch.hideShared(val) { $A.IDBSave("fileHideShared", val) }`。`<Checkbox v-model="hideShared">` 外面套 `v-if="hasShareFile"`（`hasShareFile` = `fileLists` 里有「别人共享给我」的文件）。
  - `tableMode`（data，初值 `""`）：`beforeRouteEnter` `FileObject.mode = await $A.IDBString("fileTableMode")` → `created()` 赋值；`watch.tableMode(val){ $A.IDBSave("fileTableMode", val) }`；`block` / `table` 切换按钮在工具栏。
  - `searchKey`（data）：`browseFolder(0)` 里会清掉它（`browseFolder(id>0)` 走 `goForward` 不清）。
  - `browseFolder(id)`：`id>0` → `goForward({name:'manage-file', params:{folderId:id,...}})`；`id==0` → 清 `searchKey` 后 `goForward`。block 视图点文件夹 → `dropFile` 最终 `this.browseFolder(item.id)`。
  - `getFileList()`：`routeName==='manage-file'` 时 `dispatch("getFiles", this.pid)`。`pid` 变化（路由 param 变）会触发它。
  - 取消/退出共享：`onShare` → `file/share/update`（`share__update`）；右键「退出共享」→ `file/share/out`（`share__out`）。成功后现在没有显式刷新列表（靠 ws push / 重新进目录）。

## 5. 方案

### 5.1 后端：新增 `FileController::shared()` → `GET api/file/shared`

```php
/**
 * @api {get} api/file/shared 我共享的文件汇总
 * @apiDescription 需要token身份；返回当前用户拥有的、所有 share=1 或 guest_access=1 的文件/文件夹（跨文件夹拉平）
 * @apiVersion 1.0.0
 * @apiGroup file
 * @apiName shared
 */
public function shared()
{
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
        $temp['pid'] = 0;           // 拉平：汇总视图里不体现层级（与 getFileList 对「别人共享给我」的处理一致）
        $temp['permission'] = 1000; // 自己的文件：与 getFileList 对 pid=0 自己文件给的 permission=1000 保持一致
        $array[] = File::handleImageUrl($temp);
    }
    return Base::retSuccess('success', $array);
}
```

- 不分页（`take(500)`，与 `getFileList` 的 500 上限一致）。
- 返回元素结构与 `getFileList` 同构（`id/pid/name/type/ext/size/share/guest_access/pshare/permission/updated_at/image_url...`），前端可直接复用渲染与 `saveFile` 合并。
- 临时（链接访客）用户调用 → `retError`。

### 5.2 前端 `file.vue` + vuex

#### (a) 新状态 `sharedView` + 数据来源

- vuex 新增 action `sharedFiles({state, dispatch})`，**完全照搬 `searchFiles` 的写法**：`dispatch("call", {url:'file/shared'})` → `dispatch("saveFile", result.data)` → `resolve(result)`。（也可在组件里直接 `dispatch("call",...)`，但为与 `getFiles`/`searchFiles` 一致，走 action。）
- `file.vue` 新增 data：
  - `sharedView: false` —— `beforeRouteEnter` 里 `FileObject.sharedView = await $A.IDBBoolean("fileSharedView")`，`created()` 里 `this.sharedView = FileObject.sharedView`。
  - `sharedIds: []` —— 进入汇总视图时 = `file/shared` 返回的 `data.map(({id}) => id)`。
  - `_tableModeBeforeShared: ""` —— 进汇总视图前的 `tableMode` 快照。
- `watch.sharedView(val)`：
  - `$A.IDBSave("fileSharedView", val)`。
  - `val === true`：`this.searchKey = ''`；若有剪切/多选态先 `this.clearShear()` / `this.clearSelect()`；`this._tableModeBeforeShared = this.tableMode; this.tableMode = 'table'`；`this.loadIng++; dispatch("sharedFiles").then(({data}) => { this.sharedIds = data.map(({id})=>id); this.loadIng-- }).catch(...)`。
  - `val === false`：`this.sharedIds = []`；`this.tableMode = this._tableModeBeforeShared || 'block'`（恢复，避免「自动切表格」被 watcher 永久写进 IDB）；`this.getFileList()`（回当前 `pid`）。
- `fileList` computed 增加**最前面**的分支：
  ```js
  fileLists.filter(file => {
      if (sharedView) return sharedIds.includes(file.id);
      // ……以下保持原样：hideShared / searchKey / file.pid == pid
  })
  ```
- `created()` 里若 `this.sharedView` 为 true（IDB 恢复的），需在 `mounted`/`getFileList` 之后补一次 `dispatch("sharedFiles")` 拉数据（否则刷新后 `sharedIds` 为空、列表空）。具体：在现有「首屏 `getFileList()`」之后 `if (this.sharedView) { 重新走 watch.sharedView 的 val===true 分支逻辑 }`（抽成一个方法 `loadSharedView()` 复用）。

#### (b) 工具栏开关

- `.file-navigator` 里、`hideShared` 的 `<div class="only-checkbox">` **左边**新增：
  ```html
  <div class="only-checkbox">
      <Checkbox v-model="sharedView">{{showBtnText ? $L('我共享的') : $L('我共享')}}</Checkbox>
  </div>
  ```
- **始终显示**（不像 `hideShared` 那样套 `v-if="hasShareFile"`）。
- 与搜索互斥：`watch.searchKey` 已存在；进入搜索时若 `sharedView` 则 `this.sharedView = false`（或在 `onSearchChange` 里处理）。

#### (c) 退出汇总视图的入口

- 点行内**文件夹**：复用现有 `dropFile`→`browseFolder(item.id)`。在 `browseFolder(id)` 方法**最前面**加 `this.sharedView = false`（这样：点文件夹、点面包屑「全部文件」`browseFolder(0)`、其他任何 `browseFolder` 调用 都会退出汇总视图；`watch.sharedView` 的 `false` 分支会 `getFileList()`，但 `browseFolder(id>0)` 紧接着 `goForward` 改 `pid` 又会触发一次 `getFileList()` —— 重复一次无副作用，可接受；若想更干净，可在 `false` 分支里判断 `if (this.routeName === 'manage-file' && !this._navigating) getFileList()`，**非必须**）。
- 点行内**文件** → 现有预览逻辑不变。
- 用搜索框 → 见 (b)。

#### (d) 行内「游客」/「共享」标记

- **block 视图**（`.file-list ul li`，约 165 行 `<template v-if="item.share">` 附近）：
  - 保留现有 `item.share` 的图标/共享者头像。
  - 新增：`item.guest_access` 时在文件块角上加一个「游客」徽标 —— 用现有图标体系（`<i class="taskfont">…</i>` 选一个地球/链接类字形，或 `<Icon type="md-globe" />`），外面包 `<Tooltip :content="$L('已开启游客访问链接')">`。`item.share` 与 `item.guest_access` 可并存（两个徽标并列）。CSS 跟现有 `.share-icon` 对齐尺寸（移动端也要看得清）。
- **table 视图**（`created()` 里的 `this.columns` 数组，在「文件名」列之后插入）：
  - 「共享状态」列：`render` 出 Tag —— `row.share` → `<Tag color="green">{{$L('共享')}}</Tag>`；`row.guest_access` → `<Tag color="orange">{{$L('游客')}}</Tag>`；都没有则空。
  - 「共享给」列：`row.share` → 显示「已共享」（或共享人数，若 render 里能廉价拿到 `file_users` 计数则显示，否则就「已共享」）；`row.guest_access && !row.share` → `—（仅链接）`；都没有 → 空。（`share` 文件在汇总视图里恒为自己的，permission=1000，不显示「只读」。）
  - 这两列在普通浏览（非汇总）的 table 视图下也会显示——数据本来就在，无害。
- 普通浏览的 block 视图同样显示这些徽标（数据已在 `fileLists`），不只汇总视图。

#### (e) 取消/退出共享后的刷新

- `onShare`（`share/update`）成功回调、右键「退出共享」（`share/out`）成功回调里：`if (this.sharedView) { this.loadSharedView() } else { this.getFileList() }` —— 让汇总列表里被取消共享的项立即消失。

#### (f) i18n

- 新增用户可见文案，去重后追加到 `language/original-web.txt`（CRLF、追加不排序——与 dootask 现状一致）：`我共享的`、`我共享`、`已开启游客访问链接`、`共享状态`、`共享给`、`游客`、`共享`、`仅链接`（视实现取舍）。`共享` 若已存在就不重复加。
- 后端：`无法查看共享列表` 追加到 `language/original-api.txt`（去重；`无法查看共享列表` 现不存在则加）。

## 6. 影响面 / 风险

- 后端：纯新增 `shared()` 方法，不碰已有逻辑 → 风险极低；无 migration。
- 前端：`file.vue` 已经很大；本次新增集中在 —— 1 个工具栏 checkbox、`fileList` computed 加 1 个前置分支、`browseFolder` 加 1 行、`columns` 加 2 列、block 模板加 1 个徽标、`watch.sharedView` + `loadSharedView()` 方法、`onShare`/`share/out` 回调各加 1 个 if。不重构现有代码。
- `sharedView` 与 `searchKey` / `hideShared` / `tableMode` / `shearFirst`（剪切态）/ `selectedItems`（多选态）交互：进入 `sharedView` 时清 `searchKey`、`clearShear()`、`clearSelect()`；`tableMode` 快照并在退出时恢复。
- `tableMode` watcher 会把 `'table'` 写进 IDB —— 用 `_tableModeBeforeShared` 在退出时恢复，避免「用过一次汇总视图后表格视图被永久记住」。
- 刷新页面后 `sharedView` 从 IDB 恢复 → `created()`/首屏需补 `loadSharedView()` 拉数据，否则列表空。
- 移动端（`showBtnText=false`）：开关短文案「我共享」；徽标小尺寸下也要可辨。

## 7. 验收标准

1. 后端：`GET api/file/shared`（带 token）返回当前用户所有 `share=1 OR guest_access=1` 的文件，`updated_at` 倒序，每条带 `pid=0`、`permission=1000`、图片含 `image_url`；临时用户调用返回 `retError`。Feature 测试覆盖：① 有共享文件 ② 有游客链接文件 ③ 两者都有的文件只出现一次 ④ 都没有 → 空数组 ⑤ 别人共享给我的文件不出现 ⑥ 临时用户报错。
2. 前端：勾「我共享的」→ 列表变成跨文件夹拉平结果，自动切到 table 视图，能看到「共享状态」「共享给」列；取消勾选 → 回到原文件夹、`tableMode` 恢复成勾选前的值。
3. 普通浏览某文件夹时，`guest_access=1` 的行能看到「游客」徽标/Tag，`share=1` 的能看到共享标记。
4. 汇总视图里点文件夹 → 跳进该文件夹且开关复位；点文件 → 正常预览；右键「退出共享」/改共享后 → 汇总列表里该项立即消失/更新。
5. 刷新页面后「我共享的」开关状态保持，且列表数据正确（不为空）。
6. `./cmd prod` 构建通过；dootask Feature 测试（`./cmd composer exec phpunit --filter <新测试类>`）通过。

## 8. 标记约定

本功能所有提交的代码/文档文件都带 `[CUSTOM:file-share-manage]` 标记（fork 的 `.git/hooks/pre-commit` 已把此标记加入允许列表）。`language/*.txt` 例外（钩子不检查）。
