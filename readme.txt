=== AICommentAudit ===
Contributors: RainReiki
Tags: comments, comment moderation, spam, ai, deepseek
Requires at least: 7.1
Tested up to: 7.1.5
Requires PHP: 8.0
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

本地规则 + AI 评论审核。先拦截明显垃圾，再结合 DeepSeek 或 OpenAI 兼容接口、评论者信誉和身份信息给出最终结果。

== Description ==

AICommentAudit 把评论审核拆成两层：

1. 本地硬规则：明确违禁词、IP / 邮箱 / 网址黑名单、同一 IP 频率、重复评论、链接数量；
2. AI 审核：把评论、文章上下文、回复上下文、评论者信誉和身份信息交给 DeepSeek 或 OpenAI 兼容接口；
3. 最终决策：结合 AI 结果、信誉等级、身份风险分和阈值，给出通过 / 待审 / 拒绝。

主要功能：

* 支持 DeepSeek 官方和第三方 OpenAI 兼容中转站；
* 支持获取模型列表、测试连接并显示耗时；
* 回复评论会参考父评论及上级对话；
* 身份检查使用 AI 0–10 分，识别灌水、占位邮箱、平台首页、昵称冒用等组合；
* 评论者信誉：新用户 / 普通用户 / 老访客 / 白名单；
* 审核日志、详情、人工改判和信誉管理；
* 历史扫描：仅本地规则或本地 + AI，可批量通过 / 待审 / 垃圾 / 删除；
* 可选原生评论列表审核状态列；
* 不修改 WordPress 核心文件。

== Installation ==

1. 上传 `ai-comment-audit` 目录到 `/wp-content/plugins/`，或在后台“插件 → 安装插件”上传 zip；
2. 启用插件；
3. 打开“评论审核 → 设置”，填写服务地址、API Key 和模型；
4. 保存后点击“获取模型列表”选择模型，再点“测试连接”确认；
5. 根据需要配置词表、黑名单、频率和审核策略。

== Frequently Asked Questions ==

= 支持哪些 AI 服务？ =

任何兼容 OpenAI `chat/completions` 的服务都可以，包括 DeepSeek 官方和常见中转站。服务地址可以填根地址、`/v1` 或完整的 `/v1/chat/completions`。

= 为什么官方和中转站的模型名不一样？ =

模型 ID 由服务商自己定义。官方可能叫 `deepseek-flash`，中转站可能叫 `deepseek-v4.1-flash`。保存设置后点击“获取模型列表”，选择该服务实际返回的 ID。

= 拒绝的评论会怎样？ =

被拒绝的评论会以“垃圾”状态保存，不会公开显示；待审评论进入 WordPress 的待审核队列。

= 会不会拖慢评论提交？ =

AI 调用是同步的。可以在设置里关闭思考模式、降低最大 token，或减少文章上下文来降低耗时；测试连接会显示实际耗时。

= 会把哪些内容发给 AI？ =

评论内容、昵称、邮箱前缀与域名、网址域名、文章标题/原文/摘要、回复上下文、评论者信誉统计和身份历史摘要。不会发送完整邮箱、IP、网址路径或参数。

== Changelog ==

= 1.1.0 =
* 插件更名为 AICommentAudit。
* 自动迁移 CommentAudit 1.0.0 的设置和数据库表，已装站点不需要重新配置。

= 1.0.0 =
* 首个 WordPress 版本，基于 Typecho CommentAudit 1.0.28。
* 本地规则、AI 审核、身份分、信誉、日志、历史扫描和设置页。
