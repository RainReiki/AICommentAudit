<?php

namespace AICommentAudit\AI;

use AICommentAudit\Repository;
use AICommentAudit\Rules\ConversationContext;
use AICommentAudit\Rules\IdentityContext;

defined('ABSPATH') || exit;

/**
 * OpenAI-compatible audit client (DeepSeek official or a relay).
 */
final class Client
{
    private const USER_AGENT = 'AICommentAudit-WP/1.3.0';

    /**
     * @param array $comment
     * @param array $article
     * @param array $features
     * @param array $settings
     * @param bool $preview
     * @param bool $ignoreQuota Allow the connection test to run even when the quota is exhausted.
     * @return array
     */
    public static function audit(array $comment, array $article, array $features, array $settings, bool $preview = false, bool $ignoreQuota = false): array
    {
        $model = trim((string) ($settings['model'] ?? 'deepseek-flash'));
        if ($model === '') {
            $model = 'deepseek-flash';
        }

        $apiKey = trim((string) ($settings['apiKey'] ?? ''));
        if ($apiKey === '') {
            return self::failure('ai_missing_key');
        }

        try {
            $features['conversation_context'] = ConversationContext::build($comment);
        } catch (\Throwable $e) {
            return self::failure('ai_error', 0, __('暂时无法读取回复的对话记录，请稍后重试。', 'ai-comment-audit'));
        }

        $identityEnabled = !empty($settings['identityProtection']);
        if ($identityEnabled) {
            try {
                $features['identity_context'] = IdentityContext::build($comment);
            } catch (\Throwable $e) {
                return self::failure('ai_error', 0, __('暂时无法读取身份检查所需的站点记录，请稍后重试。', 'ai-comment-audit'));
            }
        }

        $cacheKey = PromptBuilder::cacheKey($model, $comment, $article, $features);
        $cached = Repository::getCache($cacheKey);
        if (is_array($cached)) {
            $cached['cached'] = true;
            $cached['latency_ms'] = 0;
            return $cached;
        }

        $circuit = Repository::getState('ai_circuit');
        if (!empty($circuit['open_until']) && (int) $circuit['open_until'] > time()) {
            return self::failure('ai_circuit_open');
        }

        $tokenQuota = max(0, (int) ($settings['tokenQuota'] ?? 0));
        $quotaPeriod = (string) ($settings['quotaPeriod'] ?? 'monthly');
        if (!$ignoreQuota && $tokenQuota > 0) {
            $usage = Repository::getTokenUsage($quotaPeriod);
            if ((int) $usage['tokens'] >= $tokenQuota) {
                return self::failure('quota_exceeded', 0, sprintf(
                    __('AI 令牌额度已用完（%1$s / %2$s tokens）。请调高额度或重置已用额度。', 'ai-comment-audit'),
                    number_format_i18n((int) $usage['tokens']),
                    number_format_i18n($tokenQuota)
                ));
            }
        }

        $messages = PromptBuilder::build($comment, $article, $features);
        $baseUrl = self::normalizeBaseUrl((string) ($settings['baseUrl'] ?? 'https://api.deepseek.com'));
        $timeout = max(3, (int) ($settings['timeout'] ?? 8));
        $sslVerify = !empty($settings['sslVerify']);
        $body = self::buildBody($model, $messages, $settings, $baseUrl);

        $start = microtime(true);
        $response = ['body' => '', 'error' => 'http_error', 'status' => 0];
        $data = null;
        $endpoint = '';
        $attempts = self::endpoints($baseUrl);
        foreach ($attempts as $index => $candidate) {
            $endpoint = $candidate;
            $response = self::request($candidate, $apiKey, $body, $timeout, $sslVerify);

            if ($response['error'] === '') {
                $data = self::decodeResponseBody($response['body']);
                if (is_array($data)) {
                    break;
                }
            }

            $retryable = ($response['error'] === '' && $response['status'] < 400)
                || in_array((int) $response['status'], [404, 405], true);
            if (!$retryable || $index === count($attempts) - 1) {
                break;
            }
            self::debug($settings, 'endpoint unavailable, trying fallback: ' . $candidate);
        }
        $latency = (int) round((microtime(true) - $start) * 1000);

        if ($response['error'] !== '') {
            self::debug($settings, 'http error status=' . (int) $response['status'] . ' endpoint=' . $endpoint);
            if (!$preview) {
                self::recordFailure();
            }
            return self::failure('ai_error', $latency, self::requestDiagnostic((int) $response['status'], (string) $response['error']));
        }

        if (!is_array($data)) {
            self::debug($settings, 'invalid response body: ' . self::short((string) $response['body'], 300));
            if (!$preview) {
                self::recordFailure();
            }
            return self::failure('ai_error', $latency, self::invalidResponseDiagnostic($response, $endpoint));
        }

        if (isset($data['error'])) {
            $message = self::errorMessage($data['error']);
            self::debug($settings, 'API returned an error: ' . self::short($message, 300));
            if (!$preview) {
                self::recordFailure();
            }
            return self::failure('ai_error', $latency, $message !== ''
                ? sprintf(__('审核服务返回错误：%s', 'ai-comment-audit'), self::short($message, 240))
                : __('审核服务拒绝了请求，请检查模型、密钥和账户设置。', 'ai-comment-audit'));
        }

        $content = self::extractContent($data);
        if (trim($content) === '') {
            self::debug($settings, 'no choices[0].message.content in response');
            if (!$preview) {
                self::recordFailure();
            }
            return self::failure('ai_error', $latency,
                __('审核服务返回了 JSON，但没有找到模型输出内容。请确认中转站兼容 OpenAI 的 /v1/chat/completions 格式。', 'ai-comment-audit'));
        }

        $parsed = ResultParser::parse($content, $identityEnabled);
        if (!$parsed) {
            self::debug($settings, 'could not parse the audit decision');
            if (!$preview) {
                self::recordFailure();
            }
            $truncated = ($data['choices'][0]['finish_reason'] ?? '') === 'length';
            return self::failure('ai_error', $latency, $truncated
                ? __('模型输出被截断，请在插件设置中增加「最大 token 数」。', 'ai-comment-audit')
                : __('模型没有返回可用的审核结果，请检查模型设置后重试。', 'ai-comment-audit'));
        }

        if ($identityEnabled && is_array($parsed['identity'] ?? null)) {
            $context = $features['identity_context'];
            $allowed = [
                'site_name_similarity', 'placeholder_email', 'public_mailbox', 'platform_homepage',
                'search_engine_homepage', 'shortened_website', 'brand_impersonation',
                'profile_mismatch', 'unverified_website',
            ];
            if (!empty($context['related_profiles'])) {
                $allowed[] = 'historical_name_similarity';
                $allowed[] = 'history_profile_conflict';
                if (array_sum(array_column($context['related_profiles'], 'spam')) > 0) {
                    $allowed[] = 'history_spam_pattern';
                }
            }
            $parsed['identity']['sources'] = array_values(array_intersect($allowed, $parsed['identity']['sources']));
            if (!empty($context['current']['authenticated_site_owner'])
                || $parsed['identity']['category'] === 'none'
                || empty($parsed['identity']['evidence'])) {
                $parsed['identity'] = [
                    'risk' => 'low',
                    'score' => 0,
                    'category' => 'none',
                    'sources' => [],
                    'evidence' => [],
                ];
            }
        }

        $parsed['latency_ms'] = $latency;
        $parsed['tokens'] = (int) ($data['usage']['total_tokens'] ?? 0);
        $parsed['cached'] = false;
        $parsed['conversation'] = $features['conversation_context'];

        if ($parsed['tokens'] > 0) {
            try {
                Repository::addTokenUsage((int) $parsed['tokens'], $quotaPeriod);
            } catch (\Throwable $e) {
                // Usage tracking must not break the audit.
            }
        }

        if ($preview) {
            return $parsed;
        }

        try {
            Repository::setCache(
                $cacheKey,
                $model,
                PromptBuilder::PROMPT_VERSION,
                $parsed,
                (int) ($settings['cacheTtl'] ?? 7)
            );
        } catch (\Throwable $e) {
            // Cache failure must not break the audit.
        }

        self::recordSuccess();

        return $parsed;
    }

