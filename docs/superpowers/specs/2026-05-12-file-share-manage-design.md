# 文件页「我共享的」汇总视图 + 行内共享/游客标记 — 设计

> [CUSTOM:file-share-manage] DooTask 二开自定义功能
> 日期：2026-05-12
> 状态：已确认，待出实施计划

## 1. 背景与问题

DooTask 文件页只能一层层翻文件夹。用户共享给成员（`files.share = 1`，走 `file_users` 表）或开启游客访问链接（`files.guest_access = 1`）后，**无法集中看到「我共享了哪些文件、哪些允许游客访问」**——共享状态只在浏览到文件所在文件夹时才以一个小图标体现，`guest_access` 状态则只在打开「共享设置」弹窗时才看得到。结果是没法管理（撤销共享、关闭游客链接）。

## 2. 目标

- **汇总视图**：一键看到当前用户拥有的、所有 `share = 1` 或 `guest_access = 1` 的文件/文件夹（跨文件夹拉平）。
- **行内标记**：在普通浏览和汇总视图里，每个文件行都能看到「已共享给成员」「已开游客链接」状态。
- 汇总视图里能直接对每条做现有的管理操作（共享设置 / 复制链接 / 取消共享 —— 复用现有右键菜单，不新增操作）。

## 3. 非目标（YAGNI）

- 不新增路由 / 独立页面，全部在现有文件页内实现。
- 不做批量取消共享（现有右键菜单已有「取消共享」，汇总视图里照样能用）。
- 不递归列出共享文件夹下的子文件——只列「共享根」（用户显式共享/开放的那个文件或文件夹）。`files.pshare != 0 && files.share == 0` 的传递性子文件不进汇总。
- 不动现有 `file/lists`、`file/search`、`hideShared`（「仅显示我的」）的行为。

## 4. 数据事实（已核对代码）

- `files` 表已有列：`share`（是否共享，`int`）、`guest_access`（是否允许游客访问，`int`）、`pshare`（所属分享根 ID）。**无需 migration。**
- `File` 模型无 `$hidden` / `$appends`，`$file->toArray()` 已带出 `share` / `guest_access` / `pshare` —— 即**前端现有文件列表数据里已经含这两个字段**，只是没渲染 `guest_access`。
- `guest_access` 在 `FileController::link()`（`api/file/link`）里被设置：生成公开链接时按 `?guest_access=yes/no` 写入 `files.guest_access`。所以「允许游客访问」≈「这个文件有过一条允许游客的公开链接」，与 `share`（共享给成员）相互独立。
- 现有 `FileController::lists()` → `File::getFileList($user, $pid)`：`pid=0` 时返回「自己根目录的文件 + 别人共享给我的文件（`pid` 改写成 0）」；`pid>0` 时返回该文件夹下内容 + 父级链。
- 前端 `file.vue`：`fileList` 既用于 block 视图（`.file-list ul li`）也用于 table 视图（`<Table :columns="columns" :data="fileList">`）；工具栏 `.file-navigator` 里已有 `hideShared` checkbox（「仅显示我的」，状态存 IndexedDB `fileHideShared`）和 `tableMode`（`block` / `table`）切换；`fileBlockIconClasses(item)` 给 `item.share` 的块加 `share` class，模板里 `item.share` 时显示 `<i class="taskfont">&#xe757;</i>` 图标或共享者头像。

## 5. 方案

### 5.1 后端：新增 `GET api/file/shared`

`FileController::shared()`：

```php
public function shared()
{
    $user = User::auth();
    if ($user->isTemp()) {
        return Base::retError('无法查看');
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
        $temp['pid'] = 0;                 // 拉平：汇总视图里不体现层级
        $temp['permission'] = 1;          // 自己的文件恒为读写
        $array[] = File::handleImageUrl($temp);  // 图片返回预览地址，与 getFileList 一致
    }
    return Base::retSuccess('success', $array);
}
```

- 路由自动映射（`Route::any('api/{resource}/{method}')` → `api/file/shared` → `shared()`），无需改路由文件。
- 不分页（`take(500)`，与 `getFileList` 的 500 上限一致）；后续若有人共享 >500 个文件再说。
- 返回结构和 `getFileList` 的元素同构（含 `id/pid/name/type/share/guest_access/permission/size/updated_at/...`），前端能直接复用渲染。

### 5.2 前端 `file.vue`

#### (a) 「我共享的」开关

- 在 `.file-navigator` 工具栏，`hideShared` 那个 `<div class="only-checkbox">` **左边**，加：
  ```html
  <div class="only-checkbox">
      <Checkbox v-model="sharedView">{{showBtnText ? $L('我共享的') : $L('我共享')}}</Checkbox>
  </div>
  ```
