<?php

namespace WeWP\AdvancedQuotes;

/**
 * Plugin settings stored in one option. WooCommerce → Settings → Quotes edits them.
 */
final class Settings
{
    public const OPTION = 'wewp_aq_settings';

    public static function defaults(): array
    {
        $countries = function_exists('WC') && WC()->countries ? WC()->countries : null;
        $address = '';
        if ($countries) {
            $address = implode("\n", array_filter([
                $countries->get_base_address(),
                $countries->get_base_address_2(),
                trim($countries->get_base_postcode().' '.$countries->get_base_city()),
                $countries->countries[$countries->get_base_country()] ?? $countries->get_base_country(),
            ]));
        }

        return [
            'business' => get_bloginfo('name'),
            'address' => $address,
            'tax_id' => '',
            'email' => (string) get_option('woocommerce_email_from_address', get_option('admin_email')),
            'phone' => '',
            'website' => home_url('/'),
            'logo_id' => 0,
            'template' => 'essential',
            'accent' => '#214ee8',
            'paper' => 'A4',
            'title' => __('Quote', 'advanced-quotes-for-woocommerce'),
            'prefix' => 'Q-',
            'validity' => 30,
            'intro' => __('Thank you for your request. This quote lists the agreed items and prices.', 'advanced-quotes-for-woocommerce'),
            'terms' => __('Prices are valid until the date shown. Accept the quote online to create your order, then pay with any available payment method.', 'advanced-quotes-for-woocommerce'),
            'footer' => '',
            'display' => get_option('woocommerce_tax_display_cart', 'excl') === 'incl' ? 'incl' : 'excl',
            'button' => 'yes',
            'button_label' => __('Request a quote', 'advanced-quotes-for-woocommerce'),
            'cart_button' => 'yes',
            'request_page' => (int) get_option('wewp_aq_request_page', 0),
            'field_phone' => 'optional',
            'field_company' => 'optional',
            'field_tax_id' => 'optional',
            'field_address' => 'optional',
            'field_message' => 'optional',
            'request_fields' => [],
        ];
    }

    public static function get(): array
    {
        $stored = get_option(self::OPTION, []);

        return self::sanitize(array_merge(self::defaults(), is_array($stored) ? $stored : []));
    }

    public static function value(string $key): mixed
    {
        return self::get()[$key] ?? null;
    }

    /**
     * Normalise every setting. Unknown keys are dropped.
     */
    public static function sanitize(array $input): array
    {
        $defaults = self::defaults();
        $out = [];
        foreach (['business', 'tax_id', 'phone', 'title', 'button_label'] as $key) {
            $out[$key] = mb_substr(sanitize_text_field((string) ($input[$key] ?? $defaults[$key])), 0, 200);
        }
        foreach (['address', 'intro', 'terms', 'footer'] as $key) {
            $out[$key] = mb_substr(sanitize_textarea_field((string) ($input[$key] ?? $defaults[$key])), 0, 4000);
        }
        $out['email'] = sanitize_email((string) ($input['email'] ?? '')) ?: '';
        $out['website'] = esc_url_raw((string) ($input['website'] ?? ''), ['http', 'https']);
        $out['logo_id'] = absint($input['logo_id'] ?? 0);
        $out['template'] = sanitize_key((string) ($input['template'] ?? 'essential')) ?: 'essential';
        $out['accent'] = sanitize_hex_color((string) ($input['accent'] ?? '')) ?: $defaults['accent'];
        $out['paper'] = ($input['paper'] ?? '') === 'Letter' ? 'Letter' : 'A4';
        $prefix = (string) ($input['prefix'] ?? 'Q-');
        $out['prefix'] = preg_match('/^[A-Za-z0-9\-\/_.]{0,12}$/', $prefix) ? $prefix : 'Q-';
        $out['validity'] = max(1, min(365, (int) ($input['validity'] ?? 30)));
        $out['display'] = ($input['display'] ?? '') === 'incl' ? 'incl' : 'excl';
        $out['button'] = ($input['button'] ?? 'yes') === 'no' ? 'no' : 'yes';
        $out['cart_button'] = ($input['cart_button'] ?? 'yes') === 'no' ? 'no' : 'yes';
        $out['request_page'] = absint($input['request_page'] ?? 0);
        foreach (RequestFields::CONTACT as $field) {
            $mode = $input['field_'.$field] ?? 'optional';
            $out['field_'.$field] = in_array($mode, RequestFields::MODES, true) ? $mode : 'optional';
        }
        $out['request_fields'] = RequestFields::sanitize($input['request_fields'] ?? []);
        if ($out['title'] === '') {
            $out['title'] = $defaults['title'];
        }
        if ($out['button_label'] === '') {
            $out['button_label'] = $defaults['button_label'];
        }

        return $out;
    }

    public static function save(array $values): void
    {
        update_option(self::OPTION, self::sanitize(array_merge(self::get(), $values)), false);
    }

    /**
     * Logo as a PNG or JPEG data URI for immutable snapshots. Empty when unset or invalid.
     */
    public static function logoDataUri(int $attachmentId): string
    {
        if ($attachmentId <= 0 || ! wp_attachment_is_image($attachmentId)) {
            return '';
        }
        $file = get_attached_file($attachmentId);
        $uploads = wp_get_upload_dir();
        $real = $file ? realpath($file) : false;
        $base = realpath($uploads['basedir']);
        if (! $real || ! $base || ! str_starts_with($real, $base.DIRECTORY_SEPARATOR) || filesize($real) > 2097152) {
            return '';
        }
        $info = @getimagesize($real);
        if (! $info || $info[0] * $info[1] > 6000000 || ! in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
            return '';
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file inside the uploads directory, validated above.
        $bytes = file_get_contents($real);
        $image = $bytes !== false ? imagecreatefromstring($bytes) : false;
        if (! $image) {
            return '';
        }
        // Each sent revision embeds the logo, so keep it small: at most 900 × 300 px, re-encoded.
        $scale = min(1, 900 / imagesx($image), 300 / imagesy($image));
        if ($scale < 1) {
            $resized = imagecreatetruecolor(max(1, (int) round(imagesx($image) * $scale)), max(1, (int) round(imagesy($image) * $scale)));
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            imagefill($resized, 0, 0, imagecolorallocatealpha($resized, 255, 255, 255, 127));
            imagecopyresampled($resized, $image, 0, 0, 0, 0, imagesx($resized), imagesy($resized), imagesx($image), imagesy($image));
            imagedestroy($image);
            $image = $resized;
        }
        ob_start();
        if ($info[2] === IMAGETYPE_PNG) {
            imagesavealpha($image, true);
            imagepng($image, null, 9);
            $mime = 'png';
        } else {
            imagejpeg($image, null, 85);
            $mime = 'jpeg';
        }
        $encoded = (string) ob_get_clean();
        imagedestroy($image);

        return $encoded !== '' && strlen($encoded) <= 400000 ? 'data:image/'.$mime.';base64,'.base64_encode($encoded) : '';
    }
}
