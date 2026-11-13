<?php
declare(strict_types=1);
session_start();
if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
$error = ''; $success = ''; $assignments = []; $selectedAssignmentId = filter_input(INPUT_GET, 'assignment_id', FILTER_VALIDATE_INT) ?: 0;
try {
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=kanji_master;charset=utf8mb4', 'root', 'root', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
    $role = strtolower((string)($_SESSION['user_role'] ?? ''));
    if ($role !== 'student') {
        http_response_code(403);
        $error = 'This page is for student accounts only. Your current account role is "'.($role ?: 'not set').'".';
    } else {
        // Ensure the PDF assignment tables/columns exist even if the teacher page has not been opened recently.
        $pdo->exec("CREATE TABLE IF NOT EXISTS teacher_kanji_assignments (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, teacher_id INT NOT NULL, title VARCHAR(150) NOT NULL, instructions TEXT NULL, due_date DATE NULL, pdf_path VARCHAR(500) NULL, pdf_original_name VARCHAR(255) NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX (teacher_id,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $assignmentColumns = $pdo->query('SHOW COLUMNS FROM teacher_kanji_assignments')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('pdf_path', $assignmentColumns, true)) $pdo->exec('ALTER TABLE teacher_kanji_assignments ADD COLUMN pdf_path VARCHAR(500) NULL');
        if (!in_array('pdf_original_name', $assignmentColumns, true)) $pdo->exec('ALTER TABLE teacher_kanji_assignments ADD COLUMN pdf_original_name VARCHAR(255) NULL');
        $pdo->exec("CREATE TABLE IF NOT EXISTS teacher_kanji_assignment_students (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, assignment_id BIGINT UNSIGNED NOT NULL, student_id INT NOT NULL, status VARCHAR(24) NOT NULL DEFAULT 'assigned', submitted_at DATETIME NULL, UNIQUE KEY uq_tkas (assignment_id,student_id), INDEX (student_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $studentColumns = $pdo->query('SHOW COLUMNS FROM teacher_kanji_assignment_students')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('status', $studentColumns, true)) $pdo->exec("ALTER TABLE teacher_kanji_assignment_students ADD COLUMN status VARCHAR(24) NOT NULL DEFAULT 'assigned'");
        if (!in_array('submitted_at', $studentColumns, true)) $pdo->exec('ALTER TABLE teacher_kanji_assignment_students ADD COLUMN submitted_at DATETIME NULL');
        if (!in_array('submission_pdf_path', $studentColumns, true)) $pdo->exec('ALTER TABLE teacher_kanji_assignment_students ADD COLUMN submission_pdf_path VARCHAR(500) NULL');
        if (!in_array('submission_pdf_name', $studentColumns, true)) $pdo->exec('ALTER TABLE teacher_kanji_assignment_students ADD COLUMN submission_pdf_name VARCHAR(255) NULL');
        if (!in_array('grade', $studentColumns, true)) $pdo->exec('ALTER TABLE teacher_kanji_assignment_students ADD COLUMN grade TINYINT UNSIGNED NULL');
        if (!in_array('teacher_feedback', $studentColumns, true)) $pdo->exec('ALTER TABLE teacher_kanji_assignment_students ADD COLUMN teacher_feedback TEXT NULL');
        if (!in_array('graded_at', $studentColumns, true)) $pdo->exec('ALTER TABLE teacher_kanji_assignment_students ADD COLUMN graded_at DATETIME NULL');

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $token = (string)($_POST['csrf_token'] ?? '');
            $assignmentId = filter_input(INPUT_POST, 'assignment_id', FILTER_VALIDATE_INT);
            if (!hash_equals((string)$_SESSION['csrf_token'], $token)) {
                $error = 'Your form session expired. Refresh the page and try again.';
            } elseif (!$assignmentId || $assignmentId < 1) {
                $error = 'Invalid assignment.';
            } elseif (!isset($_FILES['completed_pdf']) || $_FILES['completed_pdf']['error'] !== UPLOAD_ERR_OK) {
                $error = 'Please choose your completed worksheet PDF to upload.';
            } elseif ((int)$_FILES['completed_pdf']['size'] > 15 * 1024 * 1024) {
                $error = 'The PDF is too large. Maximum file size is 15 MB.';
            } else {
                $check = $pdo->prepare('SELECT s.submission_pdf_path FROM teacher_kanji_assignment_students s JOIN teacher_kanji_assignments a ON a.id=s.assignment_id WHERE s.assignment_id=? AND s.student_id=?');
                $check->execute([$assignmentId, (int)$_SESSION['user_id']]);
                $assigned = $check->fetch();
                $tmp = (string)$_FILES['completed_pdf']['tmp_name'];
                $originalName = basename((string)$_FILES['completed_pdf']['name']);
                $handle = is_uploaded_file($tmp) ? fopen($tmp, 'rb') : false;
                $signature = $handle ? fread($handle, 5) : '';
                if ($handle) fclose($handle);
                if (!$assigned) {
                    $error = 'That assignment is not assigned to your account.';
                } elseif ($signature !== '%PDF-') {
                    $error = 'The uploaded file does not appear to be a valid PDF.';
                } else {
                    $uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'submissions';
                    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                        $error = 'Could not create the submission upload folder. Check folder permissions.';
                    } else {
                        try { $randomName = bin2hex(random_bytes(16)) . '.pdf'; } catch (Throwable $ignored) { $randomName = uniqid('submission_', true) . '.pdf'; }
                        $destination = $uploadDir . DIRECTORY_SEPARATOR . $randomName;
                        if (!move_uploaded_file($tmp, $destination)) {
                            $error = 'Could not save your PDF. Check the uploads folder permissions and try again.';
                        } else {
                            $relativePath = 'uploads/submissions/' . $randomName;
                            $update = $pdo->prepare("UPDATE teacher_kanji_assignment_students SET status='submitted', submitted_at=NOW(), submission_pdf_path=?, submission_pdf_name=? WHERE assignment_id=? AND student_id=?");
                            $update->execute([$relativePath, substr($originalName ?: 'completed-worksheet.pdf', 0, 255), $assignmentId, (int)$_SESSION['user_id']]);
                            $oldPath = (string)($assigned['submission_pdf_path'] ?? '');
                            if ($oldPath !== '' && strpos($oldPath, 'uploads/submissions/') === 0) { $oldFull = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $oldPath); if (is_file($oldFull)) @unlink($oldFull); }
                            $success = 'Your completed worksheet PDF was uploaded successfully.';
                            $selectedAssignmentId = $assignmentId;
                        }
                    }
                }
            }
        }

        // Only query assignments belonging to the signed-in student.
        $q = $pdo->prepare("SELECT a.id, a.title, a.instructions, a.due_date, a.created_at, a.pdf_path, a.pdf_original_name, s.status, s.submitted_at, s.submission_pdf_path, s.submission_pdf_name, s.grade, s.teacher_feedback, s.graded_at, '' AS kanji_list, 0 AS kanji_count
            FROM teacher_kanji_assignment_students s
            JOIN teacher_kanji_assignments a ON a.id=s.assignment_id
            WHERE s.student_id=?
            ORDER BY CASE WHEN s.status='submitted' THEN 1 ELSE 0 END, a.due_date IS NULL, a.due_date ASC, a.created_at DESC");
        $q->execute([(int)$_SESSION['user_id']]);
        $assignments = $q->fetchAll();
    }
} catch (Throwable $t) {
    error_log('Kanji Master student assignments: '.$t->getMessage());
    if ($error === '') $error = 'Could not load assignments: '. $t->getMessage();
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>My Assignments | Kanji Master</title>
<style>
:root{--bg:#f4f6fb;--ink:#182238;--muted:#68748a;--line:#e3e8f1;--accent:#5558d9;--green:#087f5b;--red:#b42318}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.55 Arial,sans-serif}header{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:16px 4%;background:white;border-bottom:1px solid var(--line)}.brand{font-size:18px;font-weight:800}.brand span,a{color:var(--accent)}nav{display:flex;gap:16px;flex-wrap:wrap}a{text-decoration:none;font-weight:700}main{width:min(1000px,94%);margin:30px auto 60px}.eyebrow{font-size:11px;font-weight:800;letter-spacing:1.5px;color:var(--accent)}h1{font-size:32px;margin:6px 0}h2{font-size:20px;margin:0 0 8px}p{color:var(--muted);margin:6px 0 12px}.card,.list-panel{background:white;border:1px solid var(--line);border-radius:16px;padding:22px;margin:18px 0;box-shadow:0 8px 24px #1b2b4d08}.status{white-space:nowrap;padding:5px 10px;border-radius:99px;background:#fff4d6;color:#885900;font-size:12px;font-weight:800}.status.done{background:#e5f8ef;color:var(--green)}.due,.muted{font-size:13px;color:var(--muted)}.instructions{white-space:pre-line}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;min-width:620px}th,td{text-align:left;padding:14px 12px;border-bottom:1px solid var(--line)}th{font-size:12px;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);background:#fafbff}tbody tr:last-child td{border-bottom:0}.assignment-link{font-size:15px}.pdf-actions{margin:18px 0;padding:14px;border:1px solid var(--line);border-radius:12px;background:#fbfcff}.pdf-name{color:var(--muted);font-size:13px;margin:4px 0 12px;overflow-wrap:anywhere}.pdf-links{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px}.pdf-button,button{display:inline-block;padding:10px 14px;background:var(--accent);color:white;border:0;border-radius:8px;font-weight:800;cursor:pointer}.pdf-button.secondary{background:#eef0ff;color:var(--accent)}.pdf-preview{display:block;width:100%;height:75vh;min-height:500px;border:1px solid var(--line);border-radius:8px;background:#eef1f7}.notice{padding:12px 14px;border-radius:9px;margin:14px 0}.error{background:#fff0ef;color:var(--red)}.success{background:#eafaf3;color:var(--green)}.empty{text-align:center;background:white;border:1px dashed #cbd3e2;border-radius:14px;padding:34px;color:var(--muted)}.back-link{display:inline-block;margin:10px 0 4px}@media(max-width:600px){header{align-items:flex-start;flex-direction:column}h1{font-size:27px}.card,.list-panel{padding:16px}.pdf-preview{height:65vh;min-height:420px}}
</style></head><body>
<header><div class="brand">日本語 <span>Kanji Master</span></div><nav><a href="dashboard.php">Dashboard</a><a href="study.php">Kanji Study</a><a href="logout.php">Log out</a></nav></header>
<main><div class="eyebrow">Student tools</div><h1>My assignments</h1><p>Select an assignment to open its complete PDF worksheet. The list shows when it was sent and when it is due.</p>
<?php if($error!==''): ?><div class="notice error"><?=e($error)?></div><?php endif; ?>
<?php if($success!==''): ?><div class="notice success"><?=e($success)?></div><?php endif; ?>
<?php if(strtolower((string)($_SESSION['user_role']??''))==='student'): ?>
<?php if(!$assignments): ?><div class="empty"><h2>No assignments yet</h2>Your teacher's assignments will appear here when they assign work to your account.</div>
<?php elseif($selectedAssignmentId): $selected=null; foreach($assignments as $item){if((int)$item['id']===$selectedAssignmentId){$selected=$item;break;}} ?>
<?php if(!$selected): ?><div class="notice error">That assignment was not found in your account.</div><a class="back-link" href="student_assignments.php">← Back to all assignments</a>
<?php else: $a=$selected; $isDone=$a['status']==='submitted'; $isOverdue=!$isDone && $a['due_date'] && $a['due_date'] < date('Y-m-d'); ?>
<a class="back-link" href="student_assignments.php">← Back to all assignments</a>
<section class="card"><div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap"><div><h2><?=e($a['title'])?></h2><div class="muted">Sent: <?=e(date('Y-m-d',strtotime($a['created_at'])))?> · Submission deadline: <?=$a['due_date']?e($a['due_date']):'No deadline'?><?php if($isOverdue): ?> · <strong style="color:#b42318">Overdue</strong><?php endif; ?></div></div><span class="status <?=$isDone?'done':''?>"><?=$isDone?'Submitted':'Assigned'?></span></div>
<?php if(trim((string)$a['instructions'])!==''): ?><p class="instructions"><?=e($a['instructions'])?></p><?php endif; ?>
<?php if(!empty($a['pdf_path'])): ?><div class="pdf-actions"><strong><?=e($a['pdf_original_name'] ?: 'PDF practice worksheet')?></strong><div class="pdf-links"><a class="pdf-button" href="<?=e($a['pdf_path'])?>" target="_blank" rel="noopener">Open PDF in new tab</a><a class="pdf-button secondary" href="<?=e($a['pdf_path'])?>" download>Download PDF</a></div><iframe class="pdf-preview" src="<?=e($a['pdf_path'])?>" title="Complete PDF worksheet"></iframe></div><?php else: ?><p>Your teacher has not attached a PDF to this assignment.</p><?php endif; ?>
<?php if(!empty($a['grade']) || $a['grade'] === '0' || !empty($a['teacher_feedback'])): ?><div class="pdf-actions"><h2>Teacher feedback</h2><?php if($a['grade'] !== null && $a['grade'] !== ''): ?><p><strong>Grade: <?=e($a['grade'])?> / 100</strong></p><?php else: ?><p>Your teacher has not entered a grade yet.</p><?php endif; ?><?php if(trim((string)($a['teacher_feedback'] ?? '')) !== ''): ?><p class="instructions"><?=e($a['teacher_feedback'])?></p><?php endif; ?><?php if(!empty($a['graded_at'])): ?><div class="muted">Last reviewed: <?=e(date('Y-m-d H:i',strtotime($a['graded_at'])))?></div><?php endif; ?></div><?php elseif($isDone): ?><p class="muted">Your teacher has not graded this assignment yet.</p><?php endif; ?>
<?php if(!empty($a['submission_pdf_path'])): ?><p>Submitted on <?=!empty($a['submitted_at'])?e(date('Y-m-d H:i',strtotime($a['submitted_at']))):'—'?>.</p><p><a class="pdf-button" href="<?=e($a['submission_pdf_path'])?>" target="_blank" rel="noopener">View your submitted PDF</a></p><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?=e($_SESSION['csrf_token'])?>"><input type="hidden" name="assignment_id" value="<?=(int)$a['id']?>"><label for="completed_pdf">Replace submitted PDF (optional, max 15 MB)</label><p><input id="completed_pdf" type="file" name="completed_pdf" accept="application/pdf,.pdf" required></p><button type="submit">Upload replacement PDF</button></form><?php else: ?><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?=e($_SESSION['csrf_token'])?>"><input type="hidden" name="assignment_id" value="<?=(int)$a['id']?>"><label for="completed_pdf">Upload your completed worksheet (PDF only, max 15 MB)</label><p><input id="completed_pdf" type="file" name="completed_pdf" accept="application/pdf,.pdf" required></p><button type="submit">Upload completed PDF</button></form><?php endif; ?></section>
<?php endif; ?>
<?php else: ?>
<div class="list-panel"><div class="table-wrap"><table><thead><tr><th>Assignment name</th><th>Sent date</th><th>Submission last date</th><th>Status</th></tr></thead><tbody>
<?php foreach($assignments as $a): $isDone=$a['status']==='submitted'; ?><tr><td><a class="assignment-link" href="student_assignments.php?assignment_id=<?=(int)$a['id']?>"><?=e($a['title'])?> ↗</a><div class="muted">Click to open PDF</div></td><td><?=e(date('Y-m-d',strtotime($a['created_at'])))?></td><td><?=$a['due_date']?e($a['due_date']):'No deadline'?></td><td><span class="status <?=$isDone?'done':''?>"><?=$isDone?'Submitted':'Assigned'?></span></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php endif; ?><?php endif; ?></main></body></html>
