<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';

function respond(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respond(405, ['success' => false, 'message' => 'Use POST to sign in.']);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    respond(400, ['success' => false, 'message' => 'Invalid JSON request.']);
}

$email = filter_var(trim((string)($input['email'] ?? '')), FILTER_VALIDATE_EMAIL);
$password = (string)($input['password'] ?? '');
$role = (string)($input['role'] ?? 'student');

if (
    !$email ||
    $password === '' ||
    !in_array($role, ['student', 'teacher', 'master_admin'], true)
) {
    respond(422, ['success' => false, 'message' => 'Enter a valid email, password, and account type.']);
}

try {
    // The existing config/database.php creates the $pdo variable.
    $stmt = $pdo->prepare(
        'SELECT id, name, email, password_hash, role, status
         FROM users
         WHERE email = ?
         LIMIT 1'
    );
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (
        !$user ||
        !password_verify($password, (string)$user['password_hash']) ||
        (string)$user['role'] !== $role
    ) {
        respond(401, [
            'success' => false,
            'message' => 'Email, password, or account type is incorrect.'
        ]);
    }

    if ((string)$user['status'] !== 'active') {
        respond(403, [
            'success' => false,
            'message' => (string)$user['status'] === 'pending'
                ? 'Your account is awaiting approval.'
                : 'This account is not active. Please contact the administrator.'
        ]);
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['user_name'] = (string)$user['name'];
    $_SESSION['user_email'] = (string)$user['email'];
    $_SESSION['user_role'] = (string)$user['role'];

    // Your existing dashboard filename uses an underscore.
    $redirect = match ((string)$user['role']) {
        'teacher', 'master_admin' => 'teacher_dashboard.php',
        default => 'dashboard.php',
    };

    respond(200, [
        'success' => true,
        'message' => 'Signed in successfully.',
        'redirect' => $redirect,
        'user' => [
            'id' => (int)$user['id'],
            'name' => (string)$user['name'],
            'email' => (string)$user['email'],
            'role' => (string)$user['role']
        ]
    ]);
} catch (Throwable $e) {
    error_log('Login API error: ' . $e->getMessage());
    respond(500, ['success' => false, 'message' => 'Server error. Check the PHP error log.']);
}
