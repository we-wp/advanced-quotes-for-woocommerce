<?php

namespace WeWP\AdvancedQuotes;

/**
 * Extra fields on the quote request form. Merchants define them in WooCommerce → Settings → Quotes → Request form.
 * Each request keeps the labels it was answered with, so later changes to the form do not alter it.
 */
final class RequestFields
{
    public const MAX_FIELDS = 20;

    public const MAX_CHOICES = 30;

    public const TYPES = ['text', 'textarea', 'number', 'date', 'select', 'radio', 'checkboxes', 'checkbox'];

    /** Contact fields a merchant can require, keep optional or hide. Name, email and country are always required. */
    public const CONTACT = ['phone', 'company', 'tax_id', 'address', 'message'];

    public const MODES = ['optional', 'required', 'hidden'];

    /**
     * @return array<string, string> Field type => name shown to the merchant.
     */
    public static function typeNames(): array
    {
        return [
            'text' => __('Single line text', 'advanced-quotes-for-woocommerce'),
            'textarea' => __('Paragraph text', 'advanced-quotes-for-woocommerce'),
            'number' => __('Number', 'advanced-quotes-for-woocommerce'),
            'date' => __('Date', 'advanced-quotes-for-woocommerce'),
            'select' => __('Dropdown', 'advanced-quotes-for-woocommerce'),
            'radio' => __('Radio buttons', 'advanced-quotes-for-woocommerce'),
            'checkboxes' => __('Checkboxes', 'advanced-quotes-for-woocommerce'),
            'checkbox' => __('Single checkbox', 'advanced-quotes-for-woocommerce'),
        ];
    }

    public static function hasChoices(string $type): bool
    {
        return in_array($type, ['select', 'radio', 'checkboxes'], true);
    }

    /**
     * Normalise field definitions from settings or add-ons. Rows without a label are dropped.
     *
     * @return list<array{id:string,label:string,type:string,required:bool,help:string,choices:list<string>}>
     */
    public static function sanitize(mixed $input): array
    {
        $fields = [];
        $seen = [];
        foreach (is_array($input) ? $input : [] as $key => $row) {
            if (! is_array($row) || count($fields) >= self::MAX_FIELDS) {
                continue;
            }
            $label = mb_substr(sanitize_text_field((string) ($row['label'] ?? '')), 0, 120);
            if ($label === '') {
                continue;
            }
            $id = (string) ($row['id'] ?? $key);
            if (! preg_match('/^[a-z][a-z0-9_]{1,39}$/', $id) || isset($seen[$id])) {
                // Stable for the same input, so answers posted to a filtered field still match it.
                $id = 'f_'.substr(md5($label.'|'.count($fields)), 0, 8);
            }
            $seen[$id] = true;
            $type = in_array($row['type'] ?? '', self::TYPES, true) ? $row['type'] : 'text';
            $choices = [];
            if (self::hasChoices($type)) {
                $raw = $row['choices'] ?? [];
                foreach (is_array($raw) ? $raw : preg_split('/\R/', (string) $raw) as $choice) {
                    $choice = is_scalar($choice) ? mb_substr(sanitize_text_field((string) $choice), 0, 120) : '';
                    if ($choice !== '' && ! in_array($choice, $choices, true)) {
                        $choices[] = $choice;
                    }
                }
            }
            $required = $row['required'] ?? false;
            $fields[] = [
                'id' => $id,
                'label' => $label,
                'type' => $type,
                'required' => $required === true || $required === 'yes' || $required === '1',
                'help' => mb_substr(sanitize_text_field((string) ($row['help'] ?? '')), 0, 300),
                'choices' => array_slice($choices, 0, self::MAX_CHOICES),
            ];
        }

        return $fields;
    }

    /**
     * Fields shown on the request form. A choice field stays off the form until it has choices.
     *
     * @return list<array{id:string,label:string,type:string,required:bool,help:string,choices:list<string>}>
     */
    public static function active(): array
    {
        /**
         * Filters the extra fields on the quote request form.
         *
         * @param array $fields Field definitions: id, label, type, required, help and choices.
         */
        $fields = self::sanitize(apply_filters('wewp_aq_request_fields', Settings::value('request_fields')));

        return array_values(array_filter($fields, static fn (array $field): bool => ! self::hasChoices($field['type']) || $field['choices']));
    }

