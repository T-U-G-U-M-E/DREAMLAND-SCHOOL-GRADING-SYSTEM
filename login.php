<?php
session_start();
require __DIR__ . '/db.php';

$message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $user = trim($_POST['username'] ?? '');
    $pass = $_POST['password'] ?? '';

    if (!empty($user) && !empty($pass)) {
        if (!$conn || $conn->connect_error) {
            $message = "Database connection error.";
        } else {
            $stmt = $conn->prepare("SELECT id, password, role, must_change_password FROM users WHERE username = ?");
            if ($stmt) {
                $stmt->bind_param("s", $user);
                $stmt->execute();
                $stmt->store_result();

                if ($stmt->num_rows > 0) {
                    $stmt->bind_result($id, $hashed_password, $role, $must_change);
                    $stmt->fetch();
                    $stmt->close();

                    if (password_verify($pass, $hashed_password)) {
                        if ($role === 'teacher') {
                            // Teacher: must be linked to an ACTIVE teacher record
                            $t = $conn->prepare("SELECT id FROM teachers WHERE user_id = ? AND status = 'Active'");
                            $t->bind_param("i", $id);
                            $t->execute();
                            $teacher = $t->get_result()->fetch_assoc();
                            $t->close();

                            if (!$teacher) {
                                $message = "This teacher account is not active. Please contact the administrator.";
                            } else {
                                session_regenerate_id(true);
                                $_SESSION['user_id']    = $id;
                                $_SESSION['username']   = $user;
                                $_SESSION['role']       = 'teacher';
                                $_SESSION['teacher_id'] = (int)$teacher['id'];
                                $_SESSION['must_change'] = (int)$must_change;

                                header("Location: " . ($must_change ? "change_password.php" : "teacher_dashboard.php"));
                                exit();
                            }
                        } else {
                            session_regenerate_id(true);
                            $_SESSION['user_id']  = $id;
                            $_SESSION['admin_id'] = $id;
                            $_SESSION['username'] = $user;
                            $_SESSION['role']     = 'admin';
                            $_SESSION['must_change'] = (int)$must_change;

                            header("Location: " . ($must_change ? "change_password.php" : "dashboard.php"));
                            exit();
                        }
                    } else {
                        $message = "Invalid password.";
                    }
                } else {
                    $stmt->close();
                    $message = "Username not found.";
                }
            } else {
                $message = "Database SQL error: " . htmlspecialchars($conn->error) . " (have you run teacher_portal.sql?)";
            }
        }
    } else {
        $message = "Please fill in all fields.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
    <style>
        body { font-family: Arial, sans-serif; background-color: #f3f4f6; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .login-container { background: white; padding: 2.5rem; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); width: 100%; max-width: 350px; }
        h2 { text-align: center; margin-bottom: 0.3rem; }
        .sub { text-align:center; color:#6b7280; font-size:.85rem; margin-bottom:1.5rem; }
        .alert { background-color: #fee2e2; color: #991b1b; padding: 0.75rem; border-radius: 6px; margin-bottom: 1rem; text-align: center; font-size: 0.9rem; }
        .form-group { margin-bottom: 1.25rem; }
        label { display: block; margin-bottom: 0.5rem; font-size: 0.9rem; color: #4b5563; }
        input { width: 100%; padding: 0.75rem; border: 1px solid #d1d5db; border-radius: 6px; box-sizing: border-box; }
        button { width: 100%; padding: 0.75rem; background-color: #4f46e5; color: white; border: none; border-radius: 6px; font-weight: bold; cursor: pointer; margin-top: 0.5rem; }
        button:hover { background-color: #4338ca; }
    </style>
</head>
<body>
<div class="login-container">
    <h2>Login</h2>
    <div class="sub">Administrators and Teachers</div>
    <?php if (!empty($message)): ?>
        <div class="alert"><?php echo $message; ?></div>
    <?php endif; ?>
    <form method="POST" action="login.php">
        <div class="form-group">
            <label for="username">Username</label>
            <input type="text" id="username" name="username" required autocomplete="off">
        </div>
        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required>
        </div>
        <button type="submit">Login</button>
    </form>
    <p style="text-align:center; margin-top:1.5rem; font-size:0.8rem; color:#6b7280;">
        Teachers: use the username and password given by the administrator.
    </p>
</div>
</body>
</html>
