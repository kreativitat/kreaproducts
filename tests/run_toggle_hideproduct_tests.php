<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace KreaProductsToggleHideproductTest;

// Run the real action after bootstrap in subprocesses; never load an ERP environment.
if (isset($argv[1])) {
    $case = json_decode(base64_decode($argv[1]), true);
    $fetches = 0;
    $optionalFetches = 0;
    $writes = array();
    $headers = array();
    function GETPOST($key, $type) { return $GLOBALS['case']['input'][$key] ?? ''; }
    function GETPOSTINT($key) { return (int) GETPOST($key, 'int'); }
    function currentToken() { return 'validtoken'; }
    function newToken() { return 'renewedtoken'; }
    function getEntity($element) {
        if ($element !== 'product') { throw new \RuntimeException('Wrong visibility scope'); }
        return $GLOBALS['case']['entities'];
    }
    function dol_sanitizeUrl($path, $type) { return $GLOBALS['case']['sanitized'] ?? $path; }
    function top_httphead($type) {}
    function header($header) {
        $GLOBALS['headers'][] = $header;
        if (strpos($header, 'Location: ') === 0) { http_response_code(302); }
    }
    function accessforbidden() { http_response_code(403); exit; }
    class TestLangs {
        public function trans($key, ...$args) { return $key; }
    }
    class TestDB {
        public function close() {}
    }
    class Product {
        const TYPE_SERVICE = 1;
        public $entity;
        public $type;
        public $array_options = array();
        public function __construct($db) {}
        public function fetch($id) {
            $GLOBALS['fetches']++;
            $this->entity = $GLOBALS['case']['entity'];
            $this->type = $GLOBALS['case']['type'];
            return $GLOBALS['case']['fetch_result'];
        }
        public function fetch_optionals() { $GLOBALS['optionalFetches']++; }
        public function updateExtraField($key) {
            $GLOBALS['writes'][] = array($key, $this->array_options['options_'.$key]);
            return 1;
        }
    }
    $db = new TestDB();
    $langs = new TestLangs();
    $user = (object) array('rights' => (object) array(
        'produit' => (object) array('creer' => $case['product_right']),
        'service' => (object) array('creer' => $case['service_right']),
    ));
    ob_start();
    register_shutdown_function(function () {
        $body = ob_get_clean();
        echo json_encode(array('http' => http_response_code() ?: 200, 'body' => $body,
            'fetches' => $GLOBALS['fetches'], 'optional_fetches' => $GLOBALS['optionalFetches'],
            'writes' => $GLOBALS['writes'], 'headers' => $GLOBALS['headers']));
    });
    $source = file_get_contents(__DIR__.'/../ajax/toggle_hideproduct.php');
    $start = strpos($source, '$action = GETPOST(');
    if ($start === false) { throw new \RuntimeException('AJAX action boundary missing'); }
    // The namespace permits observing Location headers in CLI without a web server.
    eval('namespace '.__NAMESPACE__.';'.substr($source, $start));
    exit;
}

$defaults = array('input' => array('action' => 'set', 'id' => 42, 'value' => 1,
    'token' => 'validtoken', 'backtopage' => ''), 'entities' => '2, 8', 'entity' => '8',
    'type' => 0, 'fetch_result' => 1, 'product_right' => true, 'service_right' => false);
$allowed = array('http' => 200, 'body' => '{"status":"ok"}', 'fetches' => 1,
    'optional_fetches' => 1, 'writes' => array(array('kreap_hideproduct', 1)), 'headers' => array());
$notFound = array('http' => 404, 'body' => '{"status":"error","message":"KreapProductNotFound"}',
    'optional_fetches' => 0, 'writes' => array());
$badToken = array('http' => 403, 'body' => '{"status":"error","message":"ErrorBadOrExpiredToken"}',
    'fetches' => 0, 'optional_fetches' => 0, 'writes' => array());
$forbidden = array('http' => 403, 'body' => '', 'optional_fetches' => 0, 'writes' => array());
$cases = array(
    'visible shared non-owner product is writable' => array(array(), array()),
    'current-entity product is writable' => array(array('entity' => 2), array()),
    'invisible product returns not-found without optional access or writes' => array(array('entity' => 9), $notFound),
    'missing product returns the same not-found response' => array(array('fetch_result' => 0), $notFound),
    'entity zero has no implicit visibility exemption' => array(array('entity' => 0), $notFound),
    'explicitly shared entity zero is writable' => array(array('entity' => 0, 'entities' => '0,2,8'), array()),
    'empty visibility fails closed' => array(array('entities' => ''), $notFound),
    'missing token prevents fetching and writing' => array(array('input' => array('token' => '')), $badToken),
    'invalid token prevents fetching and writing' => array(array('input' => array('token' => 'invalid')), $badToken),
    'renewed token remains accepted' => array(array('input' => array('token' => 'renewedtoken')), array()),
    'product without product-create right is forbidden' => array(array('product_right' => false), $forbidden),
    'service-create right does not grant product writes' => array(array('product_right' => false, 'service_right' => true), $forbidden),
    'product-create right does not grant service writes' => array(array('type' => 1), $forbidden),
    'service with service-create right is writable' => array(array('type' => 1, 'product_right' => false, 'service_right' => true), array()),
    'zero value is persisted' => array(array('input' => array('value' => 0)), array('writes' => array(array('kreap_hideproduct', 0)))),
    'internal return path is kept' => array(array('input' => array('backtopage' => '/product/card.php?id=1')),
        array('http' => 302, 'body' => '', 'headers' => array('Location: /product/card.php?id=1'))),
    'protocol-relative return path falls back to JSON' => array(array('input' => array('backtopage' => '//evil.example/x')), array()),
    'backslash-relative return path falls back to JSON' => array(array('input' => array('backtopage' => '/\\evil.example')), array()),
    'absolute return URL falls back to JSON' => array(array('input' => array('backtopage' => 'https://evil.example')), array()),
    'embedded scheme is rejected before sanitization' => array(array('input' => array('backtopage' => '/https://evil.example'), 'sanitized' => '/httpsevil.example'), array()),
    'scheme without slashes is rejected' => array(array('input' => array('backtopage' => '/javascript:alert(1)')), array()),
    'bare slash falls back to JSON' => array(array('input' => array('backtopage' => '/')), array()),
    'sanitized return path must still be internal' => array(array('input' => array('backtopage' => '/product/card.php'), 'sanitized' => '//evil.example'), array()),
);
foreach ($cases as $name => $test) {
    list($overrides, $expectedOverrides) = $test;
    $case = array_replace($defaults, $overrides);
    $case['input'] = array_replace($defaults['input'], $overrides['input'] ?? array());
    $expected = array_replace($allowed, $expectedOverrides);
    $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' '.escapeshellarg(base64_encode(json_encode($case)));
    $output = array();
    $status = 0;
    exec($command, $output, $status);
    $actual = json_decode(implode("\n", $output), true);
    if ($status !== 0 || $actual !== $expected) {
        fwrite(STDERR, 'FAIL '.$name.': '.json_encode(array('exit' => $status, 'expected' => $expected, 'output' => $output)).PHP_EOL);
        exit(1);
    }
    echo 'PASS '.$name.PHP_EOL;
}
echo 'Hide-product regression tests passed ('.count($cases).' cases).'.PHP_EOL;
