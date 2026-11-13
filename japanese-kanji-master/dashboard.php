<?php
session_start();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

function dashboard_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$name = isset($_SESSION['user_name']) && $_SESSION['user_name'] !== ''
    ? $_SESSION['user_name']
    : 'Student';
$role = isset($_SESSION['user_role']) ? $_SESSION['user_role'] : 'student';
$unreadNotifications = 0;
try {
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=kanji_master;charset=utf8mb4', 'root', 'root', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec("CREATE TABLE IF NOT EXISTS student_notifications (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, notification_type VARCHAR(40) NOT NULL, title VARCHAR(180) NOT NULL, message VARCHAR(500) NOT NULL, target_url VARCHAR(500) NOT NULL, is_read TINYINT(1) NOT NULL DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_student_notifications_user (user_id, is_read, created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $n = $pdo->prepare('SELECT COUNT(*) FROM student_notifications WHERE user_id=? AND is_read=0'); $n->execute([(int)$_SESSION['user_id']]); $unreadNotifications = (int)$n->fetchColumn();
} catch (Throwable $e) { error_log('Kanji Master notifications: '.$e->getMessage()); }

if ($role !== 'student') {
    http_response_code(403);
    exit('This dashboard is for student accounts.');
}
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
.header-left{display:flex;align-items:center;gap:12px}.back-link{display:inline-flex;align-items:center;justify-content:center;width:38px;height:38px;border:1px solid #334155;border-radius:10px;background:#1f2937;color:#fff;font-size:24px;line-height:1;flex-shrink:0}.brand{font-weight:800}.brand span{color:#a5b4fc}
a{color:#c7d2fe;text-decoration:none}
a:hover{text-decoration:underline}
.header-links{display:flex;gap:16px;flex-wrap:wrap;font-size:14px;align-items:center}.bell-link{position:relative;display:inline-flex;align-items:center;gap:7px;padding:8px 11px;border:1px solid #334155;border-radius:10px;background:#1f2937;color:#fff}.bell-icon{font-size:20px}.bell-count{display:inline-flex;min-width:19px;height:19px;align-items:center;justify-content:center;padding:0 5px;border-radius:99px;background:#ef4444;color:white;font-size:11px;font-weight:800}
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
</style>
</head>
<body>
<header>
    <div class="header-left"><a class="back-link" href="javascript:history.back()" onclick="if(history.length<=1){window.location.href='login.php';return false;}" aria-label="Go back" title="Go back">←</a><div class="brand">日本語 <span>Kanji Master</span></div></div>
    <nav class="header-links">
        <a class="bell-link" href="notifications.php" aria-label="Notifications<?= $unreadNotifications ? ', ' . $unreadNotifications . ' unread' : '' ?>"><span class="bell-icon" aria-hidden="true">🔔</span><span>Notifications</span><?php if ($unreadNotifications > 0): ?><span class="bell-count"><?= $unreadNotifications > 99 ? '99+' : $unreadNotifications ?></span><?php endif; ?></a>
        <a href="progress.php">My Progress</a>
        <a href="study_activity.php">Study Activity</a>
        <a href="study_streak.php">Study Streak</a>
        <a href="study_achievements.php">Achievements</a>
        <a href="leaderboard.php">Leaderboard</a>
        <a href="study.php">Kanji Study</a>
        <a href="logout.php">Log out</a>
    </nav>
</header>
<main>
    <section class="welcome">
        <div class="eyebrow">Student dashboard</div>
        <h1>Welcome, <?php echo dashboard_h($name); ?>!</h1>
        <p class="intro">Build your Japanese kanji skills one step at a time. Study meanings and readings now; more practice and progress tools will be added next.</p>
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
            <p>Practise writing kanji on a digital canvas. The practice tool is the next feature to build.</p>
            <a class="action" href="practice.php">Start Writing Practice →</a>
        </article>

        <article class="card">
            <div class="icon" aria-hidden="true">📚</div>
            <h2>Assignments</h2>
            <p>Open PDF practice worksheets assigned by your teacher, review the due date, and mark your work as submitted.</p>
            <a class="action" href="student_assignments.php">View My Assignments →</a>
        </article>

        <article class="card">
            <div class="icon" aria-hidden="true">📈</div>
            <h2>My Progress</h2>
            <p>Track assignment completion, grades, and teacher feedback in one place.</p>
            <a class="action" href="progress.php">View My Progress →</a>
        </article>
        <article class="card">
            <div class="icon" aria-hidden="true">📊</div>
            <h2>Study Activity</h2>
            <p>Review your saved writing attempts, total practice time, and daily practice frequency.</p>
            <a class="action" href="study_activity.php">View Study Activity →</a>
        </article>
        <article class="card"><div class="icon" aria-hidden="true">🔥</div><h2>Study Streak & Weekly Goal</h2><p>Track consecutive study days and set a weekly practice goal.</p><a class="action" href="study_streak.php">View Streak & Goal →</a></article>
        <article class="card"><div class="icon" aria-hidden="true">🏆</div><h2>Achievements</h2><p>Earn badges for practice milestones, exploring kanji, and building a regular study habit.</p><a class="action" href="study_achievements.php">View Achievements →</a></article>
        <article class="card"><div class="icon" aria-hidden="true">🥇</div><h2>Weekly Leaderboard</h2><p>Compare your saved writing-practice activity with other students this week.</p><a class="action" href="leaderboard.php">View Leaderboard →</a></article>
    </section>
</main>
</body>
</html>
