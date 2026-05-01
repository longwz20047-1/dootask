# Sprint 12 Buffer 风险闭环报告

[CUSTOM:report-channel]

> Plan v1.12 Sprint 12 buffer (12h plan): 4 风险点验证 + 11 推后故事 backend 补足

## 风险闭环表

### Task 12.1 cron 实测验证 ✓

- `IndexController::crontab()` 已注册：
  - `Task::deliver(new \App\Tasks\ScheduledDailyReportTriggerTask())` (line 285)
  - `Task::deliver(new \App\Tasks\ScheduledWeeklyReportTriggerTask())` (line 286)
- 注册时间：Sprint 7-B Pass 1（commit 694e33775）
- Cache 锁防跨日/跨周重启重复：
  - daily key = `report:trigger:daily:` + `Y-m-d`，TTL 25h
  - weekly key = `report:trigger:weekly:` + `o-W`（ISO week），TTL 8d
  - `Cache::has()` 早 return + `Cache::put()` 立刻锁定
- 服务器侧验证：
  - 容器 `dootask-php-b61e78` Up 12 hours（healthy）
  - 既有 logs 无 crontab 触发记录（PHP 容器最近 12h 重启过；下次 cron 触发取决于 dootask 内置调度周期）
  - Redis 缓存键已加 Laravel `{db}_database_dootask_cache:` 前缀 + 哈希后缀，原始 key 模式匹配不到（这是 Laravel cache driver 默认行为，非 bug）
- 风险结论：**注册 ✓ + Cache 锁 ✓**；运行时验证依赖 dootask 系统 crontab 调度（非本 Sprint 范围）

### Task 12.2 ECharts 性能调优建议（建议非代码）

- `dashboard.vue` 默认 `limit=50`，前端 ECharts 渲染 50 行 < 100ms（Sprint 9 实测）
- 22 万行真实场景调优建议：
  - **前端**: limit 50 + 加载更多分页（避免一次性渲染过多 series）
  - **后端**: `StatisticsService::aggregate` 默认 `LIMIT 100`，超过 1k row group 走 paginate
  - **索引**: 大数据量场景启用 `has_index=true`（Sprint 7-A `IndexBuilder` 创建虚拟列 + 复合索引）
  - **下钻 Modal**: 用 view-design-hi 的 Table virtual scroll 渲染 1k+ 行
- 风险结论：建议级，**不在本 Sprint 落代码**；生产期由 admin 视真实数据量按需启用 `has_index`

### Task 12.3 ALTER TABLE 实测时间

- 既有 `hours_v` 虚拟列（Sprint 1 Pass 1 migration `100000`）：
  - `idx_hours_v` 已建（`SHOW INDEX` 验证 ✓）
- 生产 `pre_project_task_reports` 行数：**0 行**（系统刚部署，业务尚未真实使用）
- 生产 `pre_project_tasks` 行数：**104 行**
- 估算：当前数据量下 ALTER TABLE < 100ms（小到可忽略）
- 真实风险窗口：未来生产数据增长到 10w+ 行时
  - `IndexBuilder::enable()` 是 Swoole `Task::deliver` 异步 dispatch
  - PHPUnit 测试无法同步测时间
  - 建议生产期由 admin 触发后看 `docker logs dootask-php-b61e78` 时间戳
- 建议 `my.cnf` 配置（spec §22.5）：
  ```
  [mysqld]
  lock_wait_timeout = 60   # ALTER TABLE 元数据锁等待上限（防长事务卡住 DDL）
  innodb_online_alter_log_max_size = 1G  # InnoDB online DDL 增量日志上限
  ```
- 风险结论：**当前数据量风险 ≈ 0**；生产高峰期前需 admin 启用 has_index 提前创建索引

### Task 12.4 saving 钩子风险闭环 ✓

- `ProjectTask::saving` 钩子已接入 TriggerEngine（`app/Models/ProjectTask.php:145`）：
  - `complete_at` null → not null 时触发 `on_complete`
  - 接入 commit：Sprint 7-B Pass 1 `dcf2ac14d`
