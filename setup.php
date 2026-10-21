<?php
declare(strict_types=1);
require __DIR__ . '/config/database.php';

$demoUsers = [
    ['System Administrator', 'admin@hospital.test', 'Admin@123', 'admin', null, null],
    ['Dr. Priya Kumar', 'doctor@hospital.test', 'Doctor@123', 'doctor', 'General Medicine', 500.00],
    ['Demo Patient', 'patient@hospital.test', 'Patient@123', 'patient', null, null],
    ['Pharmacy Staff', 'pharmacy@hospital.test', 'Pharmacy@123', 'pharmacist', null, null],
];

try {
    $pdo->beginTransaction();
    foreach ($demoUsers as $u) {
        $check = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $check->execute([$u[1]]);
        $existing = $check->fetchColumn();
        if ($existing) {
            $userId = (int)$existing;
        } else {
            $stmt = $pdo->prepare('INSERT INTO users (full_name,email,password_hash,role) VALUES (?,?,?,?)');
            $stmt->execute([$u[0], $u[1], password_hash($u[2], PASSWORD_DEFAULT), $u[3]]);
            $userId = (int)$pdo->lastInsertId();
        }
        if ($u[3] === 'doctor') {
            $checkDoc = $pdo->prepare('SELECT id FROM doctors WHERE user_id = ?');
            $checkDoc->execute([$userId]);
            if (!$checkDoc->fetchColumn()) {
                $doc = $pdo->prepare('INSERT INTO doctors (user_id,specialization,consultation_fee) VALUES (?,?,?)');
                $doc->execute([$userId, $u[4], $u[5]]);
            }
        }
    }
    $pdo->commit();
    $message = 'Demo accounts created or already existed.';
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    exit('Setup failed. Confirm you imported database/hospital_db.sql first. Details: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Hospital setup</title><link rel="stylesheet" href="assets/css/style.css"></head>
<body class="setup-page"><main class="setup-card"><div class="brand-mark">+</div><h1>Setup complete</h1><p><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
<h2>Demo login accounts</h2><div class="table-wrap"><table><thead><tr><th>Role</th><th>Email</th><th>Password</th></tr></thead><tbody>
<tr><td>Admin</td><td>admin@hospital.test</td><td>Admin@123</td></tr>
<tr><td>Doctor</td><td>doctor@hospital.test</td><td>Doctor@123</td></tr>
<tr><td>Patient</td><td>patient@hospital.test</td><td>Patient@123</td></tr>
<tr><td>Pharmacist</td><td>pharmacy@hospital.test</td><td>Pharmacy@123</td></tr>
</tbody></table></div><p class="warning">For a real deployment, change these passwords and delete or restrict setup.php after setup.</p><a class="btn" href="index.php?page=login">Go to login</a></main></body></html>
