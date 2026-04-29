<?php

// [CUSTOM:report-channel]
// Spec §3.3 task_field_attachments：附件表（关联 project_task_reports.id + dootask files 表）

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTaskFieldAttachmentsTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('task_field_attachments')) {
            return;
        }
        Schema::create('task_field_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('report_id')->index()->comment('关联 project_task_reports.id');
            $table->string('field_code', 64);
            $table->unsignedBigInteger('file_id')->comment('dootask files 表主键');
            $table->string('filename', 255);
            $table->unsignedBigInteger('size');
            $table->string('mime_type', 128)->nullable();
            $table->unsignedInteger('uploader_userid');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['report_id', 'field_code'], 'idx_report_field');
        });
    }

    public function down()
    {
        Schema::dropIfExists('task_field_attachments');
    }
}
