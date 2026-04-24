{{-- 注意：
     用 {!! ... !!} 原样输出（escapeMarkdownChars 已转义 markdown 特殊字符）
     如果用 {{ }} Laravel 会 HTML escape < > " '，导致 markdown 反引号等被破坏
     而纯 markdown 文本里 < > 也需要原样保留（企微 markdown 是纯 markdown 不是 HTML）
--}}
### 📋 新任务分配给你

**任务**：{!! $escape($task_name) !!}
**项目**：{!! $escape($project_name) !!}
**截止**：{{ $end_at_formatted ?: '无' }}
**分配人**：{!! $escape($creator_nickname) !!}
**优先级**：{!! $escape($priority ?: '未设置') !!}

---
💬 你可以直接发送：

- `完成 #{{ (int) $task_id }}` — 标记完成
- `延期 #{{ (int) $task_id }} 到 2026-05-03` — 改截止时间
- `拒绝 #{{ (int) $task_id }} 并说明原因：...` — 拒绝任务
- `#{{ (int) $task_id }} 附件：[拖动文件进聊天窗]` — 添加交付物（M3 可用）
- `查看 #{{ (int) $task_id }} 详细` — 查看完整信息
