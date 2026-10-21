<?php
declare(strict_types=1);
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/functions.php';

$page = $_GET['page'] ?? (current_user() ? 'dashboard' : 'login');
$publicPages = ['login', 'register'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'login') {
        $email = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $stmt = $pdo->prepare('SELECT id,full_name,email,password_hash,role FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user'] = ['id' => (int)$user['id'], 'name' => $user['full_name'], 'email' => $user['email'], 'role' => $user['role']];
            flash('Welcome back, ' . $user['full_name'] . '!');
            redirect('dashboard');
        }
        flash('Invalid email or password.', 'error');
        redirect('login');
    }

    if ($action === 'register') {
        $name = trim((string)($_POST['full_name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
            flash('Enter a name, valid email, and password with at least 8 characters.', 'error');
            redirect('register');
        }
        try {
            $stmt = $pdo->prepare("INSERT INTO users (full_name,email,password_hash,role,phone) VALUES (?,?,?,'patient',?)");
            $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $phone ?: null]);
            flash('Registration successful. Please sign in.');
            redirect('login');
        } catch (PDOException $e) {
            flash('That email may already be registered.', 'error');
            redirect('register');
        }
    }

    require_login();
    $role = current_user()['role'];
    if ($action === 'create_appointment' && $role === 'patient') {
        $doctorId = (int)($_POST['doctor_id'] ?? 0);
        $date = (string)($_POST['appointment_date'] ?? '');
        $time = (string)($_POST['appointment_time'] ?? '');
        $reason = trim((string)($_POST['reason'] ?? ''));
        if (!$doctorId || $date < date('Y-m-d') || !preg_match('/^\d{2}:\d{2}$/', $time) || $reason === '') {
            flash('Please provide a valid doctor, future date, time, and reason.', 'error');
            redirect('appointments');
        }
        $check = $pdo->prepare('SELECT id FROM doctors WHERE id = ?');
        $check->execute([$doctorId]);
        if (!$check->fetchColumn()) {
            flash('Please select a valid doctor.', 'error');
            redirect('appointments');
        }
        $stmt = $pdo->prepare("INSERT INTO appointments (patient_id,doctor_id,appointment_date,appointment_time,reason) VALUES (?,?,?,?,?)");
        $stmt->execute([current_user()['id'], $doctorId, $date, $time, $reason]);
        flash('Appointment request submitted.');
        redirect('appointments');
    }

    if ($action === 'appointment_status' && in_array($role, ['admin','doctor'], true)) {
        $id = (int)($_POST['appointment_id'] ?? 0);
        $status = (string)($_POST['status'] ?? '');
        if (!in_array($status, ['Approved','Completed','Cancelled'], true)) {
            flash('Invalid appointment status.', 'error'); redirect('appointments');
        }
        if ($role === 'doctor') {
            $stmt = $pdo->prepare('UPDATE appointments a JOIN doctors d ON d.id=a.doctor_id SET a.status=? WHERE a.id=? AND d.user_id=?');
            $stmt->execute([$status, $id, current_user()['id']]);
        } else {
            $stmt = $pdo->prepare('UPDATE appointments SET status=? WHERE id=?');
            $stmt->execute([$status, $id]);
        }
        flash('Appointment status updated.');
        redirect('appointments');
    }

    if ($action === 'save_record' && $role === 'doctor') {
        $appointmentId = (int)($_POST['appointment_id'] ?? 0);
        $diagnosis = trim((string)($_POST['diagnosis'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));
        $medicine = trim((string)($_POST['medicine_name'] ?? ''));
        $dosage = trim((string)($_POST['dosage'] ?? ''));
        $frequency = trim((string)($_POST['frequency'] ?? ''));
        $duration = trim((string)($_POST['duration'] ?? ''));
        $instructions = trim((string)($_POST['instructions'] ?? ''));
        $stmt = $pdo->prepare("SELECT a.id,a.patient_id,d.id AS doctor_id FROM appointments a JOIN doctors d ON d.id=a.doctor_id WHERE a.id=? AND d.user_id=? AND a.status IN ('Approved','Completed')");
        $stmt->execute([$appointmentId, current_user()['id']]);
        $appt = $stmt->fetch();
        if (!$appt || $diagnosis === '') {
            flash('Choose one of your approved/completed appointments and enter a diagnosis.', 'error');
            redirect('records');
        }
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO medical_records (appointment_id,patient_id,doctor_id,diagnosis,notes) VALUES (?,?,?,?,?)');
            $stmt->execute([$appointmentId, $appt['patient_id'], $appt['doctor_id'], $diagnosis, $notes ?: null]);
            $recordId = (int)$pdo->lastInsertId();
            if ($medicine !== '' && $dosage !== '' && $frequency !== '' && $duration !== '') {
                $stmt = $pdo->prepare('INSERT INTO prescriptions (record_id,medicine_name,dosage,frequency,duration,instructions) VALUES (?,?,?,?,?,?)');
                $stmt->execute([$recordId, $medicine, $dosage, $frequency, $duration, $instructions ?: null]);
            }
            $pdo->commit();
            flash('Medical record saved.');
        } catch (Throwable $e) {
            $pdo->rollBack(); throw $e;
        }
        redirect('records');
    }

    if ($action === 'create_bill' && in_array($role, ['admin','doctor'], true)) {
        $appointmentId = (int)($_POST['appointment_id'] ?? 0);
        $amount = filter_var($_POST['amount'] ?? null, FILTER_VALIDATE_FLOAT);
        if (!$amount || $amount <= 0) { flash('Enter a valid bill amount.', 'error'); redirect('billing'); }
        $sql = "SELECT a.id,a.patient_id FROM appointments a ";
        if ($role === 'doctor') $sql .= "JOIN doctors d ON d.id=a.doctor_id WHERE a.id=? AND d.user_id=?";
        else $sql .= "WHERE a.id=?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($role === 'doctor' ? [$appointmentId, current_user()['id']] : [$appointmentId]);
        $appt = $stmt->fetch();
        if (!$appt) { flash('Appointment not found or not assigned to you.', 'error'); redirect('billing'); }
        $stmt = $pdo->prepare("INSERT INTO bills (appointment_id,patient_id,amount) VALUES (?,?,?) ON DUPLICATE KEY UPDATE amount=VALUES(amount)");
        $stmt->execute([$appointmentId, $appt['patient_id'], $amount]);
        flash('Bill saved.');
        redirect('billing');
    }

    if ($action === 'mark_paid' && in_array($role, ['admin','patient'], true)) {
        $billId = (int)($_POST['bill_id'] ?? 0);
        if ($role === 'patient') {
            $stmt = $pdo->prepare("UPDATE bills SET payment_status='Paid' WHERE id=? AND patient_id=?");
            $stmt->execute([$billId, current_user()['id']]);
        } else {
            $stmt = $pdo->prepare("UPDATE bills SET payment_status='Paid' WHERE id=?");
            $stmt->execute([$billId]);
        }
        flash('Payment status updated. This demo does not process real payments.');
        redirect('billing');
    }

    if ($action === 'add_medicine' && in_array($role, ['admin','pharmacist'], true)) {
        $name = trim((string)($_POST['name'] ?? ''));
        $category = trim((string)($_POST['category'] ?? ''));
        $quantity = filter_var($_POST['stock_quantity'] ?? null, FILTER_VALIDATE_INT);
        $price = filter_var($_POST['unit_price'] ?? null, FILTER_VALIDATE_FLOAT);
        $expiry = trim((string)($_POST['expiry_date'] ?? ''));
        if ($name === '' || $quantity === false || $quantity < 0 || $price === false || $price < 0) {
            flash('Enter a medicine name and valid stock and price values.', 'error'); redirect('pharmacy');
        }
        $stmt = $pdo->prepare('INSERT INTO medicines (name,category,stock_quantity,unit_price,expiry_date) VALUES (?,?,?,?,?)');
        $stmt->execute([$name, $category ?: null, $quantity, $price, $expiry ?: null]);
        flash('Medicine added to inventory.');
        redirect('pharmacy');
    }

    if ($action === 'update_stock' && in_array($role, ['admin','pharmacist'], true)) {
        $id = (int)($_POST['medicine_id'] ?? 0);
        $quantity = filter_var($_POST['stock_quantity'] ?? null, FILTER_VALIDATE_INT);
        if ($quantity === false || $quantity < 0) { flash('Stock must be zero or more.', 'error'); redirect('pharmacy'); }
        $stmt = $pdo->prepare('UPDATE medicines SET stock_quantity=? WHERE id=?');
        $stmt->execute([$quantity, $id]);
        flash('Stock quantity updated.');
        redirect('pharmacy');
    }

    flash('Action not permitted for your account.', 'error');
    redirect('dashboard');
}

