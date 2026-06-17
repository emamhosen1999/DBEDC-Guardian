<?php
$databases = [
    'aeos365', 'aeos365_standalone_uat', 'aeos365_uat', 'aeos_standalone',
    'dbedc_file_handling', 'dbedc_guardian', 'tenant0cc7bc69-c89c-472f-a713-545efa6f5b68',
    'tenant13b8c0b5-7647-4385-a23b-bc08064a3941', 'tenant1bb777a8-2a6c-4a2a-9233-044bf3843955',
    'tenant3e41037e-4690-42b9-bf0a-86a8119101df', 'tenant8d66e35b-2f61-4b63-b05a-1e6136f30a8a',
    'tenanta1dabfb5-3114-4a4e-a9ef-de9dbf1c9e06', 'tenanta5b31739-605d-4d0d-9ab2-26eeb5e100e6',
    'tenante0c5f77e-4e5c-414e-8240-4eb572e6de52', 'tenante94d94f6-b82c-4104-9e1d-9cd8602f2372',
    'tenanted4c807a-d37d-4d31-95ce-10e7c0e346e0', 'tenantf9183153-3028-4ca0-9b41-867a2bc63024'
];

foreach ($databases as $db) {
    try {
        $pdo = new PDO("mysql:host=127.0.0.1;dbname=$db", "root", "");
        $stmt = $pdo->query("SHOW TABLES LIKE '%attend%'");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (count($tables) > 0) {
            echo "Database: $db\n";
            foreach ($tables as $t) {
                $count = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
                echo "  Table: $t | Count: $count\n";
            }
        }
    } catch (Exception $e) {
        // echo "$db failed\n";
    }
}
