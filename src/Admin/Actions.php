<?php

namespace WeWP\AdvancedQuotes\Admin;

use RuntimeException;
use Throwable;
use WeWP\AdvancedQuotes\Access;
use WeWP\AdvancedQuotes\Plugin;

/**
 * Form handlers for the quote generator and list. Every handler checks the capability and a nonce.
 */
final class Actions
{
    public function __construct(private Plugin $plugin) {}

    private function authorize(): void
    {
        if (! current_user_can(Admin::CAP)) {
            wp_die(esc_html__('You cannot manage quotes.', 'advanced-quotes-for-woocommerce'), '', ['response' => 403]);
        }
    }

    public static function downloadUrl(int $quoteId, ?int $revision = null): string
    {
        $args = ['action' => 'wewp_aq_download', 'quote' => $quoteId];
        if ($revision) {
            $args['revision'] = $revision;
        }

        return wp_nonce_url(add_query_arg($args, admin_url('admin-post.php')), 'wewp_aq_download_'.$quoteId);
    }

    public static function rowUrl(int $quoteId, string $operation): string
    {
        return wp_nonce_url(add_query_arg(['action' => 'wewp_aq_row', 'quote' => $quoteId, 'operation' => $operation], admin_url('admin-post.php')), 'wewp_aq_row_'.$quoteId);
    }

    public function save(): void
    {
        $this->authorize();
        check_admin_referer('wewp_aq_save');
        $input = wp_unslash($_POST);
        $operation = sanitize_key((string) ($input['operation'] ?? 'save'));
        $id = absint($input['quote_id'] ?? 0);
        $quotes = $this->plugin->quotes;
        $quote = $id ? $this->plugin->store->find($id) : null;
        if ($id && ! $quote) {
            wp_die(esc_html__('This quote no longer exists.', 'advanced-quotes-for-woocommerce'), '', ['response' => 404, 'back_link' => true]);
        }
        try {
            if ($operation === 'preview') {
                $draft = $quotes->sanitizeDraft($input, $quote['draft'] ?? []);
                $pdf = $quotes->previewPdf($quote ?? ['id' => 0, 'number' => __('Preview', 'advanced-quotes-for-woocommerce'), 'revision' => 0], $draft);
                $this->sendPdf($pdf, 'quote-preview.pdf', true);
            }
            if (in_array($operation, ['accept', 'cancel', 'reset_link', 'duplicate'], true)) {
                if (! $quote) {
                    throw new RuntimeException(__('Save the quote first.', 'advanced-quotes-for-woocommerce'));
                }
                $this->quoteAction($quote, $operation);
            }
            $draft = $quotes->sanitizeDraft($input, $quote['draft'] ?? []);
            if (! $quote) {
                $quote = $quotes->createDraft($draft);
            } elseif ($quotes->isEditable($quote)) {
                $quotes->save($quote, $draft);
            } else {
                throw new RuntimeException(__('This quote can no longer be changed.', 'advanced-quotes-for-woocommerce'));
            }
            if ($operation === 'send') {
                $quote = $this->plugin->store->find($quote['id']);
                $revision = $quotes->send($quote);
                /* translators: 1: revision number, 2: email address */
                Notice::add(sprintf(__('Revision %1$d was sent to %2$s.', 'advanced-quotes-for-woocommerce'), $revision['revision'], $revision['data']['customer']['email']));
            } else {
                Notice::add(__('Draft saved. The customer has not received this version.', 'advanced-quotes-for-woocommerce'));
            }
        } catch (RuntimeException $e) {
            if ($operation === 'preview') {
                wp_die(esc_html($e->getMessage()), esc_html__('Preview unavailable', 'advanced-quotes-for-woocommerce'), ['response' => 422]);
            }
            Notice::add($e->getMessage(), 'error');
        } catch (Throwable $e) {
            if ($operation === 'preview') {
                wp_die(esc_html__('The preview could not be created. Check the items and try again.', 'advanced-quotes-for-woocommerce'), '', ['response' => 500]);
            }
            Notice::add(__('The quote could not be saved. Nothing was sent. Try again, or check the PHP error log.', 'advanced-quotes-for-woocommerce'), 'error');
        }
        wp_safe_redirect($quote ? Admin::editUrl((int) $quote['id']) : Admin::url(['action' => 'new']));
        exit;
    }

