# AICommentAudit

> WordPress 评论审核插件：本地规则 + AI 审核。  
> 适用环境：WordPress 7.1.5、PHP 8.0+、MySQL / MariaDB

AICommentAudit 把评论审核拆成三层：

```text
本地规则先拦截明显垃圾
→ AI 结合文章、回复上下文、评论者资料和信誉判断
→ 最终给出通过 / 待审 / 拒绝
```

## 功能

- 本地硬规则：违禁词、IP / 邮箱 / 网址黑名单；
- 本地预检：同一 IP 频率、重复评论、链接数量、疑似词；
- AI 审核：DeepSeek 官方或 OpenAI 兼容中转站；
- 支持 `/v1` 自动补全、完整 endpoint、SSE、模型列表、思考模式、最大 token；
- 回复评论参考父评论和上级对话；
- 身份检查：AI 0–10 分，高风险直接拒绝，中风险待审；
- 信誉体系：新用户 / 普通用户 / 老访客 / 白名单；
- 审核日志、详情、人工通过 / 待审 / 垃圾；
- 信誉管理、历史扫描和批量处理；
- 原生评论列表审核状态列；
- 测试连接并显示耗时。

## 安装

1. 下载 Release 中的 [AICommentAudit-1.2.0.zip](https://github.com/RainReiki/AICommentAudit/releases/download/v1.2.0/AICommentAudit-1.2.0.zip)；
2. 后台 → 插件 → 安装插件 → 上传 zip；
3. 启用插件；
4. 打开「评论审核 → 设置」，填写服务地址、API Key 和模型；
5. 保存后点「获取模型列表」选择模型，再点「测试连接」。

从旧版 `CommentAudit 1.0.0` 升级时，插件会自动迁移设置和数据库表。

## 被拒绝的评论

按 WordPress 惯例保存为 **垃圾（spam）**，不会公开显示；待审评论进入 WordPress 待审核队列。

## 隐私

发送给 AI 的内容包括：评论内容、昵称、邮箱前缀与域名、网址域名、文章标题/原文/摘要、回复上下文、评论者信誉统计和身份历史摘要。不会发送完整邮箱、IP、网址路径或参数。

## 开发

```text
ai-comment-audit.php   插件入口
includes/              规则、AI、数据、后台、评论流程
assets/                后台 CSS / JS
```

## 反馈

作者：羽忆  
站点：https://lizihao.com/  
仓库：https://github.com/RainReiki/AICommentAudit
