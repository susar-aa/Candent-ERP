<?php
require_once 'config/db.php';

try {
    $stmt = $pdo->query("DESCRIBE customers");
    echo "=== CUSTOMERS ===\n";
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

    $stmt = $pdo->query("DESCRIBE routes");
    echo "\n=== ROUTES ===\n";
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
