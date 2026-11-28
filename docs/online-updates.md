# GitHub 差异升级与版本迁移

## 使用

先手动安装一次 `AICommentAudit-1.5.1.zip`，之后打开「评论审核 → 在线升级」。插件每天两次检查 `RainReiki/AICommentAudit` 的 GitHub 最新正式 Release；也可以点击「检查更新」，再点击「立即升级」。不自动安装，不通过 WordPress 的整包覆盖更新。

版本来自 Release 的 `tag_name`，支持 `v1.5.0` 或 `1.5.0` 形式的三段正式版本号；忽略草稿、预发行版和分支提交。目标必须高于当前版本。Release 必须附带 `update-manifest.json`，插件验证 GitHub 返回的清单 SHA-256，再验证每个差异文件的大小和 SHA-256。只从固定仓库的 Release 及对应标签的 GitHub raw 地址获取文件，不接受浏览器提交的下载地址。

旧版 1.4.0 没有更新客户端，无法通过自身在线安装此功能；1.5.0 的侧边升级入口存在菜单注册顺序问题。请先上传一次 1.5.1 修复包。主题定制版不属于公开升级链；差异文件含本地改动时会停止升级，防止覆盖定制内容。

## 事务边界

1. 读取本机清单，与目标清单逐文件比较 SHA-256。下载新增、变更的文件；未变文件不下载、不写入，也不改变修改时间。
2. 本地改动与目标冲突时停止；只移除旧清单追踪且新版已删除的文件。额外的本地文件保留。
3. 在独立目录暂存并校验全部差异，检查 PHP 语法和 PHP / WordPress 最低版本要求。
4. 获取排他文件锁。所有正常插件请求从入口持有共享锁直到请求结束；若仍有请求处理中，本次升级返回稍后重试。锁定期间新请求返回 503 和 Retry-After，防止并发读写及新旧代码混用。
5. 备份将修改或删除的文件并先落盘恢复日志，再在插件数据库表的副本上依次执行尚未应用的版本变更。
6. 逐文件原子替换，只修改差异项；数据库副本通过一条 `RENAME TABLE` 同时切换。
7. 保存迁移版本，用带随机令牌的本站回环请求在另一 PHP 请求中完整加载新版 bootstrap，确认新代码可加载后写入提交标记。
8. 提交前任一步骤失败，恢复文件、数据库表和迁移版本。提交后只清理备份；清理中断不会撤销已完成升级。

这是有持久恢复日志的补偿事务，而非把文件操作包在 SQL 事务里。MySQL / MariaDB DDL 会隐式提交，所以所有数据库变更先作用于副本，原表一直保留到提交。恢复日志在每个破坏性步骤前写入，常规异常、PHP 中止由关闭处理器恢复；硬中断后由下一个请求在加载其它插件类之前恢复。若磁盘或数据库仍不可用，保留备份并阻止插件继续运行，修复权限或连接后自动重试，不会假报恢复成功。

恢复目录位于 `wp-content/aicommentaudit-private/<插件路径散列>/`，包含文件备份和恢复记录；数据库备份保存在同库临时表中。Apache / IIS 禁止访问文件已自动创建；Nginx 可为该目录配置 `deny all`。备份不包含设置密钥的数据库导出文件。成功后自动清理。

## 数据库版本文件

`migrations/<版本号>.json` 按版本排序，每个公开版本提供一个文件；没有数据库变更也提交空 `steps`。`from` 明确指定上一公开版本，清单必须从基线形成无断点的版本链。新安装先建立基线表，再按版本依次迁移，手动升级和在线升级都从已记录的版本依次执行未应用文件。旧版兼容基线是 1.4.0。

例如从 1.5.1 直接升到 1.8.0，目标清单必须保留 1.5.0、1.5.1、1.6.0、1.7.0、1.8.0 的变更文件；依次执行 1.6.0 → 1.7.0 → 1.8.0。有文件缺失、次序错误或已发布迁移被改写时拒绝升级。已执行的版本不会重复执行。

