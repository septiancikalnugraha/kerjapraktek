<?php
require_once __DIR__ . '/config/config.php';

$conn = getConnection();

// Check orders table columns
$result = $conn->query("SHOW COLUMNS FROM orders");
if ($result) {
    echo "Columns in orders table:\n";
    while ($row = $result->fetch_assoc()) {
        echo $row['Field'] . ' (' . $row['Type'] . ')' . "\n";
    }
} else {
    echo "Error: " . $conn->error . "\n";
}

$conn->close();
?>
