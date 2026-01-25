<?php

namespace App\Module;

use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\User;
use App\Models\WebSocketDialog;
use App\Models\WebSocketDialogMsg;
use App\Module\AI;
use App\Module\Base;
use App\Module\Doo;
use App\Module\AiTaskSuggestion;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * AI 对话命令模块
 * 处理 /analyze 和 /summarize 命令的业务逻辑
 */
class AiDialogCommand
{
    /**
     * AI 助手的 userid
     */
    const AI_ASSISTANT_USERID = -1;

    /**
     * 执行 /analyze 命令
     */
    public static function analyze(WebSocketDialog $dialog, int $userId, int $notifyMsgId = 0): void
    {
        $lang = self::getUserLanguage($userId);

        switch ($dialog->group_type) {
            case 'task':
                self::analyzeTask($dialog, $userId, $lang, $notifyMsgId);
                break;
            case 'project':
                self::analyzeProject($dialog, $userId, $lang, $notifyMsgId);
                break;
            default:
                self::updateNotifyMessage($dialog, $notifyMsgId, '/analyze 命令仅在任务和项目对话中可用', 'error');
                break;
        }
    }

    /**
     * 执行 /summarize 命令
     */
    public static function summarize(WebSocketDialog $dialog, int $userId, int $notifyMsgId = 0): void
    {
        $lang = self::getUserLanguage($userId);

        switch ($dialog->group_type) {
            case 'task':
                self::summarizeTask($dialog, $lang, $notifyMsgId);
                break;
            case 'project':
                self::summarizeProject($dialog, $lang, $notifyMsgId);
                break;
            default:
                self::summarizeGeneral($dialog, $lang, $notifyMsgId);
                break;
        }
    }

    /**
     * 获取用户语言（用于 AI 输出语言）
     */
    private static function getUserLanguage(int $userId): string
    {
        $user = User::find($userId);
        return $user->lang ?? 'zh';
    }

    /**
     * 分析任务对话 - 复用 AiTaskSuggestion 逻辑
     */
    private static function analyzeTask(WebSocketDialog $dialog, int $userId, string $lang, int $notifyMsgId): void
    {
        $task = ProjectTask::with(['project', 'projectColumn'])->whereDialogId($dialog->id)->first();
        if (!$task) {
            self::updateNotifyMessage($dialog, $notifyMsgId, '未找到关联的任务', 'error');
            return;
        }

        $suggestions = [];

        // 检查并生成各类建议
        if (AiTaskSuggestion::shouldExecute($task, 'description')) {
            $result = AiTaskSuggestion::generateDescription($task);
            if ($result) $suggestions[] = $result;
        }

        if (AiTaskSuggestion::shouldExecute($task, 'subtasks')) {
            $result = AiTaskSuggestion::generateSubtasks($task);
            if ($result) $suggestions[] = $result;
        }

        if (AiTaskSuggestion::shouldExecute($task, 'assignee')) {
            $result = AiTaskSuggestion::generateAssignee($task);
            if ($result) $suggestions[] = $result;
        }

        if (AiTaskSuggestion::shouldExecute($task, 'similar')) {
            $result = AiTaskSuggestion::findSimilarTasks($task);
            if ($result) $suggestions[] = $result;
        }

        if (empty($suggestions)) {
            self::updateNotifyMessage($dialog, $notifyMsgId, '当前任务状态良好，暂无建议', 'success');
        } else {
            // 更新提示消息为成功
            self::updateNotifyMessage($dialog, $notifyMsgId, '分析完成', 'success');
            // 复用 AiTaskSuggestion 的消息构建和发送逻辑
            AiTaskSuggestion::sendSuggestionMessage($task, $suggestions);
        }
    }

    /**
     * 分析项目对话 - 项目健康度分析
     */
    private static function analyzeProject(WebSocketDialog $dialog, int $userId, string $lang, int $notifyMsgId): void
    {
        $project = Project::whereDialogId($dialog->id)->first();
        if (!$project) {
            self::updateNotifyMessage($dialog, $notifyMsgId, '未找到关联的项目', 'error');
            return;
        }

        // 收集项目统计数据
        $stats = self::getProjectHealthStats($project);

        // 构建分析 prompt
        $prompt = self::buildProjectAnalysisPrompt($project, $stats, $lang);

        // 调用 AI
        $result = AI::invoke([
            ['system', '你是 DooTask 任务管理系统的 AI 助手，擅长分析项目健康度并给出改进建议。'],
            ['user', $prompt],
        ], 120);

        if (Base::isError($result)) {
            self::updateNotifyMessage($dialog, $notifyMsgId, $result['msg'] ?? 'AI 分析失败', 'error');
            return;
        }

        $content = $result['data']['content'] ?? '';
        if (empty($content)) {
            self::updateNotifyMessage($dialog, $notifyMsgId, 'AI 未返回有效内容', 'error');
            return;
        }

        // 更新提示消息为成功
        self::updateNotifyMessage($dialog, $notifyMsgId, '分析完成', 'success');
        // 发送分析结果
        self::sendMessage($dialog, $content);
    }

