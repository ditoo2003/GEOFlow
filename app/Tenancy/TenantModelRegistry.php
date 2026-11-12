<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * 根据 `config('tenancy.tables')` 自动发现需要租户作用域的 Eloquent 模型。
 *
 * 以清单为单一事实来源，避免新增模型时忘记接入全局作用域。
 */
final class TenantModelRegistry
{
    /**
     * @return array<class-string<Model>, string> 模型类名 => 表名
     */
    public static function scopedModels(): array
    {
        $tables = (array) config('tenancy.tables', []);
        $models = [];

        foreach (glob(app_path('Models/*.php')) ?: [] as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');

            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            try {
                $model = new $class;
            } catch (Throwable) {
                continue;
            }

            $table = $model->getTable();
            if (! in_array($table, $tables, true)) {
                continue;
            }

            $models[$class] = $table;
        }

        ksort($models);

        return $models;
    }
}
