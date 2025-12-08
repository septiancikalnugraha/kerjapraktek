<?php
require_once 'config/config.php';

$conn = getConnection();
$result = $conn->query("SHOW COLUMNS FROM users");

if ($result) {
    echo "<h2>Users Table Structure:</h2>";
    echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
    echo "<tr style='background-color: #f2f2f2;'><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td style='padding: 8px; border: 1px solid #ddd;'>" . htmlspecialchars($row['Field']) . "</td>";
        echo "<td style='padding: 8px; border: 1px solid #ddd;'>" . htmlspecialchars($row['Type']) . "</td>";
        echo "<td style='padding: 8px; border: 1px solid #ddd;'>" . htmlspecialchars($row['Null']) . "</td>";
        echo "<td style='padding: 8px; border: 1px solid #ddd;'>" . htmlspecialchars($row['Key']) . "</td>";
        echo "<td style='padding: 8px; border: 1px solid #ddd;'>" . htmlspecialchars($row['Default'] ?? 'NULL') . "</td>";
        echo "<td style='padding: 8px; border: 1px solid #ddd;'>" . htmlspecialchars($row['Extra']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Also show sample data
    echo "<h2>Sample Data (first 5 rows):</h2>";
    $sample = $conn->query("SELECT * FROM users LIMIT 5");
    if ($sample && $sample->num_rows > 0) {
        echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
        // Header
        $fields = [];
        echo "<tr style='background-color: #f2f2f2;'>";
        while ($finfo = $sample->fetch_field()) {
            echo "<th style='padding: 8px; border: 1px solid #ddd;'>" . htmlspecialchars($finfo->name) . "</th>";
            $fields[] = $finfo->name;
        }
        echo "</tr>";
        
        // Data
        $sample->data_seek(0);
        while ($row = $sample->fetch_assoc()) {
            echo "<tr>";
            foreach ($fields as $field) {
                echo "<td style='padding: 8px; border: 1px solid #ddd;'>" . htmlspecialchars($row[$field] ?? 'NULL') . "</td>";
            }
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "No data found in users table or error: " . $conn->error;
    }
    
} else {
    echo "Error: " . $conn->error;
}

$conn->close();
?>