    private function quoteAction(array $quote, string $operation): never
    {
        $target = Admin::editUrl($quote['id']);
        try {
            switch ($operation) {
                case 'accept':
                    $order = $this->plugin->acceptance()->accept($quote['id'], (int) $quote['revision'], get_current_user_id(), 'store');
                    /* translators: %s: order number */
                    Notice::add(sprintf(__('Accepted for the customer. Pending order #%s was created.', 'advanced-quotes-for-woocommerce'), $order->get_order_number()));
                    break;
                case 'cancel':
                    if (! $this->plugin->quotes->cancel($quote)) {
                        throw new RuntimeException(__('This quote can no longer be withdrawn.', 'advanced-quotes-for-woocommerce'));
                    }
                    Notice::add(__('Quote withdrawn. The customer link now shows that it is no longer available.', 'advanced-quotes-for-woocommerce'));
                    break;
                case 'reset_link':
                    Access::rotate($quote['id']);
                    Notice::add(__('New customer link created. Earlier links no longer work.', 'advanced-quotes-for-woocommerce'));
                    break;
                case 'duplicate':
                    $copy = $this->plugin->quotes->duplicate($quote);
                    /* translators: 1: new quote number, 2: original quote number */
                    Notice::add(sprintf(__('%1$s was created as a copy of %2$s.', 'advanced-quotes-for-woocommerce'), $copy['number'], $quote['number']));
                    $target = Admin::editUrl($copy['id']);
                    break;
            }
        } catch (RuntimeException $e) {
            Notice::add($e->getMessage(), 'error');
        }
        wp_safe_redirect($target);
        exit;
    }

    public function row(): void
    {
        $this->authorize();
        $id = absint($_GET['quote'] ?? 0);
        check_admin_referer('wewp_aq_row_'.$id);
        $quote = $this->plugin->store->find($id);
        $operation = sanitize_key(wp_unslash($_GET['operation'] ?? ''));
        if (! $quote || ! in_array($operation, ['duplicate'], true)) {
            wp_die(esc_html__('This quote no longer exists.', 'advanced-quotes-for-woocommerce'), '', ['response' => 404, 'back_link' => true]);
        }
        $this->quoteAction($quote, $operation);
    }

    public function download(): void
    {
        $this->authorize();
        $id = absint($_GET['quote'] ?? 0);
        check_admin_referer('wewp_aq_download_'.$id);
        $quote = $this->plugin->store->find($id);
        $revision = isset($_GET['revision']) ? absint($_GET['revision']) : null;
        try {
            if (! $quote) {
                throw new RuntimeException(__('This quote no longer exists.', 'advanced-quotes-for-woocommerce'));
            }
            [$sent, $pdf] = $this->plugin->quotes->latestPdf($quote, $revision);
            $this->sendPdf($pdf, sanitize_file_name($quote['number'].'-r'.$sent['revision'].'.pdf'), false);
        } catch (Throwable $e) {
            wp_die(esc_html($e instanceof RuntimeException ? $e->getMessage() : __('The PDF is unavailable. Try again from the quote screen.', 'advanced-quotes-for-woocommerce')), '', ['response' => 503, 'back_link' => true]);
        }
    }

    private function sendPdf(string $pdf, string $filename, bool $inline): never
    {
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Length: '.strlen($pdf));
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: '.($inline ? 'inline' : 'attachment').'; filename="'.$filename.'"');
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary PDF body.
        echo $pdf;
        exit;
    }
}
