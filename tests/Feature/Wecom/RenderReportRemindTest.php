<?php
// [CUSTOM:report-channel]
// Sprint 5a Task 5a.6: WecomMarkdownRenderer::renderReportRemind tests (spec §10A)

namespace Tests\Feature\Wecom;

use App\Models\Project;
use App\Models\ProjectTask;
use App\Services\Wecom\WecomMarkdownRenderer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * renderReportRemind 单元测试（spec §10A · plan v1.9 L1 P0）
 *
 * use DatabaseTransactions（不用 RefreshDatabase）— Swoole 常驻 + migrate:fresh
 * 触发 Config Facade 静态状态污染，第二个 test 起全炸（与 WecomMarkdownRendererTest 同款约束）
 */
class RenderReportRemindTest extends TestCase
{
    use DatabaseTransactions;

    public function test_render_report_remind_outputs_full_markdown(): void
    {
        $project = Project::factory()->create(['name' => '测试项目']);
        $task = ProjectTask::factory()->create([
            'name'       => '完成方案设计',
            'project_id' => $project->id,
        ]);

        $renderer = new WecomMarkdownRenderer();
        $rendered = $renderer->renderReportRemind([
            'task_id' => $task->id,
            'message' => '请尽快完成上报',
        ]);

        // 任务名 + 项目名 + 自定义提示文案
        $this->assertStringContainsString('完成方案设计', $rendered);
        $this->assertStringContainsString('测试项目', $rendered);
        $this->assertStringContainsString('请尽快完成上报', $rendered);
    }

    public function test_render_report_remind_includes_entry_redirect_task_url(): void
    {
        $project = Project::factory()->create();
        $task = ProjectTask::factory()->create([
            'name'       => 'X',
            'project_id' => $project->id,
        ]);

        $renderer = new WecomMarkdownRenderer();
        $rendered = $renderer->renderReportRemind([
            'task_id' => $task->id,
            'message' => '请补汇报',
        ]);

        // task URL 走 entry?redirect= 包装（spec §4C.14）
        $this->assertStringContainsString('/api/wecom/entry?redirect=', $rendered);
        // urlencoded 路径含 single/task/{id} ('/' → %2F, '#' → %23)
        $this->assertStringContainsString('%2F%23%2Fsingle%2Ftask%2F' . $task->id, $rendered);
    }

    public function test_render_report_remind_returns_empty_when_no_task(): void
    {
        $renderer = new WecomMarkdownRenderer();
        $rendered = $renderer->renderReportRemind([
            'task_id' => 999999999,  // 不存在的 task_id
            'message' => '',
        ]);

        $this->assertSame('', $rendered);
    }

    public function test_render_report_remind_returns_empty_when_no_task_id_key(): void
    {
        $renderer = new WecomMarkdownRenderer();
        // 完全没有 task_id key（?? 0 fallback 后 ProjectTask::find(0) 返 null）
        $rendered = $renderer->renderReportRemind(['message' => '测']);
        $this->assertSame('', $rendered);
    }

    public function test_render_report_remind_escapes_special_chars(): void
    {
        $project = Project::factory()->create(['name' => 'Proj']);
        $task = ProjectTask::factory()->create([
            'name'       => 'Test [special] *chars*',
            'project_id' => $project->id,
        ]);

        $renderer = new WecomMarkdownRenderer();
        $rendered = $renderer->renderReportRemind([
            'task_id' => $task->id,
            'message' => '提醒',
        ]);

        // 原始未 escape 的 [special] / *chars* 不应出现
        $this->assertStringNotContainsString('[special]', $rendered);
        $this->assertStringNotContainsString('*chars*', $rendered);
        // escape 后含 \[special\] + \*chars\*
        $this->assertStringContainsString('\\[special\\]', $rendered);
        $this->assertStringContainsString('\\*chars\\*', $rendered);
    }

    public function test_render_report_remind_handles_null_end_at(): void
    {
        $project = Project::factory()->create(['name' => 'P']);
        $task = ProjectTask::factory()->create([
            'name'       => 'Y',
            'project_id' => $project->id,
            'end_at'     => null,
        ]);

        $renderer = new WecomMarkdownRenderer();
        $rendered = $renderer->renderReportRemind([
            'task_id' => $task->id,
            'message' => '提醒',
        ]);

        // 无 end_at 不抛错，不渲染"截止"行
        $this->assertStringContainsString('提醒', $rendered);
        $this->assertStringNotContainsString('截止', $rendered);
    }

    public function test_render_report_remind_formats_end_at_in_prc_no_offset(): void
    {
        // 与既有 test_formats_end_at_in_prc_tz_no_offset 同款断言：不应出现 +8h offset
        $project = Project::factory()->create();
        $task = ProjectTask::factory()->create([
            'name'       => 'Z',
            'project_id' => $project->id,
            'end_at'     => '2026-04-29 18:00:00',
        ]);

        $renderer = new WecomMarkdownRenderer();
        $rendered = $renderer->renderReportRemind([
            'task_id' => $task->id,
            'message' => '提醒',
        ]);

        $this->assertStringContainsString('2026-04-29 18:00', $rendered);
        $this->assertStringNotContainsString('2026-04-30 02:00', $rendered);
    }

    public function test_render_report_remind_uses_default_message_when_missing(): void
    {
        $project = Project::factory()->create();
        $task = ProjectTask::factory()->create(['project_id' => $project->id]);

        $renderer = new WecomMarkdownRenderer();
        // 不传 message 字段
        $rendered = $renderer->renderReportRemind(['task_id' => $task->id]);

        // spec §10A: 默认值 "请尽快完成本次任务上报"
        $this->assertStringContainsString('请尽快完成本次任务上报', $rendered);
    }
}
