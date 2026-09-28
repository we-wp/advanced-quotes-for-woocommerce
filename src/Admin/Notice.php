<?php

namespace WeWP\AdvancedQuotes\Admin;

/**
 * One-time admin notices carried across the post-redirect-get cycle.
 */
final class Notice
{
    private static function key(): string
    {
        return 'wewp_aq_notice_'.get_current_user_id();
    }

    public static function add(string $message, string $type = 'success'): void
    {
        $notices = get_transient(self::key()) ?: [];
        $notices[] = ['message' => $message, 'type' => in_array($type, ['success', 'error', 'warning', 'info'], true) ? $type : 'info'];
        set_transient(self::key(), $notices, 5 * MINUTE_IN_SECONDS);
    }

    public static function render(): void
    {
        $notices = get_transient(self::key());
        if (! $notices) {
            return;
        }
        delete_transient(self::key());
        foreach ((array) $notices as $notice) {
            printf('<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr($notice['type']), esc_html($notice['message']));
        }
    }
}
