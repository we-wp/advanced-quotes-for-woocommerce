<?php

namespace WeWP\AdvancedQuotes\Admin;

use WC_Settings_Page;
use WeWP\AdvancedQuotes\Plugin;
use WeWP\AdvancedQuotes\Settings;

if (! defined('ABSPATH')) {
    exit;
}

if (! class_exists(WC_Settings_Page::class)) {
    return;
}

/**
 * WooCommerce → Settings → Quotes.
 */
final class SettingsTab extends WC_Settings_Page
{
    public function __construct()
    {
        $this->id = 'wewp_quotes';
        $this->label = __('Quotes', 'advanced-quotes-for-woocommerce');
        parent::__construct();
        add_action('woocommerce_admin_field_wewp_aq_logo', [$this, 'logoField']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
    }

    public function assets(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen check.
        if (sanitize_key(wp_unslash($_GET['page'] ?? '')) === 'wc-settings' && sanitize_key(wp_unslash($_GET['tab'] ?? '')) === $this->id) {
            wp_enqueue_media();
            wp_enqueue_script('wewp-aq-settings', Plugin::url('assets/js/settings.js'), ['jquery'], WEWP_AQ_VERSION, true);
            wp_enqueue_style('wewp-aq-admin', Plugin::url('assets/css/admin.css'), [], WEWP_AQ_VERSION);
        }
    }

    protected function get_settings_for_default_section(): array
    {
        $s = Settings::get();
        $key = static fn (string $name): string => Settings::OPTION.'['.$name.']';
        $templates = [];
        foreach (Plugin::instance()->templates()->all() as $id => $template) {
            $templates[$id] = $template->name().($template->edition() === 'pro' ? ' (Pro)' : '');
        }
        $pages = ['' => __('Select a page', 'advanced-quotes-for-woocommerce')];
        foreach (get_pages(['post_status' => 'publish']) as $page) {
            $pages[$page->ID] = $page->post_title;
        }

        return [
            ['title' => __('Business details', 'advanced-quotes-for-woocommerce'), 'type' => 'title', 'desc' => __('These details appear on every quote. A sent quote keeps the details it was sent with.', 'advanced-quotes-for-woocommerce'), 'id' => 'wewp_aq_business'],
            ['title' => __('Business name', 'advanced-quotes-for-woocommerce'), 'id' => $key('business'), 'type' => 'text', 'default' => $s['business']],
            ['title' => __('Address', 'advanced-quotes-for-woocommerce'), 'id' => $key('address'), 'type' => 'textarea', 'default' => $s['address'], 'css' => 'min-height:88px'],
            ['title' => __('Tax or company number', 'advanced-quotes-for-woocommerce'), 'id' => $key('tax_id'), 'type' => 'text', 'default' => $s['tax_id']],
            ['title' => __('Email', 'advanced-quotes-for-woocommerce'), 'id' => $key('email'), 'type' => 'email', 'default' => $s['email']],
            ['title' => __('Phone', 'advanced-quotes-for-woocommerce'), 'id' => $key('phone'), 'type' => 'text', 'default' => $s['phone']],
            ['title' => __('Website', 'advanced-quotes-for-woocommerce'), 'id' => $key('website'), 'type' => 'url', 'default' => $s['website']],
            ['title' => __('Logo', 'advanced-quotes-for-woocommerce'), 'id' => $key('logo_id'), 'type' => 'wewp_aq_logo', 'default' => $s['logo_id'], 'desc' => __('PNG or JPEG from your media library, up to 2 MB. It is embedded in each sent quote.', 'advanced-quotes-for-woocommerce')],
            ['type' => 'sectionend', 'id' => 'wewp_aq_business'],

            ['title' => __('Quote documents', 'advanced-quotes-for-woocommerce'), 'type' => 'title', 'id' => 'wewp_aq_documents'],
            ['title' => __('Default template', 'advanced-quotes-for-woocommerce'), 'id' => $key('template'), 'type' => 'select', 'options' => $templates, 'default' => $s['template'], 'desc_tip' => __('New quotes start with this template. You can change it on each quote.', 'advanced-quotes-for-woocommerce')],
            ['title' => __('Accent colour', 'advanced-quotes-for-woocommerce'), 'id' => $key('accent'), 'type' => 'color', 'default' => $s['accent'], 'css' => 'width:6em', 'desc' => __('Use your brand colour. Text in this colour is darkened automatically when it would be hard to read.', 'advanced-quotes-for-woocommerce')],
            ['title' => __('Paper size', 'advanced-quotes-for-woocommerce'), 'id' => $key('paper'), 'type' => 'select', 'options' => ['A4' => 'A4', 'Letter' => 'US Letter'], 'default' => $s['paper']],
            ['title' => __('Document heading', 'advanced-quotes-for-woocommerce'), 'id' => $key('title'), 'type' => 'text', 'default' => $s['title'], 'desc_tip' => __('For example Quote, Offer or Estimate.', 'advanced-quotes-for-woocommerce')],
            ['title' => __('Number prefix', 'advanced-quotes-for-woocommerce'), 'id' => $key('prefix'), 'type' => 'text', 'default' => $s['prefix'], 'css' => 'width:8em', 'desc' => __('Letters, digits, - / _ . only, up to 12 characters. Numbers continue from the last quote and are never reused.', 'advanced-quotes-for-woocommerce')],
            ['title' => __('Valid for (days)', 'advanced-quotes-for-woocommerce'), 'id' => $key('validity'), 'type' => 'number', 'default' => $s['validity'], 'custom_attributes' => ['min' => 1, 'max' => 365, 'step' => 1], 'css' => 'width:6em'],
            ['title' => __('Show line prices', 'advanced-quotes-for-woocommerce'), 'id' => $key('display'), 'type' => 'select', 'options' => ['excl' => __('Excluding tax', 'advanced-quotes-for-woocommerce'), 'incl' => __('Including tax', 'advanced-quotes-for-woocommerce')], 'default' => $s['display']],
            ['title' => __('Introduction', 'advanced-quotes-for-woocommerce'), 'id' => $key('intro'), 'type' => 'textarea', 'default' => $s['intro'], 'css' => 'min-height:72px'],
            ['title' => __('Terms', 'advanced-quotes-for-woocommerce'), 'id' => $key('terms'), 'type' => 'textarea', 'default' => $s['terms'], 'css' => 'min-height:88px', 'desc' => __('You write and approve this text. The plugin does not check it for legal requirements.', 'advanced-quotes-for-woocommerce')],
            ['title' => __('Footer text', 'advanced-quotes-for-woocommerce'), 'id' => $key('footer'), 'type' => 'textarea', 'default' => $s['footer'], 'css' => 'min-height:60px', 'desc_tip' => __('For example registration details or bank information.', 'advanced-quotes-for-woocommerce')],
            ['type' => 'sectionend', 'id' => 'wewp_aq_documents'],

            ['title' => __('Storefront', 'advanced-quotes-for-woocommerce'), 'type' => 'title', 'id' => 'wewp_aq_storefront'],
            ['title' => __('Product pages', 'advanced-quotes-for-woocommerce'), 'id' => $key('button'), 'type' => 'checkbox', 'default' => $s['button'], 'desc' => __('Show a request button on product pages', 'advanced-quotes-for-woocommerce')],
            ['title' => __('Cart', 'advanced-quotes-for-woocommerce'), 'id' => $key('cart_button'), 'type' => 'checkbox', 'default' => $s['cart_button'], 'desc' => __('Let customers request a quote for their whole cart', 'advanced-quotes-for-woocommerce')],
            ['title' => __('Button text', 'advanced-quotes-for-woocommerce'), 'id' => $key('button_label'), 'type' => 'text', 'default' => $s['button_label']],
            ['title' => __('Quote request page', 'advanced-quotes-for-woocommerce'), 'id' => $key('request_page'), 'type' => 'select', 'options' => $pages, 'default' => $s['request_page'], 'desc' => __('The page must contain the [wewp_quote_request] shortcode.', 'advanced-quotes-for-woocommerce')],
            ['type' => 'sectionend', 'id' => 'wewp_aq_storefront'],
        ];
    }

    public function save(): void
    {
        // WooCommerce verifies the settings nonce before this runs.
        parent::save();
        $stored = get_option(Settings::OPTION, []);
        update_option(Settings::OPTION, Settings::sanitize(is_array($stored) ? $stored : []), false);
    }

    public function logoField(array $field): void
    {
        $id = (int) Settings::value('logo_id');
        $src = $id ? wp_get_attachment_image_url($id, 'medium') : '';
        echo '<tr><th scope="row" class="titledesc"><label for="wewp-aq-logo-id">'.esc_html($field['title']).'</label></th><td class="forminp">';
        echo '<div class="wewp-aq-logo-field"><span class="wewp-aq-logo-preview">'.($src ? '<img src="'.esc_url($src).'" alt="">' : '').'</span>';
        echo '<input type="hidden" id="wewp-aq-logo-id" name="'.esc_attr($field['id']).'" value="'.esc_attr((string) $id).'">';
        echo '<button type="button" class="button wewp-aq-logo-choose" data-title="'.esc_attr__('Choose a logo', 'advanced-quotes-for-woocommerce').'">'.esc_html__('Choose logo', 'advanced-quotes-for-woocommerce').'</button> ';
        echo '<button type="button" class="button-link button-link-delete wewp-aq-logo-remove"'.($id ? '' : ' hidden').'>'.esc_html__('Remove', 'advanced-quotes-for-woocommerce').'</button>';
        echo '<p class="description">'.esc_html($field['desc']).'</p></div></td></tr>';
    }
}
