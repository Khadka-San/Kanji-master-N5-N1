<?php
session_start();
ini_set('display_errors', '0');
error_reporting(E_ALL);

/* Replace this value with a private code before distributing the app. */
const TEACHER_REGISTRATION_CODE = 'anabuki123';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$error = '';
$success = '';
function e($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        $error = 'Your form session expired. Refresh the page and try again.';
    } else {
        $name = trim((string)($_POST['name'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $password = $_POST['password'] ?? '';
        $requestedRole = (string)($_POST['account_type'] ?? 'student');
        $teacherCode = (string)($_POST['teacher_code'] ?? '');

        if (!in_array($requestedRole, ['student', 'teacher'], true)) {
            $error = 'Choose Student or Teacher.';
        } elseif ($requestedRole === 'teacher' && !hash_equals(TEACHER_REGISTRATION_CODE, $teacherCode)) {
            $error = 'The teacher registration code is incorrect.';
        } elseif ($name === '' || strlen($name) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190 || !is_string($password) || strlen($password) < 12 || strlen($password) > 72) {
            $error = 'Enter a valid name and email, and use a password of 12–72 characters.';
        } else {
            $host = '127.0.0.1'; $port = '3306'; $dbname = 'kanji_master'; $dbUser = 'root'; $dbPassword = 'root';
            try {
                $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
                $pdo = new PDO($dsn, $dbUser, $dbPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (:name, :email, :password_hash, :role)');
                $stmt->execute([':name' => $name, ':email' => $email, ':password_hash' => $hash, ':role' => $requestedRole]);
                $success = 'Registration successful! Your ' . $requestedRole . ' account has been created. You can now log in.';
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            } catch (PDOException $ex) {
                error_log('Kanji Master registration error: ' . $ex->getMessage());
                if ($ex->getCode() === '23000') $error = 'This email is already registered. Please use a different email or log in.';
                elseif (strpos($ex->getMessage(), 'Access denied') !== false) $error = 'Could not log in to MySQL. Check the database username and password in register.php.';
                elseif (strpos($ex->getMessage(), 'Unknown database') !== false) $error = 'The kanji_master database was not found. Create it in phpMyAdmin.';
                else $error = 'Could not save the account. Check that MAMP MySQL is running and review the users table.';
            }
        }
    }
}
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Create Account | Japanese Kanji Master</title>
<style>*{box-sizing:border-box}body{margin:0;min-height:100vh;padding:24px;display:flex;align-items:center;justify-content:center;background:#0b1020;color:#f8fafc;font-family:Arial,Helvetica,sans-serif}.panel{width:100%;max-width:510px;padding:32px;border:1px solid #334155;border-radius:22px;background:#151d31;box-shadow:0 20px 70px #0007}.eyebrow{color:#a5b4fc;font-size:12px;letter-spacing:2px;text-transform:uppercase}h1{margin:10px 0;font-size:30px}.sub{color:#cbd5e1;line-height:1.5;margin-bottom:24px}form{display:grid;gap:10px}label{margin-top:8px;font-size:14px;color:#e2e8f0}input,select{width:100%;padding:13px 14px;border:1px solid #475569;border-radius:10px;background:#0b1220;color:white;font-size:16px}input:focus,select:focus{outline:2px solid #818cf8;border-color:transparent}button{margin-top:16px;padding:14px;border:0;border-radius:11px;background:#a5b4fc;color:#111827;font-size:16px;font-weight:bold;cursor:pointer}.message{padding:12px;border-radius:10px;line-height:1.5;overflow-wrap:anywhere}.error{color:#fecaca;background:#7f1d1d55;border:1px solid #991b1b}.success{color:#bbf7d0;background:#14532d66;border:1px solid #166534}a{color:#c7d2fe}.links{margin-top:22px;color:#cbd5e1;font-size:14px;line-height:1.9}.hint{color:#94a3b8;font-size:12px;line-height:1.5;margin-top:14px}</style></head><body><main class="panel">
<div class="eyebrow">Japanese Kanji Master</div><h1>Create your account</h1><p class="sub">Choose Student or Teacher. Teacher registration requires a special code.</p>
<?php if ($error !== ''): ?><p class="message error" role="alert"><?php echo e($error); ?></p><?php endif; ?>
<?php if ($success !== ''): ?><p class="message success" role="status"><?php echo e($success); ?></p><p><a href="login.php">Continue to login</a></p><?php else: ?>
<form method="post" action="register.php"><input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">
<label for="account_type">Account type</label><select id="account_type" name="account_type" required><option value="student" <?php echo (($_POST['account_type'] ?? 'student') === 'student') ? 'selected' : ''; ?>>Student</option><option value="teacher" <?php echo (($_POST['account_type'] ?? '') === 'teacher') ? 'selected' : ''; ?>>Teacher (special code required)</option></select>
<div id="teacher-code-wrap" hidden><label for="teacher_code">Teacher registration code</label><input id="teacher_code" name="teacher_code" type="password" autocomplete="off"><p class="hint">Ask the app developer for the teacher registration code.</p></div>
<label for="name">Full name</label><input id="name" name="name" type="text" maxlength="100" autocomplete="name" required>
<label for="email">Email address</label><input id="email" name="email" type="email" maxlength="190" autocomplete="email" required>
<label for="password">Password (12–72 characters)</label><input id="password" name="password" type="password" minlength="12" maxlength="72" autocomplete="new-password" required>
<button type="submit">Create account</button></form><p class="hint">Passwords are stored as a secure hash. Teacher accounts are created only when the correct code is provided.</p><?php endif; ?>
<div class="links"><a href="login.php">Already have an account? Log in</a><br><a href="index.php">← Back to home</a></div></main>
<script>const typeSelect=document.getElementById('account_type');const codeWrap=document.getElementById('teacher-code-wrap');const codeInput=document.getElementById('teacher_code');function toggleTeacherCode(){const isTeacher=typeSelect && typeSelect.value==='teacher';codeWrap.hidden=!isTeacher;codeInput.required=isTeacher;}typeSelect.addEventListener('change',toggleTeacherCode);toggleTeacherCode();</script></body></html>
