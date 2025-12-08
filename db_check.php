<?php
// db_check.php
require_once __DIR__ . '/config/config.php';
$conn = getConnection();

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

echo "Successfully connected to database: " . DB_NAME . "<br><br>";

// Get list of tables
$tables = $conn->query("SHOW TABLES");
$tables_list = [];
$i = 1;

echo "<h2>Database Tables:</h2>";
while($table = $tables->fetch_array()) {
    $tableName = $table[0];
    $tables_list[] = $tableName;
    echo "$i. $tableName<br>";
    
    // Get table structure
    $result = $conn->query("DESCRIBE $tableName");
    echo "<pre>";
    echo "Structure of $tableName:\n";
    while($row = $result->fetch_assoc()) {
        echo "  " . str_pad($row['Field'], 20) . " | " . 
             str_pad($row['Type'], 15) . " | " . 
             str_pad($row['Null'], 4) . " | " . 
             str_pad($row['Key'], 3) . " | " . 
             ($row['Default'] ?? 'NULL') . " | " . 
             ($row['Extra'] ?? '') . "\n";
    }
    echo "</pre>";
    
    // Get row count
    $count = $conn->query("SELECT COUNT(*) as count FROM $tableName")->fetch_assoc()['count'];
    echo "Total records: $count<br><br>";
    $i++;
}

$conn->close();
?>