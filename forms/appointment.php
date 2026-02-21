<?php
// Appointment form + handler with fallback email sender
// Receiving email — replace with real address
$receiving_email_address = 'contact@example.com';

$sent = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $date = trim($_POST['date'] ?? '');
    $time = trim($_POST['time'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $doctor = trim($_POST['doctor'] ?? '');
    $message = trim($_POST['message'] ?? '');

    // Build plain message
    $body = "Appointment request:\n\n";
    $body .= "Name: $name\n";
    $body .= "Email: $email\n";
    $body .= "Phone: $phone\n";
    $body .= "Date: $date\n";
    $body .= "Time: $time\n";
    $body .= "Department: $department\n";
    $body .= "Doctor: $doctor\n\n";
    $body .= "Message:\n$message\n";

    // Try to load vendor PHP Email Form if available
    $php_email_form = __DIR__ . '/../assets/vendor/php-email-form/php-email-form.php';
    if (file_exists($php_email_form)) {
        include_once $php_email_form;
        try {
            $contact = new PHP_Email_Form;
            $contact->ajax = false;
            $contact->to = $receiving_email_address;
            $contact->from_name = $name;
            $contact->from_email = $email;
            $contact->subject = 'Online Appointment Form';
            $contact->add_message($name, 'Name');
            $contact->add_message($email, 'Email');
            $contact->add_message($phone, 'Phone');
            $contact->add_message($date . ' ' . $time, 'Appointment');
            $contact->add_message($department, 'Department');
            $contact->add_message($doctor, 'Doctor');
            $contact->add_message($message, 'Message');
            $res = $contact->send();
            $sent = ($res === 'success' || $res === true);
            if (!$sent) $error = is_string($res) ? $res : 'Failed to send via vendor library.';
        } catch (Exception $e) {
            $error = 'Vendor mailer error: ' . $e->getMessage();
        }
    } else {
        // Fallback: use PHP mail()
        $subject = 'Online Appointment Form';
        $headers = [];
        $headers[] = 'From: ' . ($name ? $name : 'Website') . ' <' . ($email ? $email : 'no-reply@example.com') . '>';
        $headers[] = 'Reply-To: ' . ($email ?: 'no-reply@example.com');
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';

        $ok = @mail($receiving_email_address, $subject, $body, implode("\r\n", $headers));
        if ($ok) {
            $sent = true;
        } else {
            $error = 'Unable to send email via PHP mail(). Please configure SMTP or install the PHP Email Form library.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Book Appointment — Medilab</title>
  <?php $css_ver = file_exists(__DIR__ . '/parent.css') ? filemtime(__DIR__ . '/parent.css') : time(); ?>
  <link rel="stylesheet" href="parent.css?v=<?php echo $css_ver; ?>">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
</head>
<body class="parent-page">
<header class="topbar">
  <div class="brand">Medilab</div>
  <div class="header-actions">
    <div class="header-profile"><div class="profile-avatar">A</div><div class="profile-name">Guest</div></div>
    <a href="/Medilab/index.php" class="logout-btn" style="background:var(--primary-blue);">Visit Site</a>
  </div>
</header>
<aside class="sidebar">
  <ul class="sidebar-menu">
    <li class="sidebar-menu-item"><a href="parent.php"><i class="fas fa-home"></i> Dashboard</a></li>
    <li class="sidebar-menu-item"><a href="add_child.php"><i class="fas fa-child"></i> Add Child</a></li>
    <li class="sidebar-menu-item"><a href="vaccines.php"><i class="fas fa-syringe"></i> Vaccination Schedule</a></li>
    <li class="sidebar-menu-item"><a href="appointment.php" class="active"><i class="fas fa-clipboard-list"></i> Book Appointment</a></li>
  </ul>
</aside>
<main class="wrap">
  <section class="welcome-section">
    <h1>Book an Appointment</h1>
    <p style="margin:0; opacity:0.9;">Fill the form below and we'll contact you to confirm the booking.</p>
  </section>

  <div class="content-grid">
    <div>
      <div class="card">
        <?php if ($sent): ?>
          <div class="message-info">Your appointment request was sent successfully. We'll contact you soon.</div>
        <?php elseif ($error): ?>
          <div class="message-info" style="border-left-color:#d97706; background:#fff7ed; color:#92400e;"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" class="form-group">
          <label>Name</label>
          <input type="text" name="name" required placeholder="Full name">

          <label>Email</label>
          <input type="email" name="email" required placeholder="you@example.com">

          <label>Phone</label>
          <input type="text" name="phone" placeholder="Phone number">

          <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
            <div>
              <label>Appointment Date</label>
              <input type="date" name="date">
            </div>
            <div>
              <label>Time</label>
              <input type="time" name="time">
            </div>
          </div>

          <label>Department</label>
          <select name="department">
            <option value="General">General</option>
            <option value="Pediatrics">Pediatrics</option>
            <option value="Immunization">Immunization</option>
          </select>

          <label>Doctor</label>
          <select name="doctor">
            <option value="Any">Any</option>
            <option value="Dr. Ahmed">Dr. Ahmed</option>
            <option value="Dr. Khan">Dr. Khan</option>
          </select>

          <label>Message (optional)</label>
          <textarea name="message" placeholder="Any notes or special requests..."></textarea>

          <div style="margin-top:12px; display:flex; gap:12px;">
            <button class="btn primary" type="submit">Request Appointment</button>
            <a href="parent.php" class="btn secondary">Back</a>
          </div>
        </form>
      </div>
    </div>

    <div>
      <div class="sidebar-card">
        <h3><i class="fas fa-info-circle"></i> How it works</h3>
        <ul>
          <li>We review your request and confirm by phone or email.</li>
          <li>Appointments are subject to availability.</li>
          <li>For urgent issues, call the hospital directly.</li>
        </ul>
      </div>
    </div>
  </div>
</main>

<footer class="footer"><p>&copy; <?php echo date('Y'); ?> Medilab</p></footer>
</body>
</html>
