<?php

namespace WeWP\AdvancedQuotes;

use Dompdf\Dompdf;
use Dompdf\Options;
use RuntimeException;
use WeWP\AdvancedQuotes\Templates\Document;
use WeWP\AdvancedQuotes\Templates\Essential;
use WeWP\AdvancedQuotes\Templates\Registry;
use WeWP\AdvancedQuotes\Templates\Template;

/**
 * Renders snapshots with a registered template: PDF bytes through a locked-down Dompdf, or HTML for the online page.
 */
final class Renderer
{
    private Registry $templates;

    public function __construct(?Registry $templates = null)
    {
        if (! $templates) {
            $templates = new Registry;
            $templates->register(new Essential);
        }
        $this->templates = $templates;
    }

    public function templates(): Registry
    {
        return $this->templates;
    }

    public function template(array $snapshot): Template
    {
        return $this->templates->resolve((string) ($snapshot['template']['id'] ?? 'essential'));
    }

    /**
     * Complete HTML document for Dompdf.
     */
    public function pdfHtml(array $snapshot): string
    {
        $template = $this->template($snapshot);
        $doc = new Document($snapshot, 'pdf');

        return '<!doctype html><html lang="'.Document::esc(substr((string) ($snapshot['locale'] ?? 'en'), 0, 2)).'"><head><meta charset="utf-8"><title>'.Document::esc($snapshot['number'] ?? '').'</title><style>'.$template->styles($doc).'</style></head><body>'.$template->markup($doc).'</body></html>';
    }

    public function pdf(array $snapshot): string
    {
        if (function_exists('set_time_limit')) {
            set_time_limit(30);
        }
        unset($snapshot['_pages']);
        $html = $this->pdfHtml($snapshot);
        [$bytes, $pages] = $this->dompdf($html, $snapshot);
        if (str_contains($html, 'aq-pagecount')) {
            // Dompdf has no page-total counter. Render again with the total from the first pass.
            $snapshot['_pages'] = $pages;
            [$bytes, $again] = $this->dompdf($this->pdfHtml($snapshot), $snapshot);
            if ($again !== $pages) {
                $snapshot['_pages'] = $again;
                [$bytes] = $this->dompdf($this->pdfHtml($snapshot), $snapshot);
            }
        }

        return $bytes;
    }

    /**
     * @return array{0:string,1:int} PDF bytes and page count.
     */
    private function dompdf(string $html, array $snapshot): array
    {
        $root = dirname(__DIR__);
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $options->set('allowedProtocols', ['data://' => ['rules' => []]]);
        $options->set('chroot', [$root]);
        $options->set('fontDir', $root.'/fonts');
        $options->set('fontCache', $root.'/fonts');
        // DejaVu Sans ships with Dompdf, is open source and covers Latin Extended text.
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('dpi', 96);
        // Dompdf sizes a line box as line-height × (ascender − descender) × this ratio. DejaVu Sans spans
        // 1.164 em, so 1 / 1.164 makes unitless line-height values behave as they do in browsers.
        $options->set('fontHeightRatio', 1 / 1.164);
        $pdf = new Dompdf($options);
        $pdf->setPaper(($snapshot['template']['paper'] ?? 'A4') === 'Letter' ? 'letter' : 'a4');
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();
        $pages = $pdf->getCanvas()->get_page_count();
        if ($pages > 60) {
            throw new RuntimeException(__('The quote PDF would exceed 60 pages. Reduce the number of items.', 'advanced-quotes-for-woocommerce'));
        }
        $bytes = (string) $pdf->output();
        if (! str_starts_with($bytes, '%PDF-') || strlen($bytes) > 12582912) {
            throw new RuntimeException(__('The quote PDF could not be created.', 'advanced-quotes-for-woocommerce'));
        }

        return [$bytes, $pages];
    }

}
