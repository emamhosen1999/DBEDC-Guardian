<?php
try {
    $pdo = new PDO("mysql:host=127.0.0.1", "root", "");
    
    // Get all databases
    $dbs = $pdo->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN);
    echo "ALL DATABASES:\n";
    foreach ($dbs as $db) {
        if (in_array($db, ['information_schema', 'performance_schema', 'mysql', 'sys'])) {
            continue;
        }
        echo "- {$db}\n";
        
        try {
            $dbPdo = new PDO("mysql:host=127.0.0.1;dbname={$db}", "root", "");
            
            // Check for project tables
            $stmt = $dbPdo->query("SHOW TABLES LIKE '%project%'");
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (count($tables) > 0) {
                echo "  Has project tables:\n";
                foreach ($tables as $t) {
                    echo "    * {$t}\n";
                }
            }
            
            // Check for attendance tables
            $stmt = $dbPdo->query("SHOW TABLES LIKE '%attend%'");
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (count($tables) > 0) {
                echo "  Has attendance tables:\n";
                foreach ($tables as $t) {
                    echo "    * {$t}\n";
                }
            }
        } catch (Exception $e) {
            echo "  Error: " . $e->getMessage() . "\n";
        }
    }
} catch (Exception $e) {
    echo "Master connection error: " . $e->getMessage() . "\n";
}
