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
        return preg_replace('/([*_`\[\]()~#+\-.!|>])/u', '\\\\$1', $s);
    }

    /**
     * 渲染 task_assigned 通知
     *
     * @param array $payload  含 task_name/project_id/project_name/end_at/creator_nickname/priority
     * @param int $taskId
     * @return string markdown 字符串
     */
    public function renderTaskAssigned(array $payload, int $taskId): string
    {
        // dootask MariaDB 是 PRC 时区（config/app.php:70 'timezone'=>'PRC'），
        // end_at 存的就是本地时间字符串，不要再 setTimezone（会 +8h）
        $endAt = $payload['end_at'] ?? null;
        $endAtFormatted = $endAt ? Carbon::parse($endAt)->format('Y-m-d H:i') : null;

        $vars = [
            'task_id'           => $taskId,
            'task_name'         => $payload['task_name'] ?? '',
            'project_id'        => $payload['project_id'] ?? 0,
            'project_name'     => $payload['project_name'] ?? '',
            'end_at_formatted'  => $endAtFormatted,
            'creator_nickname'  => $payload['creator_nickname'] ?? '',
            'priority'          => $payload['priority'] ?? '',
            'dootask_base_url'  => rtrim(config('wecom.dootask_base_url'), '/'),
            // v2.1: 改箭头函数而非 callable 数组
            // callable 数组 [self::class, 'method'] 在 PHP 8 运行时合法（first-class callable），
            // 但 PHPStan / Psalm 静态分析会报 "Cannot invoke value of type array"，CI 会挂
            // 箭头函数零歧义，显式 Closure 类型
            'escape'            => fn(?string $s): string => self::escapeMarkdownChars($s),
        ];

        return trim(view('wecom.notifications.task_assigned', $vars)->render());
    }
}
