# 独立实例最小验证（物理隔离）

在同一台机器上用**独立 compose project、独立数据目录、独立端口**起第二套完整栈，跑完整安装与同版本升级，
验证 RLS 隔离、注册链路、配额硬限与后台看板。验证完成后整栈拆除，只保留本编排与本文档作为交付产物。

- 验证实例：project `geoflow-iso`，Web `127.0.0.1:28080`，PostgreSQL `127.0.0.1:25432`，Redis `127.0.0.1:26379`
- 现网实例：project `geoflow-laravel`，Web `127.0.0.1:18080`，PostgreSQL `127.0.0.1:15432`，Redis `127.0.0.1:16379`（全程未改动）
- 数据目录：`./docker-data/iso/{postgres,redis,storage,bootstrap-cache}`（独立 bind mount，不与现网 `./docker-data/dev` 共享）
- 网络：`geoflow-iso-net`（`10.89.0.0/16`），容器名统一 `geoflow-iso-*`

## 1. 前置条件

1. 已构建 dev 镜像 `geoflow-app:latest`（`docker compose build init` 或直接使用现网已构建镜像）。
2. 复制环境模板并填写独立实例配置：

   ```bash
   cp .env.prod.example deployment/isolated-verification/.env.iso
   ```

   `.env.iso` 已被 `.gitignore` 忽略，不提交。必须与现网不同的配置项：

   | 键 | 独立实例取值 | 说明 |
   | --- | --- | --- |
   | `COMPOSE_PROJECT_NAME` | `geoflow-iso` | 卷、网络、容器归属 |
   | `APP_PORT` / `APP_URL` | `28080` / `http://127.0.0.1:28080` | 与现网 18080 不冲突 |
   | `DB_EXPOSE_PORT` / `REDIS_EXPOSE_PORT` | `25432` / `26379` | 与现网 15432/16379 不冲突 |
   | `DB_PASSWORD` / `DB_RUNTIME_PASSWORD` / `DB_ADMIN_PASSWORD` / `REDIS_PASSWORD` | 全部新生成 | 不得复用现网口令 |
   | `APP_KEY` | 新生成 | 不得复用现网密钥 |
   | `POSTGRES_DATA_DIR` / `REDIS_DATA_DIR` | `./docker-data/iso/postgres` / `./docker-data/iso/redis` | 独立数据 |
   | `GEOFLOW_ENV_FILE` / `GEOFLOW_STORAGE_DIR` | `./deployment/isolated-verification/.env.iso` / `./docker-data/iso/storage` | prod 编排同机多实例参数 |
   | `DOCKER_NETWORK_NAME` / `DOCKER_NETWORK_SUBNET` | `geoflow-iso-net` / `10.89.0.0/16` | 独立网段 |
   | `REGISTRATION_MODE` | `public` | 验证公开注册链路 |
   | `MAIL_MAILER` | `log` | 验证环境不连真实 SMTP，验证链接从日志取 |
   | `LOG_LEVEL` | `debug` | `log` 邮件走 debug 级日志，低于 warning 会被过滤 |
   | `AUTO_MIGRATE` / `AUTO_OPTIMIZE` | `false` | 迁移与安装由 init 一次性完成；禁止把高权限 DB 配置写入 bootstrap cache |
   | `AI_QUALITY_QUEUE_REPLICAS` | `1` | 避免多余的容器副本 |

3. `AUTO_INIT_ONCE=true` 由 `docker-compose.yml` 的 `init` 服务自带；entrypoint 会执行
   `migrate --force` + `geoflow:install`，并创建 RLS 受限运行时角色 `geoflow_app`（`NOSUPERUSER` / `NOBYPASSRLS`）。

## 2. 完整起停命令

启动（项目根目录执行）：

```bash
docker compose -p geoflow-iso \
  --env-file ./deployment/isolated-verification/.env.iso \
  -f docker-compose.yml \
  -f deployment/isolated-verification/docker-compose.iso.yml up -d
```

等待 init 成功（退出码 0 且日志出现安装完成）：

```bash
docker inspect -f '{{.State.ExitCode}}' geoflow-iso-init
docker logs --tail 40 geoflow-iso-init
```

停止但保留数据（可再次 `up -d` 恢复）：

```bash
docker compose -p geoflow-iso \
  --env-file ./deployment/isolated-verification/.env.iso \
  -f docker-compose.yml \
  -f deployment/isolated-verification/docker-compose.iso.yml down
```

拆除整栈（删除容器、网络、命名卷）：

```bash
docker compose -p geoflow-iso \
  --env-file ./deployment/isolated-verification/.env.iso \
  -f docker-compose.yml \
  -f deployment/isolated-verification/docker-compose.iso.yml down -v --remove-orphans
```

清理独立数据目录与 env 文件（Windows PowerShell）：

```powershell
Remove-Item -Recurse -Force .\docker-data\iso
Remove-Item -Force .\deployment\isolated-verification\.env.iso
```

## 3. 实测结果

实例安装：193 条迁移全部 DONE，`geoflow:install` 写入安装标记，`geoflow_app` 角色创建成功
（`rolsuper=f`、`rolbypassrls=f`），seed 出 1 个租户、1 个管理员。

### 3.1 RLS 隔离

`php artisan tenant:rls-check`：

- 当前连接角色为 `geoflow_app`（非 superuser、`NOBYPASSRLS`），目标表均为 `ENABLE` + `FORCE ROW LEVEL SECURITY`；
- 会话约定 `app.tenant_id` / `app.tenant_bypass` 生效。

数据级验证（在 PostgreSQL 内直接以 `geoflow_app` 执行）：

