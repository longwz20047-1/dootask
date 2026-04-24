<?php

namespace App\Services\Wecom;

use Carbon\Carbon;

class WecomMarkdownRenderer
{
    /**
     * 转义 markdown 特殊字符，防止任务名含 * _ ` [ ] 等破坏渲染 / 注入指令
     */
    public static function escapeMarkdownChars(?string $s): string
    {
        if ($s === null || $s === '') return '';
        // 反斜杠必须最先转（否则后面的转义会被破坏）
        $s = str_replace('\\', '\\\\', $s);
        // S1: 换行符统一为单空格，防 task_name 注入新段落/标题/列表
        $s = preg_replace('/[\r\n]+/', ' ', $s);
        // S5: preg_replace 在非法 UTF-8 下可能返 null，加 ?? $s fallback 防 TypeError
        return preg_replace('/([*_`\[\]()~#+\-.!|>])/u', '\\\\$1', $s) ?? $s;
    }

    /**
     * 渲染 task_assigned 通知
     *
     * @param array $payload  含 task_name/project_id/project_name/end_at/creator_nickname/priority
     * @param int $taskId
     * @return string markdown 字符串
     */
    /**
     * M2-zero · 渲染两条消息（template_card + markdown 话术）
     *
     * 两条消息按数组顺序发送，bridge 会串行调用 wsClient.sendMessage
     * 保证"卡片紧邻话术"不被并发插入。
     *
     * @return array 含 2 个元素的数组：
     *   [0] template_card payload  （任务卡片 · card_action.url = entry 包装）
     *   [1] markdown payload       （4 条回复触发话术 · 无反引号）
     */
    public function renderTaskAssignedMessages(array $payload, int $taskId): array
    {
        $taskName       = $payload['task_name']        ?? '';
        $projectName    = $payload['project_name']     ?? '';
        $priority       = $payload['priority']         ?? '';
        $creator        = $payload['creator_nickname'] ?? '';
        $columnName     = $payload['column_name']      ?? '';
        $descSummary    = $payload['desc_summary']     ?? '';

        // 截止时间（PRC 时区，不再 setTimezone 避免 +8h）
        $endAtFormatted = '';
        if (!empty($payload['end_at'])) {
            try {
                $endAtFormatted = Carbon::parse($payload['end_at'])->format('Y-m-d H:i');
            } catch (\Throwable $e) {
                $endAtFormatted = '';
            }
        }

        // entry 包装 URL（企微 PC 点击内置浏览器，snsapi_base 静默 OAuth 直达任务）
        $baseUrl = rtrim(config('wecom.dootask_base_url') ?? '', '/');
        $taskPath = '/#/single/task/' . $taskId;
        $detailUrl = $baseUrl . '/api/wecom/entry?redirect=' . rawurlencode($taskPath);

        // 构造 template_card horizontal_content_list（上限 6，按字段存在与否动态填）
        $hcl = [];
        if ($projectName !== '')   $hcl[] = ['keyname' => '项目',    'value' => $projectName];
        if ($endAtFormatted !== '') $hcl[] = ['keyname' => '截止',    'value' => $endAtFormatted];
        if ($columnName !== '')    $hcl[] = ['keyname' => '看板列',  'value' => $columnName];
        if ($priority !== '')      $hcl[] = ['keyname' => '优先级',  'value' => $priority];
        if ($creator !== '')       $hcl[] = ['keyname' => '分配人',  'value' => $creator];
        if ($descSummary !== '')   $hcl[] = ['keyname' => '📝 描述', 'value' => $descSummary];

        // 消息 1: template_card（点击 card_action.url = 企微 PC/手机内置浏览器直达任务）
        $cardMsg = [
            'msgtype' => 'template_card',
            'template_card' => [
                'card_type' => 'text_notice',
                'source' => [
                    'icon_url' => '',
                    'desc' => 'Dootask 任务 #' . $taskId,
                    'desc_color' => 0,
                ],
                'main_title' => [
                    'title' => '📋 ' . $taskName,
                ],
                'horizontal_content_list' => $hcl,
                'card_action' => [
                    'type' => 1,
                    'url'  => $detailUrl,
                ],
            ],
        ];

        // 消息 2: markdown（4 条回复触发话术 · 无反引号避免企微渲染截断）
        $mdContent = "💬 回复触发操作：\n\n"
                   . "完成 #{$taskId} — 标记完成\n"
                   . "延期 #{$taskId} 到 2026-05-03 — 改截止时间\n"
                   . "拒绝 #{$taskId} 并说明原因：... — 拒绝任务\n"
                   . "查看 #{$taskId} 详细 — 查看完整信息";
        $mdMsg = [
            'msgtype' => 'markdown',
            'markdown' => ['content' => $mdContent],
        ];

        return [$cardMsg, $mdMsg];
    }

    /**
     * @deprecated M2-zero 改用 renderTaskAssignedMessages() 返 2 条 template_card + markdown
     * 保留此方法仅为单元测试兼容，新代码勿用。
     */
    public function renderTaskAssigned(array $payload, int $taskId): string
    {
        // dootask MariaDB 是 PRC 时区（config/app.php:70 'timezone'=>'PRC'），
        // end_at 存的就是本地时间字符串，不要再 setTimezone（会 +8h）
        // I2: try/catch 防非法字符串抛 InvalidFormatException 破坏通知链路，fallback 为 null（Blade 渲染为"无"）
        $endAt = $payload['end_at'] ?? null;
        $endAtFormatted = null;
        if ($endAt) {
            try {
                $endAtFormatted = Carbon::parse($endAt)->format('Y-m-d H:i');
            } catch (\Throwable $e) {
                $endAtFormatted = null;
            }
        }

        $vars = [
            'task_id'           => $taskId,
            'task_name'         => $payload['task_name'] ?? '',
            'project_id'        => $payload['project_id'] ?? 0,
            'project_name'      => $payload['project_name'] ?? '',
            'end_at_formatted'  => $endAtFormatted,
            'creator_nickname'  => $payload['creator_nickname'] ?? '',
            'priority'          => $payload['priority'] ?? '',
            // I1: config('wecom.dootask_base_url') 在 config/wecom.php 尚未建时返 null，
            // PHP 8.1+ rtrim(null, '/') 抛 deprecation → LaravelS 下可能终止协程
            'dootask_base_url'  => rtrim(config('wecom.dootask_base_url') ?? '', '/'),
            // v2.1: 改箭头函数而非 callable 数组
            // callable 数组 [self::class, 'method'] 在 PHP 8 运行时合法（first-class callable），
            // 但 PHPStan / Psalm 静态分析会报 "Cannot invoke value of type array"，CI 会挂
            // 箭头函数零歧义，显式 Closure 类型
            'escape'            => fn(?string $s): string => self::escapeMarkdownChars($s),
        ];

        return trim(view('wecom.notifications.task_assigned', $vars)->render());
    }
}
