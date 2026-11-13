<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

function respond($status, $payload) {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_SESSION['user_id'])) {
    respond(401, ['success' => false, 'message' => 'Please log in again.']);
}

$host = '127.0.0.1';
$port = '3306';
$dbname = 'kanji_master';
$dbUser = 'root';
$dbPassword = 'root';

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4",
        $dbUser,
        $dbPassword,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );

    // One compact summary row per student and kanji; no drawing coordinates are saved.
    $pdo->exec("CREATE TABLE IF NOT EXISTS kanji_progress (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        kanji_id INT NOT NULL,
        total_attempts INT UNSIGNED NOT NULL DEFAULT 0,
        correct_streak INT UNSIGNED NOT NULL DEFAULT 0,
        last_drawn_stroke_count INT UNSIGNED NOT NULL DEFAULT 0,
        official_stroke_count INT UNSIGNED NOT NULL DEFAULT 0,
        last_duration_seconds INT UNSIGNED NOT NULL DEFAULT 0,
        status VARCHAR(24) NOT NULL DEFAULT 'not_practiced',
        is_learned TINYINT(1) NOT NULL DEFAULT 0,
        last_practiced_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_user_kanji (user_id, kanji_id),
        INDEX idx_progress_user_status (user_id, status),
        INDEX idx_progress_kanji (kanji_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $userId = (int)$_SESSION['user_id'];
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET' && ($_GET['action'] ?? '') === 'progress') {
        $total = (int)$pdo->query('SELECT COUNT(*) FROM kanji')->fetchColumn();
        $stmt = $pdo->prepare("
            SELECT
              SUM(CASE WHEN p.is_learned = 1 THEN 1 ELSE 0 END) AS learned,
              SUM(CASE WHEN p.is_learned = 0 AND p.status = 'mastered' THEN 1 ELSE 0 END) AS mastered,
              SUM(CASE WHEN p.is_learned = 0 AND p.status = 'remembered' THEN 1 ELSE 0 END) AS remembered,
              SUM(CASE WHEN p.is_learned = 0 AND p.status = 'needs_practice' THEN 1 ELSE 0 END) AS needs_practice,
              SUM(CASE WHEN p.id IS NULL OR (p.is_learned = 0 AND p.status = 'not_practiced') THEN 1 ELSE 0 END) AS not_practiced
            FROM kanji k
            LEFT JOIN kanji_progress p ON p.kanji_id = k.id AND p.user_id = ?
        ");
        $stmt->execute([$userId]);
        $row = $stmt->fetch() ?: [];
        $kanjiId = (int)($_GET['kanji_id'] ?? 0);
        $currentLearned = false;
        if ($kanjiId > 0) {
            $cur = $pdo->prepare('SELECT is_learned FROM kanji_progress WHERE user_id = ? AND kanji_id = ?');
            $cur->execute([$userId, $kanjiId]);
            $v = $cur->fetchColumn();
            $currentLearned = $v !== false && (int)$v === 1;
        }
        respond(200, [
            'success' => true,
            'stats' => [
                'total_kanji' => $total,
                'learned' => (int)($row['learned'] ?? 0),
                'mastered' => (int)($row['mastered'] ?? 0),
                'remembered' => (int)($row['remembered'] ?? 0),
                'needs_practice' => (int)($row['needs_practice'] ?? 0),
                'not_practiced' => (int)($row['not_practiced'] ?? 0)
            ],
            'current_is_learned' => $currentLearned
        ]);
    }

    if ($method !== 'POST') {
        header('Allow: GET, POST');
        respond(405, ['success' => false, 'message' => 'Method not allowed.']);
    }

    $raw = file_get_contents('php://input');
    if (strlen($raw) > 20000) respond(413, ['success' => false, 'message' => 'Request is too large.']);
    $data = json_decode($raw, true);
    if (!is_array($data)) respond(400, ['success' => false, 'message' => 'Invalid request.']);

    $csrf = (string)($data['csrf_token'] ?? '');
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) {
        respond(403, ['success' => false, 'message' => 'Security check failed. Refresh the page and try again.']);
    }

    $kanjiId = (int)($data['kanji_id'] ?? 0);
    if ($kanjiId < 1) respond(422, ['success' => false, 'message' => 'Select a kanji first.']);
    $check = $pdo->prepare('SELECT stroke_count FROM kanji WHERE id = ?');
    $check->execute([$kanjiId]);
    $kanji = $check->fetch();
    if (!$kanji) respond(404, ['success' => false, 'message' => 'That kanji was not found.']);

    $action = (string)($data['action'] ?? 'save_practice');

    if ($action === 'mark_learned') {
        $learned = !empty($data['learned']) ? 1 : 0;
        $stmt = $pdo->prepare("
            INSERT INTO kanji_progress (user_id, kanji_id, is_learned)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE is_learned = VALUES(is_learned)
        ");
        $stmt->execute([$userId, $kanjiId, $learned]);
        respond(200, ['success' => true, 'is_learned' => (bool)$learned, 'message' => $learned ? 'Marked as learned.' : 'Learned mark removed.']);
    }

    if ($action !== 'save_practice') respond(400, ['success' => false, 'message' => 'Unknown action.']);

    $strokeCount = (int)($data['stroke_count'] ?? 0);
    $duration = (int)($data['duration_seconds'] ?? 0);
    if ($strokeCount < 1 || $strokeCount > 1000 || $duration < 0 || $duration > 86400) {
        respond(422, ['success' => false, 'message' => 'Write at least one stroke before saving.']);
    }

    $official = max(0, (int)($kanji['stroke_count'] ?? 0));
    $stmt = $pdo->prepare('SELECT total_attempts, correct_streak, status, is_learned FROM kanji_progress WHERE user_id = ? AND kanji_id = ?');
    $stmt->execute([$userId, $kanjiId]);
    $old = $stmt->fetch() ?: ['total_attempts' => 0, 'correct_streak' => 0, 'is_learned' => 0];

    $attempts = (int)$old['total_attempts'] + 1;
    $streak = ($official > 0 && $strokeCount === $official) ? ((int)$old['correct_streak'] + 1) : 0;
    $status = ($official > 0 && $strokeCount === $official)
        ? ($streak >= 3 ? 'mastered' : 'remembered')
        : 'needs_practice';
    $stmt = $pdo->prepare("
        INSERT INTO kanji_progress
          (user_id, kanji_id, total_attempts, correct_streak, last_drawn_stroke_count,
           official_stroke_count, last_duration_seconds, status, last_practiced_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
          total_attempts = VALUES(total_attempts),
          correct_streak = VALUES(correct_streak),
          last_drawn_stroke_count = VALUES(last_drawn_stroke_count),
          official_stroke_count = VALUES(official_stroke_count),
          last_duration_seconds = VALUES(last_duration_seconds),
          status = VALUES(status),
          last_practiced_at = NOW()
    ");
    $stmt->execute([$userId, $kanjiId, $attempts, $streak, $strokeCount, $official, $duration, $status]);

    respond(200, [
        'success' => true,
        'status' => $status,
        'message' => $status === 'mastered'
            ? 'Great! Three matching stroke-count attempts in a row. Mark the star if you can recall this kanji.'
            : ($status === 'remembered'
                ? 'Practice saved. Stroke count matches; keep practising to build a streak.'
                : 'Practice saved. Check the official stroke count and try again.')
    ]);
} catch (PDOException $e) {
    error_log('Kanji Master advanced progress error: ' . $e->getMessage());
    respond(500, ['success' => false, 'message' => 'Database error. Check MySQL and the kanji_master database credentials.']);
}
