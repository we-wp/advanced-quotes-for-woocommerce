<?php
// Regenerate Dompdf's JSON metric caches for the PDF fonts. Run: php tools/fonts.php
// PDFs use DejaVu Sans and DejaVu Sans Mono, which are open source and ship with Dompdf. The plugin bundles
// no other fonts. The caches live in fonts/ so Dompdf never writes into the plugin folder at runtime.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__).'/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$dir = dirname(__DIR__).'/fonts';
foreach (glob($dir.'/*.json') as $stale) {
    unlink($stale);
}
file_put_contents($dir.'/installed-fonts.json', "{}\n");
// Render every face once so Cpdf writes its caches.
$options = new Options;
$options->set('fontDir', $dir);
$options->set('fontCache', $dir);
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'DejaVu Sans');
$pdf = new Dompdf($options);
$html = '<html><body>';
foreach (['DejaVu Sans', 'DejaVu Sans Mono', 'sans-serif'] as $family) {
    foreach (['400', '700'] as $weight) {
        $html .= '<p style="font-family:\''.$family.'\';font-weight:'.$weight.'">Ąčęėįšųūž € 0123456789 <i>Italic</i></p>';
    }
}
$pdf->loadHtml($html.'</body></html>', 'UTF-8');
$pdf->render();
echo 'Font caches ready: '.(count(glob($dir.'/*.json')) - 1)." caches\n";