    /**
     * 获取项目健康度统计数据
     */
    private static function getProjectHealthStats(Project $project): array
    {
        $projectId = $project->id;
        $now = Carbon::now();

        // 基础统计 - 只统计主任务
        $baseBuilder = ProjectTask::whereProjectId($projectId)
            ->whereNull('archived_at')
            ->whereNull('deleted_at')
            ->where('parent_id', 0);

        $totalTasks = (clone $baseBuilder)->count();

        $completedTasks = (clone $baseBuilder)
            ->whereNotNull('complete_at')
            ->count();

        // 逾期任务（未完成且已超过截止日期）
        $overdueTasks = (clone $baseBuilder)
            ->whereNull('complete_at')
            ->whereNotNull('end_at')
            ->where('end_at', '<', $now)
            ->get(['id', 'name', 'end_at']);

        // 今日到期任务
        $todayTasks = (clone $baseBuilder)
            ->whereNull('complete_at')
            ->whereDate('end_at', $now->toDateString())
            ->get(['id', 'name']);

        // 无负责人任务
        $unassignedTasks = (clone $baseBuilder)
            ->whereNull('complete_at')
            ->whereDoesntHave('taskUser', function ($q) {
                $q->where('owner', 1);
            })
            ->get(['id', 'name']);

        return [
            'total' => $totalTasks,
            'completed' => $completedTasks,
            'completion_rate' => $totalTasks > 0 ? round($completedTasks / $totalTasks * 100, 1) : 0,
            'overdue' => $overdueTasks->toArray(),
            'overdue_count' => $overdueTasks->count(),
            'today' => $todayTasks->toArray(),
            'today_count' => $todayTasks->count(),
            'unassigned' => $unassignedTasks->toArray(),
            'unassigned_count' => $unassignedTasks->count(),
        ];
    }

    /**
     * 构建项目分析 Prompt
     */
    private static function buildProjectAnalysisPrompt(Project $project, array $stats, string $lang): string
    {
        $langName = Doo::getLanguages($lang) ?: '简体中文';

        $overdueList = '';
        foreach (array_slice($stats['overdue'], 0, 5) as $task) {
            $endAt = Carbon::parse($task['end_at'])->format('Y-m-d');
            $overdueList .= "- #{$task['id']} {$task['name']} (截止: {$endAt})\n";
        }
        if ($stats['overdue_count'] > 5) {
            $overdueList .= "- ... 还有 " . ($stats['overdue_count'] - 5) . " 个逾期任务\n";
        }

        $todayList = '';
        foreach (array_slice($stats['today'], 0, 5) as $task) {
            $todayList .= "- #{$task['id']} {$task['name']}\n";
        }

        $unassignedList = '';
        foreach (array_slice($stats['unassigned'], 0, 5) as $task) {
            $unassignedList .= "- #{$task['id']} {$task['name']}\n";
        }
        if ($stats['unassigned_count'] > 5) {
            $unassignedList .= "- ... 还有 " . ($stats['unassigned_count'] - 5) . " 个无负责人任务\n";
        }

        $projectDesc = mb_substr($project->desc ?? '', 0, 200);

        return <<<PROMPT
分析以下项目的健康度状况，给出评估和建议。

项目名称：{$project->name}
项目描述：{$projectDesc}

统计数据：
- 总任务数：{$stats['total']}
- 已完成：{$stats['completed']}
- 完成率：{$stats['completion_rate']}%
- 逾期任务数：{$stats['overdue_count']}
- 今日到期：{$stats['today_count']}
- 无负责人任务：{$stats['unassigned_count']}

逾期任务（前5个）：
{$overdueList}

今日到期任务：
{$todayList}

无负责人任务（前5个）：
{$unassignedList}

输出要求：
1. 使用 Markdown 格式
2. 先给出项目健康度评分（优秀/良好/一般/需关注），用 emoji 标识
3. 分析当前存在的主要问题
4. 列出需要立即关注的事项
5. 给出具体的改进建议
6. 使用{$langName}输出
7. 保持简洁专业，不超过 500 字
PROMPT;
    }

