<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateWecomNotificationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('wecom_notifications')) {
            return;
        }
        Schema::create('wecom_notifications', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('event_hash', 64)->comment('MD5(event_type:task_id:target_userid)');
            $table->string('event_type', 32)->comment('事件类型: task_assigned');
            $table->bigInteger('task_id')->unsigned()->comment('dootask project_tasks.id');
            $table->bigInteger('target_userid')->unsigned()->comment('dootask users.userid');
            $table->string('wecom_corp_id', 64)->nullable()->comment('企业 CorpID');
            $table->string('wecom_userid', 64)->nullable()->comment('企微成员 UserId');
            $table->string('a2a_agent_id', 64)->nullable()->comment('A2A Agent ID，决定 bridge 选哪个 bot');
            $table->text('rendered_markdown')->nullable()->comment('渲染后的 markdown 快照，重试不重渲染');
            $table->string('status', 16)->default('pending')->comment('状态: pending/processing/sent/failed/skipped');
            $table->smallInteger('attempts')->default(0)->comment('已尝试次数');
            $table->smallInteger('max_attempts')->default(5)->comment('最大尝试次数');
            $table->text('last_error')->nullable()->comment('最后错误信息');
            $table->json('payload')->nullable()->comment('快照 {task_name, project_id, project_name, end_at, creator_userid, creator_nickname, priority}');
            $table->timestamp('sent_at')->nullable()->comment('发送成功时间');
            $table->timestamp('next_retry_at')->nullable()->useCurrent()->comment('下次重试时间');
            $table->timestamp('processing_at')->nullable()->comment('进入 processing 状态的时间，用于僵尸回收');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['event_hash', 'target_userid'], 'uk_event_target');
            $table->index(['status', 'next_retry_at'], 'idx_status_retry');
            $table->index(['status', 'processing_at'], 'idx_processing_zombie');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('wecom_notifications');
    }
}
