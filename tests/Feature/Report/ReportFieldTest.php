<?php

// [CUSTOM:report-channel]
// Sprint 1 Pass 3 · Task 1.8 · Feature 测试：
//   ProjectController::report_field__save / report_field__delete
//
// 与 ReportSaveTest 同测试策略：直接 controller 调用 + RequestContext prime auth。

namespace Tests\Feature\Report;

use App\Http\Controllers\Api\ProjectController;
use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\TaskFieldDefinition;
use App\Models\User;
use App\Services\RequestContext;
use App\Services\TaskReport\FieldDefinitionCache;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ReportFieldTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        FieldDefinitionCache::flushAll();
    }

    /**
     * 钉死 request_id，prime auth bucket，clear input 避免 case 间残留
     */
    private function primeAuth(User $user): void
    {
        $rid = 'req_test_' . uniqid();
        request()->attributes->set('request_id', $rid);
        RequestContext::save('auth', $user, $rid);
    }

    /**
     * 调 report_field__save 的统一帮手
     */
    private function callFieldSave(User $user, array $input): array
    {
        $this->primeAuth($user);
        request()->replace($input);
        $controller = new ProjectController();
        return $controller->report_field__save();
    }

    /**
     * 调 report_field__delete 的统一帮手
     */
    private function callFieldDelete(User $user, array $input): array
    {
        $this->primeAuth($user);
        request()->replace($input);
        $controller = new ProjectController();
        return $controller->report_field__delete();
    }

    private function makeAdmin(): User
    {
        return User::factory()->create(['identity' => ',admin,']);
    }

    public function test_admin_can_create_global_field(): void
    {
        $admin = $this->makeAdmin();

        $resp = $this->callFieldSave($admin, [
            'scope'   => 'global',
            'code'    => 'mood',
            'name'    => '心情',
            'type'    => 'select',
            'options' => [['value' => 'happy', 'label' => '开心']],
        ]);

        $this->assertSame(1, $resp['ret']);
        $this->assertDatabaseHas('task_field_definitions', [
            'code'  => 'mood',
            'scope' => 'global',
        ]);
    }

    public function test_non_admin_cannot_create_global_field(): void
    {
        $user = User::factory()->create();   // identity=''

        $resp = $this->callFieldSave($user, [
            'scope' => 'global',
            'code'  => 'x',
            'name'  => 'X',
            'type'  => 'text',
        ]);

        $this->assertSame(0, $resp['ret']);
        $this->assertStringContainsString('管理员', $resp['msg']);
    }

    public function test_save_invalid_type_rejected(): void
    {
        $admin = $this->makeAdmin();

        $resp = $this->callFieldSave($admin, [
            'scope' => 'global',
            'code'  => 'x',
            'name'  => 'X',
            'type'  => 'INVALID_TYPE',
        ]);

        $this->assertSame(0, $resp['ret']);
        $this->assertStringContainsString('type 非法', $resp['msg']);
    }

    public function test_save_missing_code_rejected(): void
    {
        $admin = $this->makeAdmin();

        $resp = $this->callFieldSave($admin, [
            'scope' => 'global',
            'name'  => 'X',
            'type'  => 'text',
        ]);

        $this->assertSame(0, $resp['ret']);
        $this->assertStringContainsString('code 必填', $resp['msg']);
    }

    public function test_save_invalid_scope_rejected(): void
    {
        $admin = $this->makeAdmin();

        $resp = $this->callFieldSave($admin, [
            'scope' => 'invalid_scope',
            'code'  => 'x',
            'name'  => 'X',
            'type'  => 'text',
        ]);

        $this->assertSame(0, $resp['ret']);
        $this->assertStringContainsString('scope 非法', $resp['msg']);
    }

    public function test_save_project_scope_requires_owner(): void
    {
        // 项目成员（非负责人）尝试改 project scope 字段 → Project::userProject(mustOwner=true) 抛
        $owner = User::factory()->create();
        $project = Project::factory()->create(['userid' => $owner->userid]);
        ProjectUser::createInstance([
            'project_id' => $project->id,
            'userid'     => $owner->userid,
            'owner'      => 1,
        ])->save();
        $member = User::factory()->create();
        ProjectUser::createInstance([
            'project_id' => $project->id,
            'userid'     => $member->userid,
            'owner'      => 0,
        ])->save();

        // 非负责人 → 期望抛 ApiException（Project::userProject mustOwner=true）
        $this->expectException(\App\Exceptions\ApiException::class);
        $this->callFieldSave($member, [
            'scope'      => 'project',
            'project_id' => $project->id,
            'code'       => 'project_field',
            'name'       => '项目字段',
            'type'       => 'text',
        ]);
    }

    public function test_cannot_delete_builtin_field(): void
    {
        $admin = $this->makeAdmin();
        $hours = TaskFieldDefinition::where('code', 'hours')->first();
        $this->assertNotNull($hours, 'hours seed must exist (migration 100002)');
        $this->assertTrue((bool) $hours->is_builtin);

        $resp = $this->callFieldDelete($admin, ['id' => $hours->id]);

        $this->assertSame(0, $resp['ret']);
        $this->assertStringContainsString('内建字段不允许删除', $resp['msg']);
        // 仍存在
        $this->assertDatabaseHas('task_field_definitions', ['code' => 'hours']);
    }

    public function test_admin_can_delete_custom_field(): void
    {
        $admin = $this->makeAdmin();
        $field = TaskFieldDefinition::createInstance([
            'scope'              => 'global',
            'project_id'         => 0,
            'flow_item_id'       => 0,
            'code'               => 'temp_field',
            'name'               => '临时字段',
            'type'               => 'text',
            'options'            => [],
            'default_value'      => null,
            'required'           => false,
            'sort'               => 50,
            'enabled'            => true,
            'is_builtin'         => false,
            'aggregatable'       => false,
            'aggregate_strategy' => 'none',
            'has_index'          => false,
        ]);
        $field->save();

        $resp = $this->callFieldDelete($admin, ['id' => $field->id]);

        $this->assertSame(1, $resp['ret']);
        // 软删除？TaskFieldDefinition 当前未启用 SoftDeletes，应硬删
        $this->assertDatabaseMissing('task_field_definitions', ['id' => $field->id]);
    }

    public function test_delete_missing_id_rejected(): void
    {
        $admin = $this->makeAdmin();

        $resp = $this->callFieldDelete($admin, ['id' => 0]);

        $this->assertSame(0, $resp['ret']);
        $this->assertStringContainsString('id 必填', $resp['msg']);
    }

    public function test_delete_nonexistent_field_rejected(): void
    {
        $admin = $this->makeAdmin();

        $resp = $this->callFieldDelete($admin, ['id' => 99999999]);

        $this->assertSame(0, $resp['ret']);
        $this->assertStringContainsString('字段不存在', $resp['msg']);
    }

    public function test_non_admin_cannot_delete_global_field(): void
    {
        $user = User::factory()->create();
        // 创建一个 non-builtin global 字段（绕过非 admin 的 save，直接插）
        $field = TaskFieldDefinition::createInstance([
            'scope'              => 'global',
            'project_id'         => 0,
            'flow_item_id'       => 0,
            'code'               => 'custom_global',
            'name'               => '自定义全局',
            'type'               => 'text',
            'options'            => [],
            'default_value'      => null,
            'required'           => false,
            'sort'               => 50,
            'enabled'            => true,
            'is_builtin'         => false,
            'aggregatable'       => false,
            'aggregate_strategy' => 'none',
            'has_index'          => false,
        ]);
        $field->save();

        $resp = $this->callFieldDelete($user, ['id' => $field->id]);

        $this->assertSame(0, $resp['ret']);
        $this->assertStringContainsString('管理员', $resp['msg']);
        $this->assertDatabaseHas('task_field_definitions', ['id' => $field->id]);
    }

    public function test_admin_can_edit_custom_field_name(): void
    {
        $admin = $this->makeAdmin();
        $field = TaskFieldDefinition::createInstance([
            'scope'              => 'global',
            'project_id'         => 0,
            'flow_item_id'       => 0,
            'code'               => 'editable_field',
            'name'               => '原名',
            'type'               => 'text',
            'options'            => [],
            'default_value'      => null,
            'required'           => false,
            'sort'               => 50,
            'enabled'            => true,
            'is_builtin'         => false,
            'aggregatable'       => false,
            'aggregate_strategy' => 'none',
            'has_index'          => false,
        ]);
        $field->save();

        $resp = $this->callFieldSave($admin, [
            'id'    => $field->id,
            'scope' => 'global',
            'name'  => '新名',
            // code/type 编辑场景被忽略（保留原值）
            'code'  => 'editable_field',
            'type'  => 'text',
        ]);

        $this->assertSame(1, $resp['ret']);
        $this->assertEquals('新名', $field->fresh()->name);
    }

    public function test_edit_builtin_field_rejected(): void
    {
        $admin = $this->makeAdmin();
        $hours = TaskFieldDefinition::where('code', 'hours')->first();
        $this->assertNotNull($hours);

        $resp = $this->callFieldSave($admin, [
            'id'    => $hours->id,
            'scope' => 'global',
            'name'  => '改名试图',
            'code'  => 'hours',
            'type'  => 'number',
        ]);

        $this->assertSame(0, $resp['ret']);
        $this->assertStringContainsString('内建字段', $resp['msg']);
    }
}
