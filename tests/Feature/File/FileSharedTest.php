<?php
// [CUSTOM:file-share-manage]

namespace Tests\Feature\File;

use App\Http\Controllers\Api\FileController;
use App\Models\File;
use App\Models\User;
use App\Services\RequestContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * [CUSTOM:file-share-manage] FileController::shared() —— 我共享的文件汇总
 *
 * 测试范式同 ReportSaveTest：直接调 controller 方法（非 HTTP），prime RequestContext('auth')
 * 跳过 Doo Swoole FFI token 链路（PHPUnit 进程下 Doo::userId() 不可用）。
 */
class FileSharedTest extends TestCase
{
    use DatabaseTransactions;

    private function callShared(User $user): array
    {
        $rid = 'req_test_' . uniqid();
        request()->attributes->set('request_id', $rid);
        RequestContext::save('auth', $user, $rid);
        // Base::retSuccess / retError 直接返 array {ret, msg, data}
        return (new FileController())->shared();
    }

    private function makeFile(int $userid, array $attrs = []): File
    {
        $f = File::createInstance(array_merge([
            'pid'          => 0,
            'name'         => 'unit-' . uniqid(),
            'type'         => 'word',
            'ext'          => 'docx',
            'size'         => 1,
            'userid'       => $userid,
            'created_id'   => $userid,
            'share'        => 0,
            'guest_access' => 0,
        ], $attrs));
        $f->save();
        return $f;
    }

    public function test_returns_shared_and_guest_files_of_current_user(): void
    {
        $user   = User::factory()->create();
        $shared = $this->makeFile($user->userid, ['share' => 1]);
        $guest  = $this->makeFile($user->userid, ['guest_access' => 1]);
        $plain  = $this->makeFile($user->userid);
        $other  = $this->makeFile($user->userid + 999999, ['share' => 1]);

        $res = $this->callShared($user);
        $this->assertSame(1, $res['ret'], $res['msg'] ?? '');
        $ids = array_column($res['data'], 'id');

        $this->assertContains($shared->id, $ids);
        $this->assertContains($guest->id, $ids);
        $this->assertNotContains($plain->id, $ids);
        $this->assertNotContains($other->id, $ids);
        foreach ($res['data'] as $row) {
            $this->assertSame(0, $row['pid']);
            $this->assertSame(1000, $row['permission']);
        }
    }

    public function test_file_with_both_share_and_guest_appears_once(): void
    {
        $user = User::factory()->create();
        $both = $this->makeFile($user->userid, ['share' => 1, 'guest_access' => 1]);

        $res = $this->callShared($user);
        $ids = array_column($res['data'], 'id');
        $this->assertSame(1, count(array_filter($ids, fn($id) => $id === $both->id)));
    }

    public function test_returns_empty_when_user_has_nothing_shared(): void
    {
        $user = User::factory()->create();
        $this->makeFile($user->userid); // 仅普通文件

        $res = $this->callShared($user);
        $this->assertSame(1, $res['ret']);
        $this->assertIsArray($res['data']);
        $this->assertSame([], array_column($res['data'], 'id'));
    }

    public function test_temp_user_gets_error(): void
    {
        $user = User::factory()->create();
        $user->identity = 'temp';
        $user->save();
        if (!$user->isTemp()) {
            $this->markTestSkipped('无法在测试中把用户标记为临时身份');
        }
        $res = $this->callShared($user);
        $this->assertSame(0, $res['ret']);
    }
}
