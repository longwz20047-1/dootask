<?php

// [CUSTOM:report-channel]
// Sprint 5a Task 5a.4 · Feature 测试：ProjectController::report_attachment__upload
//
// 测试策略与 ReportSaveTest 一致：直接 controller 调用 + RequestContext::save('auth', $user) prime。
// 文件上传用 UploadedFile::fake() 走 dootask 既有 Base::upload (type=file)；
// 真落盘 public_path('uploads/task-report/...') 在 PHPUnit 进程内可写，无需 mock。
// 测试结束后 dootask Base::upload 不会自动清理（与 dootask 既有 file_upload 行为一致），
// 但 phpunit 进程沙盒只占少量字节，不破坏 idempotent。

namespace Tests\Feature\Report;

use App\Http\Controllers\Api\ProjectController;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\ProjectUser;
use App\Models\TaskFieldAttachment;
use App\Models\TaskReport;
use App\Models\User;
use App\Services\RequestContext;
use App\Services\TaskReport\FieldDefinitionCache;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ReportAttachmentTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        FieldDefinitionCache::flushAll();
    }

    /**
     * 创建项目 + 任务 + project_users 绑定 + 一份现有上报（attachment 上传需 report_id > 0）
     */
    private function setupProjectTaskReport(): array
    {
        $user = User::factory()->create();
        $project = Project::factory()->create(['userid' => $user->userid]);
        ProjectUser::createInstance([
            'project_id' => $project->id,
            'userid'     => $user->userid,
            'owner'      => 1,
        ])->save();
        $task = ProjectTask::factory()->create([
            'project_id' => $project->id,
            'userid'     => $user->userid,
        ]);
        $report = TaskReport::createInstance([
            'task_id'         => $task->id,
            'parent_id'       => (int) ($task->parent_id ?? 0),
            'project_id'      => $task->project_id,
            'reporter_userid' => $user->userid,
            'work_date'       => '2026-04-30',
            'values'          => ['hours' => 2],
            'cascade_deleted' => false,
        ]);
        $report->save();
        return compact('user', 'project', 'task', 'report');
    }

    /**
     * 调 controller 方法的统一帮手（同 ReportSaveTest 范式）
     */
    private function callUpload(User $user, array $input, ?UploadedFile $file = null): array
    {
        $rid = 'req_test_' . uniqid();
        request()->attributes->set('request_id', $rid);
        RequestContext::save('auth', $user, $rid);
        request()->replace($input);
        if ($file !== null) {
            request()->files->set('file', $file);
        }
        $controller = new ProjectController();
        return $controller->report_attachment__upload();
    }

    public function test_upload_creates_attachment_row(): void
    {
        ['user' => $user, 'task' => $task, 'report' => $report] = $this->setupProjectTaskReport();
        $file = UploadedFile::fake()->create('progress.txt', 4); // 4KB

        $resp = $this->callUpload($user, [
            'task_id'    => $task->id,
            'report_id'  => $report->id,
            'field_code' => 'progress_attach',
        ], $file);

        $this->assertSame(1, $resp['ret'], $resp['msg'] ?? '');
        $this->assertArrayHasKey('attachment_id', $resp['data']);
        $this->assertGreaterThan(0, $resp['data']['attachment_id']);
        // file_id 自引用 = attachment.id（spec §3.3 软引用）
        $this->assertSame($resp['data']['attachment_id'], $resp['data']['file_id']);

        $this->assertDatabaseHas('task_field_attachments', [
            'id'              => $resp['data']['attachment_id'],
            'report_id'       => $report->id,
            'field_code'      => 'progress_attach',
            'uploader_userid' => $user->userid,
        ]);

        $row = TaskFieldAttachment::find($resp['data']['attachment_id']);
        $this->assertNotNull($row);
        $this->assertGreaterThan(0, $row->size); // 4KB ≈ 4096 byte
        $this->assertSame((int) $row->id, (int) $row->file_id);
    }

    public function test_upload_rejects_non_member(): void
    {
        ['task' => $task, 'report' => $report] = $this->setupProjectTaskReport();
        $outsider = User::factory()->create();
        $file = UploadedFile::fake()->create('a.txt', 1);

        $resp = $this->callUpload($outsider, [
            'task_id'    => $task->id,
            'report_id'  => $report->id,
            'field_code' => 'progress_attach',
        ], $file);

        $this->assertSame(0, $resp['ret']);
        $this->assertStringContainsString('您不是该项目成员', $resp['msg']);
    }

    public function test_upload_rejects_invalid_task(): void
    {
        ['user' => $user] = $this->setupProjectTaskReport();
        $file = UploadedFile::fake()->create('a.txt', 1);

        $resp = $this->callUpload($user, [
            'task_id'    => 0,
            'report_id'  => 1,
            'field_code' => 'progress_attach',
        ], $file);

        $this->assertSame(0, $resp['ret']);
        $this->assertStringContainsString('参数错误', $resp['msg']);
    }

    public function test_upload_rejects_missing_field_code(): void
    {
        ['user' => $user, 'task' => $task, 'report' => $report] = $this->setupProjectTaskReport();
        $file = UploadedFile::fake()->create('a.txt', 1);

        $resp = $this->callUpload($user, [
            'task_id'    => $task->id,
            'report_id'  => $report->id,
            'field_code' => '',
        ], $file);

        $this->assertSame(0, $resp['ret']);
        $this->assertStringContainsString('field_code', $resp['msg']);
    }

    public function test_upload_rejects_missing_report_id(): void
    {
        ['user' => $user, 'task' => $task] = $this->setupProjectTaskReport();
        $file = UploadedFile::fake()->create('a.txt', 1);

        // spec §6 v3.2 P1-1：附件需先创建上报后再上传
        $resp = $this->callUpload($user, [
            'task_id'    => $task->id,
            'report_id'  => 0,
            'field_code' => 'progress_attach',
        ], $file);

        $this->assertSame(0, $resp['ret']);
        $this->assertStringContainsString('report_id', $resp['msg']);
    }

    public function test_upload_rejects_cross_user_report(): void
    {
        ['task' => $task, 'project' => $project, 'report' => $report] = $this->setupProjectTaskReport();
        // 另一个项目成员，但不是 report 的 reporter
        $other = User::factory()->create();
        ProjectUser::createInstance([
            'project_id' => $project->id,
            'userid'     => $other->userid,
            'owner'      => 0,
        ])->save();
        $file = UploadedFile::fake()->create('a.txt', 1);

        $resp = $this->callUpload($other, [
            'task_id'    => $task->id,
            'report_id'  => $report->id,
            'field_code' => 'progress_attach',
        ], $file);

        $this->assertSame(0, $resp['ret']);
        $this->assertStringContainsString('上报不存在或无权上传', $resp['msg']);
    }

    public function test_taskreport_attachments_relation_works(): void
    {
        ['user' => $user, 'report' => $report] = $this->setupProjectTaskReport();

        // 直写 model（绕开 controller，专测 hasMany 关联）
        $a1 = TaskFieldAttachment::createInstance([
            'report_id'       => $report->id,
            'field_code'      => 'progress_attach',
            'file_id'         => 0,
            'filename'        => 'one.txt',
            'size'            => 100,
            'mime_type'       => 'text/plain',
            'uploader_userid' => $user->userid,
        ]);
        $a1->save();
        $a1->file_id = $a1->id;
        $a1->save();

        $a2 = TaskFieldAttachment::createInstance([
            'report_id'       => $report->id,
            'field_code'      => 'design_attach',
            'file_id'         => 0,
            'filename'        => 'two.png',
            'size'            => 200,
            'mime_type'       => 'image/png',
            'uploader_userid' => $user->userid,
        ]);
        $a2->save();
        $a2->file_id = $a2->id;
        $a2->save();

        $fresh = TaskReport::find($report->id);
        $this->assertCount(2, $fresh->attachments);
        $codes = $fresh->attachments->pluck('field_code')->sort()->values()->toArray();
        $this->assertSame(['design_attach', 'progress_attach'], $codes);

        // uploader 关联 NF3 快照
        $this->assertNotNull($a1->uploader);
        $this->assertSame($user->userid, $a1->uploader->userid);
    }
}
