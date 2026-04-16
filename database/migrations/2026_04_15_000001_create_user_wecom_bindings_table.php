<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUserWecomBindingsTable extends Migration
{
    public function up()
    {
        Schema::create('user_wecom_bindings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('userid')->comment('DooTask userid');
            $table->string('wecom_corp_id', 100)->comment('企业 CorpID');
            $table->string('wecom_userid', 100)->comment('企微成员 UserId');
            $table->string('wecom_name', 100)->nullable()->default('')->comment('企微姓名');
            $table->string('wecom_avatar', 500)->nullable()->default('')->comment('企微头像');
            $table->timestamp('last_login_at')->nullable()->comment('最后企微登录时间');
            $table->timestamps();

            $table->unique(['wecom_corp_id', 'wecom_userid'], 'uk_corp_wecom_user');
            $table->index('userid', 'idx_userid');
        });
    }

    public function down()
    {
        Schema::dropIfExists('user_wecom_bindings');
    }
}