    /**
     * Read the model list from the active provider.
     *
     * @param array $settings
     * @return array
     */
    public static function listModels(array $settings): array
    {
        $apiKey = trim((string) ($settings['apiKey'] ?? ''));
        if ($apiKey === '') {
            return ['success' => 0, 'message' => __('请先填写 API Key。', 'ai-comment-audit')];
        }

        $baseUrl = self::normalizeBaseUrl((string) ($settings['baseUrl'] ?? 'https://api.deepseek.com'));
        $timeout = max(3, (int) ($settings['timeout'] ?? 8));
        $sslVerify = !empty($settings['sslVerify']);
        $attempts = self::modelEndpoints($baseUrl);

        $response = ['body' => '', 'error' => 'http_error', 'status' => 0];
        $data = null;
        $endpoint = '';
        foreach ($attempts as $index => $candidate) {
            $endpoint = $candidate;
            $response = self::request($candidate, $apiKey, null, $timeout, $sslVerify);
            if ($response['error'] === '') {
                $data = self::decodeResponseBody($response['body']);
                if (is_array($data)) {
                    break;
                }
            }

            $retryable = ($response['error'] === '' && $response['status'] < 400)
                || in_array((int) $response['status'], [404, 405], true);
            if (!$retryable || $index === count($attempts) - 1) {
                break;
            }
        }

        if ($response['error'] !== '') {
            return ['success' => 0, 'message' => self::requestDiagnostic((int) $response['status'], (string) $response['error'])];
        }
        if (!is_array($data)) {
            return ['success' => 0, 'message' => self::invalidResponseDiagnostic($response, $endpoint)];
        }
        if (isset($data['error'])) {
            $message = self::errorMessage($data['error']);
            return ['success' => 0, 'message' => $message !== ''
                ? sprintf(__('审核服务返回错误：%s', 'ai-comment-audit'), self::short($message, 240))
                : __('审核服务拒绝了请求，请检查密钥和服务地址。', 'ai-comment-audit')];
        }

        $models = self::extractModels($data);
        if (!$models) {
            return ['success' => 0, 'message' => __('服务返回了 JSON，但没有找到模型列表。', 'ai-comment-audit')];
        }

        return ['success' => 1, 'models' => $models, 'endpoint' => $endpoint];
    }

