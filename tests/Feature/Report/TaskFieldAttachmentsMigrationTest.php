<?php

// [CUSTOM:report-channel]

namespace Tests\Feature\Report;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TaskFieldAttachmentsMigrationTest extends TestCase
{
    public function test_table_exists()
    {
        $this->assertTrue(Schema::hasTable('task_field_attachments'));
    }

    public function test_required_columns_exist()
    {
        $cols = ['id', 'report_id', 'field_code', 'file_id', 'filename', 'size',
                 'mime_type', 'uploader_userid',
                 'created_at', 'updated_at', 'deleted_at'];
        foreach ($cols as $c) {
            $this->assertTrue(
                Schema::hasColumn('task_field_attachments', $c),
                "Missing column: $c"
            );
        }
    }

    public function test_idx_report_field_index_exists()
    {
        $pre = DB::connection()->getTablePrefix();
        $rows = DB::select("SHOW INDEX FROM {$pre}task_field_attachments WHERE Key_name = 'idx_report_field'");
        $this->assertNotEmpty($rows);
    }
}
