<?php
require __DIR__ . '/auth.php';

if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit(); }

$uid    = (int)$_SESSION['user_id'];
$role   = $_SESSION['role'] ?? 'teacher';
$forced = !empty($_SESSION['must_change']);
$home   = $role === 'admin' ? 'dashboard.php' : 'teacher_dashboard.php';
$error  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $current = $_POST['current'] ?? '';
    $new     = $_POST['new'] ?? '';
    $confirm = $_POST['confirm'] ?? '';

    $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->bind_param("i", $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $hash = $row['password'] ?? '';

    if (!$forced && !password_verify($current, $hash)) {
        $error = "Your current password is incorrect.";
    } elseif ($new !== $confirm) {
        $error = "The new passwords do not match.";
    } elseif (password_verify($new, $hash)) {
        $error = "Your new password must be different from the temporary/current one.";
    } else {
        $problems = password_problems($new);
        if ($problems) {
            $error = "Weak password. Missing: " . implode('; ', $problems) . ".";
        } else {
            $newHash = password_hash($new, PASSWORD_DEFAULT);
            $up = $conn->prepare("UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?");
            $up->bind_param("si", $newHash, $uid);
            if ($up->execute()) {
                $_SESSION['must_change'] = 0;
                session_regenerate_id(true);
                header("Location: " . $home);
                exit();
            }
            $error = "Could not update password: " . $conn->error;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Change Password</title>
<style>
    body { font-family: Arial, sans-serif; background:#f3f4f6; display:flex; justify-content:center; align-items:center; min-height:100vh; margin:0; }
    .box { background:#fff; padding:2.2rem; border-radius:12px; box-shadow:0 4px 6px rgba(0,0,0,.1); width:100%; max-width:380px; }
    h2 { margin:0 0 .4rem; text-align:center; }
    .note { background:#eff6ff; color:#1e3a8a; padding:.7rem; border-radius:6px; font-size:.85rem; margin-bottom:1rem; }
    .alert { background:#fee2e2; color:#991b1b; padding:.7rem; border-radius:6px; margin-bottom:1rem; font-size:.88rem; }
    label { display:block; margin:0 0 .4rem; font-size:.9rem; color:#4b5563; }
    input { width:100%; padding:.7rem; border:1px solid #d1d5db; border-radius:6px; box-sizing:border-box; margin-bottom:1rem; }
    button { width:100%; padding:.75rem; background:#4f46e5; color:#fff; border:0; border-radius:6px; font-weight:bold; cursor:pointer; }
    button:hover { background:#4338ca; }
    .rules { font-size:.8rem; color:#6b7280; margin:-.5rem 0 1rem; }
    a { color:#4f46e5; font-size:.85rem; }
</style>
</head>
<body>
<div class="box">
    <h2>Change Password</h2>
    <?php if ($forced): ?>
        <div class="note"><strong>Welcome!</strong> You are using a temporary password given by the administrator. Please choose your own password before continuing.</div>
    <?php endif; ?>
    <?php if ($error): ?><div class="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="POST">
        <?= csrf_field() ?>
        <?php if (!$forced): ?>
            <label>Current password</label><input type="password" name="current" required>
        <?php endif; ?>
        <label>New password</label><input type="password" name="new" required>
        <div class="rules">At least 8 characters, with upper and lower case letters and a special character.</div>
        <label>Confirm new password</label><input type="password" name="confirm" required>
        <button type="submit"><?= $forced ? 'Save and continue' : 'Change password' ?></button>
    </form>
    <p style="text-align:center;margin-bottom:0">
        <?php if ($forced): ?><a href="logout.php">Cancel and log out</a><?php else: ?><a href="<?= e($home) ?>">&larr; Back</a><?php endif; ?>
    </p>
</div>
</body>
</html>