- `sharedView` 状态：初始从 IndexedDB `fileSharedView` 读（和 `hideShared` 读 `fileHideShared` 一个套路）；`watch` 到变化时写回 IndexedDB。
- `sharedView` 打开时：
  1. 清掉 `searchKey`（与搜索互斥）。
  2. 调 `file/shared`，结果赋给 `fileList`（不走 vuex `getFiles`，直接 `this.$store.dispatch("call", {url:'file/shared'})` 或新增一个 action，按现有风格）。
  3. 设一个 `sharedView` 派生的「禁用层级导航」语义：面包屑只显示「全部文件 › 我共享的（N）」，不渲染 `navigator` 链。
- `sharedView` 关闭 / 点面包屑「全部文件」/ 用搜索框 → 设 `sharedView=false` 并 `getFileList()`（回到当前 `pid`）。
- 进入 `sharedView` 时**自动切 `tableMode='table'`**（表格视图才有「共享给/更新时间」列；用户仍可手动切回 block）。退出 `sharedView` 不强制改回。

#### (b) 汇总视图里的点击行为

复用现有 `dropFile` / `clickRow`：
- 点**文件夹** → `sharedView=false`，`browseFolder(folder.id)` 跳进该文件夹（回到普通浏览）。
- 点**文件** → 照常打开预览（现有逻辑不变）。
- 右键 → 现有右键菜单（共享设置 / 复制链接 / 取消共享 …）原样可用。`sharedView` 下取消共享后需刷新 `file/shared`（在现有 `share/update`、`share/out` 成功回调里：若 `sharedView` 则重新拉 `file/shared`，否则 `getFileList()`）。

#### (c) 行内「游客」标记

- **block 视图**：`item.share` 已有图标/头像。新增——`item.guest_access` 时在文件块右下角加一个「游客」徽标（`<Icon type="md-globe" />` 或 `<i class="taskfont">…</i>`，配 tooltip「已开启游客访问链接」）。`share` 与 `guest_access` 可同时出现（两个徽标并列）。
- **table 视图**：在 `columns` 里新增一列「共享状态」，render 一个或两个 Tag：`共享`（绿色）/ `游客`（橙色）；都没有则空。再补一列「共享给」——render `share=1` 时调一下现有的「读取共享对象」逻辑（或简单显示「已共享」/共享人数），`guest_access` 且未共享给成员时显示「—（仅链接）」。`columns` 是 computed，按现有写法加列即可。
- 普通浏览视图同样显示这些标记（不只汇总视图）——因为数据本来就在。

#### (d) i18n

新增用户可见文案追加到 `language/original-web.txt`（去重）：`我共享的`、`我共享`、`已开启游客访问链接`、`共享状态`、`共享给`、`游客`、`仅链接`、`我共享的（(*)）`（如用占位）。后端 `shared()` 的两个错误文案（`无法查看`）—— `无法查看` 已存在则不重复加，否则追加到 `language/original-api.txt`。

## 6. 影响面 / 风险

- 后端：纯新增方法，不碰已有逻辑 → 风险极低。
- 前端：`file.vue` 已经很大；本次新增集中在工具栏开关 + `columns` 加列 + block 模板加一个徽标 + 几个回调里 `if (sharedView) reloadShared()`。不重构现有代码。
- `sharedView` 与 `searchKey`、`hideShared`、`tableMode`、`shearFirst`（剪切态）、`selectedItems`（多选态）的交互：进入 `sharedView` 时若有未完成的剪切/多选，先 `clearShear()` / `clearSelect()`（沿用现有方法）。
- 移动端（`showBtnText=false`）：开关文案用短版「我共享」；徽标在小尺寸下也要能看清——block 视图徽标尺寸跟现有 `share-icon` 对齐。

## 7. 验收标准

1. 后端：`GET api/file/shared`（带 token）返回当前用户所有 `share=1 或 guest_access=1` 的文件，按 `updated_at` 倒序；临时用户调用返回错误。Feature 测试覆盖：有共享/有游客链接/两者都有/都没有（空数组）/临时用户。
2. 前端：勾「我共享的」→ 列表变成跨文件夹的拉平结果，面包屑显示「全部文件 › 我共享的（N）」，自动切表格视图；表格里能看到「共享状态」「共享给」列。
3. 普通浏览某个文件夹时，`guest_access=1` 的文件行能看到「游客」标记，`share=1` 的能看到共享标记。
4. 汇总视图里点文件夹 → 跳进该文件夹且开关复位；点文件 → 正常预览；右键取消某文件共享后，汇总列表自动移除该项。
5. 刷新页面后「我共享的」开关状态保持（IndexedDB）。
6. `./cmd prod` 构建通过；`./cmd composer exec phpunit`（文件相关测试）通过。

## 8. 标记约定

本功能所有提交的代码/文档文件都带 `[CUSTOM:file-share-manage]` 标记（满足 fork 的 pre-commit 钩子；钩子允许标记列表需相应新增此项）。`language/*.txt` 例外（钩子不检查）。
