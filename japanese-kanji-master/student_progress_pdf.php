<?php
/**
 * Kanji Master - Student Progress PDF Report
 * Self-contained, MAMP-friendly report. Uses the browser's Print / Save as PDF
 * feature so no third-party PDF library is required.
 */
session_start();
if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$role = strtolower((string)($_SESSION['user_role'] ?? 'student'));
if (!in_array($role, ['student', 'teacher', 'admin'], true)) { http_response_code(403); exit('Access denied.'); }
function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
$currentUserId = (int)$_SESSION['user_id'];
$selectedStudentId = $role === 'student' ? $currentUserId : (int)($_GET['student_id'] ?? 0);
$error = '';
$students = [];
$student = null;
$assignments = [];
$stats = ['total'=>0, 'submitted'=>0, 'pending'=>0, 'overdue'=>0, 'graded'=>0, 'average'=>null];
$gradeRows = [];
$today = date('Y-m-d');

try {
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=kanji_master;charset=utf8mb4', 'root', 'root', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    if ($role !== 'student') {
        $studentsSql = "SELECT DISTINCT u.id, u.name, u.email
                        FROM users u
                        JOIN teacher_kanji_assignment_students s ON s.student_id = u.id
                        JOIN teacher_kanji_assignments a ON a.id = s.assignment_id";
        $studentsParams = [];
        if ($role !== 'admin') {
            $studentsSql .= ' WHERE a.teacher_id = ?';
            $studentsParams[] = $currentUserId;
        }
        $studentsSql .= ' ORDER BY u.name ASC';
        $stmt = $pdo->prepare($studentsSql);
        $stmt->execute($studentsParams);
        $students = $stmt->fetchAll();

        if ($selectedStudentId <= 0) {
            // Show the protected student selector before generating a report.
        } else {
            $studentSql = "SELECT DISTINCT u.id, u.name, u.email
                           FROM users u
                           JOIN teacher_kanji_assignment_students s ON s.student_id = u.id
                           JOIN teacher_kanji_assignments a ON a.id = s.assignment_id
                           WHERE u.id = ?";
            $studentParams = [$selectedStudentId];
            if ($role !== 'admin') {
                $studentSql .= ' AND a.teacher_id = ?';
                $studentParams[] = $currentUserId;
            }
            $studentSql .= ' LIMIT 1';
            $stmt = $pdo->prepare($studentSql);
            $stmt->execute($studentParams);
            $student = $stmt->fetch() ?: null;
            if (!$student) $error = 'This student is not available in your assigned classes.';
        }
    } else {
        $stmt = $pdo->prepare('SELECT id, name, email FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$currentUserId]);
        $student = $stmt->fetch() ?: null;
        if (!$student) $error = 'Could not find your student account.';
    }

    if ($student && !$error) {
        $detailSql = "SELECT a.id AS assignment_id, a.title, a.due_date, a.created_at,
                             s.status, s.submitted_at, s.grade, s.teacher_feedback, s.graded_at
                      FROM teacher_kanji_assignment_students s
                      JOIN teacher_kanji_assignments a ON a.id = s.assignment_id
                      WHERE s.student_id = ?";
        $detailParams = [$selectedStudentId];
        if ($role === 'teacher') {
            $detailSql .= ' AND a.teacher_id = ?';
            $detailParams[] = $currentUserId;
        }
        $detailSql .= ' ORDER BY a.created_at DESC, a.id DESC';
        $stmt = $pdo->prepare($detailSql);
        $stmt->execute($detailParams);
        $assignments = $stmt->fetchAll();

        $gradeSum = 0;
        foreach ($assignments as $assignment) {
            $stats['total']++;
            $isSubmitted = (($assignment['status'] ?? '') === 'submitted');
            if ($isSubmitted) $stats['submitted']++;
            else {
                $stats['pending']++;
                if (!empty($assignment['due_date']) && substr((string)$assignment['due_date'], 0, 10) < $today) $stats['overdue']++;
            }
            if ($assignment['grade'] !== null && $assignment['grade'] !== '') {
                $stats['graded']++;
                $gradeSum += (float)$assignment['grade'];
                $gradeRows[] = $assignment;
            }
        }
        if ($stats['graded'] > 0) $stats['average'] = $gradeSum / $stats['graded'];
        // Show older-to-newer grades for the trend section.
        usort($gradeRows, function ($a, $b) {
            $ad = (string)($a['graded_at'] ?: $a['created_at'] ?: '');
            $bd = (string)($b['graded_at'] ?: $b['created_at'] ?: '');
            return strcmp($ad, $bd);
        });
    }
} catch (Throwable $e) {
    error_log('Kanji Master student PDF report: ' . $e->getMessage());
    $error = 'Could not load the report. Check that MAMP MySQL is running and the assignment tables exist.';
}

