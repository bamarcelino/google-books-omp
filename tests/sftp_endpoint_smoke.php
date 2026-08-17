<?php

declare(strict_types=1);

require_once __DIR__ . '/../classes/Delivery/SftpEndpoint.php';
require_once __DIR__ . '/../classes/Delivery/SftpTransport.php';

use APP\plugins\generic\googleBooks\classes\Delivery\SftpEndpoint;
use APP\plugins\generic\googleBooks\classes\Delivery\SftpTransport;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$plain = SftpEndpoint::parse('partnerupload.example.test', 19321, '');
$assert($plain['host'] === 'partnerupload.example.test', 'bare hostname normalization');
$assert($plain['port'] === 19321, 'configured port retained');
$assert($plain['remoteRoot'] === '', 'empty root retained');
$assert($plain['inputHadScheme'] === false, 'bare hostname has no scheme');

$url = SftpEndpoint::parse('sftp://partnerupload.example.test:19321/incoming/books', 22, '');
$assert($url['host'] === 'partnerupload.example.test', 'sftp URL host extracted');
$assert($url['port'] === 19321, 'sftp URL port extracted');
$assert($url['remoteRoot'] === 'incoming/books', 'sftp URL path becomes root');
$assert($url['inputHadScheme'] === true, 'sftp URL scheme detected');
$assert($url['inputHadExplicitPort'] === true, 'explicit URL port detected');
$assert($url['inputHadPath'] === true, 'URL path detected');

$hostPort = SftpEndpoint::parse('partnerupload.example.test:2200', 22, '');
$assert($hostPort['host'] === 'partnerupload.example.test', 'host:port host extracted');
$assert($hostPort['port'] === 2200, 'host:port port extracted');

$override = SftpEndpoint::parse('sftp://partnerupload.example.test:19321/url/root', 22, '/configured//root/');
$assert($override['remoteRoot'] === 'configured/root', 'dedicated root overrides URL path and normalizes slashes');

$escaped = SftpEndpoint::parse('sftp\://partnerupload.example.test:19321', 22, '');
$assert($escaped['host'] === 'partnerupload.example.test', 'documentation-escaped sftp scheme normalized');
$assert($escaped['port'] === 19321, 'documentation-escaped scheme keeps port');

$ipv6 = SftpEndpoint::parse('[2001:db8::10]:2222', 22, '');
$assert($ipv6['host'] === '2001:db8::10', 'bracketed IPv6 host extracted');
$assert($ipv6['port'] === 2222, 'bracketed IPv6 port extracted');

$empty = SftpEndpoint::parse('', 22, '/root/');
$assert($empty['host'] === '', 'empty endpoint remains empty');
$assert($empty['remoteRoot'] === 'root', 'empty endpoint still normalizes root');

foreach ([
    ['https://example.test', 'non-SFTP scheme rejected'],
    ['sftp://user:secret@example.test', 'URL userinfo rejected'],
    ['sftp://example.test:70000', 'invalid high port rejected'],
] as [$bad, $label]) {
    $thrown = false;
    try {
        SftpEndpoint::parse($bad, 22, '');
    } catch (Throwable) {
        $thrown = true;
    }
    $assert($thrown, $label);
}

$assert(SftpTransport::classifyFailure(7, 111, 'Connection refused') === 'tcp_refused', 'connection-refused classification');
$assert(SftpTransport::classifyFailure(6, 0, 'Could not resolve host') === 'dns', 'DNS classification');
$assert(SftpTransport::classifyFailure(28, 0, 'Operation timed out') === 'timeout', 'timeout classification');
$assert(SftpTransport::classifyFailure(67, 0, 'Authentication failure') === 'authentication', 'authentication classification');
$assert(SftpTransport::classifyFailure(0, 0, 'Host key fingerprint mismatch') === 'host_key', 'host-key classification');
$assert(SftpTransport::classifyFailure(9, 0, 'Permission denied') === 'remote_access', 'remote-access classification');

printf("OK %d SFTP endpoint/diagnostic assertions\n", $checks);
