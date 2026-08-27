<?php
session_start();
$config = require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pass = $_POST['password'] ?? '';
    if (hash_equals($config['dashboard_password'], $pass)) {
        $_SESSION['dashboard_auth'] = true;
        header('Location: index.php');
        exit;
    }
    $error = 'Invalid password';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login - Fintrack</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#090a0b;--card:#121416;--line:#24272c;--text:#f4f7fb;--muted:#8b929c;--cyan:#3be3ef;--blue:#4667ff;--red:#ff6b6b}
body{font-family:Inter,Arial,sans-serif;background:radial-gradient(circle at 50% -10%,rgba(59,227,239,.16),transparent 34%),var(--bg);color:var(--text);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
.card{width:100%;max-width:380px;background:linear-gradient(180deg,#17191c,#111315);border:1px solid var(--line);border-radius:10px;padding:34px;box-shadow:0 24px 70px rgba(0,0,0,.52)}
.logo{text-align:center;margin-bottom:28px}.logo-icon{width:48px;height:48px;border-radius:10px;background:linear-gradient(135deg,var(--cyan),var(--blue));display:grid;place-items:center;color:#071011;font-weight:800;margin:0 auto 12px}.logo h1{font-size:20px;font-weight:800}.logo p{font-size:12px;color:var(--muted);margin-top:5px}
label{display:block;font-size:11px;font-weight:700;color:var(--muted);margin-bottom:7px;text-transform:uppercase}
input[type=password]{width:100%;height:44px;background:#0b0c0e;border:1px solid var(--line);border-radius:7px;color:var(--text);font-family:Inter,Arial,sans-serif;padding:0 12px;outline:none}
input[type=password]:focus{border-color:var(--cyan)}
.error{background:rgba(255,107,107,.12);border:1px solid rgba(255,107,107,.25);color:#ff9aa3;padding:10px 12px;border-radius:7px;font-size:13px;margin-bottom:14px}
button{width:100%;height:44px;margin-top:18px;border:0;border-radius:7px;background:linear-gradient(135deg,var(--cyan),var(--blue));color:#061014;font-weight:800;font-family:Inter,Arial,sans-serif;cursor:pointer}
button:hover{filter:brightness(1.05)}
</style>
</head>
<body>
<div class="card">
  <div class="logo">
    <div class="logo-icon">F</div>
    <h1>Fintrack</h1>
    <p>Payment risk dashboard</p>
  </div>
  <?php if (!empty($error)): ?>
  <div class="error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
  <form method="POST">
    <label>Password</label>
    <input type="password" name="password" autofocus placeholder="Enter dashboard password">
    <button type="submit">Sign In</button>
  </form>
</div>
</body>
</html>