- 未设置上下文：`count(*) = 0`（fail-closed）；
- `app.tenant_id=1`：只见 3 条；`app.tenant_id=2`：只见 1 条（qty=5）；
- 跨租户 `INSERT` 被 `WITH CHECK` 拒绝：`new row violates row-level security policy`。

### 3.2 注册链路（公开模式）

- `POST /register` 创建 pending signup（此时不建租户）；
- 由 `storage/logs/laravel-*.log` 取得验证链接，`GET /register/verify/{token}` 后：
  新租户创建（`plan_code=free`）、管理员创建、signup 置为 consumed；
- 同一链接重放返回"无效/已使用"页；
- 验证链接生成依赖 `APP_URL`，直接访问 `http://127.0.0.1:28080/...` 可正常 302 到后台。

### 3.3 配额硬限

- 站点数：free 套餐 `site_limit=1`，新建第二个站点返回
  `当前套餐站点数已达上限（1 个），请升级套餐或归档旧站点。`
- 文章数：当月计量 30 篇后发起生成返回
  `本月已发布文章数已达套餐上限（30 篇），新的生成任务已暂停，下月自动恢复；已运行任务不受影响。`
- `summary`：`site_count=1/site_limit=1/article_count=30/article_quota=30/token_budget=3000000`。

### 3.4 后台看板

- `POST /geo_admin/login`（`username` + `password`）302 到 `/geo_admin/dashboard`；
- `GET /geo_admin/usage` 200，页面含配额/额度、站点数、新注册租户。

### 3.5 同版本升级（升级迁移清单可执行）

```bash
docker exec geoflow-iso-app php artisan geoflow:upgrade --phase=inspect --strategy=maintenance --json
docker exec -e GEOFLOW_SECURITY_UPGRADE_DRAIN_CONFIRMED=true geoflow-iso-app \
  php artisan geoflow:upgrade --phase=apply --strategy=maintenance \
  --operation=iso-same-version-20261113b --source-sequence=1 --plan-sha256=<plan 文件 SHA-256> --json
docker exec geoflow-iso-app php artisan geoflow:upgrade --phase=verify --strategy=maintenance \
  --operation=iso-same-version-20261113b --source-sequence=1 --plan-sha256=<plan 文件 SHA-256> --json
```

实测结果：`inspect` / `apply` / `verify` 全部 `status=pass`，`pending_migrations=[]`，
`system_knowledge` 校验对 3 个租户全部 `pass`，journal `pass`。
`online_eligibility` 为 `fail` 属预期：本次迁移含主键 DDL，标记为离线，`required=false`，走 maintenance 策略。

验证中发现并修复的两个缺陷（已包含在本次提交）：

1. `system_knowledge_bases` 原主键为单列 `system_key`，第二个租户写入同一 `system_key` 时触发
   `duplicate key value violates unique constraint "system_knowledge_bases_pkey"`；
   新增迁移 `2026_11_13_130000_scope_system_knowledge_bases_primary_key_by_tenant` 改为复合主键
   `(tenant_id, system_key)` 并补 `system_key` 普通索引。
2. `IteratesTenants` 在多租户下向 stdout 打印"租户 #N ..."进度行，污染 `--json` 输出导致升级数据校验解析失败；
   现在声明 `--json` 且启用时不再输出进度行。

### 3.6 升级清单与文件

- 清单文件：`deployment/upgrade-plan.json`（本次 194 条迁移，plan SHA-256 前缀 `3e4c7bdd…`）
- 重新生成与校验：

  ```bash
  python deployment/generate-upgrade-plan.py --write
  python deployment/generate-upgrade-plan.py --check
  ```

- 应用升级前必须先确认 `docker compose ... exec app php artisan geoflow:upgrade --phase=inspect` 通过，
  并按 `deployment/upgrade-plan.json` 的 `allowed_sources` 传入来源版本序号。

## 4. 拆栈后的机器状态

- 独立实例容器、网络、命名卷已全部移除；`docker-data/iso` 与 `.env.iso` 已删除。
- 无 `geoflow-iso*` 容器 / 卷 / 网络残留。
- 现网实例未受影响：12 个容器运行中，端口 18080 / 15432 / 16379 不变，
  数据 `tenants=1`、`articles=3`、`admins=1`、`migrations=193`（新增 1 条迁移待下次部署应用）。

## 5. 独立部署需要客户准备

| 类别 | 需要准备 | 说明 |
| --- | --- | --- |
| 数据库 | 一台 PostgreSQL（建议 16+，需 `pgvector` 扩展） | 提供库名、账号、口令；需允许应用与迁移账号两个角色，迁移账号有 DDL 权限，运行时账号无 superuser / bypassrls |
| Redis | 一台 Redis（建议 7+） | 用于缓存、队列、锁与广播；提供连接串与口令 |
| SMTP | 可用 SMTP 服务 | 发件服务器、端口、加密方式、账号、口令、发件人地址；用于注册验证、通知邮件 |
| 域名 | 已备案/可解析域名 + HTTPS 证书 | 配置 `APP_URL`、`SITE_URL`、`GEOFLOW_PRIMARY_HOSTS`，反代到应用 Web 端口 |
| 模型 Key | 至少一个模型供应商 API Key 与 Base URL | 用于文章生成/质检/向量化；按需配置多个模型与 embedding 供应商 |

> 说明：本机验证时生产镜像构建受外网 apt 源限制未能完成，故改用 dev 镜像 `geoflow-app:latest` +
> 隔离 override 完成验证；`docker-compose.prod.yml` 的多实例参数化改动（`GEOFLOW_ENV_FILE`、
> `GEOFLOW_STORAGE_DIR`）已作为交付物保留，默认值不改变原行为。
