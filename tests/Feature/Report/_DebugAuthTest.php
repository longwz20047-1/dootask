<?php
// [CUSTOM:report-channel]
// 临时调试 RequestContext + User::auth 链路。完成后删除。
namespace Tests\Feature\Report;

use App\Models\User;
use App\Services\RequestContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class _DebugAuthTest extends TestCase
{
    use DatabaseTransactions;

    public function test_debug_auth_chain(): void
    {
        $user = User::factory()->create();

        $req = request();
        echo "\n[DEBUG] Request class: " . get_class($req) . "\n";
        echo "[DEBUG] Has attributes: " . ($req->attributes ? "yes" : "no") . "\n";
        echo "[DEBUG] req hash: " . spl_object_hash($req) . "\n";

        // Step 1: set request_id
        $rid = 'req_debug_' . uniqid();
        $req->attributes->set('request_id', $rid);
        echo "[DEBUG] Set request_id on \$req: $rid\n";

        // Step 1b: get request again, compare instance
        $req2 = request();
        echo "[DEBUG] req2 hash: " . spl_object_hash($req2) . "\n";
        echo "[DEBUG] Same instance: " . ($req === $req2 ? "yes" : "no") . "\n";
        echo "[DEBUG] req2 has request_id attr: " . ($req2->attributes->has('request_id') ? "yes" : "no") . "\n";
        echo "[DEBUG] req2 request_id value: " . $req2->attributes->get('request_id', 'MISSING') . "\n";

        // Step 2: query getCurrentRequestId
        $currentId = RequestContext::getCurrentRequestId();
        echo "[DEBUG] getCurrentRequestId no-arg: $currentId\n";
        echo "[DEBUG] Match: " . ($currentId === $rid ? "yes" : "no") . "\n";

        // Step 2b: try app('request')
        $req3 = app('request');
        echo "[DEBUG] app('request') hash: " . spl_object_hash($req3) . "\n";
        echo "[DEBUG] Same as req: " . ($req === $req3 ? "yes" : "no") . "\n";

        // Step 3: save auth
        RequestContext::save('auth', $user, $rid);

        // Step 4: has + get
        echo "[DEBUG] has(auth): " . (RequestContext::has('auth') ? "true" : "false") . "\n";
        $got = RequestContext::get('auth');
        echo "[DEBUG] get(auth) class: " . ($got ? get_class($got) : "null") . "\n";

        // Step 5: User::auth
        try {
            $u = User::auth();
            echo "[DEBUG] User::auth OK: " . $u->userid . "\n";
            $this->assertTrue(true);
        } catch (\Exception $e) {
            echo "[DEBUG] User::auth FAIL: " . $e->getMessage() . "\n";
            $this->fail($e->getMessage());
        }
    }
}
