<?php

// [CUSTOM:report-channel]
// Sprint 6 Task 6.2: spec §3.7 task_report_template_fields N:N 中间表

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTaskReportTemplateFieldsTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('task_report_template_fields')) {
            return;
        }
        Schema::create('task_report_template_fields', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('template_id')->index()
                ->comment('关联 task_report_templates.id');
            $table->unsignedBigInteger('field_id')->index()
                ->comment('关联 task_field_definitions.id');
            $table->json('override')->nullable()
                ->comment('字段级覆盖 {hidden?, required?, sort?, default_value?}');
            $table->integer('sort')->default(0);
            $table->timestamps();

            $table->unique(['template_id', 'field_id'], 'uniq_template_field');
        });
    }

    public function down()
    {
        Schema::dropIfExists('task_report_template_fields');
    }
}
