<?php

namespace WeWP\AdvancedQuotes\Templates;

/**
 * Essential: a business-letter quote. Letterhead, a window-envelope address block with a return line,
 * a facts column, the offer, and fold marks on the printed page. The store's accent marks the number and total only.
 */
final class Essential extends Template
{
    public function id(): string
    {
        return 'essential';
    }

    public function name(): string
    {
        return __('Essential', 'advanced-quotes-for-woocommerce');
    }

    public function description(): string
    {
        return __('A business letter with a window-envelope address block. Calm, print-ready and familiar to procurement teams.', 'advanced-quotes-for-woocommerce');
    }

    public function thumbnail(): string
    {
        return function_exists('plugins_url') && defined('WEWP_AQ_FILE') ? plugins_url('assets/templates/essential.webp', WEWP_AQ_FILE) : '';
    }

    protected function css(Document $d): string
    {
        $accent = $d->accentText();

        return '@page{margin:14mm 16mm 22mm 20mm}'
            .'.aq-t-essential .ess-head td{vertical-align:bottom}'
            .'.aq-t-essential .ess-brand{font-size:1.75em;font-weight:600;letter-spacing:-.015em;line-height:1.1}'
            .'.aq-t-essential .aq-logo{max-height:16mm;max-width:64mm}'
            .'.aq-t-essential .ess-contact{text-align:right;font-size:.78em;line-height:1.55;color:#59616b}'
            .'.aq-t-essential .ess-contact b{color:#15191e;font-weight:600}'
            .'.aq-t-essential .ess-rule{border-top:.3mm solid #15191e;margin-top:3.4mm;height:0}'
            .'.aq-t-essential .ess-parties{margin-top:7.5mm}'
            .'.aq-t-essential .ess-address{width:92mm;padding-right:8mm}'
            .'.aq-t-essential .ess-return{font-size:.66em;color:#6b737d;border-bottom:.2mm solid #b9bfc6;padding-bottom:.7mm;margin-bottom:2.6mm;white-space:nowrap}'
            .'.aq-t-essential .ess-to{font-size:1.04em;line-height:1.45}'
            .'.aq-t-essential .ess-to b{font-weight:600}'
            .'.aq-t-essential .ess-reach{margin-top:2.2mm;font-size:.82em;color:#59616b;line-height:1.5}'
            .'.aq-t-essential .ess-facts td{padding:.7mm 0;font-size:.9em;border-bottom:.2mm solid #e3e6ea}'
            .'.aq-t-essential .ess-facts tr:last-child td{border-bottom:0}'
            .'.aq-t-essential .ess-facts .ess-l{color:#59616b}'
            .'.aq-t-essential .ess-facts .ess-v{text-align:right;font-weight:500;color:#15191e}'
            .'.aq-t-essential .ess-facts .ess-tot td{border-top:.3mm solid #15191e;padding-top:1.2mm}'
            .'.aq-t-essential .ess-facts .ess-tot .ess-l{color:#15191e}'
            .'.aq-t-essential .ess-facts .ess-tot .ess-v{font-weight:600}'
            .'.aq-t-essential .ess-title{margin:6.5mm 0 0;font-size:2em;line-height:1.1;font-weight:600;letter-spacing:-.02em}'
            .'.aq-t-essential .ess-no{color:'.$accent.'}'
            .'.aq-t-essential .ess-intro{margin-top:2.8mm;font-size:1.02em;line-height:1.55;color:#2b3138;width:150mm}'
            .'.aq-t-essential .aq-items{margin-top:5.5mm}'
            .'.aq-t-essential .aq-items th{font-size:.78em;font-weight:500;color:#59616b;padding:0 0 1.5mm;border-bottom:.3mm solid #15191e}'
            .'.aq-t-essential .aq-items td{padding:1.6mm 0;border-bottom:.2mm solid #dde1e5}'
            .'.aq-t-essential .aq-items th+th,.aq-t-essential .aq-items td+td{padding-left:4mm}'
            .'.aq-t-essential .aq-name{font-weight:600}'
            .'.aq-t-essential .aq-meta,.aq-t-essential .aq-sku,.aq-t-essential .aq-desc{font-size:.84em;color:#59616b;margin-top:.2mm}'
            .'.aq-t-essential .ess-sum{margin-top:4mm}'
            .'.aq-t-essential .ess-note{padding-right:12mm;font-size:.86em;line-height:1.55;color:#3c434b}'
            .'.aq-t-essential .ess-valid{font-size:1.1em;font-weight:600;color:#15191e;margin-bottom:1mm}'
            .'.aq-t-essential .ess-totalcell{width:80mm}'
            .'.aq-t-essential .aq-totals th,.aq-t-essential .aq-totals td{padding:.45mm 0;color:#2b3138}'
            .'.aq-t-essential .aq-totals .aq-t-total th,.aq-t-essential .aq-totals .aq-t-total td{border-top:.35mm solid #15191e;padding-top:2.2mm;font-size:1.3em;font-weight:600;color:#15191e}'
            .'.aq-t-essential .aq-totals .aq-t-total td{color:'.$accent.'}'
            .'.aq-t-essential .aq-totals .aq-t-included th,.aq-t-essential .aq-totals .aq-t-included td{font-size:.82em;color:#59616b;padding-top:.4mm}'
            .'.aq-t-essential .ess-terms{margin-top:5mm;page-break-inside:avoid}'
            .'.aq-t-essential .ess-terms h2{font-size:.92em;font-weight:600;margin:0 0 1.2mm}'
            .'.aq-t-essential .ess-terms p{font-size:.86em;line-height:1.55;color:#3c434b;width:160mm}'
            .'.aq-t-essential .ess-foot{font-size:7pt;line-height:1.5;color:#6b737d;border-top:.2mm solid #d4d8dd;padding-top:2mm}'
            .'.aq-t-essential .ess-foot td{padding-right:5mm}'
            .'.aq-t-essential .ess-foot .ess-page{text-align:right;padding-right:0;white-space:nowrap}'
            .'.aq-t-essential.ess-fixed{bottom:-15mm;left:0;right:0}'
            .'.aq-t-essential .ess-fold{position:absolute;left:-15mm;width:4mm;height:0;border-top:.25mm solid #a4abb3}';
    }

