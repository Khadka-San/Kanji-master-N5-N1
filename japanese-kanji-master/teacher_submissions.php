<?php
session_start();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$role = strtolower((string)($_SESSION['user_role'] ?? ''));
if (!in_array($role, ['teacher', 'admin'], true)) {
    http_response_code(403);
    exit('Access denied. This page is for teachers and administrators.');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$host = '127.0.0.1';
$port = 3306;
$dbname = 'kanji_master';
$dbuser = 'root';
$dbpass = 'root';

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4",
        $dbuser,
        $dbpass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    http_response_code(500);
    exit('Database connection failed. Check config/database.php and your MAMP MySQL settings.');
}

$teacherId = (int)$_SESSION['user_id'];
$isAdmin = ($role === 'admin');
$selectedAssignment = filter_input(INPUT_GET, 'assignment_id', FILTER_VALIDATE_INT) ?: 0;
$searchTerm = trim((string)($_GET['search'] ?? ''));
$statusFilter = (string)($_GET['status_filter'] ?? 'all');
$dueFilter = (string)($_GET['due_filter'] ?? 'all');
$error = '';
$success = '';

try {
    // Admins can review all assignments; teachers see only their own.
    if ($isAdmin) {
        $assignmentSql = "SELECT a.id, a.title, a.due_date, a.created_at, u.name AS teacher_name,
                                 (SELECT COUNT(*) FROM teacher_kanji_assignment_students s WHERE s.assignment_id = a.id) AS student_count
                          FROM teacher_kanji_assignments a
                          LEFT JOIN users u ON u.id = a.teacher_id
                          ORDER BY a.created_at DESC";
        $assignmentStmt = $pdo->query($assignmentSql);
        $assignments = $assignmentStmt->fetchAll();
    } else {
        $assignmentStmt = $pdo->prepare("SELECT a.id, a.title, a.due_date, a.created_at, u.name AS teacher_name,
                                                (SELECT COUNT(*) FROM teacher_kanji_assignment_students s WHERE s.assignment_id = a.id) AS student_count
                                         FROM teacher_kanji_assignments a
                                         LEFT JOIN users u ON u.id = a.teacher_id
                                         WHERE a.teacher_id = ?
                                         ORDER BY a.created_at DESC");
        $assignmentStmt->execute([$teacherId]);
        $assignments = $assignmentStmt->fetchAll();
    }

    $validAssignmentIds = array_map(static fn($a) => (int)$a['id'], $assignments);
    if ($selectedAssignment && !in_array($selectedAssignment, $validAssignmentIds, true)) {
        $error = 'That assignment was not found or you do not have permission to view it.';
        $selectedAssignment = 0;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS student_notifications (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, notification_type VARCHAR(40) NOT NULL, title VARCHAR(180) NOT NULL, message VARCHAR(500) NOT NULL, target_url VARCHAR(500) NOT NULL, is_read TINYINT(1) NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_student_notifications_user (user_id, is_read, created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $studentColumns = $pdo->query('SHOW COLUMNS FROM teacher_kanji_assignment_students')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('submission_pdf_path', $studentColumns, true)) $pdo->exec('ALTER TABLE teacher_kanji_assignment_students ADD COLUMN submission_pdf_path VARCHAR(500) NULL');
    if (!in_array('submission_pdf_name', $studentColumns, true)) $pdo->exec('ALTER TABLE teacher_kanji_assignment_students ADD COLUMN submission_pdf_name VARCHAR(255) NULL');
    if (!in_array('grade', $studentColumns, true)) $pdo->exec('ALTER TABLE teacher_kanji_assignment_students ADD COLUMN grade TINYINT UNSIGNED NULL');
    if (!in_array('teacher_feedback', $studentColumns, true)) $pdo->exec('ALTER TABLE teacher_kanji_assignment_students ADD COLUMN teacher_feedback TEXT NULL');
    if (!in_array('graded_at', $studentColumns, true)) $pdo->exec('ALTER TABLE teacher_kanji_assignment_students ADD COLUMN graded_at DATETIME NULL');

    // Save a grade and feedback only when the assignment belongs to this teacher (admins may grade all).
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_grade') {
        $postedToken = (string)($_POST['csrf_token'] ?? '');
        $assignmentId = filter_input(INPUT_POST, 'assignment_id', FILTER_VALIDATE_INT);
        $studentId = filter_input(INPUT_POST, 'student_id', FILTER_VALIDATE_INT);
        $gradeInput = trim((string)($_POST['grade'] ?? ''));
        $feedback = trim((string)($_POST['teacher_feedback'] ?? ''));
        if (!hash_equals((string)$_SESSION['csrf_token'], $postedToken)) {
            $error = 'Your form session expired. Refresh the page and try again.';
        } elseif (!$assignmentId || !$studentId) {
            $error = 'Invalid assignment or student.';
        } elseif ($gradeInput !== '' && (!ctype_digit($gradeInput) || (int)$gradeInput < 0 || (int)$gradeInput > 100)) {
            $error = 'Grade must be a whole number from 0 to 100, or leave it blank.';
        } elseif (strlen($feedback) > 5000) {
            $error = 'Feedback must be 5,000 characters or fewer.';
        } else {
            $scopeSql = 'SELECT s.id FROM teacher_kanji_assignment_students s JOIN teacher_kanji_assignments a ON a.id=s.assignment_id WHERE s.assignment_id=? AND s.student_id=?';
            $scopeParams = [$assignmentId, $studentId];
            if (!$isAdmin) { $scopeSql .= ' AND a.teacher_id=?'; $scopeParams[] = $teacherId; }
            $scope = $pdo->prepare($scopeSql);
            $scope->execute($scopeParams);
            if (!$scope->fetch()) {
                $error = 'You do not have permission to grade this student for that assignment.';
            } else {
                $save = $pdo->prepare('UPDATE teacher_kanji_assignment_students SET grade=?, teacher_feedback=?, graded_at=NOW() WHERE assignment_id=? AND student_id=?');
                $save->execute([$gradeInput === '' ? null : (int)$gradeInput, $feedback === '' ? null : $feedback, $assignmentId, $studentId]);
                $assignmentTitleStmt = $pdo->prepare('SELECT title FROM teacher_kanji_assignments WHERE id=?');
                $assignmentTitleStmt->execute([$assignmentId]);
                $assignmentTitle = (string)($assignmentTitleStmt->fetchColumn() ?: 'your assignment');
                $notice = $pdo->prepare("INSERT INTO student_notifications (user_id, notification_type, title, message, target_url) VALUES (?, 'feedback', ?, ?, ?)");
                $notice->execute([$studentId, 'Teacher feedback: ' . $assignmentTitle, 'Your teacher has updated your grade or feedback.', 'student_assignments.php?assignment_id=' . (int)$assignmentId]);
                header('Location: teacher_submissions.php?assignment_id=' . $assignmentId . '&saved=1');
                exit;
            }
        }
        $selectedAssignment = $assignmentId ?: $selectedAssignment;
    }
    if (isset($_GET['saved']) && $_GET['saved'] === '1') $success = 'Grade and feedback saved.';

    $sql = "SELECT s.assignment_id, s.student_id, s.status, s.submitted_at, s.submission_pdf_path, s.submission_pdf_name, s.grade, s.teacher_feedback, s.graded_at,
                   a.title AS assignment_title, a.due_date,
                   u.name AS student_name, u.email AS student_email,
                   (SELECT COUNT(*) FROM teacher_kanji_assignment_items i WHERE i.assignment_id = s.assignment_id) AS kanji_count
            FROM teacher_kanji_assignment_students s
            INNER JOIN teacher_kanji_assignments a ON a.id = s.assignment_id
            INNER JOIN users u ON u.id = s.student_id";
    $where = [];
    $params = [];
    if (!$isAdmin) {
        $where[] = 'a.teacher_id = ?';
        $params[] = $teacherId;
    }
    if ($selectedAssignment) {
        $where[] = 's.assignment_id = ?';
        $params[] = $selectedAssignment;
    }
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= " ORDER BY CASE WHEN s.status = 'submitted' THEN 0 ELSE 1 END, a.due_date ASC, u.name ASC";
    $studentStmt = $pdo->prepare($sql);
    $studentStmt->execute($params);
    $studentRows = $studentStmt->fetchAll();

    // Apply the search and status/deadline filters to the rows the current user is allowed to see.
    $today = date('Y-m-d');
    $soonLimit = date('Y-m-d', strtotime('+7 days'));
    $studentRows = array_values(array_filter($studentRows, static function (array $row) use ($searchTerm, $statusFilter, $dueFilter, $today, $soonLimit): bool {
        $needle = mb_strtolower($searchTerm, 'UTF-8');
        $haystack = mb_strtolower((string)$row['student_name'] . ' ' . (string)$row['student_email'] . ' ' . (string)$row['assignment_title'], 'UTF-8');
        if ($needle !== '' && mb_strpos($haystack, $needle, 0, 'UTF-8') === false) return false;

        $submitted = strtolower((string)($row['status'] ?? '')) === 'submitted';
        $dueDate = !empty($row['due_date']) ? date('Y-m-d', strtotime((string)$row['due_date'])) : '';
        $overdue = !$submitted && $dueDate !== '' && $dueDate < $today;
        $awaitingGrade = $submitted && ($row['grade'] === null || $row['grade'] === '') && trim((string)($row['teacher_feedback'] ?? '')) === '';
        if ($statusFilter === 'submitted' && !$submitted) return false;
        if ($statusFilter === 'pending' && $submitted) return false;
        if ($statusFilter === 'overdue' && !$overdue) return false;
        if ($statusFilter === 'awaiting_grading' && !$awaitingGrade) return false;

        if ($dueFilter === 'overdue' && !$overdue) return false;
        if ($dueFilter === 'due_soon' && ($submitted || $dueDate === '' || $dueDate < $today || $dueDate > $soonLimit)) return false;
        if ($dueFilter === 'no_due_date' && $dueDate !== '') return false;
        return true;
    }));
} catch (PDOException $e) {
    http_response_code(500);
    exit('Could not load assignments. Please open create_assignment.php once to create the assignment tables, then reload this page.');
}

$total = count($studentRows);
$submitted = 0;
foreach ($studentRows as $row) {
    if (strtolower((string)$row['status']) === 'submitted') $submitted++;
}
$pending = $total - $submitted;
function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Assignment Submissions | Kanji Master</title>
<style>
:root{--ink:#172033;--muted:#64748b;--line:#e2e8f0;--primary:#4f46e5;--bg:#f5f7fb;--green:#166534;--green-bg:#dcfce7;--amber:#92400e;--amber-bg:#fef3c7}
*{box-sizing:border-box}body{margin:0;background:var(--bg);font-family:Arial,Helvetica,sans-serif;color:var(--ink);line-height:1.5}.wrap{width:min(1180px,94%);margin:32px auto}.top{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:22px}.eyebrow{font-size:12px;text-transform:uppercase;letter-spacing:.12em;color:var(--primary);font-weight:700}h1{font-size:clamp(25px,4vw,34px);margin:4px 0}p{margin:4px 0;color:var(--muted)}.links{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.links .btn.secondary{background:#fff;color:var(--ink);border:1px solid var(--line)}.links .btn[aria-current="page"]{background:#312e81;border:1px solid #312e81;color:#fff}.btn{display:inline-block;border:0;border-radius:9px;padding:10px 14px;text-decoration:none;font-weight:700;background:var(--primary);color:white;cursor:pointer}.btn.secondary{background:white;color:var(--ink);border:1px solid var(--line)}.stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin:18px 0}.stat,.panel{background:#fff;border:1px solid var(--line);border-radius:14px;padding:18px;box-shadow:0 3px 14px rgba(15,23,42,.035)}.stat span{display:block;color:var(--muted);font-size:13px}.stat strong{font-size:28px}.panel{margin-top:16px}.panel h2{font-size:18px;margin:0 0 14px}.filter{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.filter select,.filter .search-input{max-width:100%;min-width:190px;padding:10px;border:1px solid #cbd5e1;border-radius:9px;background:white;font:inherit}.filter .search-input{flex:1;min-width:240px}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;min-width:780px}th,td{text-align:left;padding:13px 12px;border-bottom:1px solid var(--line);vertical-align:top}th{font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;background:#f8fafc}tr:last-child td{border-bottom:0}.status{display:inline-block;border-radius:999px;padding:4px 9px;font-size:12px;font-weight:700;white-space:nowrap}.status.submitted{background:var(--green-bg);color:var(--green)}.status.pending{background:var(--amber-bg);color:var(--amber)}.small{font-size:12px;color:var(--muted)}.notice{padding:12px 14px;border-radius:9px;background:#fff7ed;color:#9a3412;margin:12px 0}.notice.success{background:#dcfce7;color:#166534}.grade-details{min-width:130px}.grade-details summary{cursor:pointer;display:inline-block;padding:7px 10px;border:1px solid var(--line);border-radius:8px;background:#f8fafc;font-weight:700;font-size:13px}.grade-form{width:min(260px,70vw);padding:10px;margin-top:8px;border:1px solid var(--line);border-radius:9px;background:#fbfcff}.grade-form label{display:block;font-size:12px;font-weight:700;margin-bottom:7px}.grade-form input[type=number]{width:78px;padding:7px;border:1px solid #cbd5e1;border-radius:7px}.grade-form textarea{display:block;width:100%;min-height:54px;max-height:90px;padding:7px;border:1px solid #cbd5e1;border-radius:7px;font:inherit;font-size:13px;resize:vertical}.grade-form button{margin-top:6px;padding:8px 10px;font-size:12px}.empty{padding:25px;text-align:center;color:var(--muted)}@media(max-width:640px){.wrap{margin:20px auto}.stats{grid-template-columns:1fr}.stat{padding:14px}.panel{padding:13px}}
</style>
</head>
<body>
<main class="wrap">
  <header class="top">
    <div><div class="eyebrow">Kanji Master · Teacher tools</div><h1>Assignment submissions</h1><p>Review student completion and submission times.</p></div>
    <nav class="links"><a class="btn secondary" href="teacher_dashboard.php">← Dashboard</a><a class="btn secondary" href="create_assignment.php">＋ Create assignment</a><a class="btn" aria-current="page" href="teacher_submissions.php">📋 Review submissions</a><a class="btn secondary" href="progress.php">📈 Student progress</a><a class="btn secondary" href="logout.php">Log out ↗</a></nav>
  </header>

  <?php if ($error): ?><div class="notice"><?= h($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="notice success"><?= h($success) ?></div><?php endif; ?>

  <section class="stats" aria-label="Submission summary">
    <div class="stat"><span>Students in view</span><strong><?= $total ?></strong></div>
    <div class="stat"><span>Submitted</span><strong><?= $submitted ?></strong></div>
    <div class="stat"><span>Awaiting submission</span><strong><?= $pending ?></strong></div>
  </section>

  <section class="panel">
    <h2>Search and filter submissions</h2>
    <form class="filter" method="get" action="teacher_submissions.php">
      <input class="search-input" type="search" name="search" value="<?= h($searchTerm) ?>" placeholder="Search student, email, or assignment" aria-label="Search student, email, or assignment">
      <select name="assignment_id" aria-label="Choose an assignment">
        <option value="0">All my assignments</option>
        <?php foreach ($assignments as $assignment): ?>
          <option value="<?= (int)$assignment['id'] ?>" <?= $selectedAssignment === (int)$assignment['id'] ? 'selected' : '' ?>><?= h($assignment['title']) ?> (<?= (int)$assignment['student_count'] ?> students)</option>
        <?php endforeach; ?>
      </select>
      <select name="status_filter" aria-label="Filter by submission status">
        <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All statuses</option>
        <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Awaiting submission</option>
        <option value="submitted" <?= $statusFilter === 'submitted' ? 'selected' : '' ?>>Submitted</option>
        <option value="overdue" <?= $statusFilter === 'overdue' ? 'selected' : '' ?>>Overdue</option>
        <option value="awaiting_grading" <?= $statusFilter === 'awaiting_grading' ? 'selected' : '' ?>>Awaiting grading</option>
      </select>
      <select name="due_filter" aria-label="Filter by deadline">
        <option value="all" <?= $dueFilter === 'all' ? 'selected' : '' ?>>All deadlines</option>
        <option value="due_soon" <?= $dueFilter === 'due_soon' ? 'selected' : '' ?>>Due in the next 7 days</option>
        <option value="overdue" <?= $dueFilter === 'overdue' ? 'selected' : '' ?>>Past due</option>
        <option value="no_due_date" <?= $dueFilter === 'no_due_date' ? 'selected' : '' ?>>No due date</option>
      </select>
      <button class="btn" type="submit">Apply filters</button>
      <?php if ($searchTerm !== '' || $selectedAssignment || $statusFilter !== 'all' || $dueFilter !== 'all'): ?><a class="btn secondary" href="teacher_submissions.php">Clear all</a><?php endif; ?>
    </form>
  </section>

  <section class="panel">
    <h2>Student status</h2>
    <?php if (!$studentRows): ?>
      <div class="empty">No submissions match these filters. Try changing the search or filter options.</div>
    <?php else: ?>
      <div class="table-wrap"><table>
        <thead><tr><th>Student</th><th>Assignment</th><th>Kanji</th><th>Due date</th><th>Status</th><th>Submitted at</th><th>Completed worksheet</th><th>Grade &amp; feedback</th></tr></thead>
        <tbody>
        <?php foreach ($studentRows as $row): $isSubmitted = strtolower((string)$row['status']) === 'submitted'; ?>
          <tr>
            <td><strong><?= h($row['student_name']) ?></strong><br><span class="small"><?= h($row['student_email']) ?></span></td>
            <td><?= h($row['assignment_title']) ?></td>
            <td><?= (int)$row['kanji_count'] ?></td>
            <td><?= !empty($row['due_date']) ? h(date('Y-m-d', strtotime($row['due_date']))) : '<span class="small">No due date</span>' ?></td>
            <td><span class="status <?= $isSubmitted ? 'submitted' : 'pending' ?>"><?= $isSubmitted ? 'Submitted' : 'Pending' ?></span></td>
            <td><?= $isSubmitted && !empty($row['submitted_at']) ? h(date('Y-m-d H:i', strtotime($row['submitted_at']))) : '<span class="small">—</span>' ?></td>
            <td><?php if (!empty($row['submission_pdf_path'])): ?><a class="btn" href="<?= h($row['submission_pdf_path']) ?>" target="_blank" rel="noopener">Open PDF</a><div class="small"><?= h($row['submission_pdf_name'] ?? 'Completed worksheet') ?></div><?php else: ?><span class="small">No PDF uploaded</span><?php endif; ?></td>
            <td><details class="grade-details"><summary><?= $row['grade'] !== null ? 'Grade: ' . (int)$row['grade'] . '/100 · Edit' : (!empty($row['teacher_feedback']) ? 'Feedback saved · Edit' : '＋ Grade / feedback') ?></summary><form class="grade-form" method="post" action="teacher_submissions.php<?= $selectedAssignment ? '?assignment_id=' . (int)$selectedAssignment : '' ?>"><input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="save_grade"><input type="hidden" name="assignment_id" value="<?= (int)$row['assignment_id'] ?>"><input type="hidden" name="student_id" value="<?= (int)$row['student_id'] ?>"><label>Score / 100 <input type="number" name="grade" min="0" max="100" step="1" value="<?= $row['grade'] !== null ? (int)$row['grade'] : '' ?>"></label><label>Feedback<textarea name="teacher_feedback" maxlength="5000" rows="2" placeholder="Write a short comment..."><?= h($row['teacher_feedback'] ?? '') ?></textarea></label><div class="small"><?= !empty($row['graded_at']) ? 'Last saved: ' . h(date('Y-m-d H:i', strtotime($row['graded_at']))) : 'Not graded yet' ?></div><button class="btn" type="submit">Save</button></form></details></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <p class="small" style="margin-top:12px">Students can upload their completed worksheet as a PDF. Use “Open PDF” to review the uploaded file.</p>
    <?php endif; ?>
  </section>
</main>
</body>
</html>
