<?php

// [CUSTOM:report-channel]
// Sprint 4 Pass 1 · Task A · Feature 测试：
//   ProjectController::report_field__list
//
// 与 ReportFieldTest 同测试策略：直接 controller 调用 + RequestContext prime auth。

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

class ReportFieldListTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        FieldDefinitionCache::flushAll();
    }

    private function primeAuth(User $user): void
    {
        $rid = 'req_test_' . uniqid();
        request()->attributes->set('request_id', $rid);
        RequestContext::save('auth', $user, $rid);
    }

    private function callFieldList(User $user, array $input): array
    {
        $this->primeAuth($user);
        request()->replace($input);
        $controller = new ProjectController();
        return $controller->report_field__list();
    }

    /**
     * 创建项目并把 user 加为负责人
     */
    private function createProjectWithOwner(User $owner): Project
    {
        $project = Project::factory()->create(['userid' => $owner->userid]);
        ProjectUser::createInstance([
            'project_id' => $project->id,
            'userid'     => $owner->userid,
            'owner'      => 1,
        ])->save();
        return $project;
    }

    private function addMember(Project $project, User $user, int $owner = 0): void
    {
        ProjectUser::createInstance([
            'project_id' => $project->id,
            'userid'     => $user->userid,
            'owner'      => $owner,
        ])->save();
    }

    public function test_list_global_returns_seeded_hours_note(): void
    {
        $user = User::factory()->create();

        $resp = $this->callFieldList($user, ['scope' => 'global']);

        $this->assertSame(1, $resp['ret']);
        $codes = array_column($resp['data'], 'code');
        $this->assertContains('hours', $codes);
        $this->assertContains('note', $codes);
    }

    public function test_list_project_filters_by_project_id(): void
    {
        $owner = User::factory()->create();
        $project = $this->createProjectWithOwner($owner);
        // 创建一个 project scope 字段
        $field = TaskFieldDefinition::createInstance([
            'scope'              => 'project',
            'project_id'         => $project->id,
            'flow_item_id'       => 0,
            'code'               => 'project_only',
            'name'               => '项目字段',
            'type'               => 'text',
            'options'            => [],
            'default_value'      => null,
            'required'           => false,
            'sort'               => 10,
            'enabled'            => true,
            'is_builtin'         => false,
            'aggregatable'       => false,
            'aggregate_strategy' => 'none',
            'has_index'          => false,
        ]);
        $field->save();

        $resp = $this->callFieldList($owner, [
            'scope'      => 'project',
            'project_id' => $project->id,
        ]);

        $this->assertSame(1, $resp['ret']);
        $codes = array_column($resp['data'], 'code');
        $this->assertContains('project_only', $codes);
        // 不应包含 global hours/note
        $this->assertNotContains('hours', $codes);
        $this->assertNotContains('note', $codes);
    }

    public function test_list_both_combines_global_and_project(): void
    {
        $owner = User::factory()->create();
        $project = $this->createProjectWithOwner($owner);
        $field = TaskFieldDefinition::createInstance([
            'scope'              => 'project',
            'project_id'         => $project->id,
            'flow_item_id'       => 0,
            'code'               => 'project_extra',
            'name'               => '项目专属',
            'type'               => 'text',
            'options'            => [],
            'default_value'      => null,
            'required'           => false,
            'sort'               => 10,
            'enabled'            => true,
            'is_builtin'         => false,
            'aggregatable'       => false,
            'aggregate_strategy' => 'none',
            'has_index'          => false,
        ]);
        $field->save();

        $resp = $this->callFieldList($owner, [
            'project_id' => $project->id,
            // scope 留空 → both
        ]);

        $this->assertSame(1, $resp['ret']);
        $codes = array_column($resp['data'], 'code');
        $this->assertContains('hours', $codes);
        $this->assertContains('note', $codes);
        $this->assertContains('project_extra', $codes);
    }

    public function test_list_excludes_disabled_by_default(): void
    {
        $admin = User::factory()->create(['identity' => ',admin,']);
        $field = TaskFieldDefinition::createInstance([
            'scope'              => 'global',
            'project_id'         => 0,
            'flow_item_id'       => 0,
            'code'               => 'disabled_field',
            'name'               => '已停用',
            'type'               => 'text',
            'options'            => [],
            'default_value'      => null,
            'required'           => false,
            'sort'               => 99,
            'enabled'            => false,
            'is_builtin'         => false,
            'aggregatable'       => false,
            'aggregate_strategy' => 'none',
            'has_index'          => false,
        ]);
        $field->save();

        $resp = $this->callFieldList($admin, ['scope' => 'global']);

        $this->assertSame(1, $resp['ret']);
        $codes = array_column($resp['data'], 'code');
        $this->assertNotContains('disabled_field', $codes);
    }

    public function test_list_include_disabled_returns_all(): void
    {
        $admin = User::factory()->create(['identity' => ',admin,']);
        $field = TaskFieldDefinition::createInstance([
            'scope'              => 'global',
            'project_id'         => 0,
            'flow_item_id'       => 0,
            'code'               => 'disabled_field_2',
            'name'               => '已停用2',
            'type'               => 'text',
            'options'            => [],
            'default_value'      => null,
            'required'           => false,
            'sort'               => 99,
            'enabled'            => false,
            'is_builtin'         => false,
            'aggregatable'       => false,
            'aggregate_strategy' => 'none',
            'has_index'          => false,
        ]);
        $field->save();

        $resp = $this->callFieldList($admin, [
            'scope'            => 'global',
            'include_disabled' => true,
        ]);

        $this->assertSame(1, $resp['ret']);
        $codes = array_column($resp['data'], 'code');
        $this->assertContains('disabled_field_2', $codes);
    }

    public function test_list_project_rejects_non_member(): void
    {
        $owner = User::factory()->create();
        $project = $this->createProjectWithOwner($owner);
        $outsider = User::factory()->create();

        // 非项目成员 → Project::userProject 抛 ApiException
        $this->expectException(\App\Exceptions\ApiException::class);
        $this->callFieldList($outsider, [
            'scope'      => 'project',
            'project_id' => $project->id,
        ]);
    }

    // =====================================================================
    // [CUSTOM:report-channel] Sprint 5b.3 gap fill — 跨项目 project scope 字段隔离
    // =====================================================================

    /**
     * Sprint 5b.3 跨项目隔离：项目 A 的 project scope 字段不会出现在项目 B 的列表里。
     */
    public function test_list_project_isolates_fields_across_projects(): void
    {
        $userA = User::factory()->create();
        $projectA = $this->createProjectWithOwner($userA);
        $userB = User::factory()->create();
        $projectB = $this->createProjectWithOwner($userB);

        // 仅在 projectA 上建一个 project scope 字段
        TaskFieldDefinition::createInstance([
            'scope'              => 'project',
            'project_id'         => $projectA->id,
            'flow_item_id'       => 0,
            'code'               => 'project_a_only',
            'name'               => '仅 A 项目字段',
            'type'               => 'text',
            'options'            => [],
            'default_value'      => null,
            'required'           => false,
            'sort'               => 10,
            'enabled'            => true,
            'is_builtin'         => false,
            'aggregatable'       => false,
            'aggregate_strategy' => 'none',
            'has_index'          => false,
        ])->save();

        // userB（项目 B 负责人，非 A 成员）只看 project=B → 看不到 A 的字段
        $resp = $this->callFieldList($userB, [
            'scope'      => 'project',
            'project_id' => $projectB->id,
        ]);

        $this->assertSame(1, $resp['ret']);
        $codes = array_column($resp['data'], 'code');
        $this->assertNotContains('project_a_only', $codes);
    }

    /**
     * Sprint 5b.3 项目普通成员（非负责人）可读 project scope 字段（list 不要求 owner）。
     */
    public function test_list_project_allows_non_owner_member(): void
    {
        $owner = User::factory()->create();
        $project = $this->createProjectWithOwner($owner);
        $member = User::factory()->create();
        $this->addMember($project, $member, 0);

        TaskFieldDefinition::createInstance([
            'scope'              => 'project',
            'project_id'         => $project->id,
            'flow_item_id'       => 0,
            'code'               => 'project_visible',
            'name'               => '项目可见字段',
            'type'               => 'text',
            'options'            => [],
            'default_value'      => null,
            'required'           => false,
            'sort'               => 10,
            'enabled'            => true,
            'is_builtin'         => false,
            'aggregatable'       => false,
            'aggregate_strategy' => 'none',
            'has_index'          => false,
        ])->save();

        // 非负责人成员调 list（spec §4：list 仅要求项目成员，不要求 owner）
        $resp = $this->callFieldList($member, [
            'scope'      => 'project',
            'project_id' => $project->id,
        ]);

        $this->assertSame(1, $resp['ret']);
        $codes = array_column($resp['data'], 'code');
        $this->assertContains('project_visible', $codes);
    }
}
