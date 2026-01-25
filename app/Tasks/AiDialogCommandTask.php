<?php

namespace App\Tasks;

use App\Models\WebSocketDialog;
use App\Module\AiDialogCommand;
use Illuminate\Support\Facades\Cache;

/**
 * AI 对话命令异步任务
 * 处理 /analyze 和 /summarize 命令
 */
class AiDialogCommandTask extends AbstractTask
{
    protected int $dialogId;
    protected string $command;
    protected int $userId;
    protected int $pendingMsgId;

    public function __construct(int $dialogId, string $command, int $userId, int $pendingMsgId = 0)
    {
        parent::__construct();
        $this->dialogId = $dialogId;
        $this->command = $command;
        $this->userId = $userId;
        $this->pendingMsgId = $pendingMsgId;
    }

    public function start()
    {
        $dialog = WebSocketDialog::find($this->dialogId);
        if (!$dialog) {
            // 对话不存在，释放锁
            Cache::forget("ai_dialog_command:{$this->dialogId}");
            return;
        }

        try {
            match ($this->command) {
                'analyze' => AiDialogCommand::analyze($dialog, $this->userId, $this->pendingMsgId),
                'summarize' => AiDialogCommand::summarize($dialog, $this->userId, $this->pendingMsgId),
                default => null,
            };
        } catch (\Throwable $e) {
            // 异常时释放锁，避免死锁
            Cache::forget("ai_dialog_command:{$this->dialogId}");
            throw $e;
        }
    }

    public function end()
    {
    }
}
