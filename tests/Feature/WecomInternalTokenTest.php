<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserWecomBinding;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class WecomInternalTokenTest extends TestCase
{
    // DatabaseTransactions: 每 test BEGIN/ROLLBACK, 不跑 migrate:fresh
    // (Swoole 下 migrate:fresh 重跑会遇 Config Facade 状态污染)
    use DatabaseTransactions;

    private const CORP_ID      = 'ww_test_corp';
    private const WECOM_USERID = 'WxZhangSan';
    private const SECRET       = 'test-secret-for-phpunit';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /**
     * Dootask pre_users 自定义 schema（无 Laravel 默认 name/email_verified_at/remember_token 列），
     * UserFactory 默认字段不兼容，手动构造只填 schema 内字段。
     */
    private function makeUser(bool $disabled = false, string $identity = ''): User
    {
        static $seq = 0;
        $seq++;
        $user = new User();
        $user->email      = "test_{$seq}_" . Str::random(8) . '@example.com';
        $user->nickname   = "test_user_{$seq}";
        $user->encrypt    = Str::random(16);
        $user->password   = bcrypt('password');
        $user->identity   = $identity;
        $user->disable_at = $disabled ? now() : null;
        $user->save();
        return $user;
    }

    private function bindWecom(User $user, string $corpId = self::CORP_ID, string $wecomUserId = self::WECOM_USERID): UserWecomBinding
    {
        $binding = UserWecomBinding::createInstance([
            'userid'        => $user->userid,
            'wecom_corp_id' => $corpId,
            'wecom_userid'  => $wecomUserId,
        ]);
        $binding->save();
        return $binding;
    }

    public function test_valid_secret_and_bound_wecom_user_returns_token(): void
    {
        $user = $this->makeUser();
        $this->bindWecom($user);

        $response = $this->postJson(
            '/api/wecom/internal/generate_token',
            [
                'wecom_corp_id' => self::CORP_ID,
                'wecom_userid'  => self::WECOM_USERID,
            ],
            ['X-Internal-Secret' => self::SECRET]
        );

        $response->assertStatus(200);
        $response->assertJsonPath('ret', 1);
        $response->assertJsonStructure(['data' => ['token', 'expires_in', 'dootask_userid']]);
        $this->assertEquals($user->userid, $response->json('data.dootask_userid'));
        $this->assertEquals(3600, $response->json('data.expires_in'));
        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_missing_secret_returns_error(): void
    {
        $user = $this->makeUser();
        $this->bindWecom($user);
        $response = $this->postJson(
            '/api/wecom/internal/generate_token',
            [
                'wecom_corp_id' => self::CORP_ID,
                'wecom_userid'  => self::WECOM_USERID,
            ]
        );
        $response->assertJsonPath('ret', 0);
        $response->assertJsonPath('msg', 'invalid secret');
    }

    public function test_wrong_secret_returns_error(): void
    {
        $user = $this->makeUser();
        $this->bindWecom($user);
        $response = $this->postJson(
            '/api/wecom/internal/generate_token',
            [
                'wecom_corp_id' => self::CORP_ID,
                'wecom_userid'  => self::WECOM_USERID,
            ],
            ['X-Internal-Secret' => 'WRONG-SECRET']
        );
        $response->assertJsonPath('ret', 0);
        $response->assertJsonPath('msg', 'invalid secret');
    }

    public function test_missing_wecom_fields_returns_error(): void
    {
        $response = $this->postJson(
            '/api/wecom/internal/generate_token',
            [],
            ['X-Internal-Secret' => self::SECRET]
        );
        $response->assertJsonPath('ret', 0);
        $response->assertJsonPath('msg', 'wecom_corp_id and wecom_userid are required');
    }

    public function test_unbound_wecom_user_returns_error(): void
    {
        $this->makeUser();
        $response = $this->postJson(
            '/api/wecom/internal/generate_token',
            [
                'wecom_corp_id' => 'ww_unbound_corp',
                'wecom_userid'  => 'WxUnknown',
            ],
            ['X-Internal-Secret' => self::SECRET]
        );
        $response->assertJsonPath('ret', 0);
        $response->assertJsonPath('msg', 'wecom user not bound to any dootask account');
    }

    public function test_disabled_dootask_user_returns_error(): void
    {
        $user = $this->makeUser(disabled: true);
        $this->bindWecom($user);
        $response = $this->postJson(
            '/api/wecom/internal/generate_token',
            [
                'wecom_corp_id' => self::CORP_ID,
                'wecom_userid'  => self::WECOM_USERID,
            ],
            ['X-Internal-Secret' => self::SECRET]
        );
        $response->assertJsonPath('ret', 0);
        $response->assertJsonPath('msg', 'dootask user not found or disabled');
    }

    public function test_identity_disabled_user_rejected(): void
    {
        // 管理员显式禁用：identity 含 disable，但 disable_at=null
        $user = $this->makeUser(disabled: false, identity: ',disable,');
        $this->bindWecom($user);
        $response = $this->postJson(
            '/api/wecom/internal/generate_token',
            [
                'wecom_corp_id' => self::CORP_ID,
                'wecom_userid'  => self::WECOM_USERID,
            ],
            ['X-Internal-Secret' => self::SECRET]
        );
        $response->assertJsonPath('ret', 0);
        $response->assertJsonPath('msg', 'dootask user not found or disabled');
    }
}
