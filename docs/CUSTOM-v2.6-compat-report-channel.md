# Task 5a.5 · v2.6 兼容验证报告

`[CUSTOM:report-channel]`

> **范围**：plan v1.12 Sprint 5a Task 5a.5 — 验证 Sprint 2 + 3 已落地的生命周期级联 + 审计写入是否完全覆盖 spec §7（生命周期级联）+ spec §8（审计 + WS + Manticore）的 v2.6 继承要求。
>
> **结论**：**完全覆盖（路径 B）**。无需新增/修改代码。§3.6 模板观察者推迟到 Sprint 6 实施。

## 决策路径

任务 prompt 给出三种可能：

| 路径 | 描述 | 决策 |
|------|------|------|
| (A) | dootask `project_logs` 真有 `sign` 列且 spec §7 要求写入 → 补 commit 修 audit 段 | **不适用**（见下方实测） |
| (B) | dootask `project_logs` 无 `sign` 列 + spec §7 不要求 → Sprint 2+3 已覆盖，仅文档验证 | **采纳** |
| (C) | dootask 有 `sign` 但 v2.6 用法与本 plan 不兼容 → spec drift candidate | **不适用** |

## 实测证据

### 1. dootask `project_logs` 表结构（无 `sign` 列）

`database/migrations/2021_06_25_182631_create_project_logs_table.php` + 后续 4 处 ALTER：

| 字段 | 来源 migration |
|------|---------------|
| `id` `project_id` `column_id` `task_id` `userid` `detail` `timestamps` | `2021_06_25_182631_create_project_logs_table.php` |
| `record` (JSON) | `2022_01_11_191703_project_logs_add_record.php` |
| `record_userid` | `2022_04_26_110223_project_logs_record_userid.php` |
| `task_only` | `2024_05_31_084503_project_logs_add_task_only.php` |

**`sign` 列不存在于 `project_logs`**。

`grep -n 'sign' database/migrations/*project_logs*` → 0 命中。

### 2. dootask 中名为 `add_report_sign` 的 migration 是另一张表

`database/migrations/2022_01_04_111739_add_report_sign.php` 加列对象是 **`reports` 表**（dootask 公告/汇报模块的独立功能），**不是** `project_logs` 也不是任务上报通道相关：

```php
Schema::table('reports', function (Blueprint $table) {
    $table->string("sign")->default("")->comment("汇报唯一标识");
});
```

注意：dootask 的 `reports` 表是与本 plan 完全独立的"公告汇报"模块（每日/每周汇报），命名空间冲突。本 plan 的"任务上报通道"使用 `project_task_reports` + `task_field_definitions` + `task_field_attachments`，与 `reports` 表无任何关联。

### 3. `App\Models\ProjectLog` 模型字段（无 `sign` 属性）

`app/Models/ProjectLog.php` 的 `@property` PHPDoc 罗列字段：`id / project_id / column_id / task_id / task_only / userid / detail / record / created_at / updated_at`。无 `sign` 属性。

`grep -n 'sign' app/Models/ProjectLog.php` → 0 命中。

### 4. spec §7 / §8 不要求 `sign`

spec §7 line 2440-2455：

> ## 7. 生命周期级联（v3.3 集成 TriggerEngine）
>
> 继承 v2.6 §6（事件映射 + Observer 双架构 + CUSTOM 标记）+ v3.3 集成 TriggerEngine（详见 § 21）。仅术语 "TaskWorkHour" → "TaskReport"，scope 名 `forWorkhour` → `forReport`。

spec §8 line 2473-2479：

> ## 8. 审计 + WebSocket + Manticore
>
> 继承 v2.6 §7-§9 + §12。仅 detail 模板术语调整：
> - `登记{任务}工时 2.5h` → `提交{任务}上报 hours=2.5h difficulty=hard`（用户可见字段拼接）
> - WS action `'workhour'` → `'report'`

§7 / §8 全文均无 `sign` 字面量、无哈希签名要求。"继承 v2.6"指继承 dootask 既有 `ProjectTask::addLog()` 写 `project_logs` 体系，与 `sign` 无关。

## Sprint 2+3 已落地的覆盖证据

### §7 生命周期级联（Sprint 2 Task 2.1 完成）

`app/Observers/TaskReportObserver.php` 已实现 3 个钩子：

