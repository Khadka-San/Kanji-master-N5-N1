<?php
session_start();
if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }
function dashboard_h($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
$name = !empty($_SESSION['user_name']) ? $_SESSION['user_name'] : 'Student';
$role = $_SESSION['user_role'] ?? 'student';
if ($role !== 'student') { http_response_code(403); exit('This dashboard is for student accounts.'); }
$userId = (int)$_SESSION['user_id'];
$stats = ['total_kanji'=>0,'practised'=>0,'learned'=>0,'mastered'=>0,'remembered'=>0,'needs_practice'=>0,'not_practiced'=>0];
$dbMessage = '';
try {
 $pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=kanji_master;charset=utf8mb4','root','root',[
  PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false
 ]);
 $stats['total_kanji'] = (int)$pdo->query('SELECT COUNT(*) FROM kanji')->fetchColumn();
 $exists = $pdo->query("SHOW TABLES LIKE 'kanji_progress'")->fetchColumn();
 if ($exists) {
  $stmt = $pdo->prepare("SELECT COUNT(CASE WHEN p.total_attempts > 0 THEN 1 END) AS practised,
   SUM(CASE WHEN p.is_learned=1 THEN 1 ELSE 0 END) AS learned,
   SUM(CASE WHEN p.is_learned=0 AND p.status='mastered' THEN 1 ELSE 0 END) AS mastered,
   SUM(CASE WHEN p.is_learned=0 AND p.status='remembered' THEN 1 ELSE 0 END) AS remembered,
   SUM(CASE WHEN p.is_learned=0 AND p.status='needs_practice' THEN 1 ELSE 0 END) AS needs_practice,
   SUM(CASE WHEN p.id IS NULL OR (p.is_learned=0 AND p.status='not_practiced') THEN 1 ELSE 0 END) AS not_practiced
   FROM kanji k LEFT JOIN kanji_progress p ON p.kanji_id=k.id AND p.user_id=?");
  $stmt->execute([$userId]); $row=$stmt->fetch() ?: [];
  foreach (['practised','learned','mastered','remembered','needs_practice','not_practiced'] as $key) $stats[$key]=(int)($row[$key] ?? 0);
 } else { $dbMessage='Your progress summary will appear after the practice progress table is created by the writing practice page.'; }
} catch (Throwable $e) { $dbMessage='Progress could not be loaded. Check that MAMP and the kanji_master database are running.'; }
$percentPractised = $stats['total_kanji'] ? (int)round($stats['practised']/$stats['total_kanji']*100) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dashboard | Japanese Kanji Master</title>
<style>
*{box-sizing:border-box}
:root{color-scheme:dark}
body{margin:0;background:#0b1020;color:#f8fafc;font-family:Arial,Helvetica,sans-serif}
header{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:18px clamp(16px,4vw,44px);background:#111827;border-bottom:1px solid #263247}
.brand{font-weight:800}.brand span{color:#a5b4fc}
a{color:#c7d2fe;text-decoration:none}
a:hover{text-decoration:underline}
.header-links{display:flex;gap:16px;flex-wrap:wrap;font-size:14px}
main{width:min(1100px,92%);margin:36px auto 60px}
.eyebrow{color:#a5b4fc;text-transform:uppercase;letter-spacing:2px;font-size:12px}
h1{font-size:clamp(30px,4vw,42px);margin:10px 0}
.intro{color:#cbd5e1;line-height:1.65;max-width:700px}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:16px;margin-top:28px}
.card{display:flex;flex-direction:column;align-items:flex-start;min-height:210px;background:#151d31;border:1px solid #334155;border-radius:20px;padding:22px}
.card .icon{font-size:27px;margin-bottom:18px}
.card h2{font-size:20px;margin:0 0 9px}
.card p{color:#cbd5e1;font-size:14px;line-height:1.6;margin:0 0 20px}
.card a.action{margin-top:auto;display:inline-block;background:#a5b4fc;color:#111827;padding:11px 15px;border-radius:11px;font-weight:700}
.card.disabled{opacity:.72}
.status{display:inline-block;font-size:11px;letter-spacing:.4px;text-transform:uppercase;background:#263247;color:#cbd5e1;border-radius:99px;padding:6px 9px;margin-top:auto}
.welcome{padding:24px;background:linear-gradient(120deg,#171f38,#111827);border:1px solid #334155;border-radius:20px}
@media(max-width:600px){header{align-items:flex-start}.header-links{gap:10px}.grid{grid-template-columns:1fr}}

.progress-panel{margin-top:22px;padding:24px;background:#111827;border:1px solid #334155;border-radius:20px}
.progress-heading{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap}.progress-heading h2{font-size:25px;margin:8px 0}.progress-intro{color:#cbd5e1;margin:0;font-size:14px}
a.action{display:inline-block;background:#a5b4fc;color:#111827;padding:11px 15px;border-radius:11px;font-weight:700}
.stats-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-top:22px}.stat-card{background:#151d31;border:1px solid #334155;border-radius:14px;padding:17px;display:flex;flex-direction:column;gap:7px;min-width:0}.stat-label{font-size:13px;color:#cbd5e1}.stat-card strong{font-size:30px;line-height:1.2}.stat-note{font-size:11px;color:#94a3b8;line-height:1.4}
.progress-track{height:10px;background:#263247;border-radius:999px;overflow:hidden;margin-top:20px}.progress-fill{height:100%;background:linear-gradient(90deg,#818cf8,#34d399);border-radius:999px;transition:width .25s ease}
.status-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:16px}.status-row{display:flex;align-items:center;justify-content:space-between;gap:10px;background:#151d31;border:1px solid #334155;border-radius:12px;padding:12px;font-size:13px;color:#cbd5e1}.status-row strong{font-size:18px;color:#f8fafc}.dot{display:inline-block;width:9px;height:9px;border-radius:50%;margin-right:8px}.dot.remembered{background:#818cf8}.dot.needs{background:#fbbf24}.dot.notyet{background:#64748b}.progress-message{margin:16px 0 0;padding:11px 13px;background:#1e293b;border-radius:10px;color:#cbd5e1;font-size:13px;line-height:1.5}.progress-footnote{font-size:11px;color:#94a3b8;line-height:1.5;margin:14px 0 0}
@media(max-width:700px){.stats-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.status-grid{grid-template-columns:1fr}}

</style>
</head>
<body>
<header>
    <div class="brand">日本語 <span>Kanji Master</span></div>
    <nav class="header-links">
        <a href="study.php">Kanji Study</a>
        <a href="logout.php">Log out</a>
    </nav>
</header>
<main>
    <section class="welcome">
        <div class="eyebrow">Student dashboard</div>
        <h1>Welcome, <?php echo dashboard_h($name); ?>!</h1>
        <p class="intro">Build your Japanese kanji skills one step at a time. Study kanji, practise writing, and track your progress here.</p>
    </section>

    <section id="my-progress" class="progress-panel" aria-labelledby="progress-heading">
        <div class="progress-heading"><div><div class="eyebrow">Your learning summary</div><h2 id="progress-heading">My Progress</h2><p class="progress-intro">Only your own saved progress is shown here.</p></div><a class="action" href="practice.php">Continue practising →</a></div>
        <div class="stats-grid">
          <article class="stat-card"><span class="stat-label">Total kanji</span><strong><?php echo number_format($stats['total_kanji']); ?></strong><span class="stat-note">In the study library</span></article>
          <article class="stat-card"><span class="stat-label">Practised</span><strong><?php echo number_format($stats['practised']); ?></strong><span class="stat-note"><?php echo $percentPractised; ?>% of the library</span></article>
          <article class="stat-card"><span class="stat-label">Learned ⭐</span><strong><?php echo number_format($stats['learned']); ?></strong><span class="stat-note">Marked by you</span></article>
          <article class="stat-card"><span class="stat-label">Mastered</span><strong><?php echo number_format($stats['mastered']); ?></strong><span class="stat-note">Repeated matching stroke counts</span></article>
        </div>
        <div class="progress-track" role="progressbar" aria-label="Kanji practised" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo $percentPractised; ?>"><div class="progress-fill" style="width:<?php echo $percentPractised; ?>%"></div></div>
        <div class="status-grid">
          <div class="status-row"><span><i class="dot remembered"></i>Remembered</span><strong><?php echo number_format($stats['remembered']); ?></strong></div>
          <div class="status-row"><span><i class="dot needs"></i>Needs practice</span><strong><?php echo number_format($stats['needs_practice']); ?></strong></div>
          <div class="status-row"><span><i class="dot notyet"></i>Not practised</span><strong><?php echo number_format($stats['not_practiced']); ?></strong></div>
        </div>
        <?php if ($dbMessage !== ''): ?><p class="progress-message"><?php echo dashboard_h($dbMessage); ?></p><?php endif; ?>
        <p class="progress-footnote">Progress categories use saved practice summaries. Stroke-count matching is an estimate, not handwriting recognition.</p>
    </section>

    <section class="grid" aria-label="Learning tools">
        <article class="card">
            <div class="icon" aria-hidden="true">漢字</div>
            <h2>Kanji Study</h2>
            <p>Browse kanji, search by character or meaning, and filter your list by JLPT level.</p>
            <a class="action" href="study.php">Open Kanji Study →</a>
        </article>

        <article class="card">
            <div class="icon" aria-hidden="true">✍️</div>
            <h2>Writing Practice</h2>
            <p>Practise writing kanji on the digital canvas and save your practice progress.</p>
            <a class="action" href="practice.php">Start Writing Practice →</a>
        </article>

        <article class="card">
            <div class="icon" aria-hidden="true">📚</div>
            <h2>Assignments</h2>
            <p>View teacher-assigned kanji work and submit your practice when the assignment module is ready.</p>
            <span class="status">Coming soon</span>
        </article>

        <article class="card">
            <div class="icon" aria-hidden="true">📈</div>
            <h2>My Progress</h2>
            <p>Review your saved writing activity and learning progress.</p>
            <a class="action" href="#my-progress">View My Progress ↓</a>
        </article>
    </section>
</main>
</body>
</html>
