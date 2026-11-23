<?php

namespace AICommentAudit;

defined('ABSPATH') || exit;

/**
 * Plugin settings stored in a single option.
 */
final class Settings
{
    public const OPTION = AICOMMENTAUDIT_OPTION;
    private const LEGACY_OPTION = 'commentaudit_settings';

    /**
     * @return array
     */
    public static function defaults(): array
    {
        return [
            'blockWords' => '',
            'suspectWords' => '',
            'wordScopes' => ['text', 'author', 'email'],
            'ipBlacklist' => '',
            'emailBlacklist' => '',
            'urlBlacklist' => '',
            'ipRateLimit' => 5,
            'ipRateWindow' => 600,
            'linkLimit' => 3,
            'apiKey' => '',
            'baseUrl' => 'https://api.deepseek.com',
            'model' => 'deepseek-flash',
            'disableThinking' => 0,
            'timeout' => 8,
            'maxTokens' => 1200,
            'sslVerify' => 1,
            'debug' => 0,
            'identityProtection' => 1,
            'thresholdNew' => 0.7,
            'thresholdNormal' => 0.8,
            'thresholdTrusted' => 0.9,
            'cacheTtl' => 7,
            'logRetention' => 90,
            'commentColumn' => 0,
            'deleteDataOnUninstall' => 0,
        ];
    }

    /**
     * @return array
     */
    public static function get(): array
    {
        $stored = get_option(self::OPTION, null);
        if ($stored === null) {
            $legacy = get_option(self::LEGACY_OPTION, null);
            if (is_array($legacy)) {
                add_option(self::OPTION, $legacy, '', false);
                $stored = $legacy;
            }
        }

        $settings = wp_parse_args(is_array($stored) ? $stored : [], self::defaults());

        if (!is_array($settings['wordScopes'])) {
            $settings['wordScopes'] = self::defaults()['wordScopes'];
        }

        return $settings;
    }

    /**
     * @param array $values
     * @return array
     */
    public static function update(array $values): array
    {
        $settings = array_merge(self::get(), $values);
        update_option(self::OPTION, $settings, false);

        return $settings;
    }

    /**
     * @return void
     */
    public static function installDefaults(): void
    {
        if (get_option(self::OPTION, null) === null) {
            $legacy = get_option(self::LEGACY_OPTION, null);
            add_option(self::OPTION, is_array($legacy) ? $legacy : self::defaults(), '', false);
        }
    }
}