$latestGrade = count($gradeRows) ? $gradeRows[count($gradeRows)-1] : null;
$previousGrade = count($gradeRows) > 1 ? $gradeRows[count($gradeRows)-2] : null;
$gradeDelta = ($latestGrade && $previousGrade) ? ((float)$latestGrade['grade'] - (float)$previousGrade['grade']) : null;
$completion = $stats['total'] > 0 ? (int)round($stats['submitted'] / $stats['total'] * 100) : 0;
$feedbackRows = [];
foreach ($assignments as $assignment) {
    if (trim((string)($assignment['teacher_feedback'] ?? '')) !== '') $feedbackRows[] = $assignment;
    if (count($feedbackRows) >= 5) break;
}
$reportTitle = 'Kanji Master - Student Progress Report';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=h($reportTitle)?><?= $student ? ' - '.h($student['name']) : '' ?></title>
<style>
:root{--ink:#182338;--muted:#5e6b80;--line:#dce3ed;--pale:#f4f7fb;--purple:#6457d9;--green:#168568;--amber:#b66b0b;--red:#b4233a}
*{box-sizing:border-box}body{margin:0;background:#edf1f7;color:var(--ink);font:14px/1.5 Inter,Arial,sans-serif}.toolbar{max-width:960px;margin:22px auto 14px;display:flex;gap:10px;align-items:center;justify-content:space-between;padding:0 16px}.toolbar p{margin:0;color:#526077;font-size:13px}.button{border:0;border-radius:10px;background:#222b43;color:#fff;padding:11px 16px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-block}.button.secondary{background:#fff;color:#243047;border:1px solid #d6deea}.sheet{width:min(210mm,calc(100% - 32px));min-height:297mm;margin:0 auto 32px;background:#fff;padding:17mm 16mm;box-shadow:0 12px 36px #1c2a4518}.hero{padding:24px;border-radius:16px;background:linear-gradient(120deg,#252d55,#5549b7);color:white;position:relative;overflow:hidden}.eyebrow{text-transform:uppercase;letter-spacing:1.8px;font-size:10px;font-weight:800;opacity:.8}.hero h1{font-size:28px;line-height:1.15;margin:8px 0}.hero p{margin:4px 0;color:#e4e7ff}.hero-meta{display:flex;gap:22px;flex-wrap:wrap;margin-top:18px;font-size:12px}.hero-meta strong{display:block;font-size:14px;color:white}.section-title{font-size:16px;margin:25px 0 11px;display:flex;align-items:center;gap:8px}.section-title:before{content:"";display:block;width:4px;height:18px;border-radius:5px;background:var(--purple)}.metrics{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.metric{border:1px solid var(--line);border-radius:12px;padding:14px;background:#fff}.metric span{display:block;color:var(--muted);font-size:11px}.metric strong{display:block;font-size:25px;margin-top:4px;letter-spacing:-.5px}.metric small{font-size:12px;color:var(--muted)}.highlight{color:var(--purple)}.danger{color:var(--red)!important}.success{color:var(--green)!important}.overview{display:grid;grid-template-columns:1fr 1fr;gap:14px}.card{border:1px solid var(--line);border-radius:13px;padding:15px}.card h3{font-size:13px;margin:0 0 12px}.completion-track,.grade-track{height:9px;border-radius:20px;background:#e8edf5;overflow:hidden}.completion-fill{height:100%;border-radius:20px;background:linear-gradient(90deg,#35b895,#168568)}.legend{display:flex;justify-content:space-between;gap:10px;margin-top:10px;color:var(--muted);font-size:11px}.grade-item{margin:12px 0}.grade-head{display:flex;justify-content:space-between;gap:12px;font-size:11px;margin-bottom:5px}.grade-name{overflow-wrap:anywhere;color:#334155}.grade-fill{height:100%;border-radius:20px;background:linear-gradient(90deg,#8d83ff,#5d52d4)}.trend{display:inline-block;padding:3px 8px;border-radius:99px;background:#eef2ff;color:#4d43b4;font-size:11px;font-weight:700}.trend.down{background:#fff0f1;color:var(--red)}.trend.flat{background:#f1f4f8;color:#526077}.table-wrap{width:100%}table{width:100%;border-collapse:collapse;font-size:11px}thead th{text-align:left;background:#f3f5fa;color:#58657b;font-size:9px;text-transform:uppercase;letter-spacing:.6px;padding:10px 8px;border-bottom:1px solid var(--line)}tbody td{padding:10px 8px;vertical-align:top;border-bottom:1px solid #e9edf3}tbody tr:last-child td{border-bottom:0}.status{display:inline-block;border-radius:99px;padding:3px 7px;font-size:10px;font-weight:700;background:#eef2f7;color:#445168;white-space:nowrap}.status.submitted{background:#e6f7f1;color:#087453}.status.overdue{background:#fff0f1;color:#b4233a}.status.graded{background:#efedff;color:#5144bb}.feedback{white-space:pre-wrap;overflow-wrap:anywhere;color:#455168}.feedback-item{border:1px solid var(--line);border-radius:11px;padding:12px;margin:9px 0}.feedback-item strong{font-size:12px}.feedback-item p{margin:7px 0 0;color:#455168;white-space:pre-wrap;overflow-wrap:anywhere}.empty{border:1px dashed #cbd5e1;border-radius:10px;padding:14px;color:var(--muted);font-size:12px}.footer{margin-top:26px;padding-top:12px;border-top:1px solid var(--line);display:flex;justify-content:space-between;gap:10px;color:#7a8699;font-size:10px}.error{max-width:700px;margin:40px auto;padding:20px;border-radius:12px;background:#fff0f1;color:#9b1c31;border:1px solid #f1b8c0}.selector{max-width:700px;margin:40px auto;background:#fff;border:1px solid var(--line);border-radius:16px;padding:26px;box-shadow:0 12px 36px #1c2a4510}.selector h1{margin-top:0}.selector label{display:block;font-weight:700;margin:18px 0 7px}.selector select{width:100%;padding:12px;border:1px solid #cbd5e1;border-radius:9px;background:#fff;font:inherit}.selector .button{margin-top:14px}@media(max-width:600px){.metrics{grid-template-columns:repeat(2,1fr)}.overview{grid-template-columns:1fr}.sheet{padding:8mm}.hero{padding:18px}.hero h1{font-size:24px}.toolbar{align-items:flex-start;flex-direction:column}}@page{size:A4;margin:0}
@media print{body{background:#fff;font-size:11px}.toolbar,.no-print{display:none!important}.sheet{width:210mm;min-height:297mm;margin:0;padding:13mm 14mm;box-shadow:none;page-break-after:always}.sheet:last-child{page-break-after:auto}.hero{-webkit-print-color-adjust:exact;print-color-adjust:exact}.metric,.card,.feedback-item,.status,.completion-fill,.grade-fill{ -webkit-print-color-adjust:exact;print-color-adjust:exact}thead{display:table-header-group}tr,.metric,.card,.feedback-item{break-inside:avoid}a{color:inherit;text-decoration:none}.section-title{break-after:avoid}.footer{margin-top:16px}}
</style>
</head>
<body>
<?php if ($error !== ''): ?>
<div class="error"><strong>Report unavailable</strong><p><?=h($error)?></p><a class="button secondary" href="progress.php">Back to progress</a></div>
<?php elseif ($role !== 'student' && !$student): ?>
<div class="selector">
  <div class="eyebrow" style="color:#6457d9">Kanji Master · Reports</div>
  <h1>Export student progress</h1>
  <p>Choose a student to create a print-ready A4 report. You can save it as a PDF from your browser's print dialog.</p>
  <?php if (!$students): ?><div class="empty">No students are linked to assignments yet.</div>
  <?php else: ?><form method="get" action="student_progress_pdf.php"><label for="student_id">Student</label><select name="student_id" id="student_id" required><option value="">Select a student…</option><?php foreach($students as $option): ?><option value="<?=(int)$option['id']?>"><?=h($option['name'])?> — <?=h($option['email'])?></option><?php endforeach; ?></select><button class="button" type="submit">View report →</button></form><?php endif; ?>
  <p style="margin-top:22px"><a href="progress.php">← Back to progress</a></p>
</div>
<?php else: ?>
<div class="toolbar"><p>Report preview · A4 print layout<br>Choose <strong>Save as PDF</strong> in the print dialog.</p><div style="display:flex;gap:8px;flex-wrap:wrap"><a class="button secondary" href="<?= $role==='student'?'progress.php':'progress.php?student_id='.(int)$student['id'] ?>">← Back to progress</a><button class="button" type="button" onclick="window.print()">↓ Print / Save as PDF</button></div></div>
<main class="sheet">
  <section class="hero"><div class="eyebrow">Japanese Kanji Master · Learning analytics</div><h1>Student Progress Report</h1><p>A summary of assignment completion, grades, overdue work, and teacher feedback.</p><div class="hero-meta"><div>STUDENT<strong><?=h($student['name'])?></strong></div><div>EMAIL<strong><?=h($student['email'])?></strong></div><div>REPORT DATE<strong><?=h(date('F j, Y'))?></strong></div></div></section>
  <h2 class="section-title">Performance overview</h2>
  <section class="metrics">
    <div class="metric"><span>Total assignments</span><strong><?=$stats['total']?></strong><small>Assigned work</small></div>
    <div class="metric"><span>Submitted</span><strong class="success"><?=$stats['submitted']?></strong><small><?=$completion?>% completion</small></div>
    <div class="metric"><span>Pending</span><strong><?=$stats['pending']?></strong><small>Not yet submitted</small></div>
    <div class="metric"><span>Overdue</span><strong class="<?= $stats['overdue'] ? 'danger' : 'success' ?>"><?=$stats['overdue']?></strong><small>Past due and not submitted</small></div>
    <div class="metric"><span>Graded assignments</span><strong><?=$stats['graded']?></strong><small>With a recorded grade</small></div>
    <div class="metric"><span>Average grade</span><strong class="highlight"><?=$stats['average']===null?'—':number_format((float)$stats['average'],1).'%'?></strong><small>Out of 100</small></div>
  </section>

  <h2 class="section-title">Completion and grade trend</h2>
  <section class="overview">
    <div class="card"><h3>Assignment completion</h3><div style="display:flex;align-items:baseline;gap:8px;margin-bottom:10px"><strong style="font-size:27px"><?=$completion?>%</strong><span style="color:var(--muted);font-size:11px">submitted</span></div><div class="completion-track"><div class="completion-fill" style="width:<?=$completion?>%"></div></div><div class="legend"><span>Submitted: <?=$stats['submitted']?></span><span>Pending: <?=$stats['pending']?></span></div></div>
    <div class="card"><h3>Recent grade trend</h3><?php if (!$gradeRows): ?><div class="empty">No grades recorded yet.</div><?php else: ?><?php foreach(array_slice($gradeRows,-5) as $grade): $gradeValue=max(0,min(100,(float)$grade['grade'])); ?><div class="grade-item"><div class="grade-head"><span class="grade-name"><?=h($grade['title'])?></span><strong><?=number_format($gradeValue,0)?>%</strong></div><div class="grade-track"><div class="grade-fill" style="width:<?=$gradeValue?>%"></div></div></div><?php endforeach; ?><?php if($gradeDelta!==null): ?><span class="trend <?= $gradeDelta<0?'down':($gradeDelta==0?'flat':'') ?>"><?= $gradeDelta>0?'↑ +':($gradeDelta<0?'↓ ':'→ ') ?><?=number_format(abs($gradeDelta),1)?> points vs previous grade</span><?php endif; ?><?php endif; ?></div>
  </section>

  <h2 class="section-title">Assignment history</h2>
  <?php if (!$assignments): ?><div class="empty">No assignments found for this student.</div><?php else: ?>
  <div class="table-wrap"><table><thead><tr><th style="width:26%">Assignment</th><th>Assigned</th><th>Due date</th><th>Status</th><th>Grade</th><th style="width:30%">Teacher feedback</th></tr></thead><tbody>
  <?php foreach($assignments as $a): $isOverdue=(($a['status']??'')!=='submitted'&&!empty($a['due_date'])&&substr((string)$a['due_date'],0,10)<$today); ?><tr><td><strong><?=h($a['title'])?></strong></td><td><?=!empty($a['created_at'])?h(date('Y-m-d',strtotime($a['created_at']))):'—'?></td><td><?=!empty($a['due_date'])?h(substr((string)$a['due_date'],0,10)):'—'?></td><td><?php if($isOverdue): ?><span class="status overdue">Overdue</span><?php elseif(($a['status']??'')==='submitted'): ?><span class="status submitted">Submitted</span><?php else: ?><span class="status">Pending</span><?php endif; ?></td><td><?=($a['grade']!==null&&$a['grade']!=='')?h((int)$a['grade']).'/100':'—'?></td><td class="feedback"><?=trim((string)($a['teacher_feedback']??''))!==''?nl2br(h($a['teacher_feedback'])):'—'?></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>

  <h2 class="section-title">Recent teacher feedback</h2>
  <?php if (!$feedbackRows): ?><div class="empty">No teacher feedback has been recorded yet.</div><?php else: foreach($feedbackRows as $fb): ?><div class="feedback-item"><strong><?=h($fb['title'])?><?php if($fb['grade']!==null): ?> · <?=h((int)$fb['grade'])?>/100<?php endif; ?></strong><p><?=h($fb['teacher_feedback'])?></p><div style="color:var(--muted);font-size:10px;margin-top:6px"><?=!empty($fb['graded_at'])?'Feedback date: '.h(date('Y-m-d',strtotime($fb['graded_at']))):'Feedback available'?></div></div><?php endforeach; endif; ?>
  <div class="footer"><span>Kanji Master · Student Progress Report</span><span>Generated <?=h(date('Y-m-d H:i'))?></span></div>
</main>
<script>window.addEventListener('load',function(){ if(new URLSearchParams(window.location.search).get('print')==='1'){window.print();} });</script>
<?php endif; ?>
</body></html>
