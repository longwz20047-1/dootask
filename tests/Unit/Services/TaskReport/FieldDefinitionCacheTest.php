<?php

// [CUSTOM:report-channel]
// Spec §6.4 FieldDefinitionCache 单元测试
//
// 注：FieldDefinitionCache 是 per-request 静态属性，多 test 间需手工 flushAll
// 防漏数据污染。本测试 setUp 强制 flushAll。

namespace Tests\Unit\Services\TaskReport;

use App\Services\TaskReport\FieldDefinitionCache;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class FieldDefinitionCacheTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        FieldDefinitionCache::flushAll();
    }

    public function test_returns_seeded_hours_and_note_for_global_scope()
    {
        $defs = FieldDefinitionCache::get('global', 0);
        $codes = $defs->pluck('code')->toArray();

        $this->assertContains('hours', $codes);
        $this->assertContains('note', $codes);
    }

    public function test_cache_hit_on_second_call_returns_same_collection()
    {
        $first  = FieldDefinitionCache::get('global', 0);
        $second = FieldDefinitionCache::get('global', 0);

        // 同一引用 → 第二次未走 DB
        $this->assertSame($first, $second);
    }

    public function test_flush_all_clears_cache()
    {
        FieldDefinitionCache::get('global', 0);
        $this->assertGreaterThan(0, FieldDefinitionCache::size());

        FieldDefinitionCache::flushAll();
        $this->assertEquals(0, FieldDefinitionCache::size());
    }

    public function test_flush_only_clears_targeted_scope()
    {
        // 触发 global / project 各自缓存
        FieldDefinitionCache::get('global', 0);
        FieldDefinitionCache::get('project', 999);
        $this->assertEquals(2, FieldDefinitionCache::size());

        FieldDefinitionCache::flush('global', 0);
        $this->assertEquals(1, FieldDefinitionCache::size());
    }

    public function test_project_scope_returns_empty_when_no_definitions()
    {
        // seed 仅含 scope=global，project 99999 无字段定义
        $defs = FieldDefinitionCache::get('project', 99999);
        $this->assertCount(0, $defs);
    }

    public function test_returned_definitions_have_array_options()
    {
        $defs = FieldDefinitionCache::get('global', 0);
        $hours = $defs->firstWhere('code', 'hours');

        $this->assertNotNull($hours);
        $this->assertIsArray($hours->options);
        $this->assertArrayHasKey('min', $hours->options);
        $this->assertArrayHasKey('max', $hours->options);
    }
}
