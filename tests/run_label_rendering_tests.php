<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Uses the installed TCPDF and native label parent, without bootstrapping Dolibarr.
// Usage: php tests/run_label_rendering_tests.php [Dolibarr document root] [artifact directory]
define('DOL_DOCUMENT_ROOT', isset($argv[1]) ? $argv[1] : dirname(__DIR__, 3));
define('MAIN_DB_PREFIX', 'test_');
define('K_TCPDF_EXTERNAL_CONFIG', true);
define('K_TCPDF_THROW_EXCEPTION_ERROR', true);
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
    // Keep upstream PHP deprecations visible without hiding module or encoder warnings.
    if ($severity === E_DEPRECATED && strpos($file, DOL_DOCUMENT_ROOT.'/includes/tecnickcom/tcpdf/') === 0) {
        return false;
    }
    if (error_reporting() & $severity) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
    return false;
});

$logs = array();
function dol_syslog($message, $level = 0) { $GLOBALS['logs'][] = $message; }
function dol_string_nohtmltag($value) { return strip_tags($value); }
function checkLabelRendering($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo 'PASS '.$message.PHP_EOL;
}

if (isset($argv[2]) && $argv[2] === '--failing-encoder') {
    // Separate process: replace TCPDF only here to exercise unexpected library Errors.
    class TCPDFBarcode {
        public function __construct($value, $encoding) { throw new TypeError('encoder failed'); }
    }
    require_once __DIR__.'/../class/KreaProductsLabelService.class.php';
    $renderer = new ReflectionMethod('KreaProductsLabelService', 'renderTemplateBarcodeBlockSvg');
    $svg = $renderer->invoke(null, array('symbology' => 'EAN13', 'w_mm' => 70, 'h_mm' => 16), 'ABC-105');
    checkLabelRendering(strpos($svg, 'fill="#101828"') === false, 'encoder Errors never become decorative barcode bars');
    checkLabelRendering(count($logs) === 2, 'invalid retail value and failed Code128 attempt are logged');
    exit(0);
}

// The native parent loads format cards eagerly. Return no rows; never connect to a DB.
$db = new class {
    public $code = '';
    public function query($sql) { return true; }
    public function fetch_array($result) { return false; }
    public function fetch_object($result) { return (object) array('code' => $this->code); }
    public function free($result) {}
};
$_Avery_Labels = array();
require_once __DIR__.'/../class/KreaProductsProductLabelPdf.class.php';
require_once DOL_DOCUMENT_ROOT.'/includes/tecnickcom/tcpdf/tcpdf.php';

class LabelRenderingPdf extends TCPDF
{
    public $encodings = array();
    public function write1DBarcode($code, $type, $x = null, $y = null, $w = null, $h = null, $xres = null, $style = array(), $align = '')
    {
        $this->encodings[] = $type;
        parent::write1DBarcode($code, $type, $x, $y, $w, $h, $xres, $style, $align);
    }
}

$preview = new ReflectionMethod('KreaProductsLabelService', 'buildPreviewBarcodeArrayFromTcpdf');
$svgBlock = new ReflectionMethod('KreaProductsLabelService', 'renderTemplateBarcodeBlockSvg');
$bitmap = new ReflectionMethod('KreaProductsLabelService', 'buildTsplBitmapDataFromLinearBarcode');
$productBarcode = new ReflectionMethod('KreaProductsLabelService', 'resolveBarcode');
$pdfBlock = new ReflectionMethod('KreaProductsProductLabelPdf', 'renderTemplateBarcodeBlock');
$pdfBarcode = new ReflectionMethod('KreaProductsProductLabelPdf', 'renderBarcode');
$generator = (new ReflectionClass('KreaProductsProductLabelPdf'))->newInstanceWithoutConstructor();

$cases = array(
    array('ABC-105', 'EAN13', 'C128'),
    array('ABC-105', 'EAN8', 'C128'),
    array('ABC-105', 'UPC-A', 'C128'),
    array('ABC-105', 'UPC-E', 'C128'),
    array('4006381333931', 'EAN13', 'EAN13'),
    array('7501234567893', 'EAN13', 'EAN13'),
    array('4006381333932', 'EAN13', 'C128'),
    array('12345678901234', 'EAN13', 'C128'),
    array('123456789', 'EAN8', 'C128'),
    array('96385074', 'EAN8', 'EAN8'),
    array('96385075', 'EAN8', 'C128'),
    array('036000291452', 'UPC-A', 'UPCA'),
    array('036000291453', 'UPCA', 'C128'),
    array('03600029145', 'UPC', 'UPCA'),
    array('1234567890123', 'UPCA', 'C128'),
    array('042100005264', 'UPC-E', 'UPCE'),
    array('04210000526', 'UPCE', 'UPCE'),
    array('042100005265', 'UPCE', 'C128'),
    array('01200000456', 'UPCE', 'UPCE'),
    array('01230000045', 'UPCE', 'UPCE'),
    array('01234000005', 'UPCE', 'UPCE'),
    array('01234500005', 'UPCE', 'UPCE'),
    array('11234500005', 'UPCE', 'UPCE'),
    array('01200010456', 'UPCE', 'C128'),
    array('01230010045', 'UPCE', 'C128'),
    array('01234010005', 'UPCE', 'C128'),
    array('01234500004', 'UPCE', 'C128'),
    array('212345678909', 'UPCE', 'C128'),
    array('04252614', 'UPCE', 'C128'),
    array('425261', 'UPCE', 'C128'),
    array('123', 'EAN13', 'EAN13'),
    array('123', 'EAN8', 'EAN8'),
    array('123', 'UPCA', 'UPCA'),
    array('00000000', 'EAN8', 'EAN8'),
    array('123 ', 'EAN13', 'C128'),
    array("123\n", 'EAN13', 'C128'),
    array('ABC-105', 'C128', 'C128'),
);
foreach ($cases as $case) {
    list($value, $requested, $expected) = $case;
    checkLabelRendering(KreaProductsLabelService::resolveSafeLinearBarcodeEncoding($value, $requested) === $expected,
        $requested.' selects '.$expected.' for '.json_encode($value));
    $actual = $preview->invoke(null, $value, $requested);
    $reference = (new TCPDFBarcode($value, $expected))->getBarcodeArray();
    checkLabelRendering(!empty($actual['bcode']) && $actual === $reference, 'preview uses real '.$expected.' bars');
}
checkLabelRendering($preview->invoke(null, '', 'EAN13') === array(), 'empty value does not enter TCPDF');
checkLabelRendering($preview->invoke(null, 'ABC@105', 'C39') === (new TCPDFBarcode('ABC@105', 'C128'))->getBarcodeArray(),
    'empty encoder result retries with Code128');

