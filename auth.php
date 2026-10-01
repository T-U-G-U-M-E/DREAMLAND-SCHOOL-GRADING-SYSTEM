<?php
// Shared helpers for the teacher portal (teacher_dashboard.php, teacher_marks.php, change_password.php)
session_start();
require __DIR__ . '/db.php';

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
function csrf_field() { return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">'; }
function check_csrf() {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        http_response_code(400);
        die('Security check failed. Please go back, refresh the page and try again.');
    }
}

// Same password rules as register.php
function password_problems($p) {
    $m = [];
    if (strlen($p) < 8) $m[] = "At least 8 characters long";
    if (!preg_match('/[A-Z]/', $p)) $m[] = "At least one uppercase letter (A-Z)";
    if (!preg_match('/[a-z]/', $p)) $m[] = "At least one lowercase letter (a-z)";
    if (!preg_match('/[^A-Za-z0-9]/', $p)) $m[] = "At least one special character";
    return $m;
}

// Only logged-in, ACTIVE teachers who have already changed their temporary password get through.
function require_teacher() {
    global $conn;
    if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'teacher' || empty($_SESSION['teacher_id'])) {
        header('Location: login.php'); exit();
    }
    if (!empty($_SESSION['must_change'])) { header('Location: change_password.php'); exit(); }

    $tid = (int)$_SESSION['teacher_id'];
    $r = $conn->query("SELECT full_name, status FROM teachers WHERE id = $tid");
    $t = $r ? $r->fetch_assoc() : null;
    if (!$t || $t['status'] !== 'Active') {
        $_SESSION = array(); session_destroy();
        header('Location: login.php'); exit();
    }
    return $t;
}

function teacher_head($title, $who = '') { ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($title) ?> - Zika Foundation Schools</title>
<style>
:root { --brand:#1d4ed8; --brand-dark:#1e3a8a; --bg:#f1f5f9; --card:#fff; --text:#0f172a; --muted:#64748b; --line:#e2e8f0; --ok:#166534; --okbg:#dcfce7; --bad:#991b1b; --badbg:#fee2e2; }
* { box-sizing:border-box; }
body { margin:0; font-family:'Segoe UI',Arial,sans-serif; background:var(--bg); color:var(--text); }
.top { background:var(--brand-dark); color:#fff; padding:10px 24px; display:flex; align-items:center; gap:14px; flex-wrap:wrap; }
.top img { height:42px; background:#fff; border-radius:8px; padding:3px; }
.top .t { font-weight:700; flex:1; }
.top a { color:#cbd5e1; text-decoration:none; font-size:.9rem; padding:6px 10px; border-radius:6px; }
.top a:hover { background:rgba(255,255,255,.12); color:#fff; }
main { max-width:1000px; margin:24px auto; padding:0 16px; }
h1 { font-size:1.4rem; margin:0 0 4px; } .sub { color:var(--muted); margin-bottom:20px; }
.alert { padding:12px 16px; border-radius:8px; margin-bottom:16px; font-weight:600; }
.alert.success { background:var(--okbg); color:var(--ok); } .alert.error { background:var(--badbg); color:var(--bad); }
.cards { display:grid; grid-template-columns:repeat(auto-fill,minmax(270px,1fr)); gap:16px; }
.card { background:var(--card); border-radius:12px; padding:20px; box-shadow:0 1px 3px rgba(0,0,0,.08); }
.card.accent { border-left:5px solid var(--brand); }
.card h3 { margin:0 0 4px; } .muted { color:var(--muted); font-size:.88rem; }
.btn { display:inline-block; background:var(--brand); color:#fff; border:0; padding:9px 18px; border-radius:8px; font-weight:600; cursor:pointer; font-size:.92rem; text-decoration:none; }
.btn:hover { background:var(--brand-dark); } .btn.small { padding:5px 10px; font-size:.8rem; } .btn.red { background:#dc2626; } .btn.red:hover { background:#991b1b; }
.btn:disabled { background:#9ca3af; cursor:not-allowed; }
table { width:100%; border-collapse:collapse; font-size:.92rem; }
th { background:#f8fafc; text-align:left; padding:10px; color:var(--muted); font-size:.78rem; text-transform:uppercase; border-bottom:2px solid var(--line); }
td { padding:8px 10px; border-bottom:1px solid var(--line); vertical-align:middle; }
.avatar { width:38px; height:38px; border-radius:50%; object-fit:cover; }
input, select { padding:8px 10px; border:1px solid #cbd5e1; border-radius:8px; font:inherit; }
input.score { width:90px; } input.bad { border-color:#dc2626; background:#fef2f2; }
.grade { font-weight:700; color:var(--brand); display:inline-block; min-width:24px; }
.bar { display:flex; gap:12px; align-items:center; flex-wrap:wrap; margin-bottom:14px; }
.stats { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:14px; }
.stat { background:#eff6ff; border-radius:8px; padding:8px 14px; font-size:.88rem; } .stat b { font-size:1.1rem; }
.tablewrap { overflow-x:auto; }
@media (max-width:600px) {
    .top { padding:10px 14px; gap:8px; }
    .top .t { flex-basis:100%; }
    main { margin:18px auto; }
    .card { padding:16px; }
    .stats { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); }
    .bar { align-items:flex-start; }
}
</style>
</head>
<body>
<div class="top">
    <img src="uploads/school_badge.png" alt="">
    <div class="t">Zika Foundation Schools · Teacher Portal</div>
    <?php if ($who): ?><span style="font-size:.9rem"><?= e($who) ?></span><?php endif; ?>
    <a href="teacher_dashboard.php">My Classes</a>
    <a href="change_password.php">Change Password</a>
    <a href="logout.php" style="color:#fecaca">Logout</a>
</div>
<main>
<?php }

function teacher_foot() { echo "</main>\n</body>\n</html>"; }
