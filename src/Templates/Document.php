<?php

namespace WeWP\AdvancedQuotes\Templates;

/**
 * Read-only view of a quote snapshot for templates. Every string it returns is already HTML-escaped.
 * It has no WordPress dependencies, so documents can be rendered and tested outside WordPress.
 */
final class Document
{
    public function __construct(private array $s, private string $mode = 'pdf') {}

    public function mode(): string
    {
        return $this->mode;
    }

    public function isPdf(): bool
    {
        return $this->mode === 'pdf';
    }

    public function isPreview(): bool
    {
        return ! empty($this->s['preview']);
    }

    public static function esc(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function raw(string $path, mixed $default = ''): mixed
    {
        $value = $this->s;
        foreach (explode('.', $path) as $key) {
            if (! is_array($value) || ! array_key_exists($key, $value)) {
                return $default;
            }
            $value = $value[$key];
        }

        return $value ?? $default;
    }

    /** Escaped single-line value. */
    public function e(string $path): string
    {
        $value = $this->raw($path);

        return is_scalar($value) ? self::esc($value) : '';
    }

    /** Escaped multi-line value with line breaks. */
    public function text(string $path): string
    {
        return nl2br($this->e($path), false);
    }

    public function has(string $path): bool
    {
        $value = $this->raw($path);

        return is_scalar($value) && trim((string) $value) !== '';
    }

    public function label(string $key): string
    {
        return self::esc($this->raw('labels.'.$key, $key));
    }

    public function money(string $amount): string
    {
        $f = (array) $this->raw('format', []);
        $value = (float) $amount;
        $decimals = (int) ($f['decimals'] ?? 2);
        $number = number_format(abs($value), $decimals, (string) ($f['decimal_separator'] ?? '.'), (string) ($f['thousand_separator'] ?? ','));
        $symbol = (string) ($f['symbol'] ?? '');
        $text = match ($f['position'] ?? 'left') {
            'right' => $number.$symbol,
            'left_space' => $symbol."\u{00A0}".$number,
            'right_space' => $number."\u{00A0}".$symbol,
            default => $symbol.$number,
        };

        return self::esc(($value < 0 && round($value, $decimals) != 0.0 ? "\u{2212}" : '').$text);
    }

    public function number(): string
    {
        return $this->e('number');
    }

    public function title(): string
    {
        return $this->e('title');
    }

    public function total(): string
    {
        return $this->money((string) $this->raw('totals.total', '0'));
    }

    /**
     * Accent colour as #rrggbb. Invalid values fall back to cobalt.
     */
    public function accent(): string
    {
        $accent = (string) $this->raw('template.accent', '#214ee8');

        return preg_match('/^#[0-9a-f]{6}$/i', $accent) ? strtolower($accent) : '#214ee8';
    }

    /** Accent mixed toward white. 0 is the accent, 1 is white. */
    public function tint(float $amount): string
    {
        return self::mix($this->accent(), '#ffffff', $amount);
    }

    /** Accent mixed toward black. 0 is the accent, 1 is black. */
    public function shade(float $amount): string
    {
        return self::mix($this->accent(), '#000000', $amount);
    }

    /** The accent darkened until it reaches 4.5:1 contrast on white, for accent-coloured text. */
    public function accentText(): string
    {
        $color = $this->accent();
        for ($step = 0; $step < 20 && self::contrast($color, '#ffffff') < 4.5; $step++) {
            $color = self::mix($this->accent(), '#000000', ($step + 1) * 0.05);
        }

        return $color;
    }

    /** Black or white, whichever reads better on the accent. */
    public function onAccent(): string
    {
        return self::contrast($this->accent(), '#ffffff') >= 4.5 ? '#ffffff' : '#111418';
    }

    public static function mix(string $from, string $to, float $amount): string
    {
        $amount = max(0.0, min(1.0, $amount));
        $a = sscanf($from, '#%02x%02x%02x');
        $b = sscanf($to, '#%02x%02x%02x');
        $out = '#';
        for ($i = 0; $i < 3; $i++) {
            $out .= sprintf('%02x', (int) round($a[$i] + ($b[$i] - $a[$i]) * $amount));
        }

        return $out;
    }

    public static function contrast(string $a, string $b): float
    {
        $l1 = self::luminance($a);
        $l2 = self::luminance($b);

        return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
    }

    private static function luminance(string $hex): float
    {
        $rgb = sscanf($hex, '#%02x%02x%02x');
        $c = array_map(static function (int $v): float {
            $v /= 255;

            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, $rgb);

        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }

    /**
     * The seller logo as an <img>, only when it is an embedded PNG or JPEG.
     */
    public function logo(string $class = 'aq-logo'): string
    {
        $logo = (string) $this->raw('seller.logo');
        if (! preg_match('#^data:image/(png|jpeg);base64,[A-Za-z0-9+/=]+$#D', $logo)) {
            return '';
        }

        return '<img class="'.self::esc($class).'" src="'.self::esc($logo).'" alt="'.$this->e('seller.name').'">';
    }

    public function hasLogo(): bool
    {
        return $this->logo() !== '';
    }

    /**
     * Seller contact lines, escaped. Keys: name, address, tax_id, email, phone, website.
     */
    public function seller(): array
    {
        $website = (string) $this->raw('seller.website');

        return [
            'name' => $this->e('seller.name'),
            'address' => $this->text('seller.address'),
            'address_line' => self::esc(implode(' · ', array_filter(array_map('trim', explode("\n", (string) $this->raw('seller.address')))))),
            'tax_id' => $this->e('seller.tax_id'),
            'email' => $this->e('seller.email'),
            'phone' => $this->e('seller.phone'),
            'website' => self::esc(preg_replace('#^https?://(www\.)?|/$#', '', $website)),
        ];
    }

    /**
     * Customer lines, escaped. Keys: name, company, address, email, phone, tax_id.
     */
    public function customer(): array
    {
        return [
            'name' => $this->e('customer.name'),
            'company' => $this->e('customer.company'),
            'address' => $this->text('customer.address'),
            'email' => $this->e('customer.email'),
            'phone' => $this->e('customer.phone'),
            'tax_id' => $this->e('customer.tax_id'),
        ];
    }

    /**
     * Quote facts as [label, value] pairs: number, date, valid until, optional reference and revision.
     */
    public function facts(bool $withNumber = true): array
    {
        $facts = [];
        if ($withNumber) {
            $facts[] = [$this->label('number'), $this->number()];
        }
        $facts[] = [$this->label('date'), $this->e('issued_date')];
        $facts[] = [$this->label('valid_until'), $this->e('valid_until_date')];
        if ($this->has('reference')) {
            $facts[] = [$this->label('reference'), $this->e('reference')];
        }
        if ((int) $this->raw('revision', 0) > 1) {
            $facts[] = [$this->label('revision'), self::esc((string) $this->raw('revision'))];
        }

        return $facts;
    }

    /**
     * Item lines, escaped and formatted.
     *
     * @return list<array{no:int,name:string,meta:string,sku:string,description:string,quantity:string,unit:string,discount:string,amount:string}>
     */
    public function lines(): array
    {
        $out = [];
        foreach ((array) $this->raw('lines', []) as $index => $line) {
            $discount = (float) ($line['discount'] ?? 0);
            $out[] = [
                'no' => $index + 1,
                'name' => self::esc($line['name'] ?? ''),
                'meta' => self::esc($line['meta'] ?? ''),
                'sku' => self::esc($line['sku'] ?? ''),
                'description' => nl2br(self::esc($line['description'] ?? ''), false),
                'quantity' => self::esc((string) ($line['quantity'] ?? '')),
                'unit' => $this->money((string) ($line['unit_display'] ?? '0')),
                'discount' => $discount > 0 ? self::esc(rtrim(rtrim(number_format($discount, 2, '.', ''), '0'), '.').'%') : '',
                'amount' => $this->money((string) ($line['amount_display'] ?? '0')),
            ];
        }

        return $out;
    }

    public function hasDiscount(): bool
    {
        foreach ((array) $this->raw('lines', []) as $line) {
            if ((float) ($line['discount'] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    public function hasSku(): bool
    {
        foreach ((array) $this->raw('lines', []) as $line) {
            if (trim((string) ($line['sku'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{key:string,label:string,amount:string,included:bool}>
     */
    public function totals(): array
    {
        $rows = [];
        foreach ((array) $this->raw('totals_rows', []) as $row) {
            $rows[] = ['key' => self::esc($row['key'] ?? ''), 'label' => self::esc($row['label'] ?? ''), 'amount' => self::esc($row['amount'] ?? ''), 'included' => ! empty($row['included'])];
        }

        return $rows;
    }

    /** "Prices exclude tax." or "Prices include tax." when tax is enabled. */
    public function priceNote(): string
    {
        if (! $this->raw('tax.enabled', false)) {
            return '';
        }

        return $this->raw('tax.display') === 'incl' ? $this->label('prices_incl') : $this->label('prices_excl');
    }

    /**
     * Standard items table. Templates style it through its classes; the online quote page reflows it on phones.
     *
     * @param array{numbered?:bool,sku?:bool,class?:string} $options
     */
    public function itemsTable(array $options = []): string
    {
        $numbered = ! empty($options['numbered']);
        $sku = ($options['sku'] ?? true) && $this->hasSku();
        $discount = $this->hasDiscount();
        $head = '<tr>'.($numbered ? '<th class="aq-c-no" scope="col">#</th>' : '').'<th class="aq-c-item" scope="col">'.$this->label('item').'</th><th class="aq-c-qty" scope="col">'.$this->label('quantity').'</th><th class="aq-c-unit" scope="col">'.$this->label('unit_price').'</th>'.($discount ? '<th class="aq-c-disc" scope="col">'.$this->label('discount').'</th>' : '').'<th class="aq-c-amount" scope="col">'.$this->label('amount').'</th></tr>';
        $body = '';
        foreach ($this->lines() as $line) {
            $details = '<div class="aq-name">'.$line['name'].'</div>';
            if ($line['meta'] !== '') {
                $details .= '<div class="aq-meta">'.$line['meta'].'</div>';
            }
            if ($sku && $line['sku'] !== '') {
                $details .= '<div class="aq-sku">'.$this->label('sku').' '.$line['sku'].'</div>';
            }
            if ($line['description'] !== '') {
                $details .= '<div class="aq-desc">'.$line['description'].'</div>';
            }
            $body .= '<tr class="aq-row">'.($numbered ? '<td class="aq-c-no">'.sprintf('%02d', $line['no']).'</td>' : '')
                .'<td class="aq-c-item">'.$details.'</td>'
                .'<td class="aq-c-qty" data-label="'.$this->label('quantity').'">'.$line['quantity'].'</td>'
                .'<td class="aq-c-unit" data-label="'.$this->label('unit_price').'">'.$line['unit'].'</td>'
                .($discount ? '<td class="aq-c-disc'.($line['discount'] === '' ? ' is-empty' : '').'" data-label="'.$this->label('discount').'">'.($line['discount'] !== '' ? $line['discount'] : '&#8211;').'</td>' : '')
                .'<td class="aq-c-amount" data-label="'.$this->label('amount').'">'.$line['amount'].'</td></tr>';
        }

        return '<table class="aq-items'.(! empty($options['class']) ? ' '.self::esc($options['class']) : '').'"><thead>'.$head.'</thead><tbody>'.$body.'</tbody></table>';
    }

    /**
     * Standard totals table with one row per total, discount, shipping and tax line.
     */
    public function totalsTable(string $class = ''): string
    {
        $rows = '';
        foreach ($this->totals() as $row) {
            $rows .= '<tr class="aq-t-'.$row['key'].($row['included'] ? ' aq-t-included' : '').'"><th scope="row">'.$row['label'].'</th><td>'.$row['amount'].'</td></tr>';
        }

        return '<table class="aq-totals'.($class !== '' ? ' '.self::esc($class) : '').'">'.$rows.'</table>';
    }

    /** "Page 1 of 3" for PDF footers, or "1 of 3" without the label. Empty online. */
    public function pageCounter(bool $withLabel = true): string
    {
        if (! $this->isPdf()) {
            return '';
        }
        $pages = (int) $this->raw('_pages', 0);

        return ($withLabel ? $this->label('page').' ' : '').'<span class="aq-pagenum"></span> '.$this->label('of').' <span class="aq-pagecount">'.($pages > 0 ? $pages : '0').'</span>';
    }
}
