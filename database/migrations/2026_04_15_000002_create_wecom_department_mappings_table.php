<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateWecomDepartmentMappingsTable extends Migration
{
    public function up()
    {
        Schema::create('wecom_department_mappings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('wecom_corp_id', 100)->comment('企业 CorpID');
            $table->bigInteger('wecom_dept_id')->comment('企微部门ID');
            $table->bigInteger('dootask_dept_id')->comment('DooTask user_departments.id');
            $table->string('wecom_dept_name', 100)->nullable()->default('');
            $table->bigInteger('wecom_parent_id')->nullable()->default(0)->comment('企微父部门ID');
            $table->timestamps();

            $table->unique(['wecom_corp_id', 'wecom_dept_id'], 'uk_corp_wecom_dept');
            $table->index('dootask_dept_id', 'idx_dootask_dept');
        });
    }

    public function down()
    {
        Schema::dropIfExists('wecom_department_mappings');
    }
}
