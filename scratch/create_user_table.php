<?php
require_once __DIR__ . '/../includes/db.php';

$sql = "CREATE TABLE IF NOT EXISTS `user` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(50) DEFAULT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `suffix` varchar(50) DEFAULT NULL,
  `academic_title` varchar(100) DEFAULT NULL,
  `user_type` varchar(100) DEFAULT NULL,
  `department` varchar(100) DEFAULT NULL,
  `username` varchar(100) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username_unique` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";

if ($conn->query($sql) === TRUE) {
    echo "Table 'user' created or already exists successfully.\n";
} else {
    echo "Error creating table: " . $conn->error . "\n";
    exit;
}

// Check if any admin exists
$check = $conn->query("SELECT COUNT(*) as cnt FROM `user`");
$count = $check ? $check->fetch_assoc()['cnt'] : 0;

if ($count == 0) {
    // Insert default admin and test users with hashed password 'password' or 'admin123'
    $adminPassword = password_hash('admin123', PASSWORD_DEFAULT);
    $userPassword = password_hash('password', PASSWORD_DEFAULT);

    $insert = "INSERT INTO `user` (`title`, `first_name`, `middle_name`, `last_name`, `suffix`, `academic_title`, `user_type`, `department`, `username`, `email`, `password`) VALUES
    ('Mr.', 'Admin', '', 'User', '', 'MIT', 'Admin', 'MIS / IT Department', 'admin', 'admin@gmail.com', '$adminPassword'),
    ('Engr.', 'Vincent', 'Pogi', 'Crame', '', 'BSIT', 'Admin', 'MIS Department', 'vincent', 'vincentcrame7@gmail.com', '$adminPassword'),
    ('Dr.', 'Robert', '', 'James', '', 'Ph.D', 'School President', 'President Office', 'president', 'robert@gmail.com', '$userPassword'),
    ('Mr.', 'Jane', '', 'Doe', '', '', 'Supply In-charge', 'Supply Office', 'supply', 'jane@gmail.com', '$userPassword'),
    ('Ms.', 'Emily', '', 'Charles', '', '', 'Purchasing Officer', 'Purchasing Office', 'purchasing', 'emily@gmail.com', '$userPassword'),
    ('Dr.', 'Cinna', '', 'Rose', '', 'M.D', 'VP for Finance & Administration', 'Finance Office', 'finance', 'cinna@gmail.com', '$userPassword'),
    ('Mr.', 'Staff', 'Staff', 'Staff', '', 'Staff', 'Staff', 'General Services', 'staff', 'staff@gmail.com', '$userPassword');";

    if ($conn->query($insert) === TRUE) {
        echo "Default users inserted successfully.\n";
    } else {
        echo "Error inserting default users: " . $conn->error . "\n";
    }
} else {
    echo "Users already exist ($count user(s)).\n";
}
