<?php
session_start();
if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$role = strtolower((string)($_SESSION['user_role'] ?? 'student'));
if ($role !== 'student') { http_response_code(403); exit('The student leaderboard is available to student accounts.'); }
function lb_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
$rows = array(); $myRank = null; $myAttempts = 0; $myDays = 0; $error = '';
$weekStart = date('Y-m-d', strtotime('monday this week'));
$nextWeek = date('Y-m-d', strtotime('monday next week'));
try {
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=kanji_master;charset=utf8mb4', 'root', 'root', array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
    // Only include students who have at least one saved writing attempt this week.
    $sql = "SELECT u.id, u.name, COUNT(p.id) AS attempts, COUNT(DISTINCT p.kanji_id) AS different_kanji, COUNT(DISTINCT DATE(p.created_at)) AS active_days
            FROM users u INNER JOIN practice_attempts p ON p.user_id=u.id
            WHERE u.role='student' AND p.created_at >= ? AND p.created_at < ?
            GROUP BY u.id, u.name
            ORDER BY attempts DESC, different_kanji DESC, active_days DESC, u.name ASC";
    $st = $pdo->prepare($sql); $st->execute(array($weekStart, $nextWeek)); $rows = $st->fetchAll();
    $rank = 0; $position = 0; $prev = null;
    foreach ($rows as $r) {
        $position++;
        $score = (int)$r['attempts'];
        if ($prev === null || $score !== $prev) $rank = $position;
        $rows[$position-1]['rank'] = $rank;
        $prev = $score;
        if ((int)$r['id'] === (int)$_SESSION['user_id']) {
            $myRank = $rank; $myAttempts = $score; $myDays = (int)$r['active_days'];
        }
    }
} catch (Throwable $e) {
    error_log('Kanji Master leaderboard: '.$e->getMessage());
    $error = 'Could not load the leaderboard. Check that MAMP and MySQL are running and that writing practice has saved attempts.';
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Weekly Leaderboard | Kanji Master</title><style>
*{box-sizing:border-box}body{margin:0;background:#0b1020;color:#f8fafc;font:15px/1.55 Arial,Helvetica,sans-serif}a{color:#c7d2fe;text-decoration:none}a:hover{text-decoration:underline}header{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;padding:18px 4%;background:#111827;border-bottom:1px solid #263247}.brand{font-weight:800}.brand span{color:#a5b4fc}nav{display:flex;gap:8px;flex-wrap:wrap}nav a{padding:8px 10px;border:1px solid #334155;border-radius:9px;background:#151d31}main{width:min(1000px,94%);margin:30px auto 60px}.eyebrow{color:#a5b4fc;text-transform:uppercase;letter-spacing:1.7px;font-size:12px}h1{font-size:clamp(28px,4vw,38px);margin:8px 0}.muted{color:#cbd5e1}.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin:22px 0}.card,.table-wrap{background:#151d31;border:1px solid #334155;border-radius:16px;padding:18px}.label{color:#cbd5e1;font-size:13px}.num{font-size:32px;font-weight:800;margin-top:5px}.table-wrap{padding:0;overflow:hidden}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:14px 16px;border-bottom:1px solid #29364b}th{font-size:12px;text-transform:uppercase;letter-spacing:.7px;color:#cbd5e1;background:#111827}tbody tr:last-child td{border-bottom:0}.you{background:#202b45}.rank{font-weight:800}.medal{font-size:19px}.you-tag{font-size:11px;border-radius:99px;background:#a5b4fc;color:#111827;padding:3px 7px;margin-left:7px;font-weight:800}.notice{padding:13px 15px;border-radius:11px;background:#7f1d1d;color:#fecaca;margin:14px 0}.empty{padding:24px;text-align:center;color:#cbd5e1}.foot{font-size:13px;color:#cbd5e1;margin-top:14px}@media(max-width:600px){th,td{padding:11px 8px;font-size:13px}.hide-mobile{display:none}header{align-items:flex-start}}
</style></head><body><header><div class="brand">日本語 <span>Kanji Master</span> · Leaderboard</div><nav><a href="dashboard.php">← Dashboard</a><a href="practice.php">Writing practice</a><a href="study_activity.php">Study activity</a><a href="logout.php">Log out</a></nav></header><main><div class="eyebrow">Friendly competition</div><h1>Weekly student leaderboard 🏆</h1><p class="muted">Rankings are based on saved writing-practice attempts from Monday through Sunday this week. Only students with activity this week appear.</p>
<?php if($error): ?><div class="notice"><?=lb_h($error)?></div><?php endif; ?>
<section class="stats"><div class="card"><div class="label">Students on the board</div><div class="num"><?=count($rows)?></div></div><div class="card"><div class="label">Your weekly rank</div><div class="num"><?=$myRank===null?'—':'#'.(int)$myRank?></div></div><div class="card"><div class="label">Your saved attempts</div><div class="num"><?=number_format($myAttempts)?></div></div><div class="card"><div class="label">Your active days</div><div class="num"><?=number_format($myDays)?></div></div></section>
<div class="table-wrap"><table><thead><tr><th>Rank</th><th>Student</th><th>Attempts</th><th class="hide-mobile">Different kanji</th><th class="hide-mobile">Active days</th></tr></thead><tbody>
<?php if(!$rows): ?><tr><td colspan="5" class="empty">No saved writing attempts yet this week. Complete a writing practice and save it to appear here.</td></tr><?php else: foreach($rows as $r): $isMe=(int)$r['id']===(int)$_SESSION['user_id']; $rnk=(int)$r['rank']; ?><tr class="<?=$isMe?'you':''?>"><td class="rank"><?php if($rnk===1): ?>🥇<?php elseif($rnk===2): ?>🥈<?php elseif($rnk===3): ?>🥉<?php else: ?>#<?=$rnk?><?php endif; ?></td><td><?=lb_h($r['name'] ?: 'Student')?><?php if($isMe): ?><span class="you-tag">YOU</span><?php endif; ?></td><td><strong><?=number_format((int)$r['attempts'])?></strong></td><td class="hide-mobile"><?=number_format((int)$r['different_kanji'])?></td><td class="hide-mobile"><?=number_format((int)$r['active_days'])?></td></tr><?php endforeach; endif; ?>
</tbody></table></div><p class="foot">The leaderboard refreshes when you open or reload this page. It uses existing saved practice records and does not change your writing-practice system.</p></main></body></html>
