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

@php
    // 企微 API 限制：消息 URL 点击**不会**进自建应用 WebView，统一在企微内置浏览器打开。
    // 但经 entry?redirect= 包装后，内置浏览器触发 snsapi_base 静默 OAuth → 自动建 session →
    // 跳目标路径，**视觉等价 "应用 WebView 已登录任务页"**（2026-04-24 PC+手机双端实测通过）。
    //
    // 关键前提：dootask_base_url 必须是企微管理后台 redirect_domain 可信域名（mp.smee-china.com），
    // 不能是任何反代别名（v3 deploy guide 的 main.smee-china.com/dootask 是历史错占位，会白屏）。
    $taskPath = '/#/single/task/' . (int) $task_id;
    $detailUrl = rtrim($dootask_base_url, '/') . '/api/wecom/entry?redirect=' . rawurlencode($taskPath);
@endphp
[查看详情 →]({!! $detailUrl !!})

---
💬 你可以直接发送：

- `完成 #{{ (int) $task_id }}` — 标记完成
- `延期 #{{ (int) $task_id }} 到 2026-05-03` — 改截止时间
- `拒绝 #{{ (int) $task_id }} 并说明原因：...` — 拒绝任务
- `#{{ (int) $task_id }} 附件：[拖动文件进聊天窗]` — 添加交付物（M3 可用）
- `查看 #{{ (int) $task_id }} 详细` — 查看完整信息