    /**
     * @param string $model
     * @param array $messages
     * @param array $settings
     * @param string $baseUrl
     * @return array
     */
    private static function buildBody(string $model, array $messages, array $settings, string $baseUrl): array
    {
        $body = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0,
            'max_tokens' => max(300, (int) ($settings['maxTokens'] ?? 1200)),
            'stream' => false,
        ];

        $host = strtolower((string) wp_parse_url($baseUrl, PHP_URL_HOST));
        if (!empty($settings['disableThinking'])) {
            $body['thinking'] = ['type' => 'disabled'];
        } elseif ($host === 'api.deepseek.com'
            && in_array($model, ['deepseek-flash', 'deepseek-v4-flash', 'deepseek-v4-pro'], true)) {
            $body['thinking'] = ['type' => 'disabled'];
        }

        return $body;
    }

    /**
     * @param string $url
     * @return string
     */
    private static function normalizeBaseUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            $url = 'https://api.deepseek.com';
        }
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        $url = preg_replace('/[?#].*$/', '', $url) ?? $url;

        return rtrim($url, '/');
    }

    /**
     * @param string $baseUrl
     * @return string[]
     */
    private static function endpoints(string $baseUrl): array
    {
        $baseUrl = self::normalizeBaseUrl($baseUrl);
        if (preg_match('#/chat/completions$#i', $baseUrl)) {
            return [$baseUrl];
        }
        if (preg_match('#/v\d+(?:beta)?$#i', $baseUrl)) {
            return [$baseUrl . '/chat/completions'];
        }

        $host = strtolower((string) wp_parse_url($baseUrl, PHP_URL_HOST));
        if ($host === 'api.deepseek.com') {
            return [$baseUrl . '/chat/completions', $baseUrl . '/v1/chat/completions'];
        }

        return [$baseUrl . '/v1/chat/completions', $baseUrl . '/chat/completions'];
    }

    /**
     * @param string $baseUrl
     * @return string[]
     */
    private static function modelEndpoints(string $baseUrl): array
    {
        $baseUrl = self::normalizeBaseUrl($baseUrl);
        if (preg_match('#/chat/completions$#i', $baseUrl)) {
            $baseUrl = preg_replace('#/chat/completions$#i', '', $baseUrl) ?? $baseUrl;
        }
        if (preg_match('#/v\d+(?:beta)?$#i', $baseUrl)) {
            return [$baseUrl . '/models'];
        }

        $host = strtolower((string) wp_parse_url($baseUrl, PHP_URL_HOST));
        if ($host === 'api.deepseek.com') {
            return [$baseUrl . '/models', $baseUrl . '/v1/models'];
        }

        return [$baseUrl . '/v1/models', $baseUrl . '/models'];
    }

    /**
     * @param array $data
     * @return string[]
     */
    private static function extractModels(array $data): array
    {
        $rows = $data['data'] ?? $data['models'] ?? null;
        if (!is_array($rows)) {
            return [];
        }

        $models = [];
        foreach ($rows as $row) {
            if (is_string($row)) {
                $models[] = trim($row);
                continue;
            }
            if (!is_array($row)) {
                continue;
            }
            foreach (['id', 'name', 'model'] as $key) {
                if (isset($row[$key]) && is_string($row[$key]) && trim($row[$key]) !== '') {
                    $models[] = trim($row[$key]);
                    break;
                }
            }
        }

        $models = array_values(array_unique(array_filter($models, static fn ($model) => $model !== '')));
        sort($models, SORT_NATURAL | SORT_FLAG_CASE);

        return $models;
    }

    /**
     * @param string $endpoint
     * @param string $apiKey
     * @param array|null $body
     * @param int $timeout
     * @param bool $sslVerify
     * @return array{body:string,error:string,status:int}
     */
    private static function request(string $endpoint, string $apiKey, ?array $body, int $timeout, bool $sslVerify): array
    {
        $args = [
            'method' => $body === null ? 'GET' : 'POST',
            'timeout' => $timeout,
            'sslverify' => $sslVerify,
            'redirection' => 3,
            'decompress' => true,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $apiKey,
                'User-Agent' => self::USER_AGENT,
            ],
        ];
        if ($body !== null) {
            $encoded = wp_json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            $args['body'] = is_string($encoded) ? $encoded : '{}';
        }

        $response = wp_remote_request($endpoint, $args);
        if (is_wp_error($response)) {
            return ['body' => '', 'error' => 'wp_error', 'status' => 0];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        if ($status >= 400) {
            return ['body' => $raw, 'error' => 'http_' . $status, 'status' => $status];
        }

        return ['body' => $raw, 'error' => '', 'status' => $status];
    }

    /**
     * @param string $body
     * @return array|null
     */
    private static function decodeResponseBody(string $body): ?array
    {
        $body = trim($body);
        if ($body === '') {
            return null;
        }
        if (strncmp($body, "\xEF\xBB\xBF", 3) === 0) {
            $body = substr($body, 3);
        }

        $data = json_decode($body, true);
        if (is_array($data)) {
            return $data;
        }

        if (stripos($body, 'data:') !== false) {
            $sse = self::decodeSse($body);
            if ($sse !== null) {
                return $sse;
            }
        }

        $start = strpos($body, '{');
        $end = strrpos($body, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $data = json_decode(substr($body, $start, $end - $start + 1), true);
            if (is_array($data)) {
                return $data;
            }
        }

        return null;
    }

    /**
     * @param string $body
     * @return array|null
     */
    private static function decodeSse(string $body): ?array
    {
        $last = null;
        $text = '';
        foreach (preg_split('/\r\n|\n|\r/', $body) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, ':') === 0 || stripos($line, 'data:') !== 0) {
                continue;
            }
            $payload = trim(substr($line, 5));
            if ($payload === '' || $payload === '[DONE]') {
                continue;
            }
            $data = json_decode($payload, true);
            if (!is_array($data)) {
                continue;
            }
            $last = $data;
            $delta = $data['choices'][0]['delta']['content'] ?? null;
            if (is_string($delta)) {
                $text .= $delta;
            }
            $message = $data['choices'][0]['message']['content'] ?? null;
            if (is_string($message) && $message !== '') {
                $text = $message;
            }
        }

        if ($last === null) {
            return null;
        }
        if ($text !== '') {
            $last['choices'][0]['message']['content'] = $text;
            unset($last['choices'][0]['delta']);
        }

        return $last;
    }

    /**
     * @param array $data
     * @return string
     */
    private static function extractContent(array $data): string
    {
        $choice = $data['choices'][0] ?? null;
        if (is_array($choice)) {
            $message = $choice['message'] ?? null;
            if (is_array($message)) {
                $content = $message['content'] ?? '';
                if (is_string($content)) {
                    return $content;
                }
                if (is_array($content)) {
                    return self::joinTextParts($content);
                }
            }
            if (isset($choice['text']) && is_string($choice['text'])) {
                return $choice['text'];
            }
        }

        if (isset($data['output_text']) && is_string($data['output_text'])) {
            return $data['output_text'];
        }

        if (isset($data['output']) && is_array($data['output'])) {
            $text = self::joinTextParts($data['output']);
            if ($text !== '') {
                return $text;
            }
        }

        foreach (['response', 'content'] as $key) {
            if (isset($data[$key]) && is_string($data[$key])) {
                return $data[$key];
            }
        }

        return '';
    }

    /**
     * @param array $parts
     * @return string
     */
    private static function joinTextParts(array $parts): string
    {
        $text = '';
        foreach ($parts as $part) {
            if (is_string($part)) {
                $text .= $part;
            } elseif (is_array($part)) {
                if (isset($part['text']) && is_string($part['text'])) {
                    $text .= $part['text'];
                } elseif (isset($part['content'])) {
                    if (is_string($part['content'])) {
                        $text .= $part['content'];
                    } elseif (is_array($part['content'])) {
                        $text .= self::joinTextParts($part['content']);
                    }
                }
            }
        }

        return $text;
    }

    /**
     * @param array $response
     * @param string $endpoint
     * @return string
     */
    private static function invalidResponseDiagnostic(array $response, string $endpoint): string
    {
        $status = (int) ($response['status'] ?? 0);
        $body = (string) ($response['body'] ?? '');
        $trimmed = ltrim($body);
        if ($trimmed !== '' && $trimmed[0] === '<') {
            $hint = __('返回的是 HTML 页面，可能是中转站拦截、鉴权失败或服务地址填错。', 'ai-comment-audit');
        } elseif (stripos($body, 'data:') !== false) {
            $hint = __('返回的是流式数据，但无法解析出审核结果。', 'ai-comment-audit');
        } else {
            $hint = __('请确认服务地址兼容 OpenAI 的 /v1/chat/completions 接口。', 'ai-comment-audit');
        }
        $snippet = self::short(trim((string) preg_replace('/\s+/', ' ', wp_strip_all_tags($body))), 120);

        return sprintf(
            __('审核服务返回的不是有效 JSON（HTTP %1$d，地址 %2$s）。%3$s%4$s', 'ai-comment-audit'),
            $status,
            $endpoint,
            $hint,
            $snippet !== '' ? ' ' . sprintf(__('返回内容：%s', 'ai-comment-audit'), $snippet) : ''
        );
    }

    /**
     * @param mixed $error
     * @return string
     */
    private static function errorMessage($error): string
    {
        if (is_string($error)) {
            return trim($error);
        }
        if (is_array($error)) {
            foreach (['message', 'msg', 'error', 'detail'] as $key) {
                if (isset($error[$key])) {
                    $message = self::errorMessage($error[$key]);
                    if ($message !== '') {
                        return $message;
                    }
                }
            }
        }

        return '';
    }

    /**
     * @param int $status
     * @param string $error
     * @return string
     */
    private static function requestDiagnostic(int $status, string $error): string
    {
        if ($status >= 500) {
            return sprintf(__('审核服务暂时不可用（HTTP %d），请稍后重试。', 'ai-comment-audit'), $status);
        }

        return match ($status) {
            400, 422 => sprintf(__('审核服务无法接受此请求（HTTP %d），请检查模型、服务地址和请求格式。', 'ai-comment-audit'), $status),
            401 => __('审核服务未接受当前密钥（HTTP 401），请核对密钥和服务地址。', 'ai-comment-audit'),
            402 => __('审核服务账户余额不足（HTTP 402），请充值后继续扫描。', 'ai-comment-audit'),
            403 => __('审核服务拒绝访问（HTTP 403），请检查账号权限或中转站白名单。', 'ai-comment-audit'),
            404 => __('审核服务找不到接口或模型（HTTP 404），请检查服务地址和模型名称；中转站通常需要 /v1/chat/completions。', 'ai-comment-audit'),
            429 => __('审核服务请求过多（HTTP 429），请稍后重试。', 'ai-comment-audit'),
            default => $error === 'wp_error'
                ? __('无法连接审核服务，请检查网络、SSL 证书和服务地址。', 'ai-comment-audit')
                : __('无法连接审核服务，请检查网络和服务地址。', 'ai-comment-audit'),
        };
    }

    /**
     * @param string $text
     * @param int $limit
     * @return string
     */
    private static function short(string $text, int $limit): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        return function_exists('mb_substr') ? mb_substr($text, 0, $limit, 'UTF-8') : substr($text, 0, $limit);
    }

    /**
     * @param array $settings
     * @param string $message
     * @return void
     */
    private static function debug(array $settings, string $message): void
    {
        if (empty($settings['debug'])) {
            return;
        }

        error_log('[AICommentAudit] ' . $message);
    }

    /**
     * @param string $reason
     * @param int $latency
     * @param string|null $diagnostic
     * @return array
     */
    private static function failure($reason, $latency = 0, ?string $diagnostic = null): array
    {
        return [
            'decision' => 'review',
            'category' => 'normal',
            'severity' => 'low',
            'threat_type' => 'none',
            'on_topic' => true,
            'faithful' => true,
            'confidence' => 0,
            'reason' => $reason,
            'latency_ms' => (int) $latency,
            'tokens' => 0,
            'cached' => false,
            'error' => true,
            'diagnostic' => $diagnostic,
        ];
    }

    /**
     * @return void
     */
    private static function recordFailure(): void
    {
        try {
            $state = Repository::getState('ai_circuit');
            $failures = (int) ($state['failures'] ?? 0) + 1;
            $openUntil = (int) ($state['open_until'] ?? 0);

            if ($failures >= 3) {
                $openUntil = time() + 300;
                $failures = 0;
            }

            Repository::setState('ai_circuit', [
                'failures' => $failures,
                'open_until' => $openUntil,
                'updated' => time(),
            ]);
        } catch (\Throwable $e) {
            // Ignore circuit state failures.
        }
    }

    /**
     * @return void
     */
    private static function recordSuccess(): void
    {
        try {
            Repository::setState('ai_circuit', [
                'failures' => 0,
                'open_until' => 0,
                'updated' => time(),
            ]);
        } catch (\Throwable $e) {
            // Ignore.
        }
    }
}
