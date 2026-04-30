<?php

// [CUSTOM:report-channel]
// Sprint 5a Task 5a.4 · Spec §3.3 task_field_attachments 模型
//
// 关联：
//   - report (TaskReport, belongsTo) ：所属上报
//   - uploader (User, belongsTo, NF3 快照) ：上传人 userid 写入即冻结
//
// 注：spec §3.3 描述 file_id 为"dootask files 表主键，软引用无 FK 约束"。
// 报告通道场景下不复用 dootask 的 File 文档管理体系（带历史/权限/共享，过度复杂），
// 直接走 Base::upload() 写 public/ 落盘 + 写本表 metadata；约定 file_id = 本行 id（自引用），
// 让 file_id 仍是 NOT NULL 合法值，未来若要改用真正的 files 表，仅改 controller 即可。

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class TaskFieldAttachment extends AbstractModel
{
    use SoftDeletes;

    protected $table = 'task_field_attachments';

    protected $fillable = [
        'report_id',
        'field_code',
        'file_id',
        'filename',
        'size',
        'mime_type',
        'uploader_userid',
    ];

    protected $casts = [
        'report_id'       => 'integer',
        'file_id'         => 'integer',
        'size'            => 'integer',
        'uploader_userid' => 'integer',
    ];

    /**
     * 关联：所属 report
     */
    public function report()
    {
        return $this->belongsTo(TaskReport::class, 'report_id', 'id');
    }

    /**
     * 关联：上传人（NF3 快照不漂移：userid 写入即冻结）
     */
    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploader_userid', 'userid');
    }
}
