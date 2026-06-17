<?php
$files = scandir(__DIR__.'/database/migrations');
foreach ($files as $file) {
    if (str_ends_with($file, '.php')) {
        $content = file_get_contents(__DIR__.'/database/migrations/'.$file);
        if (preg_match_all('/Schema::create\(\'([^\']+)\'/', $content, $matches)) {
            foreach ($matches[1] as $table) {
                echo "Migration: {$file} -> Creates: {$table}\n";
            }
        }
    }
}
