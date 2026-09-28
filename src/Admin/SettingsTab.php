<?php

namespace WeWP\AdvancedQuotes\Admin;

use WC_Settings_Page;
use WC_Admin_Settings;
use WeWP\AdvancedQuotes\Plugin;
use WeWP\AdvancedQuotes\RequestFields;
use WeWP\AdvancedQuotes\Settings;

if (! defined('ABSPATH')) {
    exit;
}

if (! class_exists(WC_Settings_Page::class)) {
    return;
}

/**
 * WooCommerce → Settings → Quotes, with a General and a Request form section.
 */
final class SettingsTab extends WC_Settings_Page
{
    public function __construct()
    {
        $this->id = 'wewp_quotes';
        $this->label = __('Quotes', 'advanced-quotes-for-woocommerce');
        parent::__construct();
        add_action('woocommerce_admin_field_wewp_aq_logo', [$this, 'logoField']);
        add_action('woocommerce_admin_field_wewp_aq_fields', [$this, 'fieldsBuilder']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
    }

    public function assets(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen check.
        if (sanitize_key(wp_unslash($_GET['page'] ?? '')) === 'wc-settings' && sanitize_key(wp_unslash($_GET['tab'] ?? '')) === $this->id) {
            $dir = dirname(WEWP_AQ_FILE).'/assets/';
            wp_enqueue_media();
            wp_enqueue_script('wewp-aq-settings', Plugin::url('assets/js/settings.js'), ['jquery', 'wp-a11y'], WEWP_AQ_VERSION.'-'.filemtime($dir.'js/settings.js'), true);
            wp_localize_script('wewp-aq-settings', 'wewpAqSettings', [
                'movedUp' => __('Field moved up.', 'advanced-quotes-for-woocommerce'),
                'movedDown' => __('Field moved down.', 'advanced-quotes-for-woocommerce'),
                'removed' => __('Field removed. Save changes to update the form.', 'advanced-quotes-for-woocommerce'),
            ]);
            wp_enqueue_style('wewp-aq-admin', Plugin::url('assets/css/admin.css'), [], WEWP_AQ_VERSION.'-'.filemtime($dir.'css/admin.css'));
        }
    }

    protected function get_own_sections(): array
    {
        return [
            '' => __('General', 'advanced-quotes-for-woocommerce'),
            'request' => __('Request form', 'advanced-quotes-for-woocommerce'),
        ];
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
        /* translators: %s: link to the Request form settings */
        $formLink = sprintf(__('Choose what customers fill in under %s.', 'advanced-quotes-for-woocommerce'), '<a href="'.esc_url(admin_url('admin.php?page=wc-settings&tab='.$this->id.'&section=request')).'">'.esc_html__('Request form', 'advanced-quotes-for-woocommerce').'</a>');

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

            ['title' => __('Storefront', 'advanced-quotes-for-woocommerce'), 'type' => 'title', 'desc' => $formLink, 'id' => 'wewp_aq_storefront'],
            ['title' => __('Product pages', 'advanced-quotes-for-woocommerce'), 'id' => $key('button'), 'type' => 'checkbox', 'default' => $s['button'], 'desc' => __('Show a request button on product pages', 'advanced-quotes-for-woocommerce')],
            ['title' => __('Cart', 'advanced-quotes-for-woocommerce'), 'id' => $key('cart_button'), 'type' => 'checkbox', 'default' => $s['cart_button'], 'desc' => __('Let customers request a quote for their whole cart', 'advanced-quotes-for-woocommerce')],
            ['title' => __('Button text', 'advanced-quotes-for-woocommerce'), 'id' => $key('button_label'), 'type' => 'text', 'default' => $s['button_label']],
            ['title' => __('Quote request page', 'advanced-quotes-for-woocommerce'), 'id' => $key('request_page'), 'type' => 'select', 'options' => $pages, 'default' => $s['request_page'], 'desc' => __('The page must contain the [wewp_quote_request] shortcode.', 'advanced-quotes-for-woocommerce')],
            ['type' => 'sectionend', 'id' => 'wewp_aq_storefront'],
        ];
    }

