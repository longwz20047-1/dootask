# 任务上报通道 i18n 包装规约

`[CUSTOM:report-channel]`

> **适用范围**：任务上报通道（plan v1.12 Sprint 3-10）所有新写 PHP / Vue 用户可见字串
>
> **不适用**：Sprint 1-2 已写代码（保留中文字面量，由 Sprint 10 Task 10.4 集中走 dootask `language/translate.php` 自动提取追加 `original-api.txt`）

## 背景

dootask 真实 i18n 体系：

- **不走** Laravel 标准 `resources/lang/`（dootask 0 处该目录）
- **真实位置**：`dootask/language/`
  - `original-api.txt` — 后端中文原文清单
  - `original-web.txt` — 前端中文原文清单
  - `translate.json` — 1.3M k-v 翻译表（多语言）
  - `translate.php` — 提取脚本（一次性扫源码追加原文 + 重生 translate.json）
- **后端 helper**：`App\Module\Doo::translate()` —— 不是 `Base::Lang()` / `__()` / `trans()`
- **前端 helper**：`$L()` —— 读 `translate.json`，与后端共享翻译表

## 核心规约

### PHP 后端

所有用户可见字串必须用 `Doo::translate()` 包装：

```php
use App\Module\Doo;

// ✓ 正确
throw new ApiException(Doo::translate('需补两次完成上报后再标记完成'), [], -1);
return Base::retSuccess(Doo::translate('保存成功'), $data);

// ✗ 错误
throw new ApiException('字段校验失败', [], -1);          // 未包装
throw new ApiException(Base::Lang('xxx'));               // dootask 不存在 Base::Lang
throw new ApiException(__('xxx'));                       // dootask 不走 Laravel 标准 i18n
```

`Doo::translate()` 真实实现位于 `app/Module/Doo.php`，会自动读 `language/translate.json` 做 i18n 替换。

### Vue 前端

所有用户可见字串必须用 `$L()` 包装（dootask 既有约定）：

```vue
<!-- ✓ 正确 -->
<Modal :title="$L('请尽快完成本次任务上报')" />
this.$Message.error(this.$L('完成失败'));

<!-- ✗ 错误：未包装 -->
<Modal title="请尽快完成本次任务上报" />

<!-- ✗ 错误：动态拼接破坏翻译完整性 -->
this.$L('共') + n + this.$L('条')      // 不可拼接

<!-- ✓ 正确：动态值用 (*) 占位 -->
this.$L('共(*)条', n)
```

注意（依据项目 CLAUDE.md）：

- `$A.modalXXX`、`$A.messageXXX`、`$A.noticeXXX` 内部已自动 `$L`，调用方不要再包
- 仅当传入 `language: false` 时由调用方自行包 `$L`

## 集中提取流程（Sprint 10 Task 10.4）

Sprint 10 末次执行：

```bash
cd dootask/language && php translate.php
```

自动：

1. 扫描所有 `Doo::translate(...)` / `$L(...)` 调用
2. 提取中文原文追加到 `original-api.txt` / `original-web.txt`
3. 重新生成 `translate.json`（之后一次性人工翻译 en/ja/ko 等）

## 报告通道当前状态

| Sprint        | 范围                                           | i18n 状态                                                |
| ------------- | ---------------------------------------------- | -------------------------------------------------------- |
| Sprint 1 (Pass 1-3) | Migration / Model / Service / Controller | 中文字面量保留（Sprint 10 集中提取）                     |
| Sprint 2      | Observer / 离职快照 / audit / **本规约**       | 同上（Observer 内部无用户可见字串；audit detail 走 §8 模板） |
| Sprint 3+     | Vue / 前端组件 / MCP 工具                      | **必须用 `$L()` / `Doo::translate()` 包装新增字串**       |

## 参考

- plan v1.12 Task 2.4（line 1281-1298）
- spec §10 i18n + 部署 + Fork 维护
- 项目根 `CLAUDE.md` 的「国际化」与「前端」章节
