<?php

// [CUSTOM:report-channel]
// Spec §6 FieldValuesValidator 单元测试
//
// 覆盖：8 类型 (text/textarea/number/date/select/multi_select/attachment/json)
//        + save/read 双模式
//        + required 校验
//        + options 边界（min/max/max_length/enum）
//        + unknown 字段 mode 分流（save 报错 / read 保孤儿）
//
// 仅 user 类型需要项目成员预查询 → user 类型测试单独走 DatabaseTransactions；
// 其它类型不查 DB，跑纯 PHP 校验，使用 Tests\TestCase 即可（已 boot Laravel
// 因为 validator 会 Carbon::createFromFormat 等）。

namespace Tests\Unit\Services\TaskReport;

use App\Models\Project;
use App\Models\ProjectUser;
use App\Models\User;
use App\Services\TaskReport\FieldValuesValidator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class FieldValuesValidatorTest extends TestCase
{
    // user-type 测试需要项目成员表 + User 表，且必须每 test BEGIN/ROLLBACK
    // 防 pre_users / pre_project_users 残留；其它纯 PHP 校验测试无副作用。
    use DatabaseTransactions;

    private FieldValuesValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new FieldValuesValidator();
    }

    public function test_required_missing_returns_missing_error()
    {
        $defs = [
            ['code' => 'hours', 'name' => '工时', 'type' => 'number', 'required' => true, 'options' => []],
        ];
        $result = $this->validator->validate([], $defs, 0, 0, 'save');

        $this->assertNotEmpty($result['errors']);
        $this->assertEquals('missing', $result['errors'][0]['kind']);
        $this->assertEquals('hours', $result['errors'][0]['code']);
    }

    public function test_number_max_violation_reports_error()
    {
        $defs = [
            ['code' => 'hours', 'name' => '工时', 'type' => 'number', 'required' => true,
             'options' => ['min' => 0, 'max' => 24]],
        ];
        $result = $this->validator->validate(['hours' => 25], $defs, 0, 0, 'save');

        $this->assertNotEmpty($result['errors']);
        $this->assertEquals('hours', $result['errors'][0]['code']);
        $this->assertStringContainsString('不能大于 24', $result['errors'][0]['reason']);
    }

    public function test_number_min_violation_reports_error()
    {
        $defs = [
            ['code' => 'hours', 'name' => '工时', 'type' => 'number', 'required' => true,
             'options' => ['min' => 0, 'max' => 24]],
        ];
        $result = $this->validator->validate(['hours' => -1], $defs, 0, 0, 'save');

        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('不能小于 0', $result['errors'][0]['reason']);
    }

    public function test_number_valid_passes_and_casts_to_float()
    {
        $defs = [
            ['code' => 'hours', 'name' => '工时', 'type' => 'number', 'required' => true,
             'options' => ['min' => 0, 'max' => 24]],
        ];
        $result = $this->validator->validate(['hours' => '4'], $defs, 0, 0, 'save');

        $this->assertEmpty($result['errors']);
        $this->assertSame(4.0, $result['sanitized']['hours']);
    }

    public function test_textarea_max_length_violation()
    {
        $defs = [
            ['code' => 'note', 'name' => '备注', 'type' => 'textarea', 'required' => false,
             'options' => ['max_length' => 10]],
        ];
        $result = $this->validator->validate(['note' => str_repeat('啊', 11)], $defs, 0, 0, 'save');

        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('超过 10 字', $result['errors'][0]['reason']);
    }

    public function test_text_default_max_length_is_100()
    {
        $defs = [
            ['code' => 'title', 'name' => '标题', 'type' => 'text', 'required' => false,
             'options' => []],
        ];
        // 100 char is fine, 101 char fails
        $ok = $this->validator->validate(['title' => str_repeat('a', 100)], $defs, 0, 0, 'save');
        $this->assertEmpty($ok['errors']);

        $bad = $this->validator->validate(['title' => str_repeat('a', 101)], $defs, 0, 0, 'save');
        $this->assertNotEmpty($bad['errors']);
    }

    public function test_date_format_violation()
    {
        $defs = [
            ['code' => 'when', 'name' => '日期', 'type' => 'date', 'required' => false, 'options' => []],
        ];
        $result = $this->validator->validate(['when' => '2026/04/29'], $defs, 0, 0, 'save');

        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('YYYY-MM-DD', $result['errors'][0]['reason']);
    }

    public function test_date_valid_format_passes()
    {
        $defs = [
            ['code' => 'when', 'name' => '日期', 'type' => 'date', 'required' => false, 'options' => []],
        ];
        $result = $this->validator->validate(['when' => '2026-04-29'], $defs, 0, 0, 'save');

        $this->assertEmpty($result['errors']);
        $this->assertEquals('2026-04-29', $result['sanitized']['when']);
    }

    public function test_select_invalid_value_rejected()
    {
        $defs = [
            ['code' => 'kind', 'name' => '类型', 'type' => 'select', 'required' => false,
             'options' => [['value' => 'a', 'label_key' => 'A'], ['value' => 'b', 'label_key' => 'B']]],
        ];
        $result = $this->validator->validate(['kind' => 'c'], $defs, 0, 0, 'save');

        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('取值非法', $result['errors'][0]['reason']);
    }

    public function test_select_valid_value_passes()
    {
        $defs = [
            ['code' => 'kind', 'name' => '类型', 'type' => 'select', 'required' => false,
             'options' => [['value' => 'a', 'label_key' => 'A']]],
        ];
        $result = $this->validator->validate(['kind' => 'a'], $defs, 0, 0, 'save');

        $this->assertEmpty($result['errors']);
        $this->assertEquals('a', $result['sanitized']['kind']);
    }

    public function test_multi_select_filters_invalid_values()
    {
        $defs = [
            ['code' => 'tags', 'name' => '标签', 'type' => 'multi_select', 'required' => false,
             'options' => [['value' => 'x'], ['value' => 'y']]],
        ];
        $result = $this->validator->validate(['tags' => ['x', 'z']], $defs, 0, 0, 'save');

        // 含非法值 'z' → 报错；sanitized 仅保留合法 'x'
        $this->assertNotEmpty($result['errors']);
        $this->assertEquals(['x'], $result['sanitized']['tags']);
    }

    public function test_attachment_save_new_report_rejects_prefilled_ids()
    {
        // reportId=0 + 含 attachment id → 报错（v3.2 P1-1 防越权）
        $defs = [
            ['code' => 'files', 'name' => '附件', 'type' => 'attachment', 'required' => false, 'options' => []],
        ];
        $result = $this->validator->validate(['files' => [42]], $defs, 0, 0, 'save');

        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('需先创建上报', $result['errors'][0]['reason']);
    }

    public function test_json_must_be_array()
    {
        $defs = [
            ['code' => 'meta', 'name' => 'Meta', 'type' => 'json', 'required' => false, 'options' => []],
        ];
        $bad = $this->validator->validate(['meta' => 'not-array'], $defs, 0, 0, 'save');
        $this->assertNotEmpty($bad['errors']);

        $ok = $this->validator->validate(['meta' => ['k' => 'v']], $defs, 0, 0, 'save');
        $this->assertEmpty($ok['errors']);
        $this->assertEquals(['k' => 'v'], $ok['sanitized']['meta']);
    }

    public function test_save_mode_unknown_field_reports_unknown_error()
    {
        $defs = [
            ['code' => 'hours', 'name' => '工时', 'type' => 'number', 'required' => false, 'options' => []],
        ];
        $result = $this->validator->validate(
            ['hours' => 4, 'orphan' => 'old-value'],
            $defs,
            0,
            0,
            'save'
        );

        // hours 通过；orphan 报 unknown
        $this->assertNotEmpty($result['errors']);
        $unknown = array_filter($result['errors'], fn ($e) => ($e['kind'] ?? null) === 'unknown');
        $this->assertCount(1, $unknown);
        // sanitized 不含 orphan（save 剔除）
        $this->assertArrayNotHasKey('orphan', $result['sanitized']);
        // deprecated 在 save 模式应空
        $this->assertEmpty($result['deprecated']);
    }

    public function test_read_mode_keeps_unknown_field_in_deprecated_no_error()
    {
        $defs = [
            ['code' => 'hours', 'name' => '工时', 'type' => 'number', 'required' => false, 'options' => []],
        ];
        $result = $this->validator->validate(
            ['hours' => 4, 'orphan' => 'old-value'],
            $defs,
            0,
            0,
            'read'
        );

        // read 模式：unknown 不报错
        $unknownErrs = array_filter($result['errors'], fn ($e) => ($e['kind'] ?? null) === 'unknown');
        $this->assertEmpty($unknownErrs);
        // deprecated 含 orphan
        $this->assertEquals(['orphan' => 'old-value'], $result['deprecated']);
    }

    /**
     * R-2 fix: required 校验对空数组 ([]) 也要触发 missing。
     * multi_select / user / attachment 等数组类型 required:true + value:[] 必须报错。
     */
    public function test_required_multi_select_empty_array_triggers_error()
    {
        $defs = [[
            'code'     => 'tags',
            'name'     => '标签',
            'type'     => 'multi_select',
            'required' => true,
            'options'  => [['value' => 'a', 'label' => 'A']],
        ]];
        $result = $this->validator->validate(['tags' => []], $defs, 0, 0, 'save');

        $this->assertNotEmpty($result['errors']);
        $missing = array_filter($result['errors'], fn ($e) => ($e['kind'] ?? null) === 'missing');
        $this->assertCount(1, $missing);
        $this->assertEquals('tags', array_values($missing)[0]['code']);
    }

    /**
     * I-1 fix regression test: ensure user-type branch does not crash on
     * Project::relationUserids() return-type ambiguity (line 54 used to call
     * ->toArray() on the already-array result and PHP-fataled).
     *
     * 同时校验项目成员交集逻辑：outsider（非项目成员）应被剔除。
     */
    public function test_user_type_filters_non_project_members()
    {
        // 项目负责人
        $owner    = User::factory()->create();
        $project  = Project::factory()->create(['userid' => $owner->userid]);

        // 加 owner 进入 pre_project_users（dootask 项目成员表）
        // ProjectUser 继承 AbstractModel 且无 $fillable → 必须用 createInstance
        // （dootask CLAUDE.md 约定，绕开 mass-assignment 限制）
        ProjectUser::createInstance([
            'project_id' => $project->id,
            'userid'     => $owner->userid,
            'owner'      => 1,
        ])->save();

        // 非项目成员
        $outsider = User::factory()->create();

        $defs = [[
            'code'     => 'assignee',
            'name'     => '负责人',
            'type'     => 'user',
            'required' => false,
            'options'  => [],
        ]];

        $result = $this->validator->validate(
            ['assignee' => [$owner->userid, $outsider->userid]],
            $defs,
            0,
            $project->id,
            'save'
        );

        // outsider 不在 pre_project_users → sanitized 应过滤掉；只保留 owner
        $this->assertEquals([$owner->userid], $result['sanitized']['assignee']);
        // 同时报 invalid（含项目外用户）
        $invalidErrs = array_filter($result['errors'], fn ($e) => ($e['kind'] ?? null) === 'invalid');
        $this->assertNotEmpty($invalidErrs);
    }

    // =====================================================================
    // [CUSTOM:report-channel] Sprint 5b.1 gap fill — 8 type valid-path 补缺
    // 既有用例多覆盖 invalid 路径或 mixed valid+invalid，本节补纯 valid passthrough。
    // =====================================================================

    /**
     * Sprint 5b.1 textarea valid path：长度小于 max_length，sanitized 保留原文 string。
     */
    public function test_textarea_valid_within_max_length_passes()
    {
        $defs = [
            ['code' => 'note', 'name' => '备注', 'type' => 'textarea', 'required' => false,
             'options' => ['max_length' => 200]],
        ];
        $value = '简短的进度备注';
        $result = $this->validator->validate(['note' => $value], $defs, 0, 0, 'save');

        $this->assertEmpty($result['errors']);
        $this->assertSame($value, $result['sanitized']['note']);
    }

    /**
     * Sprint 5b.1 multi_select 全合法值通过：sanitized 保留原序列、无 errors。
     */
    public function test_multi_select_all_valid_values_pass()
    {
        $defs = [
            ['code' => 'tags', 'name' => '标签', 'type' => 'multi_select', 'required' => false,
             'options' => [['value' => 'x'], ['value' => 'y'], ['value' => 'z']]],
        ];
        $result = $this->validator->validate(['tags' => ['x', 'z']], $defs, 0, 0, 'save');

        $this->assertEmpty($result['errors']);
        $this->assertSame(['x', 'z'], $result['sanitized']['tags']);
    }

    /**
     * Sprint 5b.1 attachment 已存在 report 时（reportId > 0）+ TaskFieldAttachment 未建路径
     *  → class_exists 短路保留输入 IDs（spec §6 Pass 2 兜底，line ~230）。
     */
    public function test_attachment_with_report_id_keeps_ids_when_no_db_lookup()
    {
        $defs = [
            ['code' => 'files', 'name' => '附件', 'type' => 'attachment', 'required' => false, 'options' => []],
        ];
        // reportId=42 + 输入 IDs；TaskFieldAttachment model 已建（Sprint 5a.4）会做 DB 查询，
        // 但这里 IDs 在 DB 不存在 → 应报 invalid，且 sanitized 为空数组（dedup 后剔除）。
        $result = $this->validator->validate(['files' => [101, 102]], $defs, 42, 0, 'save');

        // 当 model 存在 + IDs 不在 DB → invalid 错（含非本上报附件）
        $invalidErrs = array_filter($result['errors'], fn ($e) => ($e['kind'] ?? null) === 'invalid');
        $this->assertNotEmpty($invalidErrs);
        // sanitized 必为数组（不论是空还是 dedup 后保留）
        $this->assertIsArray($result['sanitized']['files']);
    }

    /**
     * Sprint 5b.1 user type 全部为项目成员时 valid path：sanitized 含全部 userids，无 invalid 错。
     */
    public function test_user_type_all_project_members_pass()
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $project = Project::factory()->create(['userid' => $owner->userid]);
        ProjectUser::createInstance([
            'project_id' => $project->id,
            'userid'     => $owner->userid,
            'owner'      => 1,
        ])->save();
        ProjectUser::createInstance([
            'project_id' => $project->id,
            'userid'     => $member->userid,
            'owner'      => 0,
        ])->save();

        $defs = [[
            'code'     => 'assignee',
            'name'     => '负责人',
            'type'     => 'user',
            'required' => false,
            'options'  => [],
        ]];

        $result = $this->validator->validate(
            ['assignee' => [$owner->userid, $member->userid]],
            $defs,
            0,
            $project->id,
            'save'
        );

        // 全部项目成员 → 无 invalid 错
        $invalidErrs = array_filter($result['errors'], fn ($e) => ($e['kind'] ?? null) === 'invalid');
        $this->assertEmpty($invalidErrs);
        // sanitized 含两个 userid（顺序由 array_intersect 保留 existing 的顺序）
        sort($result['sanitized']['assignee']);
        $expected = [$owner->userid, $member->userid];
        sort($expected);
        $this->assertEquals($expected, $result['sanitized']['assignee']);
    }
}
