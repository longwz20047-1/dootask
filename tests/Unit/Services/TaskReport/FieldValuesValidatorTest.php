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

use App\Services\TaskReport\FieldValuesValidator;
use Tests\TestCase;

class FieldValuesValidatorTest extends TestCase
{
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
}