```json
{
  "version": "1.6.0",
  "from": "1.5.1",
  "steps": [
    {
      "table": "logs",
      "action": "add_column",
      "column": "audit_source",
      "definition": "VARCHAR(30) NOT NULL DEFAULT ''"
    },
    {
      "table": "logs",
      "action": "update_rows",
      "values": {"audit_source": "legacy"},
      "where": {"audit_source": ""}
    }
  ]
}
```

允许操作：

| action | 必填参数 | 用途 |
| --- | --- | --- |
| add_column / modify_column | table、column、definition | 添加 / 修改字段 |
| drop_column | table、column | 删除字段 |
| add_index | table、name、columns，可选 unique | 添加索引 |
| drop_index | table、name | 删除索引 |
| update_rows | table、values、where | 按等值条件回填数据 |

`table` 只允许 `logs`、`reputation`、`ai_cache`、`state`，自动映射到当前站点前缀的插件表。字段定义使用受限类型、长度、NULL 和字面默认值；不执行任意 SQL、PHP、存储过程、表达式或其它 WordPress 表的变更。需要其它迁移类型时，应先扩展迁移执行器和测试，再发布使用该类型的版本。

副本保留原表数据、自增序号及表结构。含自定义触发器、外键的插件表会阻止自动迁移，因为 `CREATE TABLE LIKE` 不会复制这些对象；不会静默丢弃约束。

## 发布

每个新版本：

1. 修改插件头、`AICOMMENTAUDIT_VERSION`、`readme.txt` 稳定版本号并补齐 README 更新日志。
2. 新增 `migrations/<版本>.json`，保留所有已发布文件；新增 `docs/releases/<版本>.md` 发布说明。
3. 运行 `python tools/build-release.py`。`dist/` 生成正式安装 ZIP 与 `update-manifest.json`；ZIP 内也有同一清单。
4. 清单追踪运行所需文件、README、readme 和所有迁移文件；测试、发布工具和 Git 元数据不安装到站点。使用 LF 字节打包，保证 GitHub raw 和清单哈希一致。
5. 推送 `v<版本>` 标签，GitHub Actions 验证 PHP 8.0 和真实 MariaDB 升级 / 回滚测试后，创建草稿、上传 ZIP 和清单，再一并公开。也支持手工上传这两个产物，不能只发布 ZIP。

`Install::DB_VERSION` 保持旧版基线 1.1.0；未来数据库变更只追加迁移文件，不再通过提高该常量在原表上运行 dbDelta。

不要手改 GitHub 上已发布的文件或迁移；修复应发布新版本。升级引导器的 journal 格式、`Guard` 入口和验收协议必须向后兼容，否则正在运行的旧更新客户端无法安全完成新版验收。

## 环境与限制

- 目前支持单 WordPress 站点。多站点涉及各站点独立的表和版本记录，此版明确禁止事务在线升级。
- 要求 PHP 8.0+、可直接写入插件及 `wp-content` 的文件权限、GitHub HTTPS 访问，以及可用的本站回环请求。`DISALLOW_FILE_MODS` 会禁用升级。
- 有数据库迁移时，需要为四张插件表创建副本的空间，以及 CREATE、ALTER、INSERT、UPDATE、RENAME、DROP 权限；不复制整个 WordPress 数据库。
- 回环验收要求另一个 PHP 请求能够执行；单工作进程或阻断回环的部署会验收失败并回滚。
- 磁盘损坏、外部手工覆盖、站点核心故障或不经过插件入口的外部写库不在文件锁保护范围内。恢复数据仍不可写时必须先修复环境；持久日志和备份会保留。
- 新版 bootstrap 验收检查完整类文件加载；不代表所有业务场景均已运行。发布前仍需测试业务功能。

## 本地验证

```text
python tools/build-release.py
AICA_DB_HOST=127.0.0.1 AICA_DB_PORT=3306 AICA_DB_USER=root AICA_DB_PASSWORD=... php tests/updater-test.php
```

测试使用随机命名的独立数据库，结束后删除测试库；不要指向无权创建测试库的生产连接。覆盖跨版本顺序迁移、差异下载、保留未变文件、删改回滚、真实新进程加载、校验与语法失败、迁移失败、验收失败、数据库版本写入失败、中断前后恢复、提交后清理、并发锁和路径限制。