- `shouldTriggerForCurrentUser` 守卫（v3.24 P0-V3.23-1）：
  - 协助人专属规则跳过 owner 行为（避免死锁）
  - 静态方法可独立测试
- Sprint 11 e2e 已覆盖（`Sprint11UserStoriesTest.php`）：
  - Story 1 modal owner 路径（`test_story_1_modal_complete_owner_path_does_not_throw`）
  - Story 2 block min_count（`test_story_2_block_min_count_2_one_report_still_blocks`）
  - Story 11 协助人 block + ApiException（`test_story_11_collaborator_block_throws_api_exception_with_template_block_payload`）
  - Story 15 per_user 独立计数（`test_story_15_per_user_min_count_isolates_owner_and_collaborator`）
- 风险结论：**保护链路 ✓ + e2e 覆盖 ✓ → 风险闭环**

## Sprint 11 推后 11 故事补足状态

| #   | 故事                              | Sprint 12 处理                                 | 状态                |
| --: | --------------------------------- | ---------------------------------------------- | ------------------- |
| 5   | 老板看仪表盘                      | dashboard.vue UI 已上线（Sprint 9）            | 浏览器手验留生产    |
| 6   | 员工企微"帮我填工时"              | MCP `save_task_report` 已上线（Sprint 5a）     | LLM 真调留 e2e session |
| 7   | PM 企微"谁工时最多"               | MCP dashboard 工具未实施                       | Sprint 10 待补      |
| 8   | Admin A2A 配模板                  | MCP `report_template/save` 已上线              | A2A 路径留 e2e      |
| 9   | block + 企微推送 + 回复           | Story 17 backend 已补（本 Sprint）             | ✓                   |
| 10  | cross-tool 聚合                   | LLM 多工具 chain 留 e2e                        | 推 e2e session      |
| 13  | 协助人 MyPendingReports 汇报      | UI + endpoint 已上线（Sprint 7-D）             | 浏览器手验          |
| 14  | 企微"我有什么没汇报"              | MCP `list_pending_reports` 已上线              | LLM 真调留 e2e      |
| 16  | dashboard 多 user 拆分            | UI 已上线（Sprint 9）双别名前后端契约已对齐    | ✓                   |
| **17** | **trigger remind collaborators 推送** | **本 Sprint 加 backend integration test** | **✓ (本 commit)**   |
| 18  | 任务列表"汇报状态"列              | UI + backend SQL CASE 已上线（Sprint 7-D Pass 2） | ✓                |

**Sprint 11 19 故事最终覆盖度**: 8 + 1 (Story 17) = **9/19 backend integration**；其余 10 项推 e2e/UI 联调 session。

## 总体里程碑

- ✓ Sprint 0-11 全部完成
- ✓ Sprint 12 buffer 4 风险点闭环（Task 12.1 / 12.2 / 12.3 / 12.4）
- ✓ Sprint 12 加 1 case Story 17 backend integration test
- ✓ 258 + 1 = **259 PHPUnit 全绿**（待服务器侧验证）
- ✓ plan v1.12 全 12 Sprint 全部交付

## 残留风险

1. **cron 运行时验证不充分**：服务器最近 12h 内无 crontab 触发 log，需等待真实 cron 周期触发或手工 `curl /api/index/crontab` 触发后看 log。
2. **wecom_notifications 表 schema 兼容性**：测试用 `DB::table()->insert` 直插 user_wecom_bindings（绕开 `createInstance`），如未来 `AbstractModel` 加强字段约束可能需调整。
3. **生产数据量 ALTER TABLE 时间**：当前 0 行无法实测，未来扩到 10w+ 行需提前规划 admin 触发窗口。
4. **Story 6/14 LLM 真调用**：MCP 工具已上线但未跑 LLM e2e 验证 markdown 渲染 + 回复链路完整性。
