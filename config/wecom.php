<?php

/**
 * 企业微信模块配置
 *
 * dootask CLAUDE.md 约定：严禁 Service 层用 env() 直读（config:cache 后返 null）。
 * 所有 M1 / M2 / 未来版本的 wecom 配置都走这里。
 *
 * M1 任务分配企微通知（见 docs/plans/2026-04-22-dootask-wecom-task-notification-design.md v2.1 §8.2）：
 * - as_push_url              Dootask → AgentStudio 推送 URL (宿主 IP，v2.1 决策 10)
 * - as_push_secret           HMAC 签名密钥，Dootask 签 / AS 验（v2.1 §8.3）
 * - dootask_base_url         Blade 模板渲染详情链接用
 * - default_a2a_agent_id     M1 单 bot 启动校验 + Service::getDefaultA2aAgentId() 用
 * - notify_retry_max_attempts 推送重试上限（默认 5，超过转 failed）
 */

return [

    /*
    |--------------------------------------------------------------------------
    | M1 任务分配企微通知
    |--------------------------------------------------------------------------
    */

    // AgentStudio push endpoint URL (用宿主 IP；三项目 docker network 不互通)
    'as_push_url' => env('AS_PUSH_URL'),

    // AS_PUSH_SECRET: Dootask → AS 方向的 HMAC secret，必须与 AS 侧同值
    // 生成：openssl rand -hex 32
    'as_push_secret' => env('AS_PUSH_SECRET'),

    // Dootask H5 基地址，Blade 渲染详情链接用（v2.1 §7.2 Renderer 消费）
    'dootask_base_url' => env('DOOTASK_BASE_URL'),

    // M1 单 bot 启动校验 + WecomNotifierService::getDefaultA2aAgentId() 取值
    // 必须与 wecom-bot-bridge 的 bots.json[0].a2aAgentId 一致（v2.1 §8.2.1 跨团队协同）
    'default_a2a_agent_id' => env('WECOM_DEFAULT_A2A_AGENT_ID'),

    // 推送重试上限，超过转 status='failed'（v2.1 §5 状态机）
    'notify_retry_max_attempts' => (int) env('WECOM_NOTIFY_RETRY_MAX_ATTEMPTS', 5),

];
