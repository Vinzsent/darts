<?php
// Forge a logged-in session file (usable over HTTP) so the e2e pagination
// test can run without knowing any account password.
require_once __DIR__ . '/../includes/db.php';

$u = $conn->query("SELECT * FROM `user` WHERE username = 'admin' LIMIT 1")->fetch_assoc();
if (!$u) {
    $u = $conn->query("SELECT * FROM `user` LIMIT 1")->fetch_assoc();
}
if (!$u) {
    fwrite(STDERR, "No user rows found\n");
    exit(1);
}

// Session id must be lowercase within the 0-9a-v alphabet (sid_bits=5) and
// 26 chars (sid_length) or PHP silently rejects it and starts a fresh session.
$id = 'darts' . substr(md5($u['id'] . $u['username']), 0, 21);
session_id($id);
session_start();

$_SESSION['user'] = $u;
$_SESSION['user_id'] = $u['id'];
$_SESSION['id'] = $u['id'];
$_SESSION['user_type'] = $u['user_type'];
$_SESSION['username'] = $u['username'];
$_SESSION['name'] = $u['name'] ?? ($u['username'] ?? '');

session_write_close();

echo "session_id=$id\n";
echo 'save_path=' . session_save_path() . "\n";
echo "user={$u['username']} type={$u['user_type']}\n";