    /**
     * 总结任务对话
     */
    private static function summarizeTask(WebSocketDialog $dialog, string $lang, int $notifyMsgId): void
    {
        $task = ProjectTask::whereDialogId($dialog->id)->first();
        if (!$task) {
            self::updateNotifyMessage($dialog, $notifyMsgId, '未找到关联的任务', 'error');
            return;
        }

        // 获取最近消息
        $messages = self::getRecentMessages($dialog->id, 50);

        if (empty($messages)) {
            self::updateNotifyMessage($dialog, $notifyMsgId, '暂无可供总结的消息记录', 'success');
            return;
        }

        // 构建总结 prompt
        $prompt = self::buildTaskSummaryPrompt($task, $messages, $lang);

        // 调用 AI
        $result = AI::invoke([
            ['system', '你是 DooTask 任务管理系统的 AI 助手，擅长总结任务讨论内容和进展情况。'],
            ['user', $prompt],
        ], 120);

        if (Base::isError($result)) {
            self::updateNotifyMessage($dialog, $notifyMsgId, $result['msg'] ?? 'AI 总结失败', 'error');
            return;
        }

        $content = $result['data']['content'] ?? '';
        if (empty($content)) {
            self::updateNotifyMessage($dialog, $notifyMsgId, 'AI 未返回有效内容', 'error');
            return;
        }

        // 更新提示消息为成功
        self::updateNotifyMessage($dialog, $notifyMsgId, '总结完成', 'success');
        // 发送总结结果
        self::sendMessage($dialog, $content);
    }

    /**
     * 总结项目对话
     */
    private static function summarizeProject(WebSocketDialog $dialog, string $lang, int $notifyMsgId): void
    {
        $project = Project::whereDialogId($dialog->id)->first();
        if (!$project) {
            self::updateNotifyMessage($dialog, $notifyMsgId, '未找到关联的项目', 'error');
            return;
        }

        // 获取最近消息
        $messages = self::getRecentMessages($dialog->id, 50);

        if (empty($messages)) {
            self::updateNotifyMessage($dialog, $notifyMsgId, '暂无可供总结的消息记录', 'success');
            return;
        }

        // 构建总结 prompt
        $prompt = self::buildProjectSummaryPrompt($project, $messages, $lang);

        // 调用 AI
        $result = AI::invoke([
            ['system', '你是 DooTask 任务管理系统的 AI 助手，擅长总结项目讨论内容。'],
            ['user', $prompt],
        ], 120);

        if (Base::isError($result)) {
            self::updateNotifyMessage($dialog, $notifyMsgId, $result['msg'] ?? 'AI 总结失败', 'error');
            return;
        }

        $content = $result['data']['content'] ?? '';
        if (empty($content)) {
            self::updateNotifyMessage($dialog, $notifyMsgId, 'AI 未返回有效内容', 'error');
            return;
        }

        // 更新提示消息为成功
        self::updateNotifyMessage($dialog, $notifyMsgId, '总结完成', 'success');
        // 发送总结结果
        self::sendMessage($dialog, $content);
    }

    /**
     * 总结普通对话
     */
    private static function summarizeGeneral(WebSocketDialog $dialog, string $lang, int $notifyMsgId): void
    {
        // 获取最近消息
        $messages = self::getRecentMessages($dialog->id, 50);

        if (empty($messages)) {
            self::updateNotifyMessage($dialog, $notifyMsgId, '暂无可供总结的消息记录', 'success');
            return;
        }

        // 构建总结 prompt
        $prompt = self::buildGeneralSummaryPrompt($dialog, $messages, $lang);

        // 调用 AI
        $result = AI::invoke([
            ['system', '你是 DooTask 任务管理系统的 AI 助手，擅长总结聊天内容。'],
            ['user', $prompt],
        ], 120);

        if (Base::isError($result)) {
            self::updateNotifyMessage($dialog, $notifyMsgId, $result['msg'] ?? 'AI 总结失败', 'error');
            return;
        }

        $content = $result['data']['content'] ?? '';
        if (empty($content)) {
            self::updateNotifyMessage($dialog, $notifyMsgId, 'AI 未返回有效内容', 'error');
            return;
        }

        // 更新提示消息为成功
        self::updateNotifyMessage($dialog, $notifyMsgId, '总结完成', 'success');
        // 发送总结结果
        self::sendMessage($dialog, $content);
    }

    /**
     * 获取最近消息
     */
    private static function getRecentMessages(int $dialogId, int $limit = 50): array
    {
        $messages = WebSocketDialogMsg::whereDialogId($dialogId)
            ->where('type', 'text')
            ->where('userid', '>', 0) // 排除系统消息和 AI 消息
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->get();

        $result = [];
        foreach ($messages->reverse() as $msg) {
            $user = User::find($msg->userid);
            $text = $msg->msg['text'] ?? '';
            // 移除 HTML 标签，保留纯文本
            $text = strip_tags($text);
            $text = mb_substr(trim($text), 0, 500);

            if (!empty($text)) {
                $result[] = [
                    'user' => $user ? $user->nickname : '未知用户',
                    'text' => $text,
                    'time' => $msg->created_at->format('Y-m-d H:i'),
                ];
            }
        }

        return $result;
    }