    protected function webCss(Document $d): string
    {
        return '.aq-t-essential .aq-logo{max-height:64px;max-width:240px}'
            .'.aq-t-essential .ess-rule{border-top-width:2px}'
            .'.aq-t-essential .ess-address{width:55%}'
            .'.aq-t-essential .ess-intro,.aq-t-essential .ess-terms p{width:auto;max-width:62ch}'
            .'.aq-t-essential .ess-totalcell{width:46%}'
            .'.aq-t-essential .aq-web-only{margin-top:40px}'
            .'@media (max-width:640px){'
            .'.aq-t-essential .ess-contact{text-align:left}'
            .'.aq-t-essential .ess-title{font-size:1.7em;margin-top:28px}'
            .'.aq-t-essential .ess-note{padding-right:0}'
            .'.aq-t-essential .ess-foot td{display:block;padding:0 0 6px}'
            .'.aq-t-essential .ess-foot .ess-page{text-align:left}'
            .'}';
    }

    protected function body(Document $d): string
    {
        $s = $d->seller();
        $c = $d->customer();
        $brand = $d->hasLogo() ? $d->logo() : '<div class="ess-brand">'.$s['name'].'</div>';
        $contact = self::lines(['<b>'.$s['name'].'</b>', $s['address'], $s['email'], $s['phone'], $s['website']]);
        $to = self::lines([$c['company'] !== '' ? '<b>'.$c['company'].'</b>' : '', $c['company'] !== '' ? $c['name'] : '<b>'.$c['name'].'</b>', $c['address']]);
        $reach = self::lines([$c['email'], $c['phone'], $c['tax_id'] !== '' ? $d->label('tax_id').' '.$c['tax_id'] : '']);
        $facts = '';
        foreach ($d->facts() as [$label, $value]) {
            $facts .= '<tr><td class="ess-l">'.$label.'</td><td class="ess-v">'.$value.'</td></tr>';
        }
        // The total also opens the letter, so page 1 shows it even when the item list runs on.
        $facts .= '<tr class="ess-tot"><td class="ess-l">'.$d->label('total').'</td><td class="ess-v">'.$d->total().'</td></tr>';
        $foot = $this->footer($d, $s);

        $html = '';
        if ($d->isPdf()) {
            $html .= '<div class="ess-fold" style="top:91mm"></div><div class="ess-fold" style="top:134.5mm;width:6mm"></div><div class="ess-fold" style="top:196mm"></div>';
        }
        $html .= '<table class="ess-head aq-stack"><tr><td>'.$brand.'</td><td class="ess-contact">'.$contact.'</td></tr></table><div class="ess-rule"></div>';
        $html .= '<table class="ess-parties aq-stack"><tr><td class="ess-address">'
            .($s['address_line'] !== '' ? '<div class="ess-return aq-pdf-only">'.$s['name'].' · '.$s['address_line'].'</div>' : '')
            .'<div class="ess-to">'.$to.'</div>'.($reach !== '' ? '<div class="ess-reach">'.$reach.'</div>' : '')
            .'</td><td><table class="ess-facts">'.$facts.'</table></td></tr></table>';
        $html .= '<h1 class="ess-title">'.$d->title().' <span class="ess-no">'.$d->number().'</span></h1>';
        if ($d->has('intro')) {
            $html .= '<p class="ess-intro">'.$d->text('intro').'</p>';
        }
        $html .= $d->itemsTable();
        $note = '<p class="ess-valid">'.$d->label('valid_until').' '.$d->e('valid_until_date').'</p><p>'.$d->label('accept').'</p>'.($d->priceNote() !== '' ? '<p>'.$d->priceNote().'</p>' : '');
        $html .= '<table class="ess-sum aq-sum aq-stack aq-keep"><tr><td class="ess-note">'.$note.'</td><td class="ess-totalcell">'.$d->totalsTable().'</td></tr></table>';
        if ($d->has('terms')) {
            $html .= '<div class="ess-terms"><h2>'.$d->label('terms').'</h2><p>'.$d->text('terms').'</p></div>';
        }

        return $html.'<div class="aq-web-only">'.$foot.'</div>';
    }

    protected function fixed(Document $d): string
    {
        return $this->fixedBlock('ess-fixed', $this->footer($d, $d->seller()));
    }

    private function footer(Document $d, array $s): string
    {
        $cols = [
            self::lines(['<b>'.$s['name'].'</b>', $s['address_line']]),
            self::lines([$s['email'], $s['phone'], $s['website']]),
            self::lines([$s['tax_id'] !== '' ? $d->label('tax_id').' '.$s['tax_id'] : '', $d->text('footer')]),
        ];
        $cells = '';
        foreach ($cols as $col) {
            if ($col !== '') {
                $cells .= '<td>'.$col.'</td>';
            }
        }
        $page = $d->isPdf() ? $d->number().' · '.$d->pageCounter() : $d->number();

        return '<table class="ess-foot"><tr>'.$cells.'<td class="ess-page">'.$page.'</td></tr></table>';
    }

    /** Join non-empty escaped fragments with line breaks. */
    private static function lines(array $parts): string
    {
        return implode('<br>', array_filter($parts, static fn ($part) => trim((string) preg_replace('/<[^>]*>/', '', (string) $part)) !== ''));
    }
}
