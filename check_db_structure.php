<?php
require_once 'config/config.php';

$conn = getConnection();

// Check if database exists
$db_selected = $conn->select_db(DB_NAME);
if (!$db_selected) {
    die("Error: Database '" . DB_NAME . "' doesn't exist.\n");
}

// Function to check table structure
function checkTable($conn, $tableName) {
    echo "<h3>Table: $tableName</h3>";
    
    // Check if table exists
    $result = $conn->query("SHOW TABLES LIKE '$tableName'");
    if ($result->num_rows === 0) {
        echo "<div class='alert alert-danger'>Table '$tableName' does not exist.</div>";
        return;
    }
    
    // Show table structure
    echo "<table class='table table-bordered table-striped'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    
    $columns = $conn->query("SHOW COLUMNS FROM $tableName");
    while ($col = $columns->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($col['Field']) . "</td>";
        echo "<td>" . htmlspecialchars($col['Type']) . "</td>";
        echo "<td>" . htmlspecialchars($col['Null']) . "</td>";
        echo "<td>" . htmlspecialchars($col['Key']) . "</td>";
        echo "<td>" . htmlspecialchars($col['Default'] ?? 'NULL') . "</td>";
        echo "<td>" . htmlspecialchars($col['Extra']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Show sample data (first 2 rows)
    echo "<h5>Sample Data (first 2 rows):</h5>";
    $data = $conn->query("SELECT * FROM $tableName LIMIT 2");
    if ($data && $data->num_rows > 0) {
        echo "<pre>";
        while ($row = $data->fetch_assoc()) {
            print_r($row);
            echo "\n";
        }
        echo "</pre>";
    } else {
        echo "<div class='alert alert-warning'>No data found in table '$tableName'</div>";
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Structure Check</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-4">
        <h1>Database Structure Check</h1>
        <div class="alert alert-info">
            <strong>Database:</strong> <?php echo DB_NAME; ?><br>
            <strong>Tables found:</strong> 
            <?php 
            $tables = $conn->query("SHOW TABLES");
            $tableList = [];
            while ($table = $tables->fetch_array()) {
                $tableList[] = $table[0];
            }
            echo implode(', ', $tableList);
            ?>
        </div>
        
        <?php
        // Check important tables
        $tablesToCheck = ['transaksi', 'pelanggan', 'users', 'barang'];
        foreach ($tablesToCheck as $table) {
            checkTable($conn, $table);
            echo "<hr>";
        }
        ?>
    </div>
</body>
</html>
<?php $conn->close(); ?>