if (!in_array($page, ['login','register','dashboard','appointments','records','billing','pharmacy','doctors'], true)) {
    $page = 'dashboard';
}
if (!in_array($page, $publicPages, true)) require_login();
if (in_array($page, $publicPages, true) && current_user()) redirect('dashboard');

$user = current_user();
$flashMessage = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

function fetch_all(PDO $pdo, string $sql, array $params = []): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

$doctors = fetch_all($pdo, "SELECT d.id,d.specialization,d.consultation_fee,u.full_name FROM doctors d JOIN users u ON u.id=d.user_id ORDER BY u.full_name");
$appointments = [];
$records = [];
$bills = [];
$medicines = [];

if ($user) {
    if ($page === 'appointments') {
        if ($user['role'] === 'patient') {
            $appointments = fetch_all($pdo, "SELECT a.*,u.full_name AS doctor_name,d.specialization FROM appointments a JOIN doctors d ON d.id=a.doctor_id JOIN users u ON u.id=d.user_id WHERE a.patient_id=? ORDER BY a.appointment_date DESC,a.appointment_time DESC", [$user['id']]);
        } elseif ($user['role'] === 'doctor') {
            $appointments = fetch_all($pdo, "SELECT a.*,p.full_name AS patient_name,d.id AS doctor_id FROM appointments a JOIN doctors d ON d.id=a.doctor_id JOIN users p ON p.id=a.patient_id WHERE d.user_id=? ORDER BY a.appointment_date DESC,a.appointment_time DESC", [$user['id']]);
        } else {
            $appointments = fetch_all($pdo, "SELECT a.*,p.full_name AS patient_name,du.full_name AS doctor_name,d.specialization FROM appointments a JOIN users p ON p.id=a.patient_id JOIN doctors d ON d.id=a.doctor_id JOIN users du ON du.id=d.user_id ORDER BY a.appointment_date DESC,a.appointment_time DESC");
        }
    }
    if ($page === 'records') {
        if ($user['role'] === 'patient') {
            $records = fetch_all($pdo, "SELECT mr.*,du.full_name AS doctor_name,p.medicine_name,p.dosage,p.frequency,p.duration,p.instructions FROM medical_records mr JOIN doctors d ON d.id=mr.doctor_id JOIN users du ON du.id=d.user_id LEFT JOIN prescriptions p ON p.record_id=mr.id WHERE mr.patient_id=? ORDER BY mr.created_at DESC", [$user['id']]);
        } elseif ($user['role'] === 'doctor') {
            $records = fetch_all($pdo, "SELECT mr.*,pu.full_name AS patient_name,p.medicine_name,p.dosage,p.frequency,p.duration,p.instructions FROM medical_records mr JOIN doctors d ON d.id=mr.doctor_id JOIN users pu ON pu.id=mr.patient_id LEFT JOIN prescriptions p ON p.record_id=mr.id WHERE d.user_id=? ORDER BY mr.created_at DESC", [$user['id']]);
        } else {
            $records = fetch_all($pdo, "SELECT mr.*,pu.full_name AS patient_name,du.full_name AS doctor_name,p.medicine_name,p.dosage,p.frequency,p.duration,p.instructions FROM medical_records mr JOIN users pu ON pu.id=mr.patient_id JOIN doctors d ON d.id=mr.doctor_id JOIN users du ON du.id=d.user_id LEFT JOIN prescriptions p ON p.record_id=mr.id ORDER BY mr.created_at DESC");
        }
    }
    if ($page === 'billing') {
        if ($user['role'] === 'patient') {
            $bills = fetch_all($pdo, "SELECT b.*,a.appointment_date,a.appointment_time,du.full_name AS doctor_name FROM bills b JOIN appointments a ON a.id=b.appointment_id JOIN doctors d ON d.id=a.doctor_id JOIN users du ON du.id=d.user_id WHERE b.patient_id=? ORDER BY b.created_at DESC", [$user['id']]);
        } elseif ($user['role'] === 'doctor') {
            $bills = fetch_all($pdo, "SELECT b.*,a.appointment_date,a.appointment_time,pu.full_name AS patient_name,du.full_name AS doctor_name FROM bills b JOIN appointments a ON a.id=b.appointment_id JOIN users pu ON pu.id=b.patient_id JOIN doctors d ON d.id=a.doctor_id JOIN users du ON du.id=d.user_id WHERE d.user_id=? ORDER BY b.created_at DESC", [$user['id']]);
        } else {
            $bills = fetch_all($pdo, "SELECT b.*,a.appointment_date,a.appointment_time,pu.full_name AS patient_name,du.full_name AS doctor_name FROM bills b JOIN appointments a ON a.id=b.appointment_id JOIN users pu ON pu.id=b.patient_id JOIN doctors d ON d.id=a.doctor_id JOIN users du ON du.id=d.user_id ORDER BY b.created_at DESC");
        }
    }
    if ($page === 'pharmacy') {
        $medicines = fetch_all($pdo, 'SELECT * FROM medicines ORDER BY name');
    }
}

