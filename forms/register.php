<?php
session_start();
include 'connection.php';
include 'csrf.php';

$error = '';
$success = '';

// Real registration: insert into parents/hospitals/admins depending on role
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $error = 'Security validation failed.';
    } else {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $confirm_password = trim($_POST['confirm_password'] ?? '');
        $role = trim($_POST['role'] ?? '');
        $phone = trim($_POST['phone'] ?? '');

        if ($name === '' || $email === '' || $password === '' || $confirm_password === '' || $role === '' ) {
            $error = 'All fields are required.';
        } elseif ($password !== $confirm_password) {
            $error = 'Passwords do not match.';
        } elseif (!in_array($role, ['parent','hospital'], true)) {
            $error = 'Invalid role selected.';
        } else {
            // Automatically add prefix to name based on role
            if ($role === 'hospital') {
                $display_name = 'Hospital Medilab / ' . $name;
            } elseif ($role === 'parent') {
                $display_name = 'Dr. ' . $name;
            } else {
                $display_name = $name;
            }

            // Map role to table
            $table = $role === 'parent' ? 'parents' : ($role === 'hospital' ? 'hospitals' : 'admins');

            // Check email uniqueness
            $check = $conn->prepare("SELECT id FROM $table WHERE email = ? LIMIT 1");
            $check->bind_param('s', $email);
            $check->execute();
            $res = $check->get_result();
            if ($res && $res->num_rows > 0) {
                $error = 'An account with that email already exists.';
                $check->close();
            } else {
                $check->close();
                $password_hash = password_hash($password, PASSWORD_DEFAULT);

                $stmt = $conn->prepare("INSERT INTO $table (name, email, password, phone) VALUES (?, ?, ?, ?)");
                if (!$stmt) {
                    $error = 'DB prepare failed: ' . $conn->error;
                } else {
                    $phone_param = $phone ?: null;
                    $stmt->bind_param('ssss', $display_name, $email, $password_hash, $phone_param);
                    if ($stmt->execute()) {
                        $success = 'Registration successful! You can now login.';
                    } else {
                        $error = 'DB insert failed: ' . $stmt->error;
                    }
                    $stmt->close();
                }
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
    <title>Register | Medical</title>
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
        
        .register-container {
            width: 100%;
            max-width: 480px;
        }
        
        .register-card {
            background: white;
            border-radius: 24px;
            padding: 3rem;
            box-shadow: 0 32px 64px rgba(0, 0, 0, 0.2);
        }
        
        .register-header {
            text-align: center;
            margin-bottom: 2.5rem;
        }
        
        .register-logo {
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
        
        .register-title {
            font-family: 'Manrope', sans-serif;
            font-size: 1.75rem;
            font-weight: 800;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }
        
        .register-subtitle {
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
        
        .register-btn {
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
        
        .register-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0, 102, 255, 0.4);
        }
        
        .register-footer {
            text-align: center;
            color: var(--gray);
        }
        
        .register-link {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
        }
        
        .register-link:hover {
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
            .register-card {
                padding: 2rem;
            }
            
            body {
                padding: 1rem;
            }
        }
    </style>
</head>
<body>
    <div class="register-container">
        <div class="register-card">
            <div class="register-header">
                <div class="register-logo">
                    <div class="logo-icon">M</div>
                    <div class="logo-text">Medical</div>
                </div>
                <h1 class="register-title">Create Account</h1>
                <p class="register-subtitle">Join Medical today</p>
            </div>
            
            <?php if (!empty($error)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($success)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo $success; ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <?php echo csrf_field(); ?>
                <div class="form-group">
                    <label for="name" class="form-label">Full Name</label>
                    <input type="text" id="name" name="name" class="form-control" placeholder="Enter your full name" required>
                </div>
                
                <div class="form-group">
                    <label for="email" class="form-label">Email Address</label>
                    <input type="email" id="email" name="email" class="form-control" placeholder="Enter your email" required>
                </div>
                
                <div class="form-group">
                    <label for="phone" class="form-label">Phone Number</label>
                    <input type="tel" id="phone" name="phone" class="form-control" placeholder="Enter your phone number" required>
                </div>
                
                <div class="form-group">
                    <label for="role" class="form-label">I am a</label>
                    <select id="role" name="role" class="form-select" required>
                        <option value="">Select Role</option>
                        <option value="hospital">Hospital Staff</option>
                        <option value="parent">Parent/Guardian</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" id="password" name="password" class="form-control" placeholder="Create a password" required>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password" class="form-label">Confirm Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" placeholder="Confirm your password" required>
                </div>
                
                <button type="submit" class="register-btn">
                    <i class="fas fa-user-plus"></i>
                    Create Account
                </button>
            </form>
            
            <div class="register-footer">
                Already have an account? <a href="login.php" class="register-link">Sign in here</a>
            </div>
        </div>
    </div>
</body>
</html>