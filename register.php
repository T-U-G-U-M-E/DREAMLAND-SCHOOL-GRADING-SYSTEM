<?php
require __DIR__ . '/db.php';

// 1. PASSWORD VALIDATION FUNCTION
function checkPasswordStrength($password) {
    $missing_criteria = [];

    if (strlen($password) < 8) {
        $missing_criteria[] = "At least 8 characters long";
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $missing_criteria[] = "At least one uppercase letter (A-Z)";
    }
    if (!preg_match('/[a-z]/', $password)) {
        $missing_criteria[] = "At least one lowercase letter (a-z)";
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        $missing_criteria[] = "At least one special character";
    }

    if (empty($missing_criteria)) {
        return ['status' => 'strong'];
    } else {
        return ['status' => 'weak', 'missing' => $missing_criteria];
    }
}

$message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $user = trim($_POST['username'] ?? '');
    $pass = $_POST['password'] ?? '';

    if (!empty($user) && !empty($pass)) {
        
        // 2. RUN PASSWORD STRENGTH CHECK
        $password_result = checkPasswordStrength($pass);

        if ($password_result['status'] === 'weak') {
            // Build a helpful error message listing what's missing
            $message = "<strong>Weak Password!</strong> Missing requirements:<br>";
            $message .= "<ul style='text-align: left; margin-left: 20px; font-size: 0.85rem;'>";
            foreach ($password_result['missing'] as $rule) {
                $message .= "<li>" . htmlspecialchars($rule) . "</li>";
            }
            $message .= "</ul>";
        } else {
            // 3. PROCEED ONLY IF PASSWORD IS STRONGER
            // Hash the password securely
            $hashed_password = password_hash($pass, PASSWORD_DEFAULT);

            // Use a prepared statement to prevent SQL injection
            $stmt = $conn->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
            $stmt->bind_param("ss", $user, $hashed_password);

            if ($stmt->execute()) {
                $message = "Registration successful!";
            } else {
                $message = "Error: Username might already exist.";
            }

            $stmt->close();
        }
    } else {
        $message = "Please fill in all fields.";
    }
}
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Registration</title>
    <style>
        :root {
            --primary-color: #4f46e5;
            --primary-hover: #4338ca;
            --background: #f3f4f6;
            --card-bg: #ffffff;
            --text-main: #1f2937;
            --text-muted: #4b5563;
            --border: #d1d5db;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: var(--background);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            color: var(--text-main);
        }

        .register-container {
            background-color: var(--card-bg);
            padding: 2.5rem;
            border-radius: 12px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 400px;
        }

        h2 {
            margin-bottom: 1.5rem;
            font-size: 1.75rem;
            font-weight: 600;
            text-align: center;
        }

        .alert {
            padding: 0.75rem 1rem;
            border-radius: 6px;
            margin-bottom: 1.25rem;
            font-size: 0.9rem;
            text-align: center;
        }

        .alert-info {
            background-color: #eff6ff;
            color: #1e40af;
            border: 1px solid #bfdbfe;
        }

        .form-group {
            margin-bottom: 1.25rem;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--text-muted);
        }

        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.2s ease;
            outline: none;
        }

        input[type="text"]:focus,
        input[type="password"]:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15);
        }

        button {
            width: 100%;
            padding: 0.75rem;
            background-color: var(--primary-color);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s ease;
            margin-top: 0.5rem;
        }

        button:hover {
            background-color: var(--primary-hover);
        }
    </style>
</head>
<body>

    <div class="register-container">
        <h2>Register</h2>
        
        <?php if (!empty($message)): ?>
            <div class="alert alert-info">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="register.php">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" value="<?php echo isset($user) ? htmlspecialchars($user) : ''; ?>" required autocomplete="off">
            </div>
            
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>
            
            <button type="submit">Register</button>
        </form>

        <p style="text-align:center; margin-top:1.5rem; font-size:0.875rem;">
            Already have an account? <a href="login.php">Login here</a>
        </p>
    </div>

</body>
</html>
