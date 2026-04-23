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
