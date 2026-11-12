<?php

/**
 * 套餐配额与成本护栏（默认值，可在后台调整，不得写死）。
 *
 * - 站点数与月度文章篇数是对外配额：篇数是第一道硬限，站点数是入口硬限。
 * - token 预算只作内部成本护栏：达到 degrade_ratio 降级到低成本模型，
 *   达到 block_ratio 拦截新发起；不对外报价、不写入计费账本。
 * - 租户可用 `tenants.plan_code` 选择套餐，并可在 `tenants.settings['quota']`
 *   覆盖 `site_limit / article_quota / token_budget / downgrade_model_id`。
 */
return [
    'default_plan' => env('BILLING_DEFAULT_PLAN', 'free'),

    'degrade_ratio' => (float) env('BILLING_DEGRADE_RATIO', 0.8),

    'block_ratio' => (float) env('BILLING_BLOCK_RATIO', 1.0),

    'plans' => [
        'free' => [
            'label' => 'Free',
            'site_limit' => 1,
            'article_quota' => 30,
            'token_budget' => 3_000_000,
            'downgrade_model_id' => null,
        ],
        'pro' => [
            'label' => 'Pro',
            'site_limit' => 5,
            'article_quota' => 200,
            'token_budget' => 30_000_000,
            'downgrade_model_id' => null,
        ],
        'enterprise' => [
            'label' => 'Enterprise',
            'site_limit' => 20,
            'article_quota' => 1000,
            'token_budget' => 200_000_000,
            'downgrade_model_id' => null,
        ],
    ],
];
