<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$logs = \App\Models\RequestLog::where('response_status', 500)->orWhere('method', 'PUT')->orderBy('id', 'desc')->get();
if ($logs->isEmpty()) {
    echo "No 500 or PUT logs found.\n";
}
foreach ($logs as $log) {
    echo "ID: {$log->id} | URL: {$log->url} | Method: {$log->method} | Status: {$log->response_status}\n";
    echo "Headers: " . json_encode($log->headers) . "\n";
    echo "Body: " . json_encode($log->request_body) . "\n";
    echo "Response: " . substr($log->response_body, 0, 500) . "\n";
    echo "---------------------------------------------------\n";
}
