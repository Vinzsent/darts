<?php
include '../includes/auth.php';
include '../includes/db.php';

$user_id = $_SESSION['user_id'] ?? $_SESSION['user']['id'] ?? null;

if (!$user_id || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../dashboard.php');
    exit;
}

$tab = $_POST['tab'] ?? '';

// ─── PERSONAL INFO — REMOVED ────────────────────────────────────────────────
// This tab used to write user_type / department straight from POST data, which
// let any logged-in user promote themselves to another role. Name, title and
// department are now read-only and are maintained by an administrator through
// pages/users.php. Any request naming this tab is rejected outright.
if ($tab === 'personal') {
    $_SESSION['profile_error'] = 'Personal details are managed by an administrator. '
        . 'You can only change your username and password.';
    header('Location: ../pages/profile.php?tab=account');
    exit;
}

// ─── ACCOUNT & SECURITY ─────────────────────────────────────────────────────
if ($tab === 'account') {
    $username         = trim($_POST['username']         ?? '');
    $current_password = $_POST['current_password']      ?? '';
    $new_password     = $_POST['new_password']          ?? '';
    $confirm_password = $_POST['confirm_password']      ?? '';

    if (empty($username)) {
        $_SESSION['profile_error'] = 'Username is required.';
        header('Location: ../pages/profile.php?tab=account');
        exit;
    }

    // Login (index.php) authenticates against `user`, but this file previously
    // only knew about `employees`. Resolve which table actually holds this account
    // first, so the uniqueness check and the UPDATE both target the same rows the
    // user logs in with. On a deployment where `user` is a real table this matters.
    $user_table = null;
    foreach (['user', 'employees'] as $candidate) {
        $probe = $conn->prepare("SELECT id FROM `{$candidate}` WHERE id = ? LIMIT 1");
        if (!$probe) {
            continue;
        }
        $probe->bind_param("i", $user_id);
        $probe->execute();
        $found = $probe->get_result()->fetch_assoc();
        $probe->close();
        if ($found) {
            $user_table = $candidate;
            break;
        }
    }

    if ($user_table === null) {
        $_SESSION['profile_error'] = 'Your account could not be found. Please log in again.';
        header('Location: ../pages/profile.php');
        exit;
    }

    // Check username uniqueness (excluding self)
    $check = $conn->prepare("SELECT id FROM `{$user_table}` WHERE username = ? AND id != ?");
    $check->bind_param("si", $username, $user_id);
    $check->execute();
    $check->store_result();
    if ($check->num_rows > 0) {
        $_SESSION['profile_error'] = 'Username already taken. Please choose another.';
        $check->close();
        header('Location: ../pages/profile.php?tab=account');
        exit;
    }
    $check->close();

    // If changing password
    if (!empty($new_password)) {
        if (empty($current_password)) {
            $_SESSION['profile_error'] = 'Current password is required to set a new one.';
            header('Location: ../pages/profile.php?tab=account');
            exit;
        }
        if ($new_password !== $confirm_password) {
            $_SESSION['profile_error'] = 'New password and confirmation do not match.';
            header('Location: ../pages/profile.php?tab=account');
            exit;
        }
        if (strlen($new_password) < 6) {
            $_SESSION['profile_error'] = 'New password must be at least 6 characters.';
            header('Location: ../pages/profile.php?tab=account');
            exit;
        }

        // Verify current password
        $pw_check = $conn->prepare("SELECT password FROM `{$user_table}` WHERE id = ?");
        $pw_check->bind_param("i", $user_id);
        $pw_check->execute();
        $pw_check->bind_result($hashed);
        $pw_check->fetch();
        $pw_check->close();

        if (!password_verify($current_password, $hashed)) {
            $_SESSION['profile_error'] = 'Current password is incorrect.';
            header('Location: ../pages/profile.php?tab=account');
            exit;
        }

        $new_hashed = password_hash($new_password, PASSWORD_BCRYPT);

        $stmt = $conn->prepare("UPDATE `{$user_table}` SET username = ?, password = ? WHERE id = ?");
        $stmt->bind_param("ssi", $username, $new_hashed, $user_id);
    } else {
        // Only update username
        $stmt = $conn->prepare("UPDATE `{$user_table}` SET username = ? WHERE id = ?");
        $stmt->bind_param("si", $username, $user_id);
    }

    if ($stmt->execute()) {
        $_SESSION['username'] = $username;
        $_SESSION['profile_success'] = 'Account updated successfully.';
    } else {
        $_SESSION['profile_error'] = 'Failed to update account. Please try again.';
    }
    $stmt->close();

    header('Location: ../pages/profile.php?tab=account');
    exit;
}

header('Location: ../pages/profile.php');
exit;