    /**
     * Check posted answers against the fields.
     *
     * @param list<array{id:string,label:string,type:string,required:bool,help:string,choices:list<string>}> $fields
     * @param array<string, mixed> $input Answers keyed by field id.
     * @return array{answers: list<array{id:string,label:string,type:string,value:string|list<string>}>, errors: list<string>, invalid: list<string>}
     */
    public static function collect(array $fields, array $input): array
    {
        $answers = [];
        $errors = [];
        $invalid = [];
        foreach ($fields as $field) {
            $raw = $input[$field['id']] ?? '';
            $text = is_scalar($raw) ? trim((string) $raw) : '';
            $error = '';
            switch ($field['type']) {
                case 'checkboxes':
                    $picked = array_map('strval', array_filter(is_array($raw) ? $raw : [], 'is_scalar'));
                    $value = array_values(array_intersect($field['choices'], $picked));
                    if (count(array_unique($picked)) !== count($value)) {
                        /* translators: %s: field label */
                        $error = sprintf(__('“%s” must use the listed answers.', 'advanced-quotes-for-woocommerce'), $field['label']);
                    }
                    break;
                case 'checkbox':
                    $value = $text === 'yes' ? 'yes' : '';
                    break;
                case 'select':
                case 'radio':
                    $value = $text;
                    if ($value !== '' && ! in_array($value, $field['choices'], true)) {
                        /* translators: %s: field label */
                        $error = sprintf(__('“%s” must use one of the listed answers.', 'advanced-quotes-for-woocommerce'), $field['label']);
                    }
                    break;
                case 'number':
                    $value = str_replace(',', '.', $text);
                    if ($value !== '' && ! preg_match('/^-?\d{1,12}(\.\d{1,6})?$/', $value)) {
                        /* translators: %s: field label */
                        $error = sprintf(__('“%s” must be a number.', 'advanced-quotes-for-woocommerce'), $field['label']);
                    }
                    break;
                case 'date':
                    $value = $text;
                    if ($value !== '' && (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $date) || ! checkdate((int) $date[2], (int) $date[3], (int) $date[1]))) {
                        /* translators: %s: field label */
                        $error = sprintf(__('“%s” must be a valid date.', 'advanced-quotes-for-woocommerce'), $field['label']);
                    }
                    break;
                case 'textarea':
                    $value = is_scalar($raw) ? mb_substr(sanitize_textarea_field((string) $raw), 0, 2000) : '';
                    break;
                default:
                    $value = mb_substr(sanitize_text_field($text), 0, 200);
            }
            $empty = $value === '' || $value === [];
            if ($error === '' && $empty && $field['required']) {
                /* translators: %s: field label */
                $error = sprintf(__('“%s” is required.', 'advanced-quotes-for-woocommerce'), $field['label']);
            }
            if ($error !== '') {
                $errors[] = $error;
                $invalid[] = $field['id'];
            } elseif (! $empty || $field['type'] === 'checkbox') {
                $answers[] = ['id' => $field['id'], 'label' => $field['label'], 'type' => $field['type'], 'value' => $value];
            }
        }

        return ['answers' => $answers, 'errors' => $errors, 'invalid' => $invalid];
    }

    /**
     * Stored answers of a request, as label and display text.
     *
     * @return list<array{label:string,text:string}>
     */
    public static function answers(?array $request): array
    {
        $out = [];
        foreach ((array) ($request['fields'] ?? []) as $answer) {
            if (! is_array($answer) || ! isset($answer['label'])) {
                continue;
            }
            $text = self::text($answer);
            if ($text !== '') {
                $out[] = ['label' => (string) $answer['label'], 'text' => $text];
            }
        }

        return $out;
    }

    /**
     * One answer as display text in the current language.
     */
    public static function text(array $answer): string
    {
        $value = $answer['value'] ?? '';

        return match ($answer['type'] ?? 'text') {
            'checkbox' => $value === 'yes' ? __('Yes', 'advanced-quotes-for-woocommerce') : __('No', 'advanced-quotes-for-woocommerce'),
            'checkboxes' => implode(', ', array_map('strval', array_filter((array) $value, 'is_scalar'))),
            'date' => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? (string) wp_date((string) get_option('date_format'), (int) strtotime($value.' 12:00:00')) : (is_scalar($value) ? (string) $value : ''),
            default => is_scalar($value) ? (string) $value : '',
        };
    }
}
