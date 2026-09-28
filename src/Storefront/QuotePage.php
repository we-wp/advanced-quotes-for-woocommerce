<?php

namespace WeWP\AdvancedQuotes\Storefront;

use RuntimeException;
use Throwable;
use WC_Order;
use WeWP\AdvancedQuotes\Access;
use WeWP\AdvancedQuotes\Acceptance;
use WeWP\AdvancedQuotes\Orders;
use WeWP\AdvancedQuotes\Plugin;
use WeWP\AdvancedQuotes\Templates\Document;

/**
 * The customer's online quote: the document, its status, and Accept and pay / Decline actions.
 * It is a standalone page, so its layout is the same with every theme. It uses the site's body font.
 */
final class QuotePage
{
    public function __construct(private Plugin $plugin) {}

    public function boot(): void
    {
        add_action('template_redirect', [$this, 'route'], 0);
    }

    private function headers(int $status = 200): void
    {
        status_header($status);
        nocache_headers();
        if (! defined('DONOTCACHEPAGE')) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Shared constant read by page-caching plugins.
            define('DONOTCACHEPAGE', true);
        }
        header('X-Robots-Tag: noindex, nofollow');
        header('Referrer-Policy: no-referrer');
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data: 'self'; font-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    }

    public function route(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- The private key authorises read access.
        if (! isset($_GET[Access::QUERY])) {
            return;
        }
        $id = absint($_GET[Access::QUERY]);
        $key = sanitize_text_field(wp_unslash($_GET['key'] ?? ''));
        $message = sanitize_key(wp_unslash($_GET['aq_msg'] ?? ''));
        $download = isset($_GET['download']);
        // phpcs:enable
        $quote = $id ? $this->plugin->store->find($id) : null;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- handle() verifies the key and a nonce.
        if ($quote && isset($_SERVER['REQUEST_METHOD'], $_POST['wewp_aq_operation']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handle($quote, $key);
        }
        if (! $quote || $quote['revision'] === 0 || ! Access::canView($quote, $key)) {
            $this->headers(404);
            echo $this->shell(__('Quote not found', 'advanced-quotes-for-woocommerce'), '<main class="aqp-missing"><h1>'.esc_html__('This quote link does not work', 'advanced-quotes-for-woocommerce').'</h1><p>'.esc_html__('The link may be incomplete, or the store created a new link. Ask the store to send the quote again.', 'advanced-quotes-for-woocommerce').'</p></main>', ''); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
            exit;
        }
        try {
            $sent = $this->plugin->store->revision($quote['id']);
            if ($download) {
                $pdf = $this->plugin->store->pdf($sent, $this->plugin->renderer());
                $this->headers();
                header('Content-Type: application/pdf');
                header('Content-Length: '.strlen($pdf));
                header('Content-Disposition: attachment; filename="'.sanitize_file_name($quote['number'].'.pdf').'"');
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary PDF body.
                echo $pdf;
                exit;
            }
        } catch (Throwable $e) {
            $this->headers(503);
            echo $this->shell(__('Quote unavailable', 'advanced-quotes-for-woocommerce'), '<main class="aqp-missing"><h1>'.esc_html__('This quote is temporarily unavailable', 'advanced-quotes-for-woocommerce').'</h1><p>'.esc_html__('Try again in a few minutes. If it still does not open, contact the store.', 'advanced-quotes-for-woocommerce').'</p></main>', ''); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
            exit;
        }
        $isCustomer = $this->actsAsCustomer($quote, $key);
        if ($isCustomer) {
            $this->recordView($quote);
            if ($quote['order_id'] && in_array($quote['status'], ['accepted', 'paid'], true)) {
                Orders::rememberForSession($quote['order_id']);
            }
        }
        $this->headers();
        echo $this->page($quote, $sent['data'], $key, $isCustomer, $message); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts and template output.
        exit;
    }

    private function recordView(array $quote): void
    {
        $flag = 'wewp_aq_viewed_'.$quote['id'].'_'.$quote['revision'];
        if (! get_transient($flag)) {
            set_transient($flag, 1, 12 * HOUR_IN_SECONDS);
            $this->plugin->store->event($quote['id'], 'viewed', '', get_current_user_id());
        }
    }

    /**
     * Store staff who open a customer link see the staff view, so their visits and actions are never recorded as the customer's.
     */
    private function actsAsCustomer(array $quote, string $key): bool
    {
        if (current_user_can('manage_wewp_quotes') && get_current_user_id() !== (int) $quote['customer_id']) {
            return false;
        }

        return Access::isCustomer($quote, $key);
    }

    /**
     * Accept or decline. Runs on the front end, where WooCommerce has loaded the visitor's session.
     */
    private function handle(array $quote, string $key): void
    {
        $id = (int) $quote['id'];
        $operation = sanitize_key(wp_unslash($_POST['wewp_aq_operation'] ?? ''));
        if (! $this->actsAsCustomer($quote, $key) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wewp_aq'] ?? '')), 'wewp_aq_customer_'.$id)) {
            wp_die(esc_html__('This quote link does not work. Open the quote from your email and try again.', 'advanced-quotes-for-woocommerce'), '', ['response' => 403]);
        }
        $back = static fn (string $message = ''): string => add_query_arg(array_filter([Access::QUERY => $id, 'key' => $key, 'aq_msg' => $message]), home_url('/'));
        try {
            if ($operation === 'accept') {
                $order = $this->plugin->acceptance()->accept($id, absint($_POST['revision'] ?? 0), get_current_user_id(), 'customer');
                Orders::rememberForSession($order->get_id());
                wp_safe_redirect($order->needs_payment() ? $order->get_checkout_payment_url() : $back());
                exit;
            }
            if ($operation === 'decline') {
                $this->plugin->acceptance()->decline($id, absint($_POST['revision'] ?? 0), sanitize_textarea_field(wp_unslash($_POST['reason'] ?? '')));
                wp_safe_redirect($back('declined'));
                exit;
            }
        } catch (RuntimeException $e) {
            set_transient('wewp_aq_msg_'.$id.'_'.md5($key), $e->getMessage(), 5 * MINUTE_IN_SECONDS);
            wp_safe_redirect($back('error'));
            exit;
        }
        wp_safe_redirect($back());
        exit;
    }

    private function css(): string
    {
        $file = dirname(WEWP_AQ_FILE).'/assets/css/quote-page.css';

        return (string) file_get_contents($file); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Bundled stylesheet.
    }

    private function shell(string $title, string $body, string $extraCss, string $accent = '#214ee8'): string
    {
        $doc = new Document(['template' => ['accent' => $accent]]);
        [$family, $faces] = self::siteFont();
        $font = ($family !== '' ? $family.', ' : '').'system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif';
        $vars = ':root{--aqp-font:'.$font.';--aqp-accent:'.$doc->accent().';--aqp-on-accent:'.$doc->onAccent().';--aqp-accent-text:'.$doc->accentText().';--aqp-accent-tint:'.$doc->tint(0.9).'}';

        return '<!doctype html><html lang="'.esc_attr(get_bloginfo('language')).'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><title>'.esc_html($title).'</title>'
            .'<style>'.$faces.$vars.$this->css().$extraCss.'</style></head><body class="aqp">'.$body.'</body></html>';
    }

    /**
     * The site's body font from the theme's global styles, with the theme's own @font-face rules.
     * Both are empty when the theme sets no body font. The page then uses the system font.
     *
     * @return array{0:string,1:string} CSS font-family list and @font-face rules.
     */
    private static function siteFont(): array
    {
        $family = function_exists('wp_get_global_styles') ? wp_get_global_styles(['typography', 'fontFamily'], ['transforms' => ['resolve-variables']]) : '';
        // Accept a plain family list only, for example: Manrope, sans-serif.
        if (! is_string($family) || ! preg_match('/^[\p{L}\p{N}\s,"\'_-]{1,200}$/u', $family)) {
            return ['', ''];
        }
        $faces = '';
        if (function_exists('wp_print_font_faces')) {
            ob_start();
            wp_print_font_faces();
            $faces = (string) preg_replace('#</?style[^>]*>#i', '', (string) ob_get_clean());
            if (str_contains($faces, '<')) {
                $faces = '';
            }
        }

        return [trim($family), $faces];
    }

    private function page(array $quote, array $snapshot, string $key, bool $isCustomer, string $message): string
    {
        $renderer = $this->plugin->renderer();
        $template = $renderer->template($snapshot);
        $doc = new Document($snapshot, 'web');
        $seller = $doc->seller();
        $brand = $doc->hasLogo() ? $doc->logo('aqp-logo') : '<span class="aqp-store-name">'.$seller['name'].'</span>';
        $pdfUrl = add_query_arg([Access::QUERY => $quote['id'], 'key' => $key, 'download' => 1], home_url('/'));
        $panel = $this->panel($quote, $doc, $key, $isCustomer, $message);
        /* translators: 1: quote number, 2: store name */
        $title = sprintf(__('Quote %1$s · %2$s', 'advanced-quotes-for-woocommerce'), $quote['number'], wp_strip_all_tags(html_entity_decode($seller['name'], ENT_QUOTES, 'UTF-8')));
        $body = '<header class="aqp-bar"><div class="aqp-bar-in"><span class="aqp-brand">'.$brand.'</span><a class="aqp-link" href="'.esc_url($pdfUrl).'"><svg aria-hidden="true" width="16" height="16" viewBox="0 0 16 16"><path d="M8 1v9m0 0L4.5 6.5M8 10l3.5-3.5M2 12.5V15h12v-2.5" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>'.esc_html__('Download PDF', 'advanced-quotes-for-woocommerce').'</a></div></header>'
            .'<main class="aqp-main"><div class="aqp-grid"><aside class="aqp-panel" aria-label="'.esc_attr__('Quote status and actions', 'advanced-quotes-for-woocommerce').'">'.$panel.'</aside>'
            .'<article class="aqp-sheet" aria-label="'.esc_attr__('Quote document', 'advanced-quotes-for-woocommerce').'">'.$template->markup($doc).'</article></div>'
            .$this->footerAction($quote, $doc, $key, $isCustomer).'</main>';

        return $this->shell($title, $body, $template->styles($doc), $doc->accent());
    }

    private function effectiveStatus(array $quote): string
    {
        if ($quote['status'] === 'sent' && $quote['expires_on'] && $quote['expires_on'] < current_time('Y-m-d')) {
            return 'expired';
        }

        return $quote['status'];
    }

    private function actionUrl(array $quote, string $key): string
    {
        return add_query_arg(array_filter([Access::QUERY => (int) $quote['id'], 'key' => $key]), home_url('/'));
    }

    private function acceptForm(array $quote, string $key, string $class = ''): string
    {
        return '<form class="aqp-accept '.esc_attr($class).'" method="post" action="'.esc_url($this->actionUrl($quote, $key)).'">'
            .wp_nonce_field('wewp_aq_customer_'.$quote['id'], '_wewp_aq', false, false)
            .'<input type="hidden" name="wewp_aq_operation" value="accept"><input type="hidden" name="revision" value="'.esc_attr((string) $quote['revision']).'">'
            .'<button type="submit" class="aqp-primary">'.esc_html__('Accept and pay', 'advanced-quotes-for-woocommerce').'</button></form>';
    }

    private function panel(array $quote, Document $doc, string $key, bool $isCustomer, string $message): string
    {
        $status = $this->effectiveStatus($quote);
        $out = '';
        if ($message === 'error') {
            $text = get_transient('wewp_aq_msg_'.$quote['id'].'_'.md5($key)) ?: __('Something went wrong. Refresh the page and try again.', 'advanced-quotes-for-woocommerce');
            $out .= '<div class="aqp-alert is-error" role="alert">'.esc_html((string) $text).'</div>';
        }
        $out .= '<dl class="aqp-figures"><div><dt>'.esc_html__('Total', 'advanced-quotes-for-woocommerce').'</dt><dd class="aqp-total">'.$doc->total().'</dd></div>';
        $out .= '<div><dt>'.$doc->label('valid_until').'</dt><dd>'.$doc->e('valid_until_date').'</dd></div><div><dt>'.$doc->label('number').'</dt><dd>'.$doc->number().'</dd></div></dl>';
        if (! $isCustomer) {
            return $out.'<p class="aqp-note">'.esc_html__('You are viewing the customer page as store staff. Accept and decline are available only to the customer.', 'advanced-quotes-for-woocommerce').'</p><p><a class="aqp-secondary" href="'.esc_url(admin_url('admin.php?page=wewp-quotes&action=edit&quote='.$quote['id'])).'">'.esc_html__('Back to the quote editor', 'advanced-quotes-for-woocommerce').'</a></p>';
        }
        $contact = sanitize_email((string) $doc->raw('seller.email'));
        $mail = $contact !== '' ? ' <a href="mailto:'.esc_attr($contact).'">'.esc_html($contact).'</a>' : '';
        switch ($status) {
            case 'sent':
                $out .= $this->acceptForm($quote, $key);
                $out .= '<p class="aqp-fine">'.esc_html__('Accepting creates your order at these prices. You choose how to pay on the next page.', 'advanced-quotes-for-woocommerce').'</p>';
                if ($quote['customer_id'] && get_current_user_id() !== $quote['customer_id']) {
                    $out .= '<p class="aqp-fine">'.esc_html__('This quote belongs to a customer account. Log in to that account when the payment page asks you to.', 'advanced-quotes-for-woocommerce').'</p>';
                }
                $out .= '<details class="aqp-decline"><summary>'.esc_html__('Decline this quote', 'advanced-quotes-for-woocommerce').'</summary><form method="post" action="'.esc_url($this->actionUrl($quote, $key)).'">'.wp_nonce_field('wewp_aq_customer_'.$quote['id'], '_wewp_aq', false, false)
                    .'<input type="hidden" name="wewp_aq_operation" value="decline"><input type="hidden" name="revision" value="'.esc_attr((string) $quote['revision']).'">'
                    .'<label for="aqp-reason">'.esc_html__('Reason (optional)', 'advanced-quotes-for-woocommerce').'</label><textarea id="aqp-reason" name="reason" rows="3" maxlength="500"></textarea><button type="submit" class="aqp-secondary">'.esc_html__('Decline quote', 'advanced-quotes-for-woocommerce').'</button></form></details>';
                break;
            case 'expired':
                /* translators: %s: date */
                $out .= '<div class="aqp-alert">'.esc_html(sprintf(__('This quote expired on %s.', 'advanced-quotes-for-woocommerce'), (string) $doc->raw('valid_until_date'))).'</div><p class="aqp-fine">'.esc_html__('Ask the store for an updated quote.', 'advanced-quotes-for-woocommerce').$mail.'</p>';
                break;
            case 'accepting':
                $out .= '<div class="aqp-alert">'.esc_html__('Your acceptance is being processed. Refresh this page in a moment.', 'advanced-quotes-for-woocommerce').'</div>';
                break;
            case 'accepted':
            case 'paid':
                $order = $quote['order_id'] ? wc_get_order($quote['order_id']) : null;
                if ($order instanceof WC_Order && $order->needs_payment()) {
                    /* translators: %s: order number */
                    $out .= '<div class="aqp-alert is-success">'.esc_html(sprintf(__('You accepted this quote. Order #%s is waiting for payment.', 'advanced-quotes-for-woocommerce'), $order->get_order_number())).'</div>';
                    $out .= '<a class="aqp-primary" href="'.esc_url($order->get_checkout_payment_url()).'">'.esc_html__('Pay now', 'advanced-quotes-for-woocommerce').'</a>';
                } elseif ($order instanceof WC_Order && $order->has_status(['cancelled', 'refunded', 'failed', 'trash'])) {
                    /* translators: %s: order number */
                    $out .= '<div class="aqp-alert">'.esc_html(sprintf(__('Order #%s for this quote was cancelled. Contact the store if you still want these items.', 'advanced-quotes-for-woocommerce'), $order->get_order_number())).'</div>';
                } elseif ($order instanceof WC_Order) {
                    $message = $order->is_paid()
                        /* translators: %s: order number */
                        ? sprintf(__('Thank you. Order #%s is paid and confirmed.', 'advanced-quotes-for-woocommerce'), $order->get_order_number())
                        /* translators: %s: order number */
                        : sprintf(__('Thank you. Order #%s was received. The store will confirm it when your payment arrives.', 'advanced-quotes-for-woocommerce'), $order->get_order_number());
                    $out .= '<div class="aqp-alert is-success">'.esc_html($message).'</div>';
                    $link = get_current_user_id() && $order->get_customer_id() === get_current_user_id() ? $order->get_view_order_url() : $order->get_checkout_order_received_url();
                    $out .= '<a class="aqp-secondary" href="'.esc_url($link).'">'.esc_html__('View order', 'advanced-quotes-for-woocommerce').'</a>';
                } else {
                    $out .= '<div class="aqp-alert">'.esc_html(Acceptance::statusMessage($quote)).'</div>';
                }
                break;
            case 'declined':
                $out .= '<div class="aqp-alert">'.esc_html__('You declined this quote.', 'advanced-quotes-for-woocommerce').'</div><p class="aqp-fine">'.esc_html__('Changed your mind? Contact the store for a new version.', 'advanced-quotes-for-woocommerce').$mail.'</p>';
                break;
            default:
                $out .= '<div class="aqp-alert">'.esc_html(Acceptance::statusMessage($quote)).'</div>';
        }

        return $out;
    }

    private function footerAction(array $quote, Document $doc, string $key, bool $isCustomer): string
    {
        if (! $isCustomer || $this->effectiveStatus($quote) !== 'sent') {
            return '';
        }

        return '<section class="aqp-end" aria-label="'.esc_attr__('Accept the quote', 'advanced-quotes-for-woocommerce').'"><p><span>'.esc_html__('Total', 'advanced-quotes-for-woocommerce').'</span> <strong>'.$doc->total().'</strong></p>'.$this->acceptForm($quote, $key, 'is-end').'</section>';
    }
}
