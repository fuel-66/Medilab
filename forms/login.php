<?php
session_start();
include 'connection.php';
include 'csrf.php';

$error = "";

// If already logged in, redirect
if (isset($_SESSION['parent_id']))       header("Location: parent.php");
if (isset($_SESSION['hospital_id']))     header("Location: hospital.php");
if (isset($_SESSION['admin_id']))        header("Location: dashboard.php");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrf_verify($_POST['csrf_token'] ?? "")) {
        $error = "Security validation failed.";
    } else {

        $email = trim($_POST['email'] ?? "");
        $password = trim($_POST['password'] ?? "");
        $role = trim($_POST['role'] ?? "");

        if ($email === "" || $password === "" || $role === "") {
            $error = "All fields are required.";
        }
        else {

            // Select table according to role
            if ($role === 'parent') {
                $table = "parents";
            } elseif ($role === 'hospital') {
                $table = "hospitals";
            } elseif ($role === 'admin') {
                $table = "admins";
            } else {
                $table = null;
            }

            if (!$table) {
                $error = "Invalid role selected.";
            } else {

                $stmt = $conn->prepare("SELECT id, password, email, name FROM $table WHERE email = ? LIMIT 1");
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result && $result->num_rows) {
                    $row = $result->fetch_assoc();
                    $dbpass = $row['password'];

                    $valid = (strlen($dbpass) > 20 && password_verify($password, $dbpass))
                             || $password === $dbpass;

                    if ($valid) {
                        // Log user in
                        if ($role === 'parent') $_SESSION['parent_id'] = $row['id'];
                        if ($role === 'hospital') $_SESSION['hospital_id'] = $row['id'];
                        if ($role === 'admin') $_SESSION['admin_id'] = $row['id'];

                        // Also set generic session keys expected by dashboard.php
                        $_SESSION['user_id'] = $row['id'];
                        $_SESSION['user_role'] = $role;
                        $_SESSION['user_name'] = $row['name'];

                        if ($role === 'admin') {
                            $redirect = "/Medilab/forms/dashboard.php";
                        } elseif ($role === 'hospital') {
                            $redirect = "/Medilab/forms/hospital.php";
                        } else {
                            $redirect = "/Medilab/forms/parent.php";
                        }
                        header("Location: " . $redirect);
                        exit;
                    } else {
                        $error = "Incorrect password.";
                    }
                } else {
                    $error = "Account not found.";
                }

                $stmt->close();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Medical</title>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        :root {
            --primary: #0066FF;
            --primary-dark: #0052CC;
            --primary-light: #3385FF;
            --secondary: #00D4AA;
            --accent: #FF6B35;
            --dark: #0A0F1C;
            --dark-light: #1A2332;
            --light: #F8FAFF;
            --gray: #64748B;
            --success: #10B981;
            --warning: #F59E0B;
            --error: #EF4444;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: linear-gradient(135deg, #0A0F1C 0%, #1A2332 100%);
            color: var(--dark);
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }
        
        .login-container {
            width: 100%;
            max-width: 440px;
        }
        
        .login-card {
            background: white;
            border-radius: 24px;
            padding: 3rem;
            box-shadow: 0 32px 64px rgba(0, 0, 0, 0.2);
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 2.5rem;
        }
        
        .login-logo {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }
        
        .logo-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
            font-weight: 700;
        }
        
        .logo-text {
            font-family: 'Manrope', sans-serif;
            font-weight: 800;
            font-size: 1.5rem;
            background: linear-gradient(135deg, var(--dark), var(--primary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .login-title {
            font-family: 'Manrope', sans-serif;
            font-size: 1.75rem;
            font-weight: 800;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }
        
        .login-subtitle {
            color: var(--gray);
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--dark);
        }
        
        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid rgba(226, 232, 240, 0.8);
            border-radius: 12px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(0, 102, 255, 0.1);
        }
        
        .form-select {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid rgba(226, 232, 240, 0.8);
            border-radius: 12px;
            font-size: 1rem;
            background: white;
            cursor: pointer;
        }
        
        .login-btn {
            width: 100%;
            padding: 0.75rem;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-bottom: 1.5rem;
        }
        
        .login-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0, 102, 255, 0.4);
        }
        
        .login-footer {
            text-align: center;
            color: var(--gray);
        }
        
        .login-link {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
        }
        
        .login-link:hover {
            text-decoration: underline;
        }
        
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            font-weight: 600;
        }
        
        .alert-error {
            background: rgba(220, 38, 38, 0.1);
            color: #b91c1c;
            border-left: 4px solid #dc2626;
            border-radius: 6px;
        }
        
        .alert-success {
            background: rgba(34, 197, 94, 0.1);
            color: #15803d;
            border-left: 4px solid #22c55e;
            border-radius: 6px;
        }
        
        @media (max-width: 768px) {
            .login-card {
                padding: 2rem;
            }
            
            body {
                padding: 1rem;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <div class="login-logo">
                    <div class="logo-icon">M</div>
                    <div class="logo-text">Medical</div>
                </div>
                <h1 class="login-title">Welcome Back</h1>
                <p class="login-subtitle">Sign in to your account</p>
            </div>
            
            <?php if (!empty($error)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <?php echo csrf_field(); ?>
                <div class="form-group">
                    <label for="role" class="form-label">I am a</label>
                    <select id="role" name="role" class="form-select" required>
                        <option value="">Select Role</option>
                        <option value="hospital">Hospital Staff</option>
                        <option value="parent">Parent/Guardian</option>
                        <option value="admin">Administration</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="email" class="form-label">Email Address</label>
                    <input type="email" id="email" name="email" class="form-control" placeholder="Enter your email" required>
                </div>
                
                <div class="form-group">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" id="password" name="password" class="form-control" placeholder="Enter your password" required>
                </div>
                
                <button type="submit" class="login-btn">
                    <i class="fas fa-sign-in-alt"></i>
                    Sign In
                </button>
            </form>
            
            <div class="login-footer">
                Don't have an account? <a href="register.php" class="login-link">Sign up here</a>
            </div>
        </div>
    </div>
</body>
</html>