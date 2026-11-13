<?php
declare(strict_types=1);

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
    respond(405, ['success' => false, 'message' => 'Use POST to register.']);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    respond(400, ['success' => false, 'message' => 'Invalid JSON request.']);
}

$name = trim((string)($input['name'] ?? ''));
$email = filter_var(trim((string)($input['email'] ?? '')), FILTER_VALIDATE_EMAIL);
$password = (string)($input['password'] ?? '');
$requestedRole = (string)($input['role'] ?? 'student');

// Public registration must never be able to create teacher or master-admin accounts.
if ($requestedRole !== 'student') {
    respond(403, [
        'success' => false,
        'message' => 'Teacher and administrator accounts must be created or approved by an administrator.'
    ]);
}

if ($name === '' || mb_strlen($name) > 100 || !$email || strlen($password) < 8) {
    respond(422, [
        'success' => false,
        'message' => 'Enter your name, a valid email, and a password of at least 8 characters.'
    ]);
}

try {
    $check = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $check->execute([$email]);

    if ($check->fetch()) {
        respond(409, ['success' => false, 'message' => 'An account with this email already exists.']);
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    $insert = $pdo->prepare(
        "INSERT INTO users (name, email, password_hash, role, status)
         VALUES (?, ?, ?, 'student', 'active')"
    );
    $insert->execute([$name, $email, $passwordHash]);

    respond(201, [
        'success' => true,
        'message' => 'Account created successfully. You can now sign in.',
        'redirect' => 'login.html'
    ]);
} catch (PDOException $e) {
    // Handle a concurrent registration against the unique email index.
    if ($e->getCode() === '23000') {
        respond(409, ['success' => false, 'message' => 'An account with this email already exists.']);
    }
    error_log('Registration API error: ' . $e->getMessage());
    respond(500, ['success' => false, 'message' => 'Could not create your account. Check the PHP error log.']);
} catch (Throwable $e) {
    error_log('Registration API error: ' . $e->getMessage());
    respond(500, ['success' => false, 'message' => 'Server error. Check the PHP error log.']);
}