    /**
     * 构建任务总结 Prompt
     */
    private static function buildTaskSummaryPrompt(ProjectTask $task, array $messages, string $lang): string
    {
        $langName = Doo::getLanguages($lang) ?: '简体中文';

        $chatContent = '';
        foreach ($messages as $msg) {
            $chatContent .= "[{$msg['time']}] {$msg['user']}: {$msg['text']}\n";
        }

        $taskStatus = $task->complete_at ? '已完成' : '进行中';
        $progress = $task->percent ?? 0;

        return <<<PROMPT
总结以下任务的评论区讨论内容和进展情况。

任务名称：{$task->name}
任务状态：{$taskStatus}
完成进度：{$progress}%

讨论记录：
{$chatContent}

输出要求：
1. 使用 Markdown 格式
2. 总结主要讨论内容和关键决策
3. 列出待解决的问题（如有）
4. 总结任务当前进展和下一步计划
5. 使用{$langName}输出
6. 保持简洁，不超过 300 字
PROMPT;
    }

    /**
     * 构建项目总结 Prompt
     */
    private static function buildProjectSummaryPrompt(Project $project, array $messages, string $lang): string
    {
        $langName = Doo::getLanguages($lang) ?: '简体中文';

        $chatContent = '';
        foreach ($messages as $msg) {
            $chatContent .= "[{$msg['time']}] {$msg['user']}: {$msg['text']}\n";
        }

        return <<<PROMPT
总结以下项目对话的讨论内容。

项目名称：{$project->name}

讨论记录：
{$chatContent}

输出要求：
1. 使用 Markdown 格式
2. 总结主要讨论话题
3. 列出关键决策和结论
4. 列出待处理事项（如有）
5. 使用{$langName}输出
6. 保持简洁，不超过 300 字
PROMPT;
    }

    /**
     * 构建普通对话总结 Prompt
     */
    private static function buildGeneralSummaryPrompt(WebSocketDialog $dialog, array $messages, string $lang): string
    {
        $langName = Doo::getLanguages($lang) ?: '简体中文';

        $chatContent = '';
        foreach ($messages as $msg) {
            $chatContent .= "[{$msg['time']}] {$msg['user']}: {$msg['text']}\n";
        }

        $dialogName = $dialog->name ?? '对话';

        return <<<PROMPT
总结以下聊天记录的主要内容。

对话名称：{$dialogName}

聊天记录：
{$chatContent}

输出要求：
1. 使用 Markdown 格式
2. 总结主要讨论话题
3. 列出关键信息点和结论
4. 列出待处理事项（如有）
5. 使用{$langName}输出
6. 保持简洁，不超过 300 字
PROMPT;
    }

    /**
     * 发送消息到对话
     */
    private static function sendMessage(WebSocketDialog $dialog, string $content): void
    {
        WebSocketDialogMsg::sendMsg(
            null,
            $dialog->id,
            'text',
            ['text' => $content, 'type' => 'md'],
            self::AI_ASSISTANT_USERID,
            true,   // push_self
            false,  // push_retry
            true    // push_silence
        );
    }

    /**
     * 更新提示消息
     * @param WebSocketDialog $dialog 对话
     * @param int $notifyMsgId 提示消息ID
     * @param string $content 新内容
     * @param string $status 状态: success/error
     */
    private static function updateNotifyMessage(WebSocketDialog $dialog, int $notifyMsgId, string $content, string $status): void
    {
        // 清除并发锁
        self::releaseLock($dialog->id);

        if ($notifyMsgId <= 0) {
            // 没有提示消息ID，直接发送新消息
            self::sendMessage($dialog, $content);
            return;
        }

        // 根据状态添加前缀图标
        $prefix = $status === 'error' ? '❌ ' : '✅ ';
        $noticeContent = $prefix . $content;

        // 更新消息（不带 source=api，让前端自动翻译）
        WebSocketDialogMsg::sendMsg(
            'update-' . $notifyMsgId,
            $dialog->id,
            'notice',
            ['notice' => $noticeContent],
            self::AI_ASSISTANT_USERID,
            true,   // push_self
            false,  // push_retry
            true    // push_silence
        );
    }

    /**
     * 释放对话的 AI 命令锁
     */
    private static function releaseLock(int $dialogId): void
    {
        Cache::forget("ai_dialog_command:{$dialogId}");
    }

}
