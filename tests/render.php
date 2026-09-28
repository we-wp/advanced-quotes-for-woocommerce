<?php

// Standalone rendering checks. No WordPress or database is needed.
// Usage: php tests/render.php [--packaged] [--out=DIR] [--templates=essential,other]
$root = dirname(__DIR__);
$args = getopt('', ['packaged', 'out:', 'templates:', 'extra:']);
require (isset($args['packaged']) ? $root.'/build/advanced-quotes-for-woocommerce' : $root).'/vendor/autoload.php';
require __DIR__.'/fixtures.php';

if (! function_exists('__')) {
    function __($text, $domain = null)
    {
        return $text;
    }
}

use WeWP\AdvancedQuotes\Money;
use WeWP\AdvancedQuotes\Renderer;
use WeWP\AdvancedQuotes\Templates\Document;
use WeWP\AdvancedQuotes\Templates\Essential;
use WeWP\AdvancedQuotes\Templates\Registry;

function verify(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException('FAIL: '.$message);
    }
}

$registry = new Registry;
$registry->register(new Essential);
// Optional add-on templates: --extra=/path/to/autoload.php (defines aq_extra_templates(Registry $r)).
if (! empty($args['extra'])) {
    require $args['extra'];
    aq_extra_templates($registry);
}
$renderer = new Renderer($registry);

verify(Money::units('32.50') === 32500000, 'exact decimal amount');
verify(Money::units('-0.001') === -1000, 'signed three-decimal amount');
foreach (['NaN', '1e8', '999999999999', '1.2345678', ''] as $invalid) {
    try {
        Money::units($invalid);
        throw new LogicException('Invalid amount accepted: '.$invalid);
    } catch (RuntimeException $expected) {
    }
}

// Hostile input in every text field, for every registered template, in PDF and online output.
foreach (array_keys($registry->all()) as $id) {
    $hostile = aq_fixture_snapshot(['template' => ['id' => $id]]);
    $payload = '<script>alert(1)</script>"><img src=x onerror=alert(1)>';
    $hostile['lines'][0]['name'] = $payload;
    $hostile['lines'][0]['description'] = $payload;
    $hostile['lines'][0]['meta'] = $payload;
    $hostile['customer']['company'] = $payload;
    $hostile['customer']['address'] = $payload;
    $hostile['seller']['name'] = $payload;
    $hostile['intro'] = $payload;
    $hostile['terms'] = $payload;
    $hostile['footer'] = $payload;
    $hostile['reference'] = $payload;
    $hostile['labels']['customer_copy'] = $payload;
    $hostile['seller']['logo'] = 'https://untrusted.example.test/logo.png';
    $hostile['template']['accent'] = 'red;}body{display:none';
    $template = $registry->resolve($id);
    foreach (['pdf', 'web'] as $mode) {
        $doc = new Document($hostile, $mode);
        $html = $template->styles($doc).$template->markup($doc);
        verify(! str_contains($html, '<script>') && ! str_contains($html, '<img src=x'), "$id $mode escapes every text field");
        verify(! str_contains($html, 'untrusted.example.test'), "$id $mode refuses a remote logo");
        verify(! str_contains($html, 'display:none;}') && ! str_contains($html, 'red;}'), "$id $mode ignores an invalid accent");
    }
}
$labelled = new Document(aq_fixture_snapshot(['labels' => ['customer_copy' => 'Kliento kopija']]));
verify($labelled->label('customer_copy') === 'Kliento kopija', 'stored template labels are used');
verify(str_starts_with((new Document(aq_fixture_snapshot(['_pages' => 3])))->pageCounter(false), '<span class="aq-pagenum">'), 'page counter without label');
verify(Document::contrast('#ffffff', '#000000') > 20, 'contrast maths');
$pale = new Document(aq_fixture_snapshot(['template' => ['accent' => '#ffe066']]));
verify(Document::contrast($pale->accentText(), '#ffffff') >= 4.5, 'pale accent darkened for text');

$out = $args['out'] ?? null;
$ids = isset($args['templates']) ? explode(',', $args['templates']) : array_keys($registry->all());
foreach ($ids as $id) {
    foreach (['a4' => ['paper' => 'A4'], 'letter' => ['paper' => 'Letter']] as $name => $paper) {
        $snapshot = aq_fixture_snapshot(['template' => ['id' => $id] + $paper]);
        $bytes = $renderer->pdf($snapshot);
        verify(str_starts_with($bytes, '%PDF-') && strlen($bytes) > 2000, $id.' '.$name.' PDF rendered');
        if ($out && $name === 'a4') {
            file_put_contents($out.'/'.$id.'.pdf', $bytes);
        }
    }
    $long = aq_fixture_snapshot(['template' => ['id' => $id]]);
    $long['lines'] = array_fill(0, 120, $long['lines'][0]);
    $long['preview'] = true;
    $bytes = $renderer->pdf($long);
    verify(str_starts_with($bytes, '%PDF-'), $id.' 120-line multipage preview rendered');
    if ($out) {
        file_put_contents($out.'/'.$id.'-long.pdf', $bytes);
        $logo = aq_fixture_snapshot(['template' => ['id' => $id, 'accent' => '#0f766e']]);
        $png = imagecreatetruecolor(360, 96);
        imagefill($png, 0, 0, imagecolorallocate($png, 255, 255, 255));
        imagefilledrectangle($png, 0, 18, 60, 78, imagecolorallocate($png, 15, 118, 110));
        imagestring($png, 5, 78, 38, 'NORTHWIND WORKSPACE', imagecolorallocate($png, 20, 24, 30));
        ob_start();
        imagepng($png);
        $logo['seller']['logo'] = 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
        file_put_contents($out.'/'.$id.'-logo.pdf', $renderer->pdf($logo));
    }
}

echo 'PASS money validation, hostile input in every template (PDF and online), remote logo refusal, accent fallback, stored labels, contrast, '.count($ids)." template(s) in A4, Letter and multipage\n";