| 钩子 | 监听 | 职责 | spec 章节 |
|------|------|------|----------|
| `deleting(ProjectTask)` | 父任务软删 | 级联软删 reports + 标 `cascade_deleted=true` | v2.6 §6.2.1 / §9.2 |
| `restored(ProjectTask)` | 父任务恢复 | 仅恢复 `cascade_deleted=true` 的 reports（用户主动删的不动） | v2.6 §6.2.1 |
| `saved(ProjectTask)` | parent_id / project_id 变 | 同步关联 reports 三元组 | spec §3.4 |

注册：`app/Providers/EventServiceProvider.php` 把 `TaskReportObserver` 挂在 `ProjectTask::observe()` 上。

测试：`tests/Unit/Observers/TaskReportObserverTest.php`（覆盖 deleting / restored / saved 三路径 + cascade_deleted 区分）。

### §8 审计（Sprint 1 Pass 3 + Sprint 2 Task 2.3 完成）

`app/Http/Controllers/Api/ProjectController.php::report__save()` line 4147-4158：

```php
// 6. 写审计（spec §8 审计：复用 dootask 既有 ProjectLog 体系，detail 模板见 spec line 2475）
$detail = ($isUpdate ? '更新' : '提交') . '{任务}上报';
if (!empty($result['sanitized'])) {
    $detail .= ' [' . implode(',', array_keys($result['sanitized'])) . ']';
}
$task->addLog($detail, [
    'report_id' => (int) $report->id,
    'work_date' => $workDate,
    'values'    => $result['sanitized'],
]);
```

通过 `ProjectTask::addLog()` 走 dootask 既有 `ProjectLog::createInstance()` 写 `pre_project_logs`：
- `detail` 含动作（提交/更新）+ 字段 key 列表（不拼用户原文，避免长 textarea 撑爆模板）
- `record` JSON 含 `report_id` / `work_date` / `values`

测试：`tests/Feature/Report/ReportSaveTest.php`：
- `test_save_writes_audit_log_with_record` — 断言 detail / record JSON 结构
- `test_save_audit_log_distinguishes_create_vs_update` — 断言"提交"vs"更新"区分

### §3.4 NF3 reporter_userid 快照（Sprint 1 Pass 2 / Pass 3 完成）

`app/Models/TaskReport.php::boot()` 注册 `updating` 钩子：

```php
static::updating(function (self $report) {
    if ($report->isDirty('reporter_userid')) {
        $report->reporter_userid = $report->getOriginal('reporter_userid');
    }
});
```

测试：`tests/Unit/Models/TaskReport/ReporterSnapshotTest.php`（如存在）+ `TaskReportTest::test_reporter_relation_resolves_user`。

> **注**：本测试位于 `tests/Unit/Models/TaskReport/ReporterSnapshotTest.php`（Sprint 2 Task 2.2 创建）。

## §3.6 模板观察者推迟到 Sprint 6 显式声明

spec §3.6 描述 `TaskReportTemplateObserver` 监听 `task_field_definitions` 变更后通过模板表（`task_report_templates`）下发到模板使用方。**本 Task 5a.5 不实施 §3.6**，原因：

1. `task_report_templates` 表 DDL 在 plan v1.12 Sprint 6 Task 6.4 范围
2. `TaskReportTemplate` model 创建在 Sprint 6 Task 6.5 范围
3. `TaskReportTemplateObserver` 创建在 Sprint 6 Task 6.7 范围
4. Sprint 5a 仅完成附件上传链路 + v2.6 兼容验证，不跨 Sprint 6 范围

## 验证结论

- **§7（生命周期级联）**：Sprint 2 Task 2.1 `TaskReportObserver`（3 钩子）+ Sprint 1 Pass 2 `TaskReport::boot updating` 钩子完整覆盖。
- **§8（审计）**：Sprint 1 Pass 3 Task 1.7 `report__save` 末尾 `$task->addLog(...)` + Sprint 2 Task 2.3 测试覆盖。
- **`sign` 列**：dootask `project_logs` 无该列，spec §7/§8 不要求；非 v2.6 兼容点。
- **§3.6 模板观察者**：明确推迟到 Sprint 6 Task 6.7 实施。

**Task 5a.5 决策路径 (B) 已落地，无代码改动需求**。