// Native product types and reference fallback use the same validation as templates.
$product = (object) array('barcode' => '', 'ref' => 'ABC-105', 'barcode_type' => 1);
$db->code = 'EAN13';
$resolved = $productBarcode->invoke(null, $db, $product);
checkLabelRendering($resolved['value'] === 'ABC-105' && $resolved['encoding'] === 'C128', 'product reference fallback is safe');
$product->barcode = '036000291452';
$product->barcode_type = 2;
$db->code = 'UPC-A';
checkLabelRendering($productBarcode->invoke(null, $db, $product)['encoding'] === 'UPCA', 'native UPC-A type uses TCPDF name');
checkLabelRendering(KreaProductsLabelService::resolveSafeLinearBarcodeEncoding('ABC-105', 'QRCODE,H') === 'QRCODE,H'
    && KreaProductsLabelService::resolveSafeLinearBarcodeEncoding('ABC-105', 'DATAMATRIX') === 'DATAMATRIX', '2D encodings are unchanged');

$pdf = new LabelRenderingPdf('P', 'mm', 'A4');
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->AddPage();
$langs = new class { public function convToOutputCharset($text) { return $text; } };
$samples = array(
    array('ABC-105', 'EAN13', 'C128'),
    array('4006381333931', 'EAN13', 'EAN13'),
    array('4006381333932', 'EAN13', 'C128'),
    array('96385074', 'EAN8', 'EAN8'),
    array('036000291452', 'UPC-A', 'UPCA'),
    array('042100005264', 'UPC-E', 'UPCE'),
    array('212345678909', 'UPC-E', 'C128'),
    array('ABC@105', 'C39', 'C128'),
);
$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 280">';
foreach ($samples as $index => $sample) {
    list($value, $symbology, $expected) = $sample;
    $y = 18 + $index * 32;
    $block = array('x_mm' => 10, 'y_mm' => $y, 'w_mm' => 70, 'h_mm' => 16,
        'value' => $value, 'symbology' => $symbology, 'show_human_readable' => true);
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Text(10, $y - 5, $symbology.' -> '.$expected);
    $pdfBlock->invokeArgs($generator, array(&$pdf, $langs, $block, 0, 0, 1));
    checkLabelRendering(end($pdf->encodings) === $expected, 'native PDF renders '.$symbology.' as '.$expected);
    $svg .= '<text x="10" y="'.($y - 2).'" font-family="Helvetica" font-size="3">'.$symbology.' to '.$expected.'</text>';
    $svg .= $svgBlock->invoke(null, $block, $value);
    $bits = $bitmap->invoke(null, $value, $symbology, 560, 128);
    checkLabelRendering(!empty($bits['data']) && $bits['width_bytes'] > 0, 'TSPL bitmap is encoded for '.$symbology);
}
$svg .= '</svg>';
$nativeBytes = $pdf->Output('', 'S');
checkLabelRendering(strpos($nativeBytes, '%PDF-') === 0, 'native label PDF is generated');

$svgPdf = new TCPDF('P', 'mm', 'A4');
$svgPdf->setPrintHeader(false);
$svgPdf->setPrintFooter(false);
$svgPdf->AddPage();
$svgPdf->ImageSVG('@'.$svg, 0, 0, 100, 280);
$svgBytes = $svgPdf->Output('', 'S');
checkLabelRendering(strpos($svgBytes, '%PDF-') === 0, 'SVG preview can be embedded in a PDF');

// Library Errors must reach the fallback even when they do not extend Exception.
$throwingPdf = new class {
    public $calls = 0;
    public $text = '';
    public function write1DBarcode(...$args) { $this->calls++; throw new TypeError('encoder failed'); }
    public function SetFont(...$args) {}
    public function SetXY(...$args) {}
    public function MultiCell($w, $h, $text, ...$args) { $this->text = $text; }
};
$pdfBarcode->invokeArgs($generator, array(&$throwingPdf, 0, 0, 70, 16, 'ABC-105', 'C128', false, false, $langs, true));
checkLabelRendering($throwingPdf->calls === 2 && $throwingPdf->text === 'ABC-105', 'PDF catches TypeError and retains text if both encoders fail');
checkLabelRendering(strpos(implode("\n", $logs), 'ABC-105') === false, 'fallback logging does not expose barcode values');

if (!empty($argv[2])) {
    $output = rtrim($argv[2], '/');
    if (!is_dir($output) && !mkdir($output, 0700, true)) {
        throw new RuntimeException('Cannot create label test artifact directory');
    }
    file_put_contents($output.'/labels-native.pdf', $nativeBytes);
    file_put_contents($output.'/labels-preview.pdf', $svgBytes);
    file_put_contents($output.'/labels-preview.svg', $svg);
}
echo 'Label rendering regression tests passed on PHP '.PHP_VERSION.PHP_EOL;
