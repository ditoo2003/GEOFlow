<?php

/**
 * 多租户隔离清单（单一事实来源）。
 *
 * - `tables`：P0 纳入租户隔离的业务表，迁移会为其补 `tenant_id`、索引与 RLS 策略；
 *   Eloquent 全局作用域也按这份清单注册。
 * - `platform_tables`：`tenant_id` 允许为 NULL，表示平台级模板/系统模型池（所有租户只读可见）。
 * - `control_tables`：身份与运行时基础设施表，明确不纳入 P0 租户隔离。
 */

return [
    'default_tenant_id' => 1,

    'default_tenant' => [
        'slug' => 'default',
        'name' => '默认租户',
    ],

    /*
     * 应用运行时使用的数据库角色。
     *
     * PostgreSQL 的超级用户与 BYPASSRLS 角色会绕过行级安全，因此应用连接必须使用
     * 独立的受限角色（迁移/初始化仍用高权限角色）。角色由 2026_11_12_100600 迁移创建。
     */
    'runtime_role' => [
        'name' => env('DB_RUNTIME_USERNAME', 'geoflow_app'),
        'password' => env('DB_RUNTIME_PASSWORD', ''),
    ],

    /*
     * 系统升级在队列进程内执行 `artisan migrate` 子进程，需要高权限账号（运行时角色没有 DDL 权限）。
     * 未配置时子进程继承当前连接账号，不改变单角色部署的原有行为。
     */
    'privileged_connection' => [
        'username' => env('DB_ADMIN_USERNAME'),
        'password' => env('DB_ADMIN_PASSWORD'),
    ],

    'platform_tables' => [
        'ai_models',
        'prompts',
        'sensitive_words',
    ],

    'tables' => [
        // 内容生产
        'tasks',
        'task_runs',
        'task_schedules',
        'task_knowledge_bases',
        'task_distribution_channels',
        'articles',
        'article_images',
        'article_reviews',
        'article_risk_scans',
        'article_slug_histories',
        'categories',
        'category_slug_histories',
        'authors',
        'titles',
        'title_libraries',
        'title_generation_runs',
        'title_generation_run_keywords',
        'keyword_libraries',
        'keywords',
        'prompts',
        'sensitive_words',

        // 知识库
        'knowledge_bases',
        'knowledge_chunks',
        'knowledge_chunk_sync_rows',
        'knowledge_base_revisions',
        'knowledge_media_assets',
        'knowledge_fact_libraries',
        'knowledge_fact_library_revisions',
        'knowledge_facts',
        'knowledge_fact_values',
        'knowledge_fact_evidences',
        'knowledge_fact_generation_runs',
        'enterprise_knowledge_projects',
        'enterprise_knowledge_sources',
        'enterprise_knowledge_revisions',
        'system_knowledge_bases',

        // 分发与站点
        'distribution_channels',
        'distribution_channel_secrets',
        'distribution_channel_operations',
        'distribution_logs',
        'hosted_site_profiles',
        'hosted_site_article_assignments',
        'hosted_site_allocation_requests',
        'article_distributions',
        'manual_publications',
        'manual_publication_accounts',
        'manual_publication_personas',
        'manual_publication_transitions',

        // 站点资产
        'site_settings',
        'view_logs',
        'lead_forms',
        'lead_submissions',
        'topics',
        'topic_revisions',
        'topic_revision_articles',
        'topic_paths',
        'topic_import_batches',
        'topic_source_invalidations',
        'topic_build_runs',
        'topic_freshness_changes',
        'link_pool_targets',
        'link_pool_events',
        'link_pool_html_deliveries',
        'link_pool_import_batches',
        'link_pool_theme_adaptations',
        'link_campaigns',
        'link_campaign_sources',
        'link_campaign_targets',
        'link_campaign_drafts',
        'link_placements',
        'link_metrics_daily',
        'link_selection_batches',
        'link_selection_heads',

        // AI 与用量
        'ai_models',
        'ai_model_usage_events',
        'ai_model_usage_attempt_starts',
        'admin_ai_settings',
        'ai_source_providers',
        'ai_visibility_topics',
        'ai_visibility_topic_keywords',
        'ai_visibility_runs',
        'ai_visibility_sources',
        'ai_visibility_competitors',
        'ai_visibility_competitor_detections',
        'agent_conversations',
        'agent_conversation_messages',
        'ai_workspace_runs',
        'ai_workspace_steps',
        'ai_workspace_artifacts',
        'ai_workspace_approvals',
        'ai_workspace_external_operations',
        'ai_workspace_surface_events',
        'ai_workspace_trace_events',
        'ai_quality_audit_events',

        // 文章 AI 质检 / 优化（按文章归属租户）
        'article_ai_quality_checks',
        'article_ai_quality_check_sources',
        'article_ai_quality_knowledge_bases',
        'article_ai_quality_segments',
        'article_ai_optimization_runs',
        'article_ai_optimization_steps',

        // 图片资产
        'images',
        'image_libraries',

        // URL 导入与变更
        'url_import_jobs',
        'url_import_job_logs',
        'url_change_requests',
        'url_change_scope_states',
    ],

    'control_tables' => [
        'tenants',
        'tenant_memberships',
        'admins',
        'users',
        'personal_access_tokens',
        'api_idempotency_keys',
        'admin_activity_logs',
        'admin_ai_access_shadow_events',
        'migrations',
        'jobs',
        'failed_jobs',
        'job_batches',
        'cache',
        'cache_locks',
        'sessions',
        'password_reset_tokens',
        'system_logs',
        'system_states',
        'system_update_runs',
        'system_update_backups',
        'management_operations',
        'recovery_preparations',
        'recovery_quarantines',
        'recovery_reconciliations',
        'recovery_reconciliation_decisions',
        'theme_releases',
        'theme_revisions',
        'theme_workspaces',
        'site_theme_bindings',
        'site_theme_replications',
        'site_theme_replication_versions',
        'site_theme_replication_logs',
        'worker_heartbeats',

        // 文件系统级登记表：path_hash 全局唯一、同一物理文件被所有站点共享，
        // 无法在不改变语义的前提下按租户切分，因此保持平台级共享。
        'managed_image_paths',

        // 文章 AI 质检灰度是平台级单行配置与全局变更日志，不按租户切分。
        'article_ai_quality_rollouts',
        'article_ai_quality_rollout_events',
        'task_trash_entries',
        'task_trash_state',
    ],
];