    protected function get_settings_for_request_section(): array
    {
        $s = Settings::get();
        $key = static fn (string $name): string => Settings::OPTION.'['.$name.']';
        $modes = [
            'optional' => __('Optional', 'advanced-quotes-for-woocommerce'),
            'required' => __('Required', 'advanced-quotes-for-woocommerce'),
            'hidden' => __('Hidden', 'advanced-quotes-for-woocommerce'),
        ];
        $contact = [
            'phone' => [__('Phone', 'advanced-quotes-for-woocommerce'), ''],
            'company' => [__('Company', 'advanced-quotes-for-woocommerce'), ''],
            'tax_id' => [__('Tax number', 'advanced-quotes-for-woocommerce'), ''],
            'address' => [__('Address', 'advanced-quotes-for-woocommerce'), __('Street address, postcode and city.', 'advanced-quotes-for-woocommerce')],
            'message' => [__('Message', 'advanced-quotes-for-woocommerce'), __('A free text box at the end of the form.', 'advanced-quotes-for-woocommerce')],
        ];
        $settings = [['title' => __('Contact details', 'advanced-quotes-for-woocommerce'), 'type' => 'title', 'desc' => __('Name, email and country are always required. The country sets the tax on the quote.', 'advanced-quotes-for-woocommerce'), 'id' => 'wewp_aq_contact']];
        foreach ($contact as $name => [$title, $tip]) {
            $settings[] = ['title' => $title, 'id' => $key('field_'.$name), 'type' => 'select', 'options' => $modes, 'default' => $s['field_'.$name]] + ($tip !== '' ? ['desc_tip' => $tip] : []);
        }
        $settings[] = ['type' => 'sectionend', 'id' => 'wewp_aq_contact'];
        $settings[] = ['title' => __('Extra fields', 'advanced-quotes-for-woocommerce'), 'type' => 'title', 'desc' => __('Ask for anything else you need to price a request, such as a deadline, a budget or measurements. Answers appear with the request and in the request emails.', 'advanced-quotes-for-woocommerce'), 'id' => 'wewp_aq_extra'];
        $settings[] = ['title' => __('Extra fields', 'advanced-quotes-for-woocommerce'), 'id' => 'wewp_aq_request_fields', 'type' => 'wewp_aq_fields', 'is_option' => false];
        $settings[] = ['type' => 'sectionend', 'id' => 'wewp_aq_extra'];

        return $settings;
    }

