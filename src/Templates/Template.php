<?php

namespace WeWP\AdvancedQuotes\Templates;

/**
 * Base class for quote document templates. Free owns this contract; the Pro add-on extends it.
 *
 * One template renders two ways from the same snapshot: a PDF through Dompdf and the online quote page.
 * PDF styles may use only what Dompdf supports: CSS 2.1 tables, floats, borders, backgrounds and
 * @page rules. Flexbox and grid are unavailable. Scope every selector under .aq-document.
 */
abstract class Template
{
    /** Stable lowercase identifier stored in snapshots, for example "essential". */
    abstract public function id(): string;

    abstract public function name(): string;

    abstract public function description(): string;

    /** "free" or "pro". Shown in the template picker. */
    public function edition(): string
    {
        return 'free';
    }

    /** URL of a picker preview image. */
    public function thumbnail(): string
    {
        return '';
    }

    /** Template stylesheet for both outputs. */
    abstract protected function css(Document $d): string;

    /** Document markup. Use Document helpers; they return escaped HTML. */
    abstract protected function body(Document $d): string;

    /**
     * Elements repeated on every PDF page, such as a footer. Build each with fixedBlock().
     * Dompdf repeats fixed elements only when they are direct children of <body>.
     */
    protected function fixed(Document $d): string
    {
        return '';
    }

    /** A fixed PDF element carrying this template's scope classes. */
    final protected function fixedBlock(string $class, string $html, string $style = ''): string
    {
        return '<div class="aq-document aq-t-'.Document::esc($this->id()).' aq-mode-pdf aq-fixed '.Document::esc($class).'"'.($style !== '' ? ' style="'.Document::esc($style).'"' : '').'>'.$html.'</div>';
    }

    /**
     * Words this template prints that the standard labels do not cover, keyed for Document::label().
     * They are stored with each sent revision, so a quote keeps the language it was sent in.
     *
     * @return array<string, string>
     */
    public function labels(): array
    {
        return [];
    }

    /** Optional extra styles for the online quote page. */
    protected function webCss(Document $d): string
    {
        return '';
    }

    final public function styles(Document $d): string
    {
        $css = self::baseCss().$this->css($d);
        if (! $d->isPdf()) {
            return $css.self::webBaseCss().$this->webCss($d);
        }

        // DejaVu Sans, the PDF font, has regular and bold only. Dompdf would draw 500 and 600 as bold.
        return (string) preg_replace(['/font-weight:\s*[1-5]00\b/', '/font-weight:\s*[6-9]00\b/'], ['font-weight:400', 'font-weight:700'], $css);
    }

    final public function markup(Document $d): string
    {
        $fixed = '';
        if ($d->isPdf()) {
            $fixed = $this->fixed($d);
            if ($d->isPreview()) {
                $fixed .= $this->fixedBlock('aq-preview-mark', $d->label('preview'));
            }
        }
        $preview = ! $d->isPdf() && $d->isPreview() ? '<div class="aq-preview-mark">'.$d->label('preview').'</div>' : '';

        return $fixed.'<div class="aq-document aq-t-'.Document::esc($this->id()).' aq-mode-'.Document::esc($d->mode()).'">'.$preview.$this->body($d).'</div>';
    }

    private static function baseCss(): string
    {
        return '.aq-document{font-family:"DejaVu Sans",sans-serif;color:#15191e;font-size:9.5pt;line-height:1.45}'
            .'.aq-document table{border-collapse:collapse;width:100%}'
            .'.aq-document th,.aq-document td{text-align:left;vertical-align:top;padding:0}'
            .'.aq-document p{margin:0}'
            .'.aq-document .aq-items thead{display:table-header-group}'
            .'.aq-document .aq-items tr{page-break-inside:avoid}'
            .'.aq-document .aq-c-qty,.aq-document .aq-c-unit,.aq-document .aq-c-disc,.aq-document .aq-c-amount{text-align:right;white-space:nowrap}'
            .'.aq-document .aq-totals th{font-weight:normal}'
            .'.aq-document .aq-totals td{text-align:right;white-space:nowrap}'
            .'.aq-document .aq-keep{page-break-inside:avoid}'
            // Keep the totals with at least the last item line: Dompdf then breaks before that line instead.
            .'.aq-mode-pdf .aq-items,.aq-mode-pdf .aq-sum{page-break-before:avoid}'
            .'.aq-fixed{position:fixed}'
            .'.aq-pagenum:before{content:counter(page)}'
            .'.aq-mode-pdf .aq-web-only{display:none}'
            .'.aq-preview-mark{top:-9mm;left:0;right:0;text-align:center;font-size:7.5pt;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:#b3261e}';
    }

    /**
     * Shared rules for the online page: fixed PDF furniture is hidden, the sheet scales down, and the
     * items table becomes stacked rows on narrow screens.
     */
    private static function webBaseCss(): string
    {
        return '.aq-mode-web{font-family:inherit}'
            .'.aq-mode-web .aq-pdf-only{display:none!important}'
            .'.aq-mode-web .aq-preview-mark{position:static;margin:0 0 16px}'
            .'.aq-mode-web img{max-width:100%;height:auto}'
            .'@media (max-width:640px){'
            .'.aq-mode-web .aq-stack,.aq-mode-web .aq-stack>tbody,.aq-mode-web .aq-stack>tbody>tr,.aq-mode-web .aq-stack>tbody>tr>td{display:block;width:auto!important;text-align:left!important}'
            .'.aq-mode-web .aq-stack>tbody>tr>td+td{margin-top:16px}'
            .'.aq-mode-web .aq-items thead{display:none}'
            .'.aq-mode-web .aq-items,.aq-mode-web .aq-items tbody{display:block}'
            .'.aq-mode-web .aq-items tr.aq-row{display:block;padding:12px 0;border-bottom:1px solid #dfe2e6}'
            .'.aq-mode-web .aq-items td{display:block;padding:0!important;border:0!important;text-align:left!important;white-space:normal}'
            .'.aq-mode-web .aq-items td.aq-c-no,.aq-mode-web .aq-items td.aq-c-disc.is-empty{display:none}'
            .'.aq-mode-web .aq-items td.aq-c-item{margin-bottom:6px}'
            .'.aq-mode-web .aq-items td[data-label]{display:inline-block;margin-right:14px;font-size:.92em;color:#4a525c}'
            .'.aq-mode-web .aq-items td[data-label]:before{content:attr(data-label) ": ";color:#6b737d}'
            .'.aq-mode-web .aq-items td.aq-c-amount{display:block;margin-top:4px;font-size:1.05em;color:inherit;font-weight:600}'
            .'.aq-mode-web .aq-items td.aq-c-amount:before{content:"";}'
            .'.aq-mode-web .aq-totals{width:100%!important}'
            .'}';
    }
}
