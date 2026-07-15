<?php
$lines = file('storage/logs/laravel.log');
$trace = [];
for ($i = count($lines) - 1; $i >= 0; $i--) {
    $line = $lines[$i];
    $trace[] = $line;
    if (preg_match('/^\[\d{4}-\d{2}-\d{2}/', $line)) {
        break;
    }
}
echo implode("", array_reverse($trace));
