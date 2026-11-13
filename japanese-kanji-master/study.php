<?php
session_start();
if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$studyRole = strtolower((string)(isset($_SESSION['user_role']) ? $_SESSION['user_role'] : 'student'));
if (in_array($studyRole, array('teacher', 'admin'), true)) { header('Location: teacher_dashboard.php'); exit; }
function kanji_h($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
$host = '127.0.0.1'; $port = '3306'; $dbname = 'kanji_master'; $dbUser = 'root'; $dbPassword = 'root';
$error = ''; $kanjiRows = array();
$search = trim(isset($_GET['q']) ? (string)$_GET['q'] : '');
$level = isset($_GET['level']) ? strtoupper(trim((string)$_GET['level'])) : 'N5';
$allowedLevels = array('ALL', 'N5', 'N4', 'N3', 'N2', 'N1');
if (!in_array($level, $allowedLevels, true)) { $level = 'N5'; }
try {
    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
    $pdo = new PDO($dsn, $dbUser, $dbPassword, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false));
    $sql = 'SELECT id, kanji_character, meaning, on_reading, kun_reading, jlpt_level, stroke_count FROM kanji WHERE 1=1';
    $params = array();
    if ($level !== 'ALL') { $sql .= ' AND UPPER(TRIM(jlpt_level)) = :level'; $params[':level'] = $level; }
    if ($search !== '') {
        $sql .= ' AND (kanji_character LIKE :q_character OR meaning LIKE :q_meaning OR on_reading LIKE :q_on_reading OR kun_reading LIKE :q_kun_reading)';
        $term = '%' . $search . '%';
        $params[':q_character'] = $term; $params[':q_meaning'] = $term; $params[':q_on_reading'] = $term; $params[':q_kun_reading'] = $term;
    }
    $sql .= " ORDER BY FIELD(UPPER(TRIM(jlpt_level)), 'N5','N4','N3','N2','N1'), kanji_character ASC";
    $stmt = $pdo->prepare($sql); $stmt->execute($params); $kanjiRows = $stmt->fetchAll();
} catch (PDOException $ex) {
    error_log('Kanji Master study page error: ' . $ex->getMessage());
    if (strpos($ex->getMessage(), 'Access denied') !== false) $error = 'Database login failed. Check the MySQL username and password near the top of study.php.';
    elseif (strpos($ex->getMessage(), 'Unknown database') !== false) $error = 'The kanji_master database was not found in phpMyAdmin.';
    elseif (strpos($ex->getMessage(), "doesn't exist") !== false || strpos($ex->getMessage(), 'Unknown column') !== false) $error = 'The kanji table columns do not match the expected names: kanji_character, meaning, on_reading, kun_reading, jlpt_level, stroke_count.';
    else $error = 'Could not load kanji. Confirm MAMP MySQL is running and the kanji table exists.';
}
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Kanji Study | Japanese Kanji Master</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#0b1020;color:#f8fafc;font-family:Arial,Helvetica,sans-serif}header{display:flex;justify-content:space-between;align-items:center;gap:14px;padding:18px clamp(16px,4vw,44px);background:#111827;border-bottom:1px solid #263247}.brand{font-weight:800}.brand span{color:#a5b4fc}a{color:#c7d2fe;text-decoration:none}.header-links{display:flex;gap:16px;flex-wrap:wrap;font-size:14px}main{width:min(1400px,92%);margin:30px auto 60px}.eyebrow{color:#a5b4fc;text-transform:uppercase;letter-spacing:2px;font-size:12px}h1{font-size:clamp(28px,4vw,40px);margin:10px 0}.muted{color:#cbd5e1;line-height:1.6}.filters{display:grid;grid-template-columns:1fr 180px auto;gap:10px;margin:24px 0}input,select,button{font:inherit;border-radius:11px;padding:13px;border:1px solid #475569}input,select{background:#111827;color:#f8fafc;min-width:0}button{background:#a5b4fc;color:#111827;font-weight:bold;border:0;cursor:pointer}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:15px}.card{background:#151d31;border:1px solid #334155;border-radius:18px;padding:20px}.card-top{display:flex;justify-content:space-between;align-items:center;gap:10px}.character{font-size:54px;font-weight:700;line-height:1.2}.badge{font-size:12px;color:#c7d2fe;background:#312e8155;padding:6px 9px;border-radius:99px}.meaning{font-size:18px;font-weight:bold;margin:12px 0}.detail{font-size:14px;color:#cbd5e1;line-height:1.65;overflow-wrap:anywhere}.detail strong{color:#f8fafc}.notice{padding:14px;border-radius:12px;line-height:1.5}.error{color:#fecaca;background:#7f1d1d55;border:1px solid #991b1b}.empty{padding:24px;background:#151d31;border:1px solid #334155;border-radius:16px;color:#cbd5e1}@media(max-width:600px){.filters{grid-template-columns:1fr}.header-links{gap:10px}header{align-items:flex-start}}
</style></head><body><header><div class="brand">日本語 <span>Kanji Master</span></div><nav class="header-links"><a href="dashboard.php">Dashboard</a><a href="logout.php">Log out</a></nav></header><main>
<div class="eyebrow">Your study library</div><h1>Kanji Study</h1><p class="muted">Search kanji by character, meaning, or reading. Choose a JLPT level to browse your study list.</p>
<form class="filters" method="get" action="study.php"><input type="search" name="q" value="<?php echo kanji_h($search); ?>" placeholder="Search character, meaning, or reading"><select name="level" aria-label="JLPT level" onchange="this.form.submit()">
<?php foreach ($allowedLevels as $option): ?><option value="<?php echo kanji_h($option); ?>" <?php echo $level === $option ? 'selected' : ''; ?>><?php echo $option === 'ALL' ? 'All levels' : 'JLPT ' . $option; ?></option><?php endforeach; ?></select><button type="submit">Search</button></form>
<?php if ($error !== ''): ?><p class="notice error"><?php echo kanji_h($error); ?></p><?php elseif (count($kanjiRows) === 0): ?><div class="empty"><strong>No kanji found for <?php echo kanji_h($level); ?>.</strong><p>Try another JLPT level or choose All levels.</p></div><?php else: ?><p class="muted">Showing <?php echo count($kanjiRows); ?> result(s) for <?php echo kanji_h($level === 'ALL' ? 'all JLPT levels' : 'JLPT ' . $level); ?>.</p><section class="grid" aria-label="Kanji results">
<?php foreach ($kanjiRows as $row): ?><article class="card"><div class="card-top"><div class="character"><?php echo kanji_h($row['kanji_character']); ?></div><span class="badge"><?php echo kanji_h($row['jlpt_level']); ?></span></div><div class="meaning"><?php echo kanji_h($row['meaning']); ?></div><div class="detail"><strong>On'yomi:</strong> <?php echo kanji_h($row['on_reading'] ? $row['on_reading'] : '—'); ?></div><div class="detail"><strong>Kun'yomi:</strong> <?php echo kanji_h($row['kun_reading'] ? $row['kun_reading'] : '—'); ?></div><div class="detail"><strong>Strokes:</strong> <?php echo kanji_h($row['stroke_count'] ? $row['stroke_count'] : '—'); ?></div></article><?php endforeach; ?></section><?php endif; ?></main></body></html>
