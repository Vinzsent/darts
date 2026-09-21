<?php
include 'includes/db.php';

echo "<h2>Debug: Employees Table Contents</h2>";

// Check table structure
$result = $conn->query("DESCRIBE employees");
echo "<h3>Table Structure:</h3>";
echo "<table border='1'>";
echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
while ($row = $result->fetch_assoc()) {
    echo "<tr>";
    foreach ($row as $value) {
        echo "<td>" . htmlspecialchars($value ?? '') . "</td>";
    }
    echo "</tr>";
}
echo "</table>";

// Show all usernames
$result = $conn->query("SELECT id, username, email, user_type FROM employees ORDER BY id");
echo "<h3>All Users / Employees:</h3>";
echo "<table border='1'>";
echo "<tr><th>ID</th><th>Username</th><th>Email</th><th>User Type</th></tr>";
while ($row = $result->fetch_assoc()) {
    echo "<tr>";
    echo "<td>" . htmlspecialchars($row['id']) . "</td>";
    echo "<td>" . htmlspecialchars($row['username'] ?? 'NULL') . "</td>";
    echo "<td>" . htmlspecialchars($row['email'] ?? 'NULL') . "</td>";
    echo "<td>" . htmlspecialchars($row['user_type'] ?? 'NULL') . "</td>";
    echo "</tr>";
}
echo "</table>";
?>
