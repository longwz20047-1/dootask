<?php
// [CUSTOM:wecom-files-app]

namespace Tests\Feature\Wecom;

use App\Module\Base;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * [CUSTOM:wecom-files-app] 第 2 自建应用 entry/callback 行为测试
 *
 * 验证：
 *  1. entry?app=files 走文件应用 secret + 强制 redirect 到 /manage/file?app=files
 *  2. entry 默认行为（不传 app）保持现状
 *  3. state cache 携带 app，callback 用对的 secret
 *  4. 未配置文件应用时 ?app=files 降级 wecom_error
 *  5. 非法 app 值降级 default
 *  6. sanitizeRedirect 对文件应用 redirect 仍生效
 */
class WecomFilesAppTest extends TestCase
{
    use DatabaseTransactions;

    private const CORP_ID         = 'ww_test_corp';
    private const DEFAULT_AGENT   = '1000001';
    private const DEFAULT_SECRET  = 'default-secret-xxx';
    private const FILES_AGENT     = '1000002';
    private const FILES_SECRET    = 'files-secret-yyy';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        // 配齐两套 wecom 配置 — 用 Base::setting() 走生产写入路径
        // (Setting::updateOrCreate 不靠谱：Setting model 无显式 cast，array 序列化不一定正确;
        //  Base::setting() 内部用 createInstance/updateInstance 处理项目专属序列化)
        Base::setting('thirdAccessSetting', [
            'wecom_open' => 'open',
            'wecom_corp_id' => self::CORP_ID,
            'wecom_agent_id' => self::DEFAULT_AGENT,
            'wecom_secret' => self::DEFAULT_SECRET,
            'wecom_contact_secret' => 'contact-secret',
            'wecom_auto_reg' => 'open',
            'wecom_org_sync' => 'close',
            'wecom_files_open' => 'open',
            'wecom_files_agent_id' => self::FILES_AGENT,
            'wecom_files_secret' => self::FILES_SECRET,
        ]);
        // 正向断言：项目无现存测试用过 Base::setting()，phpunit 无 Swoole RequestContext。
        // 若 setUp 静默失败（如 RequestContext::save 异常被吞、序列化失败），后续测试会
        // 因为配置读不到而走 "wecom 未开启" 分支，所有测试反而 PASS — 误判通过。
        // fail-fast：直接读回来比对关键字段。
        $reloaded = Base::setting('thirdAccessSetting');
        $this->assertSame(self::FILES_AGENT, $reloaded['wecom_files_agent_id'] ?? null,
            'setUp 写 thirdAccessSetting 失败 — 检查 RequestContext / Setting 序列化');
        $this->assertSame(self::DEFAULT_AGENT, $reloaded['wecom_agent_id'] ?? null);
    }

    public function test_entry_app_files_redirects_to_wecom_oauth_with_files_agent_id(): void
    {
        $response = $this->get('/api/wecom/entry?app=files');
        $response->assertStatus(302);
        $location = $response->headers->get('Location');

        // 应跳转到企微 OAuth URL
        $this->assertStringStartsWith('https://open.weixin.qq.com/connect/oauth2/authorize', $location);
        // 必须带文件应用的 agentid，而非默认 agent_id
        $this->assertStringContainsString('agentid=' . self::FILES_AGENT, $location);
        $this->assertStringNotContainsString('agentid=' . self::DEFAULT_AGENT, $location);
    }

    public function test_entry_without_app_uses_default_agent_id(): void
    {
        $response = $this->get('/api/wecom/entry');
        $response->assertStatus(302);
        $location = $response->headers->get('Location');

        $this->assertStringContainsString('agentid=' . self::DEFAULT_AGENT, $location);
        $this->assertStringNotContainsString('agentid=' . self::FILES_AGENT, $location);
    }

    public function test_entry_with_invalid_app_falls_back_to_default(): void
    {
        $response = $this->get('/api/wecom/entry?app=hacker');
        $response->assertStatus(302);
        $location = $response->headers->get('Location');

        $this->assertStringContainsString('agentid=' . self::DEFAULT_AGENT, $location);
    }

    public function test_entry_app_files_without_files_config_returns_wecom_error(): void
    {
        // 关闭文件应用
        $setting = Base::setting('thirdAccessSetting');
        $setting['wecom_files_open'] = 'close';
        Base::setting('thirdAccessSetting', $setting);

        $response = $this->get('/api/wecom/entry?app=files');
        $response->assertStatus(302);
        $location = $response->headers->get('Location');

        // 应跳到登录页带 wecom_error
        $this->assertStringContainsString('wecom_error=', $location);
        $this->assertStringContainsString('login', $location);
    }

    public function test_entry_app_files_writes_app_into_state_cache(): void
    {
        $response = $this->get('/api/wecom/entry?app=files');
        $location = $response->headers->get('Location');

        // 从 OAuth URL 解析 state
        parse_str(parse_url($location, PHP_URL_QUERY), $qs);
        $state = $qs['state'] ?? '';
        $this->assertNotEmpty($state);

        // cache 应存 ['app' => 'files']
        $payload = Cache::get("wecom_state:{$state}");
        $this->assertIsArray($payload);
        $this->assertSame('files', $payload['app']);
    }

    public function test_entry_default_app_writes_default_into_state_cache(): void
    {
        $response = $this->get('/api/wecom/entry');
        $location = $response->headers->get('Location');

        parse_str(parse_url($location, PHP_URL_QUERY), $qs);
        $state = $qs['state'] ?? '';
        $payload = Cache::get("wecom_state:{$state}");
        $this->assertIsArray($payload);
        $this->assertSame('default', $payload['app']);
    }

    public function test_entry_app_files_with_explicit_redirect_does_not_overwrite(): void
    {
        $response = $this->get('/api/wecom/entry?app=files&redirect=' . urlencode('/single/file/abc'));
        $response->assertStatus(302);
        $location = $response->headers->get('Location');

        parse_str(parse_url($location, PHP_URL_QUERY), $qs);
        $state = $qs['state'] ?? '';
        $cachedRedirect = Cache::get("wecom_redirect:{$state}");

        $this->assertSame('/single/file/abc', $cachedRedirect);
    }

    public function test_entry_app_files_with_evil_redirect_falls_back_to_default(): void
    {
        // sanitizeRedirect 拒绝外部 URL → 文件应用应注入 /manage/file?app=files
        $response = $this->get('/api/wecom/entry?app=files&redirect=' . urlencode('//evil.com'));
        $response->assertStatus(302);
        $location = $response->headers->get('Location');

        parse_str(parse_url($location, PHP_URL_QUERY), $qs);
        $state = $qs['state'] ?? '';
        $cachedRedirect = Cache::get("wecom_redirect:{$state}");

        $this->assertSame('/manage/file?app=files', $cachedRedirect);
    }
}