    public function save(): void
    {
        global $current_section;
        // WooCommerce verifies the settings nonce before this runs.
        parent::save();
        $stored = get_option(Settings::OPTION, []);
        $stored = is_array($stored) ? $stored : [];
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verified the settings nonce.
        if ($current_section === 'request' && isset($_POST['wewp_aq_fields_present'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- RequestFields::sanitize() cleans every value.
            $posted = isset($_POST['wewp_aq_fields']) && is_array($_POST['wewp_aq_fields']) ? wp_unslash($_POST['wewp_aq_fields']) : [];
            $stored['request_fields'] = RequestFields::sanitize($posted);
            $named = count(array_filter($posted, static fn ($row): bool => is_array($row) && trim((string) ($row['label'] ?? '')) !== ''));
            if ($named > RequestFields::MAX_FIELDS) {
                /* translators: %d: maximum number of fields */
                WC_Admin_Settings::add_error(sprintf(__('The form can have up to %d extra fields. The fields after that were not saved.', 'advanced-quotes-for-woocommerce'), RequestFields::MAX_FIELDS));
            }
            foreach ($stored['request_fields'] as $field) {
                if (RequestFields::hasChoices($field['type']) && ! $field['choices']) {
                    /* translators: %s: field label */
                    WC_Admin_Settings::add_error(sprintf(__('Add choices to “%s”. Customers see it after you add at least one choice.', 'advanced-quotes-for-woocommerce'), $field['label']));
                }
            }
        }
        // phpcs:enable
        update_option(Settings::OPTION, Settings::sanitize($stored), false);
    }

    /**
     * The extra field builder: one row per field, in the order customers see them.
     */
    public function fieldsBuilder(array $field): void
    {
        $fields = (array) Settings::value('request_fields');
        echo '<tr class="wewp-aq-builder-row"><td colspan="2"><div class="wewp-aq-builder" data-max="'.esc_attr((string) RequestFields::MAX_FIELDS).'">';
        echo '<input type="hidden" id="wewp-aq-fields-present" name="wewp_aq_fields_present" value="1">';
        echo '<table class="widefat wewp-aq-fields-table"'.($fields ? '' : ' hidden').'><thead><tr>';
        echo '<th scope="col" class="col-label">'.esc_html__('Label', 'advanced-quotes-for-woocommerce').'</th>';
        echo '<th scope="col" class="col-type">'.esc_html__('Type', 'advanced-quotes-for-woocommerce').'</th>';
        echo '<th scope="col" class="col-choices">'.esc_html__('Choices', 'advanced-quotes-for-woocommerce').'</th>';
        echo '<th scope="col" class="col-required">'.esc_html__('Required', 'advanced-quotes-for-woocommerce').'</th>';
        echo '<th scope="col" class="col-actions"><span class="screen-reader-text">'.esc_html__('Order and remove', 'advanced-quotes-for-woocommerce').'</span></th>';
        echo '</tr></thead><tbody class="wewp-aq-fields-body">';
        foreach ($fields as $row) {
            echo $this->fieldRow($row); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fieldRow() escapes every value.
        }
        echo '</tbody></table>';
        echo '<p class="wewp-aq-fields-empty"'.($fields ? ' hidden' : '').'>'.esc_html__('No extra fields yet. Customers fill in their contact details and a message.', 'advanced-quotes-for-woocommerce').'</p>';
        /* translators: %d: maximum number of fields */
        echo '<p class="wewp-aq-fields-foot"><button type="button" class="button wewp-aq-field-add"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> '.esc_html__('Add field', 'advanced-quotes-for-woocommerce').'</button> <span class="description">'.esc_html(sprintf(__('Up to %d fields.', 'advanced-quotes-for-woocommerce'), RequestFields::MAX_FIELDS)).'</span></p>';
        echo '<template id="wewp-aq-field-template">'.$this->fieldRow(['id' => '__id__', 'label' => '', 'type' => 'text', 'required' => false, 'help' => '', 'choices' => []]).'</template>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fieldRow() escapes every value.
        echo '</div></td></tr>';
    }

    private function fieldRow(array $field): string
    {
        $id = (string) $field['id'];
        $name = static fn (string $key): string => 'wewp_aq_fields['.$id.']['.$key.']';
        $types = '';
        foreach (RequestFields::typeNames() as $type => $label) {
            $types .= '<option value="'.esc_attr($type).'"'.selected($field['type'], $type, false).'>'.esc_html($label).'</option>';
        }

        return '<tr class="wewp-aq-field-row" data-choices="'.(RequestFields::hasChoices($field['type']) ? '1' : '0').'">'
            .'<td class="col-label"><input type="hidden" name="'.esc_attr($name('id')).'" value="'.esc_attr($id).'">'
            .'<label class="wewp-aq-cell-label" for="'.esc_attr('wewp-aq-fl-'.$id).'">'.esc_html__('Label', 'advanced-quotes-for-woocommerce').'</label>'
            .'<input id="'.esc_attr('wewp-aq-fl-'.$id).'" class="wewp-aq-field-label" type="text" name="'.esc_attr($name('label')).'" value="'.esc_attr((string) $field['label']).'" maxlength="120" placeholder="'.esc_attr__('For example, When do you need it?', 'advanced-quotes-for-woocommerce').'">'
            .'<label class="wewp-aq-help-label" for="'.esc_attr('wewp-aq-fh-'.$id).'">'.esc_html__('Help text', 'advanced-quotes-for-woocommerce').' <span class="wewp-aq-muted">'.esc_html__('(optional)', 'advanced-quotes-for-woocommerce').'</span></label>'
            .'<input id="'.esc_attr('wewp-aq-fh-'.$id).'" type="text" name="'.esc_attr($name('help')).'" value="'.esc_attr((string) $field['help']).'" maxlength="300"></td>'
            .'<td class="col-type"><label class="wewp-aq-cell-label" for="'.esc_attr('wewp-aq-ft-'.$id).'">'.esc_html__('Type', 'advanced-quotes-for-woocommerce').'</label><select id="'.esc_attr('wewp-aq-ft-'.$id).'" class="wewp-aq-field-type" name="'.esc_attr($name('type')).'">'.$types.'</select></td>'
            .'<td class="col-choices"><label class="wewp-aq-cell-label" for="'.esc_attr('wewp-aq-fc-'.$id).'">'.esc_html__('Choices', 'advanced-quotes-for-woocommerce').'</label><textarea id="'.esc_attr('wewp-aq-fc-'.$id).'" name="'.esc_attr($name('choices')).'" rows="3" placeholder="'.esc_attr__('One choice per line', 'advanced-quotes-for-woocommerce').'">'.esc_textarea(implode("\n", (array) $field['choices'])).'</textarea><span class="wewp-aq-no-choices wewp-aq-muted"><span aria-hidden="true">—</span><span class="screen-reader-text">'.esc_html__('Only dropdowns, radio buttons and checkboxes have choices.', 'advanced-quotes-for-woocommerce').'</span></span></td>'
            .'<td class="col-required"><label><input type="checkbox" name="'.esc_attr($name('required')).'" value="yes"'.checked((bool) $field['required'], true, false).'> <span class="wewp-aq-cell-label">'.esc_html__('Required', 'advanced-quotes-for-woocommerce').'</span></label></td>'
            .'<td class="col-actions"><span class="wewp-aq-field-actions">'
            .'<button type="button" class="button-link wewp-aq-field-move" data-move="up" aria-label="'.esc_attr__('Move up', 'advanced-quotes-for-woocommerce').'"><span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span></button>'
            .'<button type="button" class="button-link wewp-aq-field-move" data-move="down" aria-label="'.esc_attr__('Move down', 'advanced-quotes-for-woocommerce').'"><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></button>'
            .'<button type="button" class="button-link button-link-delete wewp-aq-field-remove" aria-label="'.esc_attr__('Remove field', 'advanced-quotes-for-woocommerce').'"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button>'
            .'</span></td></tr>';
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