$stats = ['patients' => 0, 'doctors' => count($doctors), 'appointments' => 0, 'unpaid' => 0];
if ($user) {
    $stats['patients'] = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='patient'")->fetchColumn();
    $stats['appointments'] = (int)$pdo->query('SELECT COUNT(*) FROM appointments')->fetchColumn();
    $stats['unpaid'] = (int)$pdo->query("SELECT COUNT(*) FROM bills WHERE payment_status='Unpaid'")->fetchColumn();
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e(ucfirst($page)) ?> | CarePoint Hospital</title>
  <link rel="stylesheet" href="assets/css/style.css">
  <script defer src="assets/js/script.js"></script>
</head>
<body>
<?php if (in_array($page, $publicPages, true)): ?>
  <main class="auth-layout">
    <section class="auth-brand">
      <div class="brand-mark">+</div><p class="eyebrow">CAREPOINT HOSPITAL</p>
      <h1>Better care starts with better coordination.</h1>
      <p>Manage appointments, medical records, prescriptions and billing from one simple workspace.</p>
      <div class="auth-note"><span>✓</span> Secure, role-based access for hospital teams and patients</div>
    </section>
    <section class="auth-panel">
      <div class="auth-card">
        <p class="eyebrow">WELCOME TO CAREPOINT</p>
        <h2><?= $page === 'login' ? 'Sign in to your account' : 'Create a patient account' ?></h2>
        <p class="muted"><?= $page === 'login' ? 'Enter your details to continue.' : 'Register to book and manage appointments.' ?></p>
        <?php if ($flashMessage): ?><div class="alert <?= e($flashMessage['type']) ?>"><?= e($flashMessage['message']) ?></div><?php endif; ?>
        <?php if ($page === 'login'): ?>
        <form method="post" class="form-stack">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="login">
          <label>Email address<input type="email" name="email" placeholder="you@example.com" required autocomplete="username"></label>
          <label>Password<input type="password" name="password" placeholder="Enter your password" required autocomplete="current-password"></label>
          <button class="btn full" type="submit">Sign in</button>
        </form>
        <p class="form-foot">New patient? <a href="index.php?page=register">Create an account</a></p>
        <div class="demo-hint"><strong>Demo access</strong><br>Run <code>setup.php</code> first to create sample accounts.</div>
        <?php else: ?>
        <form method="post" class="form-stack">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="register">
          <label>Full name<input name="full_name" required maxlength="120"></label>
          <label>Email address<input type="email" name="email" required maxlength="160"></label>
          <label>Phone number <span class="muted">(optional)</span><input name="phone" maxlength="25"></label>
          <label>Password<input type="password" name="password" minlength="8" required autocomplete="new-password"></label>
          <button class="btn full" type="submit">Create account</button>
        </form>
        <p class="form-foot">Already registered? <a href="index.php?page=login">Sign in</a></p>
        <?php endif; ?>
      </div>
    </section>
  </main>
<?php else: ?>
<div class="app-shell">
  <aside class="sidebar" id="sidebar">
    <a class="brand" href="index.php?page=dashboard"><span class="brand-mark small">+</span><span>CarePoint<small>Hospital system</small></span></a>
    <div class="nav-label">WORKSPACE</div>
    <nav class="nav-links">
      <a class="<?= $page==='dashboard'?'active':'' ?>" href="index.php?page=dashboard"><span>▦</span> Dashboard</a>
      <a class="<?= $page==='doctors'?'active':'' ?>" href="index.php?page=doctors"><span>♧</span> Doctors</a>
      <a class="<?= $page==='appointments'?'active':'' ?>" href="index.php?page=appointments"><span>▣</span> Appointments</a>
      <a class="<?= $page==='records'?'active':'' ?>" href="index.php?page=records"><span>♡</span> Medical records</a>
      <a class="<?= $page==='billing'?'active':'' ?>" href="index.php?page=billing"><span>▤</span> Billing</a>
      <?php if (in_array($user['role'], ['admin','pharmacist'], true)): ?>
      <a class="<?= $page==='pharmacy'?'active':'' ?>" href="index.php?page=pharmacy"><span>✚</span> Pharmacy</a>
      <?php endif; ?>
    </nav>
    <div class="sidebar-bottom"><div class="help-card"><strong>Need help?</strong><p>Contact your hospital administrator for account support.</p></div><a class="logout-link" href="logout.php">↪ Sign out</a></div>
  </aside>
  <main class="main-area">
    <header class="topbar"><button class="menu-toggle" id="menuToggle" aria-label="Toggle menu">☰</button><div><p class="eyebrow">CAREPOINT HOSPITAL</p><strong><?= e(ucfirst($page)) ?></strong></div><div class="top-user"><div class="avatar"><?= e(strtoupper(substr($user['name'],0,1))) ?></div><div><strong><?= e($user['name']) ?></strong><small><?= e(ucfirst($user['role'])) ?></small></div></div></header>
    <div class="page-content">
      <?php if ($flashMessage): ?><div class="alert <?= e($flashMessage['type']) ?>"><?= e($flashMessage['message']) ?></div><?php endif; ?>
      <?php if ($page === 'dashboard'): ?>
        <div class="page-heading"><div><p class="eyebrow">OVERVIEW</p><h1>Good day, <?= e(explode(' ', $user['name'])[0]) ?>.</h1><p class="muted">Here is a quick overview of your hospital workspace.</p></div><span class="role-pill"><?= e(ucfirst($user['role'])) ?> portal</span></div>
        <?php if (in_array($user['role'], ['admin','pharmacist'], true)): ?>
        <div class="stats-grid"><div class="stat-card"><span class="stat-icon blue">♙</span><p>Total patients</p><strong><?= $stats['patients'] ?></strong><small>Registered accounts</small></div><div class="stat-card"><span class="stat-icon green">♧</span><p>Doctors</p><strong><?= $stats['doctors'] ?></strong><small>Available in directory</small></div><div class="stat-card"><span class="stat-icon purple">▣</span><p>Appointments</p><strong><?= $stats['appointments'] ?></strong><small>All recorded appointments</small></div><div class="stat-card"><span class="stat-icon orange">₹</span><p>Unpaid bills</p><strong><?= $stats['unpaid'] ?></strong><small>Awaiting payment</small></div></div>
        <?php else: ?>
        <div class="welcome-card"><div><p class="eyebrow">YOUR CARE, ORGANIZED</p><h2><?= $user['role']==='patient'?'Manage your healthcare in one place.':'Your daily care workspace.' ?></h2><p>Use the shortcuts below to manage appointments, records and billing.</p></div><div class="welcome-symbol">+</div></div>
        <?php endif; ?>
        <h2 class="section-title">Quick actions</h2><div class="quick-grid">
          <?php if ($user['role']==='patient'): ?><a class="quick-card" href="index.php?page=appointments"><span class="quick-icon blue">▣</span><strong>Book appointment</strong><small>Choose a doctor and time</small></a><?php endif; ?>
          <a class="quick-card" href="index.php?page=doctors"><span class="quick-icon green">♧</span><strong>Doctor directory</strong><small>View specialties and fees</small></a>
          <a class="quick-card" href="index.php?page=records"><span class="quick-icon purple">♡</span><strong>Medical records</strong><small>Review diagnoses and prescriptions</small></a>
          <a class="quick-card" href="index.php?page=billing"><span class="quick-icon orange">▤</span><strong>Billing</strong><small>View invoices and status</small></a>
          <?php if (in_array($user['role'], ['admin','pharmacist'], true)): ?><a class="quick-card" href="index.php?page=pharmacy"><span class="quick-icon blue">✚</span><strong>Pharmacy inventory</strong><small>Manage medicine stock</small></a><?php endif; ?>
        </div>
        <div class="info-banner"><strong>Privacy reminder</strong><span>Only access patient information needed for your assigned role. This sample project is for learning and is not a certified clinical system.</span></div>
      <?php elseif ($page === 'doctors'): ?>
        <div class="page-heading"><div><p class="eyebrow">CARE TEAM</p><h1>Doctor directory</h1><p class="muted">Browse available doctors and their consultation fees.</p></div></div>
        <div class="doctor-grid"><?php foreach ($doctors as $doc): ?><article class="doctor-card"><div class="doctor-avatar"><?= e(strtoupper(substr($doc['full_name'],0,1))) ?></div><div><h3><?= e($doc['full_name']) ?></h3><p class="muted"><?= e($doc['specialization']) ?></p><div class="doctor-fee"><?= money($doc['consultation_fee']) ?> <small>/ consultation</small></div></div><?php if ($user['role']==='patient'): ?><a class="btn secondary full" href="index.php?page=appointments">Book appointment</a><?php endif; ?></article><?php endforeach; ?><?php if (!$doctors): ?><div class="empty-state">No doctors have been added yet. Run setup.php to create the demo doctor.</div><?php endif; ?></div>
      <?php elseif ($page === 'appointments'): ?>
        <div class="page-heading"><div><p class="eyebrow">SCHEDULE</p><h1>Appointments</h1><p class="muted">Manage appointment requests and schedules.</p></div></div>
        <?php if ($user['role']==='patient'): ?>
        <section class="panel"><div class="panel-heading"><div><h2>Book an appointment</h2><p>Choose a doctor and request a suitable time.</p></div></div><form method="post" class="form-grid"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create_appointment"><label>Doctor<select name="doctor_id" required><option value="">Select doctor</option><?php foreach ($doctors as $d): ?><option value="<?= (int)$d['id'] ?>"><?= e($d['full_name'].' · '.$d['specialization'].' · '.money($d['consultation_fee'])) ?></option><?php endforeach; ?></select></label><label>Date<input type="date" name="appointment_date" min="<?= date('Y-m-d') ?>" required></label><label>Time<input type="time" name="appointment_time" required></label><label class="span-all">Reason for visit<textarea name="reason" rows="2" maxlength="500" required placeholder="Briefly describe the reason for your visit"></textarea></label><div class="span-all"><button class="btn" type="submit">Submit appointment request</button></div></form></section>
        <?php endif; ?>
        <section class="panel"><div class="panel-heading"><div><h2><?= $user['role']==='patient'?'My appointments':'Appointment list' ?></h2><p>Current and previous appointment requests.</p></div></div><div class="table-wrap"><table><thead><tr><th>Patient / Doctor</th><th>Date & time</th><th>Reason</th><th>Status</th><?php if (in_array($user['role'],['admin','doctor'],true)): ?><th>Update</th><?php endif; ?></tr></thead><tbody>
        <?php foreach ($appointments as $a): ?><tr><td><strong><?= e($user['role']==='patient'?($a['doctor_name']??'Doctor'):($a['patient_name']??'Patient')) ?></strong><?php if (!empty($a['specialization'])): ?><small class="table-sub"><?= e($a['specialization']) ?></small><?php endif; ?></td><td><?= e($a['appointment_date']) ?><small class="table-sub"><?= e(substr($a['appointment_time'],0,5)) ?></small></td><td><?= e($a['reason']) ?></td><td><span class="status <?= strtolower(e($a['status'])) ?>"><?= e($a['status']) ?></span></td>
        <?php if (in_array($user['role'],['admin','doctor'],true)): ?><td><form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="appointment_status"><input type="hidden" name="appointment_id" value="<?= (int)$a['id'] ?>"><select name="status"><option>Approved</option><option>Completed</option><option>Cancelled</option></select><button class="btn small" type="submit">Save</button></form></td><?php endif; ?></tr><?php endforeach; ?>
        <?php if (!$appointments): ?><tr><td colspan="5" class="empty-cell">No appointments found.</td></tr><?php endif; ?></tbody></table></div></section>
      <?php elseif ($page === 'records'): ?>
        <div class="page-heading"><div><p class="eyebrow">PATIENT CARE</p><h1>Medical records</h1><p class="muted">Consultation notes, diagnoses and prescriptions.</p></div></div>
        <?php if ($user['role']==='doctor'): ?><section class="panel"><div class="panel-heading"><div><h2>Add consultation record</h2><p>Select an approved or completed appointment assigned to you.</p></div></div><form method="post" class="form-grid"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_record"><label class="span-all">Appointment<select name="appointment_id" required><option value="">Select appointment</option><?php foreach (fetch_all($pdo,"SELECT a.id,a.appointment_date,a.appointment_time,u.full_name FROM appointments a JOIN doctors d ON d.id=a.doctor_id JOIN users u ON u.id=a.patient_id WHERE d.user_id=? AND a.status IN ('Approved','Completed') ORDER BY a.appointment_date DESC",[$user['id']]) as $a): ?><option value="<?= (int)$a['id'] ?>">#<?= (int)$a['id'] ?> · <?= e($a['full_name']) ?> · <?= e($a['appointment_date']) ?></option><?php endforeach; ?></select></label><label class="span-all">Diagnosis<textarea name="diagnosis" rows="2" required></textarea></label><label class="span-all">Clinical notes<textarea name="notes" rows="2"></textarea></label><div class="span-all subheading">Prescription (optional)</div><label>Medicine name<input name="medicine_name"></label><label>Dosage<input name="dosage" placeholder="e.g. 1 tablet"></label><label>Frequency<input name="frequency" placeholder="e.g. twice daily"></label><label>Duration<input name="duration" placeholder="e.g. 5 days"></label><label class="span-all">Instructions<input name="instructions" placeholder="Additional instructions"></label><div class="span-all"><button class="btn" type="submit">Save medical record</button></div></form></section><?php endif; ?>
        <section class="record-list"><?php foreach ($records as $r): ?><article class="panel record-card"><div class="record-top"><div><span class="eyebrow">RECORD #<?= (int)$r['id'] ?></span><h2><?= e($r['patient_name']??'Patient record') ?></h2><p class="muted">Doctor: <?= e($r['doctor_name']??'Assigned doctor') ?> · <?= e($r['created_at']) ?></p></div><span class="status completed">Saved</span></div><h3>Diagnosis</h3><p><?= nl2br(e($r['diagnosis'])) ?></p><?php if ($r['notes']): ?><h3>Clinical notes</h3><p><?= nl2br(e($r['notes'])) ?></p><?php endif; ?><?php if ($r['medicine_name']): ?><div class="prescription-box"><strong>Prescription</strong><p><?= e($r['medicine_name']) ?> · <?= e($r['dosage']) ?> · <?= e($r['frequency']) ?> · <?= e($r['duration']) ?></p><?php if ($r['instructions']): ?><small><?= e($r['instructions']) ?></small><?php endif; ?></div><?php endif; ?></article><?php endforeach; ?><?php if (!$records): ?><div class="empty-state">No medical records available yet.</div><?php endif; ?></section>
      <?php elseif ($page === 'billing'): ?>
        <div class="page-heading"><div><p class="eyebrow">PAYMENTS</p><h1>Billing and invoices</h1><p class="muted">Review consultation charges and payment status.</p></div></div>
        <?php if (in_array($user['role'],['admin','doctor'],true)): ?><section class="panel"><div class="panel-heading"><div><h2>Create invoice</h2><p>Bill an appointment. Use the amount agreed by your hospital.</p></div></div><form method="post" class="form-grid"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create_bill"><label class="span-all">Appointment<select name="appointment_id" required><option value="">Select appointment</option><?php
        $billAppointments = $user['role']==='doctor' ? fetch_all($pdo,"SELECT a.id,a.appointment_date,u.full_name FROM appointments a JOIN doctors d ON d.id=a.doctor_id JOIN users u ON u.id=a.patient_id WHERE d.user_id=? AND a.status IN ('Approved','Completed')",[$user['id']]) : fetch_all($pdo,"SELECT a.id,a.appointment_date,u.full_name FROM appointments a JOIN users u ON u.id=a.patient_id WHERE a.status IN ('Approved','Completed')");
        foreach ($billAppointments as $ba): ?><option value="<?= (int)$ba['id'] ?>">#<?= (int)$ba['id'] ?> · <?= e($ba['full_name']) ?> · <?= e($ba['appointment_date']) ?></option><?php endforeach; ?></select></label><label>Amount (₹)<input type="number" name="amount" min="0.01" step="0.01" required></label><div class="align-end"><button class="btn" type="submit">Create / update invoice</button></div></form></section><?php endif; ?>
        <section class="panel"><div class="panel-heading"><div><h2><?= $user['role']==='patient'?'My invoices':'Invoice register' ?></h2><p>Payment status shown below. Payment marking is a demo action, not a payment gateway.</p></div><button class="btn secondary no-print" onclick="window.print()" type="button">Print page</button></div><div class="table-wrap"><table><thead><tr><th>Invoice</th><th>Patient</th><th>Doctor</th><th>Date</th><th>Amount</th><th>Status</th><th>Action</th></tr></thead><tbody><?php foreach ($bills as $b): ?><tr><td>#INV-<?= str_pad((string)$b['id'],5,'0',STR_PAD_LEFT) ?></td><td><?= e($b['patient_name']??$user['name']) ?></td><td><?= e($b['doctor_name']) ?></td><td><?= e($b['appointment_date']) ?></td><td><strong><?= money($b['amount']) ?></strong></td><td><span class="status <?= strtolower(e($b['payment_status'])) ?>"><?= e($b['payment_status']) ?></span></td><td><?php if ($b['payment_status']==='Unpaid' && in_array($user['role'],['admin','patient'],true)): ?><form method="post" class="inline-form" data-confirm="Mark this invoice as paid?"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="mark_paid"><input type="hidden" name="bill_id" value="<?= (int)$b['id'] ?>"><button class="btn small" type="submit">Mark paid</button></form><?php else: ?><span class="muted">—</span><?php endif; ?></td></tr><?php endforeach; ?><?php if (!$bills): ?><tr><td colspan="7" class="empty-cell">No invoices found.</td></tr><?php endif; ?></tbody></table></div></section>
      <?php elseif ($page === 'pharmacy'): ?>
        <div class="page-heading"><div><p class="eyebrow">INVENTORY</p><h1>Pharmacy inventory</h1><p class="muted">Track medicine stock, unit prices and expiry dates.</p></div></div>
        <?php if (in_array($user['role'],['admin','pharmacist'],true)): ?><section class="panel"><div class="panel-heading"><div><h2>Add medicine</h2><p>Enter inventory details below.</p></div></div><form method="post" class="form-grid"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="add_medicine"><label>Medicine name<input name="name" required maxlength="160"></label><label>Category<input name="category" maxlength="100"></label><label>Stock quantity<input type="number" name="stock_quantity" min="0" required></label><label>Unit price (₹)<input type="number" name="unit_price" min="0" step="0.01" required></label><label>Expiry date<input type="date" name="expiry_date"></label><div class="align-end"><button class="btn" type="submit">Add medicine</button></div></form></section><?php endif; ?>
        <section class="panel"><div class="panel-heading"><div><h2>Medicine stock</h2><p>Low-stock items are highlighted for review.</p></div><input class="table-search" type="search" placeholder="Search medicines…" data-table-search="#medicineTable"></div><div class="table-wrap"><table id="medicineTable"><thead><tr><th>Medicine</th><th>Category</th><th>Quantity</th><th>Unit price</th><th>Expiry date</th><?php if (in_array($user['role'],['admin','pharmacist'],true)): ?><th>Update stock</th><?php endif; ?></tr></thead><tbody><?php foreach ($medicines as $m): ?><tr><td><strong><?= e($m['name']) ?></strong></td><td><?= e($m['category']??'—') ?></td><td><span class="status <?= (int)$m['stock_quantity']<10?'pending':'approved' ?>"><?= (int)$m['stock_quantity'] ?></span></td><td><?= money($m['unit_price']) ?></td><td><?= e($m['expiry_date']??'—') ?></td><?php if (in_array($user['role'],['admin','pharmacist'],true)): ?><td><form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update_stock"><input type="hidden" name="medicine_id" value="<?= (int)$m['id'] ?>"><input class="stock-input" type="number" min="0" name="stock_quantity" value="<?= (int)$m['stock_quantity'] ?>" required><button class="btn small" type="submit">Save</button></form></td><?php endif; ?></tr><?php endforeach; ?><?php if (!$medicines): ?><tr><td colspan="6" class="empty-cell">No medicines added yet.</td></tr><?php endif; ?></tbody></table></div></section>
      <?php endif; ?>
      <footer class="footer">© <?= date('Y') ?> CarePoint Hospital · Student project demo</footer>
    </div>
  </main>
</div>
<?php endif; ?>
</body></html>
