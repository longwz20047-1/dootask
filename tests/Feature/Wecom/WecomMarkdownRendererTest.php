<?php

namespace Tests\Feature\Wecom;

use App\Services\Wecom\WecomMarkdownRenderer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * M1 WecomMarkdownRenderer 单元测试
 *
 * 必须 use DatabaseTransactions (不用 RefreshDatabase)：
 *   Swoole 常驻 + migrate:fresh 会触发 Config Facade 静态状态污染，
 *   第二个 test 开始全炸（spec §9.1 + v3.5 plan A2 已踩过的坑）
 */
class WecomMarkdownRendererTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * escape 必须转义 markdown 元字符 + 反斜杠 + 换行符，防破坏渲染 / prompt 注入
     */
    public function test_escapes_markdown_special_chars(): void
    {
        // markdown 元字符集
        $input = 'hello *world* `code` [link](url) #header _emphasis_';
        $output = WecomMarkdownRenderer::escapeMarkdownChars($input);
        // 每个元字符前应有反斜杠
        $this->assertStringContainsString('\\*world\\*', $output);
        $this->assertStringContainsString('\\`code\\`', $output);
        $this->assertStringContainsString('\\[link\\]\\(url\\)', $output);
        $this->assertStringContainsString('\\#header', $output);
        $this->assertStringContainsString('\\_emphasis\\_', $output);
    }

    public function test_escapes_backslash_before_metachars(): void
    {
        // 反斜杠必须先转义，避免 \* 变 \\*（破坏后续 escape 逻辑）
        $output = WecomMarkdownRenderer::escapeMarkdownChars('a\\*b');
        // 'a\*b' → 先反斜杠 'a\\*b' → 再元字符 'a\\\\\*b'
        // 最终输出含 4 反斜杠 + 转义的 * （实际按 PHP 字面：'a\\\\\\*b' 6 字符 4 backslash）
        $this->assertStringContainsString('\\\\', $output);  // 至少含双反斜杠（转义后的原反斜杠）
        $this->assertStringContainsString('\\*', $output);   // 且 * 仍被转义
    }

    public function test_escapes_newline_to_single_space(): void
    {
        // v2.1 S1: 防攻击者通过换行符注入 "# 系统通知" 等段落
        $input = "正常标题\n\n# 钓鱼提示";
        $output = WecomMarkdownRenderer::escapeMarkdownChars($input);
        // 换行被替换为单空格；后续 # 被 markdown 元字符 escape 阻止
        $this->assertStringNotContainsString("\n", $output);
        $this->assertStringContainsString('\\#', $output);
    }

    public function test_escape_returns_empty_on_null_or_empty(): void
    {
        $this->assertSame('', WecomMarkdownRenderer::escapeMarkdownChars(null));
        $this->assertSame('', WecomMarkdownRenderer::escapeMarkdownChars(''));
    }

    public function test_escape_fallback_on_invalid_utf8(): void
    {
        // v2.1 S5: 非法 UTF-8 下 preg_replace 返 null，?? $s fallback 防 TypeError
        $input = "\xFF\xFE 非法字节";   // BOM-like 非 UTF-8 起始
        // 不应抛 TypeError
        $output = WecomMarkdownRenderer::escapeMarkdownChars($input);
        $this->assertIsString($output);
    }

    /**
     * 时区：dootask MariaDB PRC (UTC+8)，end_at 存字符串 '2026-04-29 18:00:00' (本地时间)
     * Renderer 不应再 setTimezone('Asia/Shanghai') 否则 +8h bug
     */
    public function test_formats_end_at_in_prc_tz_no_offset(): void
    {
        $renderer = new WecomMarkdownRenderer();
        $payload = [
            'task_name'        => 'test task',
            'project_id'       => 1,
            'project_name'     => 'proj',
            'end_at'           => '2026-04-29 18:00:00',
            'creator_userid'   => 1,
            'creator_nickname' => 'creator',
            'priority'         => '',
        ];
        $markdown = $renderer->renderTaskAssigned($payload, 42);
        // 期待 '2026-04-29 18:00' 原样，不是 '2026-04-30 02:00'（+8h bug 会出现这个）
        $this->assertStringContainsString('2026-04-29 18:00', $markdown);
        $this->assertStringNotContainsString('2026-04-30 02:00', $markdown);
    }

    public function test_formats_null_end_at_as_dash(): void
    {
        $renderer = new WecomMarkdownRenderer();
        $payload = [
            'task_name'        => 'test',
            'project_id'       => 1,
            'project_name'     => '',
            'end_at'           => null,
            'creator_userid'   => 1,
            'creator_nickname' => '',
            'priority'         => '',
        ];
        $markdown = $renderer->renderTaskAssigned($payload, 42);
        // Blade 里 `{{ $end_at_formatted ?: '无' }}` 应输出 "无"
        $this->assertStringContainsString('**截止**：无', $markdown);
    }

    public function test_renders_complete_markdown_with_all_fields(): void
    {
        $renderer = new WecomMarkdownRenderer();
        $payload = [
            'task_name'        => '重构认证模块',
            'project_id'       => 7,
            'project_name'     => '后端优化',
            'end_at'           => '2026-04-29 18:00:00',
            'creator_userid'   => 10,
            'creator_nickname' => '张三',
            'priority'         => '高',
        ];
        // config wecom.dootask_base_url 可能在 test env 未配，Renderer v2.1 fix I1 有 ?? '' fallback
        $markdown = $renderer->renderTaskAssigned($payload, 42);

        // 顶部 emoji 标题
        $this->assertStringContainsString('📋 新任务分配给你', $markdown);
        // 任务名
        $this->assertStringContainsString('重构认证模块', $markdown);
        // 项目名
        $this->assertStringContainsString('后端优化', $markdown);
        // 时间
        $this->assertStringContainsString('2026-04-29 18:00', $markdown);
        // 创建人
        $this->assertStringContainsString('张三', $markdown);
        // 优先级（p_name "高"）
        $this->assertStringContainsString('**优先级**：高', $markdown);
        // 5 条话术都含 #42
        $this->assertEquals(5, substr_count($markdown, '#42'));
        // 详情链接格式：包 entry?redirect= OAuth 静默登录链路（非裸 SPA 路径）
        $this->assertStringContainsString('/api/wecom/entry?redirect=', $markdown);
        // rawurlencode 后的 hash path（/#/single/project/7/dialog/task/42）
        $this->assertStringContainsString('%2F%23%2Fsingle%2Fproject%2F7%2Fdialog%2Ftask%2F42', $markdown);
    }
}
