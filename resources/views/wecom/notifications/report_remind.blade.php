{{-- resources/views/wecom/notifications/report_remind.blade.php --}}
{{-- [CUSTOM:report-channel] spec §10A 上报触发通知（remind 模式），照抄 task_assigned.blade.php 范式 --}}
{{-- 关键变量（由 Renderer 注入）：$taskId, $taskName, $projectId, $projectName, $endAtFormatted, $messageText, $baseUrl, $escape --}}
@php
    // 企微 API 限制：消息 URL 点击不会进自建应用 WebView，必须经 entry?redirect= 包装走 snsapi_base 静默 OAuth。
    // 与 task_assigned.blade.php:21-22 同款，使用 dootask SPA 真实路由 /#/single/task/{id}
    $taskPath = '/#/single/task/' . (int) $taskId;
    $taskUrl = $baseUrl . '/api/wecom/entry?redirect=' . rawurlencode($taskPath);
@endphp
### {!! $escape($messageText) !!}

> 项目：**{!! $escape($projectName) !!}**
> 任务：[{!! $escape($taskName) !!}]({!! $taskUrl !!})
@if($endAtFormatted)
> 截止：{{ $endAtFormatted }}
@endif

请尽快前往 [Dootask]({!! $taskUrl !!}) 完成上报。
