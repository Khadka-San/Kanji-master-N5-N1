<?php
session_start();
ini_set('display_errors', '0');
error_reporting(E_ALL);

// If already logged in, send the user to the dashboard for their role.
if (!empty($_SESSION['user_id'])) {
    $role = strtolower((string)($_SESSION['user_role'] ?? 'student'));
    $destination = in_array($role, ['teacher', 'admin'], true)
        ? 'teacher_dashboard.php'
        : 'dashboard.php';
    header('Location: ' . $destination);
    exit;
}

if (empty($_SESSION['login_csrf_token'])) {
    $_SESSION['login_csrf_token'] = bin2hex(random_bytes(32));
}
$error = '';
function login_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = $_POST['password'] ?? '';
    $requestedRole = (string)($_POST['account_type'] ?? 'student');

    if (!is_string($token) || !hash_equals($_SESSION['login_csrf_token'], $token)) {
        $error = 'Your login session expired. Refresh the page and try again.';
    } elseif (!in_array($requestedRole, ['student', 'teacher'], true)) {
        $error = 'Choose Student or Teacher.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || !is_string($password) || $password === '') {
        $error = 'Enter your email address and password.';
    } else {
        $host = '127.0.0.1';
        $port = '3306';
        $dbname = 'kanji_master';
        $dbUser = 'root';
        $dbPassword = 'root';

        try {
            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
            $pdo = new PDO($dsn, $dbUser, $dbPassword, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]);
            $stmt = $pdo->prepare('SELECT id, name, email, password_hash, role FROM users WHERE email = :email LIMIT 1');
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                $actualRole = strtolower((string)$user['role']);
                $roleMatches = ($actualRole === $requestedRole)
                    || ($requestedRole === 'teacher' && $actualRole === 'admin');

                if (!$roleMatches) {
                    $shownRole = $actualRole !== '' ? $actualRole : 'unknown';
                    $error = 'This account is registered as ' . $shownRole . '. Please choose the matching account type.';
                } else {
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = (int)$user['id'];
                    $_SESSION['user_name'] = $user['name'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['user_role'] = $actualRole;
                    unset($_SESSION['login_csrf_token']);

                    $destination = in_array($actualRole, ['teacher', 'admin'], true)
                        ? 'teacher_dashboard.php'
                        : 'dashboard.php';
                    header('Location: ' . $destination);
                    exit;
                }
            } else {
                $error = 'The email or password is incorrect.';
            }
        } catch (PDOException $ex) {
            error_log('Kanji Master login error: ' . $ex->getMessage());
            if (strpos($ex->getMessage(), 'Access denied') !== false) {
                $error = 'Could not log in to MySQL. Check the database username and password in login.php.';
            } elseif (strpos($ex->getMessage(), 'Unknown database') !== false) {
                $error = 'The kanji_master database was not found in phpMyAdmin.';
            } else {
                $error = 'Login could not connect to the database. Make sure MAMP MySQL is running and the users table has the required columns.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login | Japanese Kanji Master</title>
<style>
*{box-sizing:border-box}body{margin:0;min-height:100vh;padding:24px;display:flex;align-items:center;justify-content:center;background:#0b1020;color:#f8fafc;font-family:Arial,Helvetica,sans-serif}.panel{width:100%;max-width:440px;padding:32px;border:1px solid #334155;border-radius:22px;background:#151d31;box-shadow:0 20px 70px #0007}.eyebrow{color:#a5b4fc;font-size:12px;letter-spacing:2px;text-transform:uppercase}h1{margin:10px 0;font-size:30px}.sub{color:#cbd5e1;line-height:1.5;margin-bottom:24px}form{display:grid;gap:10px}label{margin-top:8px;font-size:14px;color:#e2e8f0}input,select{width:100%;padding:13px 14px;border:1px solid #475569;border-radius:10px;background:#0b1220;color:white;font-size:16px}input:focus,select:focus{outline:2px solid #818cf8;border-color:transparent}button{margin-top:16px;padding:14px;border:0;border-radius:11px;background:#a5b4fc;color:#111827;font-size:16px;font-weight:bold;cursor:pointer}.error{padding:12px;border-radius:10px;line-height:1.5;color:#fecaca;background:#7f1d1d55;border:1px solid #991b1b;overflow-wrap:anywhere}a{color:#c7d2fe}.links{margin-top:22px;color:#cbd5e1;font-size:14px;line-height:1.9}
</style>
</head>
<body>
<main class="panel">
<div class="eyebrow">Japanese Kanji Master</div>
<h1>Welcome back</h1>
<p class="sub">Choose your account type and log in to continue.</p>
<?php if ($error !== ''): ?><p class="error" role="alert"><?php echo login_h($error); ?></p><?php endif; ?>
<form method="post" action="login.php">
<input type="hidden" name="csrf_token" value="<?php echo login_h($_SESSION['login_csrf_token']); ?>">
<label for="account_type">Log in as</label>
<select id="account_type" name="account_type" required>
<option value="student" <?php echo (($_POST['account_type'] ?? 'student') === 'student') ? 'selected' : ''; ?>>Student</option>
<option value="teacher" <?php echo (($_POST['account_type'] ?? '') === 'teacher') ? 'selected' : ''; ?>>Teacher</option>
</select>
<label for="email">Email address</label>
<input id="email" name="email" type="email" maxlength="190" autocomplete="username" value="<?php echo login_h($_POST['email'] ?? ''); ?>" required>
<label for="password">Password</label>
<input id="password" name="password" type="password" autocomplete="current-password" required>
<button type="submit">Log in</button>
</form>
<div class="links">Don't have an account? <a href="register.php">Create an account</a><br><a href="index.php">← Back to home</a></div>
</main>
</body>
</html>
