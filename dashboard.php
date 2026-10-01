<?php
session_start();
require __DIR__ . '/db.php';

if (!isset($_SESSION['admin_id'])) { header("Location: login.php"); exit(); }

$admin_id = (int)$_SESSION['admin_id'];
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

function e($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function post($k) { return trim($_POST[$k] ?? ''); }
function nul($v) { return $v === '' ? null : $v; }
function flash($type, $msg) { $_SESSION['flash'] = [$type, $msg]; }
function go($page) { header("Location: dashboard.php?page=" . $page); exit(); }

function uploadPhoto($file, &$err) {
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) { $err = "Please select a student photo."; return null; }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','gif'])) { $err = "Only JPG, PNG or GIF images are allowed."; return null; }
    if (@getimagesize($file['tmp_name']) === false) { $err = "The selected file is not a valid image."; return null; }
    if (!is_dir('uploads/')) mkdir('uploads/', 0755, true);
    $path = 'uploads/' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $path)) { $err = "Failed to upload photo."; return null; }
    return $path;
}

// ---------- Uganda secondary curriculum subjects ----------
if (empty($_SESSION['subjects_ready'])) {
    $curriculum_subjects = [
        ['Agriculture', 'Core'], ['Arabic', 'Elective'], ['Art and Design', 'Elective'],
        ['Ateso', 'Language'], ['Biology', 'Core'], ['Chemistry', 'Core'], ['Chinese', 'Elective'],
        ['Christian Religious Education', 'Elective'], ['Commerce', 'Elective'],
        ['Computer Studies', 'Elective'],
        ['Dhopadhola', 'Language'], ['Economics', 'Elective'], ['English', 'Core'],
        ['Divinity', 'Elective'], ['Entrepreneurship', 'Core'], ['Fine Art', 'Elective'],
        ['Food and Nutrition', 'Elective'], ['French', 'Elective'],
        ['General Paper', 'Elective'], ['Geography', 'Core'], ['German', 'Elective'],
        ['History and Political Education', 'Core'], ['Islamic Religious Education', 'Elective'],
        ['Kiswahili', 'Core'], ['Latin', 'Elective'], ['Leblango', 'Language'],
        ['Literature in English', 'Elective'], ['Luganda', 'Language'], ['Lumasaba', 'Language'],
        ['Lusoga', 'Language'], ['Mathematics', 'Core'], ['Ngakarimojong', 'Language'],
        ['Nutrition and Food Technology', 'Elective'], ['Performing Arts', 'Elective'],
        ['Physical Education', 'Core'], ['Physics', 'Core'], ['Runyankore-Rukiga', 'Language'],
        ['Runyoro-Rutooro', 'Language'], ['Subsidiary ICT', 'Elective'],
        ['Subsidiary Mathematics', 'Elective'], ['Technology and Design', 'Elective'],
        ['Technical Drawing', 'Elective']
    ];
    $subject_stmt = $conn->prepare("INSERT IGNORE INTO subjects (name, type) VALUES (?, ?)");
    $subjects_seeded = (bool)$subject_stmt;
    if ($subject_stmt) {
        foreach ($curriculum_subjects as [$subject_name, $subject_type]) {
            $subject_stmt->bind_param("ss", $subject_name, $subject_type);
            if (!$subject_stmt->execute()) $subjects_seeded = false;
        }
        $subject_stmt->close();
    }
    if ($subjects_seeded) $_SESSION['subjects_ready'] = 1;
}

// ---------- Ensure the current academic year has all three terms ----------
$academic_year = (int)date('Y');
if (($_SESSION['terms_ready_year'] ?? 0) !== $academic_year) {
    $existing_terms = [];
    $term_rows = $conn->query("SELECT term_no FROM terms WHERE academic_year = $academic_year");
    while ($term_rows && $term_row = $term_rows->fetch_assoc()) $existing_terms[] = (int)$term_row['term_no'];
    $has_current_term = (bool)$conn->query("SELECT id FROM terms WHERE is_current = 1 LIMIT 1")->fetch_assoc();
    $term_month = (int)date('n');
    $calendar_term = $term_month <= 4 ? 1 : ($term_month <= 8 ? 2 : 3);
    $term_stmt = $conn->prepare("INSERT INTO terms (academic_year, term_no, is_current) VALUES (?, ?, ?)");
    if ($term_stmt) {
        for ($term_no = 1; $term_no <= 3; $term_no++) {
            if (in_array($term_no, $existing_terms, true)) continue;
            $is_current = !$has_current_term && $term_no === $calendar_term ? 1 : 0;
            $term_stmt->bind_param("iii", $academic_year, $term_no, $is_current);
            if ($term_stmt->execute() && $is_current) $has_current_term = true;
        }
        $term_stmt->close();
        $_SESSION['terms_ready_year'] = $academic_year;
    }
}

// ---------- current term ----------
$cur = $conn->query("SELECT id, academic_year, term_no FROM terms WHERE is_current = 1 LIMIT 1")->fetch_assoc();
if (!$cur) $cur = $conn->query("SELECT id, academic_year, term_no FROM terms ORDER BY academic_year DESC, term_no DESC LIMIT 1")->fetch_assoc();
$cur_term_id = (int)($cur['id'] ?? 0);

// ---------- Senior One to Senior Six (created / renamed automatically) ----------
$senior_names = ['Senior One','Senior Two','Senior Three','Senior Four','Senior Five','Senior Six'];
if (empty($_SESSION['classes_ready'])) {
    foreach ($senior_names as $i => $nm) {
        $n = $i + 1;
        // rename an old-style class (S.1, S1, Senior 1) so existing students keep their class
        $conn->query("UPDATE classes SET name = '$nm' WHERE name IN ('S.$n','S$n','Senior $n')
                      AND NOT EXISTS (SELECT 1 FROM (SELECT name FROM classes) x WHERE x.name = '$nm')");
        $conn->query("INSERT IGNORE INTO classes (name) VALUES ('$nm')");
    }
    $_SESSION['classes_ready'] = 1;
}
$senior_classes = []; $by_name = [];
$r = $conn->query("SELECT id, name FROM classes WHERE name IN ('" . implode("','", $senior_names) . "')");
while ($r && $x = $r->fetch_assoc()) $by_name[$x['name']] = $x;
foreach ($senior_names as $nm) if (isset($by_name[$nm])) $senior_classes[] = $by_name[$nm];
$senior_ids = array_map('intval', array_column($senior_classes, 'id'));

// ---------- POST actions (Post/Redirect/Get) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, $_POST['csrf'] ?? '')) { flash('error', 'Security check failed. Please try again.'); go('overview'); }
    $action = $_POST['action'] ?? '';

    // ---- Register student + guardians ----
    if ($action === 'add_student') {
        $name = post('student_name');
        $sex = post('sex');
        $dob = post('dob');
        $g1_name = post('g1_name'); $g1_phone = post('g1_phone');
        $err = '';
        if ($name === '' || !in_array($sex, ['Male','Female']) || $dob === '') $err = "Name, sex and date of birth are required.";
        elseif ($dob > date('Y-m-d')) $err = "Date of birth cannot be in the future.";
        elseif (!in_array((int)($_POST['class_id'] ?? 0), $senior_ids, true)) $err = "Please select the student's class (Senior One to Senior Six).";
        elseif ($g1_name === '' || $g1_phone === '') $err = "At least one parent/guardian with a phone number is required.";
        if ($err === '') $photo = uploadPhoto($_FILES['student_photo'] ?? null, $err);

        if ($err !== '') { flash('error', $err); go('add_student'); }

        $class_id = !empty($_POST['class_id']) ? (int)$_POST['class_id'] : null;
        $nationality = post('nationality') ?: 'Ugandan';
        $religion = nul(post('religion')); $address = nul(post('home_address')); $district = nul(post('district'));
        $prev = nul(post('previous_school')); $adm_date = post('admission_date') ?: date('Y-m-d'); $med = nul(post('medical_notes'));

        $conn->begin_transaction();
        $stmt = $conn->prepare("INSERT INTO students (name, sex, date_of_birth, nationality, religion, home_address, district, previous_school, admission_date, medical_notes, photo, id_user, class_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param("sssssssssssii", $name, $sex, $dob, $nationality, $religion, $address, $district, $prev, $adm_date, $med, $photo, $admin_id, $class_id);
        $ok = $stmt->execute();
        $sid = $conn->insert_id;
        $stmt->close();

        if ($ok) {
            $adm_no = sprintf("ZFS%s%04d", date('Y'), $sid);
            $conn->query("UPDATE students SET admission_no = '$adm_no' WHERE id = $sid");

            $gs = $conn->prepare("INSERT INTO guardians (student_id, relationship, full_name, phone, alt_phone, email, occupation, address, is_primary) VALUES (?,?,?,?,?,?,?,?,?)");
            foreach ([1, 2] as $n) {
                $gname = post("g{$n}_name");
                if ($gname === '') continue;
                $rel = post("g{$n}_rel") ?: 'Guardian'; $ph = post("g{$n}_phone"); $alt = nul(post("g{$n}_alt"));
                $em = nul(post("g{$n}_email")); $occ = nul(post("g{$n}_occ")); $ad = nul(post("g{$n}_address"));
                $prim = $n === 1 ? 1 : 0;
                $gs->bind_param("isssssssi", $sid, $rel, $gname, $ph, $alt, $em, $occ, $ad, $prim);
                if (!$gs->execute()) { $ok = false; break; }
            }
            $gs->close();
        }
        if ($ok) { $conn->commit(); flash('success', "Student registered. Admission No: $adm_no"); go('students'); }
        $conn->rollback();
        flash('error', "Database error: " . $conn->error);
        go('add_student');
    }

    // ---- Delete student and related records ----
    if ($action === 'delete_student') {
        $sid = (int)($_POST['student_id'] ?? 0);
        if ($sid < 1) { flash('error', 'Invalid student selected.'); go('students'); }

        $conn->begin_transaction();
        $delete_ok = true;
        foreach ([
            ['guardians', 'student_id'],
            ['student_marks', 'student_id'],
            ['students', 'id']
        ] as [$table, $column]) {
            $stmt = $conn->prepare("DELETE FROM $table WHERE $column = ?");
            if (!$stmt) { $delete_ok = false; break; }
            $stmt->bind_param('i', $sid);
            $delete_ok = $stmt->execute();
            if ($table === 'students' && $stmt->affected_rows !== 1) $delete_ok = false;
            $stmt->close();
            if (!$delete_ok) break;
        }

        if ($delete_ok) {
            $conn->commit();
            flash('success', 'Student and related records deleted.');
        } else {
            $conn->rollback();
            flash('error', 'Student could not be deleted. No records were removed.');
        }
        go('delete_student');
    }

    // ---- Delete teacher and related assignments ----
    if ($action === 'delete_teacher') {
        $tid = (int)($_POST['teacher_id'] ?? 0);
        if ($tid < 1) { flash('error', 'Invalid teacher selected.'); go('delete_teacher'); }

        $conn->begin_transaction();
        $delete_ok = true;
        $stmt = $conn->prepare("DELETE FROM teacher_assignments WHERE teacher_id = ?");
        if (!$stmt) $delete_ok = false;
        else {
            $stmt->bind_param('i', $tid);
            $delete_ok = $stmt->execute();
            $stmt->close();
        }

        if ($delete_ok) {
            $stmt = $conn->prepare("UPDATE classes SET class_teacher_id = NULL WHERE class_teacher_id = ?");
            if (!$stmt) $delete_ok = false;
            else {
                $stmt->bind_param('i', $tid);
                $delete_ok = $stmt->execute();
                $stmt->close();
            }
        }

        if ($delete_ok) {
            $stmt = $conn->prepare("DELETE FROM teachers WHERE id = ?");
            if (!$stmt) $delete_ok = false;
            else {
                $stmt->bind_param('i', $tid);
                $delete_ok = $stmt->execute() && $stmt->affected_rows === 1;
                $stmt->close();
            }
        }

        if ($delete_ok) {
            $conn->commit();
            flash('success', 'Teacher and related assignments deleted. Their login has been disabled.');
        } else {
            $conn->rollback();
            flash('error', 'Teacher could not be deleted. No records were removed.');
        }
        go('delete_teacher');
    }

    // ---- Register teacher ----
    if ($action === 'add_teacher') {
        $name = post('full_name'); $sex = post('sex'); $phone = post('phone');
        if ($name === '' || $phone === '' || !in_array($sex, ['Male','Female'])) { flash('error', 'Name, sex and phone are required.'); go('teachers'); }
        $dob = nul(post('dob')); $email = nul(post('email')); $nin = nul(post('nin'));
        $qual = nul(post('qualification')); $spec = nul(post('specialization')); $addr = nul(post('address'));
        $joined = post('date_joined') ?: date('Y-m-d');
        $stmt = $conn->prepare("INSERT INTO teachers (full_name, sex, date_of_birth, phone, email, nin, qualification, specialization, address, date_joined, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param("ssssssssssi", $name, $sex, $dob, $phone, $email, $nin, $qual, $spec, $addr, $joined, $admin_id);
        if ($stmt->execute()) {
            $tid = $conn->insert_id;
            $conn->query(sprintf("UPDATE teachers SET teacher_no = 'ZFT%04d' WHERE id = %d", $tid, $tid));
            flash('success', "Teacher $name added.");
        } else flash('error', "Database error: " . $stmt->error);
        go('teachers');
    }

    // ---- Teacher login accounts ----
    if ($action === 'create_login') {
        $tid = (int)$_POST['teacher_id']; $un = post('username'); $pw = $_POST['password'] ?? '';
        if ($un === '' || strlen($pw) < 6) { flash('error', 'Enter a username and a temporary password of at least 6 characters.'); go('teachers'); }
        $chk = $conn->query("SELECT id FROM teachers WHERE id = $tid AND user_id IS NULL")->fetch_assoc();
        if (!$chk) { flash('error', 'This teacher already has a login.'); go('teachers'); }
        $hash = password_hash($pw, PASSWORD_DEFAULT);
        $conn->begin_transaction();
        $stmt = $conn->prepare("INSERT INTO users (username, password, role, must_change_password) VALUES (?, ?, 'teacher', 1)");
        $stmt->bind_param("ss", $un, $hash);
        if ($stmt->execute()) {
            $uid = $conn->insert_id;
            $conn->query("UPDATE teachers SET user_id = $uid WHERE id = $tid");
            $conn->commit();
            flash('success', "Login created. Give the teacher the username \"$un\" and the temporary password. They must change it at first login.");
        } else {
            $conn->rollback();
            flash('error', $stmt->errno == 1062 ? 'That username is already taken. Choose another.' : 'Could not create login: ' . $stmt->error);
        }
        go('teachers');
    }

    if ($action === 'reset_password') {
        $tid = (int)$_POST['teacher_id']; $pw = $_POST['password'] ?? '';
        if (strlen($pw) < 6) { flash('error', 'Temporary password must be at least 6 characters.'); go('teachers'); }
        $hash = password_hash($pw, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users u JOIN teachers t ON t.user_id = u.id SET u.password = ?, u.must_change_password = 1 WHERE t.id = ?");
        $stmt->bind_param("si", $hash, $tid);
        $stmt->execute();
        flash('success', 'Password reset. Give the teacher the new temporary password; they must change it at next login.');
        go('teachers');
    }

    if ($action === 'set_teacher_status') {
        $st = $_POST['status'] ?? 'Active';
        if (in_array($st, ['Active','On Leave','Left'])) {
            $stmt = $conn->prepare("UPDATE teachers SET status = ? WHERE id = ?");
            $tid = (int)$_POST['teacher_id'];
            $stmt->bind_param("si", $st, $tid); $stmt->execute();
            flash('success', 'Teacher status updated.');
        }
        go('teachers');
    }

    // ---- Assign work ----
    if ($action === 'assign_subject') {
        $t = (int)$_POST['teacher_id']; $c = (int)$_POST['class_id']; $s = (int)$_POST['subject_id'];
        $term = (int)$_POST['term_id']; $ppw = nul(post('periods')); $ppw = $ppw === null ? null : (int)$ppw;
        $stmt = $conn->prepare("INSERT INTO teacher_assignments (teacher_id, class_id, subject_id, term_id, periods_per_week, assigned_by) VALUES (?,?,?,?,?,?)");
        $stmt->bind_param("iiiiii", $t, $c, $s, $term, $ppw, $admin_id);
        if ($stmt->execute()) flash('success', 'Work assigned successfully.');
        elseif ($stmt->errno == 1062) flash('error', 'That subject in that class already has a teacher for this term. Remove the old assignment first.');
        else flash('error', 'Could not assign: ' . $stmt->error);
        go('assignments');
    }

    if ($action === 'assign_class_teacher') {
        $c = (int)$_POST['class_id']; $t = !empty($_POST['teacher_id']) ? (int)$_POST['teacher_id'] : null;
        $stmt = $conn->prepare("UPDATE classes SET class_teacher_id = ? WHERE id = ?");
        $stmt->bind_param("ii", $t, $c);
        $stmt->execute();
        flash('success', 'Class teacher updated.');
        go('assignments');
    }

    if ($action === 'remove_assignment') {
        $aid = (int)$_POST['assignment_id'];
        $conn->query("DELETE FROM teacher_assignments WHERE id = $aid");
        flash('success', 'Assignment removed.');
        go('assignments');
    }
}

// ---------- page + shared data ----------
$page = $_GET['page'] ?? 'overview';
if (!in_array($page, ['overview','students','delete_student','add_student','teachers','delete_teacher','assignments','reports'])) $page = 'overview';
$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);

function rows($conn, $sql) {
    $r = $conn->query($sql);
    if (!$r) {
        die("<div style='font-family:Arial;max-width:650px;margin:60px auto;padding:20px;background:#fee2e2;color:#991b1b;border-radius:8px'>
            <h3>Database not updated yet</h3>
            <p>MySQL says: <strong>" . htmlspecialchars($conn->error) . "</strong></p>
            <p>Open phpMyAdmin, select <code>user_system</code>, go to the SQL tab and run <code>modernise_database.sql</code>, then refresh this page.</p></div>");
    }
    return $r->fetch_all(MYSQLI_ASSOC);
}

$classes = rows($conn, "SELECT c.id, c.name, c.class_teacher_id, t.full_name AS ct_name FROM classes c LEFT JOIN teachers t ON t.id = c.class_teacher_id ORDER BY c.id");
$teachers_active = rows($conn, "SELECT id, full_name FROM teachers WHERE status = 'Active' ORDER BY full_name");

function opt($rows, $key, $label, $sel = null) {
    $o = '';
    foreach ($rows as $r) $o .= '<option value="' . (int)$r[$key] . '"' . ($sel == $r[$key] ? ' selected' : '') . '>' . e($r[$label]) . '</option>';
    return $o;
}
function age($dob) { return $dob ? (new DateTime($dob))->diff(new DateTime())->y : '-'; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard - Zika Foundation Schools</title>
<style>
:root { --brand:#1d4ed8; --brand-dark:#1e3a8a; --bg:#f1f5f9; --card:#fff; --text:#0f172a; --muted:#64748b; --line:#e2e8f0; --ok:#166534; --okbg:#dcfce7; --bad:#991b1b; --badbg:#fee2e2; }
* { box-sizing:border-box; }
body { margin:0; font-family:'Segoe UI',Arial,sans-serif; background:var(--bg); color:var(--text); display:flex; min-height:100vh; }
.sidebar { width:240px; background:var(--brand-dark); color:#fff; padding:20px 14px; position:sticky; top:0; height:100vh; flex-shrink:0; display:flex; flex-direction:column; }
.sidebar .logo { text-align:center; margin-bottom:22px; }
.sidebar .logo img { width:90px; background:#fff; border-radius:12px; padding:6px; }
.sidebar .logo div { font-weight:700; margin-top:8px; font-size:.95rem; line-height:1.2; }
.sidebar a { display:block; color:#cbd5e1; text-decoration:none; padding:10px 14px; border-radius:8px; margin-bottom:4px; font-size:.95rem; }
.sidebar a:hover { background:rgba(255,255,255,.1); color:#fff; }
.sidebar a.active { background:var(--brand); color:#fff; font-weight:600; }
.sidebar .spacer { flex:1; }
.sidebar a.logout { background:rgba(220,38,38,.2); color:#fecaca; }
main { flex:1; padding:28px; min-width:0; }
.topbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:22px; flex-wrap:wrap; gap:10px; }
.topbar h1 { margin:0; font-size:1.5rem; }
.pill { background:#dbeafe; color:var(--brand-dark); padding:6px 12px; border-radius:20px; font-size:.85rem; font-weight:600; }
.alert { padding:12px 16px; border-radius:8px; margin-bottom:18px; font-weight:600; }
.alert.success { background:var(--okbg); color:var(--ok); } .alert.error { background:var(--badbg); color:var(--bad); }
.stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:16px; margin-bottom:22px; }
.stat { background:var(--card); border-radius:12px; padding:18px; box-shadow:0 1px 3px rgba(0,0,0,.08); border-left:5px solid var(--brand); }
.stat .n { font-size:1.9rem; font-weight:700; } .stat .l { color:var(--muted); font-size:.85rem; }
.card { background:var(--card); border-radius:12px; padding:22px; box-shadow:0 1px 3px rgba(0,0,0,.08); margin-bottom:22px; }
.card h3 { margin:0 0 14px; font-size:1.1rem; }
.card h4 { margin:18px 0 10px; color:var(--brand-dark); border-bottom:2px solid var(--line); padding-bottom:6px; }
.grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(210px,1fr)); gap:14px; }
label { display:block; font-size:.82rem; font-weight:600; color:#334155; margin-bottom:4px; }
input, select, textarea { width:100%; padding:9px 10px; border:1px solid #cbd5e1; border-radius:8px; font:inherit; font-size:.92rem; background:#fff; }
input:focus, select:focus, textarea:focus { outline:none; border-color:var(--brand); box-shadow:0 0 0 3px rgba(29,78,216,.15); }
.btn { background:var(--brand); color:#fff; border:0; padding:10px 20px; border-radius:8px; font-weight:600; cursor:pointer; font-size:.95rem; }
.btn:hover { background:var(--brand-dark); }
.btn.small { padding:5px 10px; font-size:.8rem; } .btn.red { background:#dc2626; } .btn.red:hover { background:#991b1b; }
.tablewrap { overflow-x:auto; }
table { width:100%; border-collapse:collapse; font-size:.9rem; }
th { background:#f8fafc; text-align:left; padding:10px; color:var(--muted); font-size:.78rem; text-transform:uppercase; letter-spacing:.04em; border-bottom:2px solid var(--line); }
td { padding:10px; border-bottom:1px solid var(--line); vertical-align:middle; }
tr:hover td { background:#f8fafc; }
.avatar { width:42px; height:42px; border-radius:50%; object-fit:cover; }
.badge { padding:3px 10px; border-radius:20px; font-size:.75rem; font-weight:600; background:#e2e8f0; }
.badge.Active { background:var(--okbg); color:var(--ok); } .badge.Left, .badge.Suspended { background:var(--badbg); color:var(--bad); } .badge.On\ Leave { background:#fef9c3; color:#854d0e; }
.filters { display:flex; gap:10px; margin-bottom:14px; flex-wrap:wrap; } .filters input, .filters select { width:auto; min-width:180px; }
a.lnk { color:var(--brand); font-weight:600; text-decoration:none; font-size:.85rem; }
.two { display:grid; grid-template-columns:repeat(auto-fit,minmax(340px,1fr)); gap:22px; }
.bar { height:8px; background:var(--line); border-radius:4px; overflow:hidden; margin-top:4px; } .bar span { display:block; height:100%; background:var(--brand); }
details summary{cursor:pointer;color:var(--brand);font-weight:600;font-size:.82rem;margin-top:4px} details form{margin-top:8px;display:grid;gap:6px;min-width:200px}
@media (max-width:800px){ body{flex-direction:column;} .sidebar{width:100%;height:auto;position:static;flex-direction:row;flex-wrap:wrap;gap:4px;} .sidebar .logo,.sidebar .spacer{display:none;} .sidebar a{margin:0;} main{padding:16px;} }
@media (max-width:500px){ .two{grid-template-columns:minmax(0,1fr);} .card{padding:16px;} .topbar{align-items:flex-start;} .sidebar{padding:10px;} .sidebar a{padding:8px 10px;font-size:.85rem;} }
</style>
</head>
<body>

<aside class="sidebar">
    <div class="logo"><img src="uploads/school_badge.png" alt="Badge"><div>Zika Foundation Schools</div></div>
    <a href="?page=overview"    class="<?= $page=='overview'?'active':'' ?>">📊 Overview</a>
    <a href="?page=students"    class="<?= $page=='students'?'active':'' ?>">🎓 Students</a>
    <a href="?page=delete_student" class="<?= $page=='delete_student'?'active':'' ?>">🗑 Delete Student</a>
    <a href="?page=reports"     class="<?= $page=='reports'?'active':'' ?>">🧾 Reports</a>
    <a href="?page=add_student" class="<?= $page=='add_student'?'active':'' ?>">➕ Register Student</a>
    <a href="?page=teachers"    class="<?= $page=='teachers'?'active':'' ?>">👩‍🏫 Teachers</a>
    <a href="?page=delete_teacher" class="<?= $page=='delete_teacher'?'active':'' ?>">🗑 Delete Teacher</a>
    <a href="?page=assignments" class="<?= $page=='assignments'?'active':'' ?>">📋 Assign Work</a>
    <a href="marks.php">📝 Enter Marks</a>
    <div class="spacer"></div>
    <a href="logout.php" class="logout">⏻ Logout</a>
</aside>

<main>
<div class="topbar">
    <h1><?= ['overview'=>'Dashboard Overview','students'=>'Students','delete_student'=>'Delete Student','reports'=>'Academic Reports','add_student'=>'Register New Student','teachers'=>'Teachers','delete_teacher'=>'Delete Teacher','assignments'=>'Assign Work to Teachers'][$page] ?></h1>
    <span class="pill">Admin: <?= e($_SESSION['username'] ?? $admin_id) ?> &nbsp;|&nbsp; <?= $cur ? e($cur['academic_year']) . ' Term ' . e($cur['term_no']) : 'No term set' ?></span>
</div>

<?php if ($flash): ?><div class="alert <?= e($flash[0]) ?>"><?= e($flash[1]) ?></div><?php endif; ?>

<?php if ($page === 'overview'):
    $n_students = (int)$conn->query("SELECT COUNT(*) c FROM students WHERE status='Active'")->fetch_assoc()['c'];
    $n_boys = (int)$conn->query("SELECT COUNT(*) c FROM students WHERE status='Active' AND sex='Male'")->fetch_assoc()['c'];
    $n_girls = (int)$conn->query("SELECT COUNT(*) c FROM students WHERE status='Active' AND sex='Female'")->fetch_assoc()['c'];
    $n_teachers = (int)$conn->query("SELECT COUNT(*) c FROM teachers WHERE status='Active'")->fetch_assoc()['c'];
    $n_assign = (int)$conn->query("SELECT COUNT(*) c FROM teacher_assignments WHERE term_id = $cur_term_id")->fetch_assoc()['c'];
    $per_class = $conn->query("SELECT c.name, COUNT(s.id) n FROM classes c LEFT JOIN students s ON s.class_id = c.id AND s.status='Active' GROUP BY c.id ORDER BY c.id")->fetch_all(MYSQLI_ASSOC);
    $max = max(1, ...array_column($per_class ?: [['n'=>1]], 'n'));
    $recent = $conn->query("SELECT s.name, s.admission_no, c.name cn FROM students s LEFT JOIN classes c ON c.id=s.class_id ORDER BY s.id DESC LIMIT 5")->fetch_all(MYSQLI_ASSOC);
?>
    <div class="stats">
        <div class="stat"><div class="n"><?= $n_students ?></div><div class="l">Active Students</div></div>
        <div class="stat"><div class="n"><?= $n_boys ?> / <?= $n_girls ?></div><div class="l">Boys / Girls</div></div>
        <div class="stat"><div class="n"><?= $n_teachers ?></div><div class="l">Active Teachers</div></div>
        <div class="stat"><div class="n"><?= $n_assign ?></div><div class="l">Subject Assignments (this term)</div></div>
    </div>
    <div class="two">
        <div class="card"><h3>Students per Class</h3>
            <?php foreach ($per_class as $pc): ?>
                <div style="margin-bottom:10px"><div style="display:flex;justify-content:space-between"><span><?= e($pc['name']) ?></span><strong><?= (int)$pc['n'] ?></strong></div>
                <div class="bar"><span style="width:<?= round($pc['n'] / $max * 100) ?>%"></span></div></div>
            <?php endforeach; ?>
        </div>
        <div class="card"><h3>Recently Registered</h3>
            <?php if (!$recent): ?><p style="color:var(--muted)">No students yet.</p><?php endif; ?>
            <?php foreach ($recent as $r): ?>
                <div style="padding:8px 0;border-bottom:1px solid var(--line)"><strong><?= e($r['name']) ?></strong><br><small style="color:var(--muted)"><?= e($r['admission_no']) ?> · <?= e($r['cn'] ?? 'No class') ?></small></div>
            <?php endforeach; ?>
            <p><a class="lnk" href="?page=add_student">+ Register a student</a></p>
        </div>
    </div>

<?php elseif ($page === 'students'):
    $q = trim($_GET['q'] ?? ''); $fc = (int)($_GET['class'] ?? 0);
    $like = "%$q%";
    $stmt = $conn->prepare("SELECT s.*, c.name AS class_name, g.full_name AS gname, g.phone AS gphone, g.relationship AS grel
        FROM students s LEFT JOIN classes c ON c.id = s.class_id
        LEFT JOIN guardians g ON g.student_id = s.id AND g.is_primary = 1
        WHERE (s.name LIKE ? OR s.admission_no LIKE ?) AND (? = 0 OR s.class_id = ?) ORDER BY s.id DESC");
    $stmt->bind_param("ssii", $like, $like, $fc, $fc);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
    <div class="card">
        <form class="filters" method="GET"><input type="hidden" name="page" value="students">
            <input type="text" name="q" placeholder="Search name or admission no." value="<?= e($q) ?>">
            <select name="class"><option value="0">All classes</option><?= opt($classes, 'id', 'name', $fc) ?></select>
            <button class="btn" type="submit">Filter</button>
        </form>
        <div class="tablewrap"><table>
            <tr><th>Photo</th><th>Adm No</th><th>Name</th><th>Sex</th><th>Age</th><th>Class</th><th>Parent / Guardian</th><th>Status</th><th>Action</th></tr>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td><img class="avatar" src="<?= e($r['photo']) ?>" alt=""></td>
                <td><?= e($r['admission_no']) ?></td>
                <td><strong><?= e($r['name']) ?></strong></td>
                <td><?= e($r['sex'] ?? '-') ?></td>
                <td><?= age($r['date_of_birth']) ?></td>
                <td><?= e($r['class_name'] ?? '-') ?></td>
                <td><?= $r['gname'] ? e($r['gname']) . ' <small>(' . e($r['grel']) . ')</small><br><small>' . e($r['gphone']) . '</small>' : '-' ?></td>
                <td><span class="badge <?= e($r['status']) ?>"><?= e($r['status']) ?></span></td>
                <td><a class="lnk" href="marks.php?student_id=<?= (int)$r['id'] ?>">Marks</a> · <a class="lnk" href="report.php?student_id=<?= (int)$r['id'] ?>">Report</a></td>
            </tr>
            <?php endforeach; if (!$rows): ?><tr><td colspan="9" style="text-align:center;color:var(--muted)">No students found.</td></tr><?php endif; ?>
        </table></div>
    </div>

<?php elseif ($page === 'delete_student'):
    $delete_students = rows($conn, "SELECT s.id, s.name, s.admission_no, c.name AS class_name FROM students s LEFT JOIN classes c ON c.id = s.class_id ORDER BY s.name");
?>
    <div class="card">
        <div class="tablewrap"><table>
            <tr><th>Admission No</th><th>Student</th><th>Class</th><th>Action</th></tr>
            <?php foreach ($delete_students as $student): ?>
            <tr>
                <td><?= e($student['admission_no']) ?></td>
                <td><strong><?= e($student['name']) ?></strong></td>
                <td><?= e($student['class_name'] ?? '-') ?></td>
                <td>
                    <form method="POST" style="display:inline" onsubmit="return confirm(<?= e(json_encode('Are you sure you want to permanently delete ' . $student['name'] . ' and all related records?')) ?>);">
                        <input type="hidden" name="csrf" value="<?= $csrf ?>">
                        <input type="hidden" name="action" value="delete_student">
                        <input type="hidden" name="student_id" value="<?= (int)$student['id'] ?>">
                        <button class="btn small red" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; if (!$delete_students): ?><tr><td colspan="4" style="text-align:center;color:var(--muted)">No students found.</td></tr><?php endif; ?>
        </table></div>
    </div>

<?php elseif ($page === 'add_student'): ?>
    <form method="POST" enctype="multipart/form-data" class="card">
        <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="add_student">

        <h4 style="margin-top:0">Student Details</h4>
        <div class="grid">
            <div><label>Full Name *</label><input type="text" name="student_name" required></div>
            <div><label>Sex *</label><select name="sex" required><option value="">-- Select --</option><option>Male</option><option>Female</option></select></div>
            <div><label>Date of Birth *</label><input type="date" name="dob" max="<?= date('Y-m-d') ?>" required></div>
            <div><label>Class *</label><select name="class_id" required><option value="">-- Select Class --</option><?= opt($senior_classes, 'id', 'name') ?></select></div>
            <div><label>Nationality</label><input type="text" name="nationality" value="Ugandan"></div>
            <div><label>Religion</label><input type="text" name="religion"></div>
            <div><label>District</label><input type="text" name="district"></div>
            <div><label>Home Address / Village</label><input type="text" name="home_address"></div>
            <div><label>Previous School</label><input type="text" name="previous_school"></div>
            <div><label>Admission Date</label><input type="date" name="admission_date" value="<?= date('Y-m-d') ?>"></div>
            <div><label>Passport Photo *</label><input type="file" name="student_photo" accept="image/*" required></div>
        </div>
        <div style="margin-top:14px"><label>Medical Notes / Allergies</label><textarea name="medical_notes" rows="2"></textarea></div>

        <?php foreach ([1 => 'Parent / Guardian 1 (Primary) *', 2 => 'Parent / Guardian 2 (Optional)'] as $n => $title): ?>
        <h4><?= $title ?></h4>
        <div class="grid">
            <div><label>Relationship</label><select name="g<?= $n ?>_rel"><?php foreach (['Father','Mother','Guardian','Uncle','Aunt','Grandparent','Sibling'] as $rel) echo "<option>$rel</option>"; ?></select></div>
            <div><label>Full Name<?= $n == 1 ? ' *' : '' ?></label><input type="text" name="g<?= $n ?>_name" <?= $n == 1 ? 'required' : '' ?>></div>
            <div><label>Phone<?= $n == 1 ? ' *' : '' ?></label><input type="tel" name="g<?= $n ?>_phone" <?= $n == 1 ? 'required' : '' ?>></div>
            <div><label>Alternative Phone</label><input type="tel" name="g<?= $n ?>_alt"></div>
            <div><label>Email</label><input type="email" name="g<?= $n ?>_email"></div>
            <div><label>Occupation</label><input type="text" name="g<?= $n ?>_occ"></div>
            <div><label>Address</label><input type="text" name="g<?= $n ?>_address"></div>
        </div>
        <?php endforeach; ?>
        <p><button class="btn" type="submit">Register Student</button></p>
    </form>

<?php elseif ($page === 'reports'):
    $report_exam_id = (int)($_GET['report_exam_id'] ?? 0);
    $report_exam_rows = $conn->query("SELECT e.id, e.name, t.academic_year, t.term_no FROM exams e JOIN terms t ON t.id = e.term_id ORDER BY t.academic_year DESC, t.term_no DESC, e.id DESC")->fetch_all(MYSQLI_ASSOC);
    if (!$report_exam_id && $report_exam_rows) $report_exam_id = (int)$report_exam_rows[0]['id'];
    $report_students = $conn->query("SELECT s.id, s.name, c.name AS class_name FROM students s LEFT JOIN classes c ON c.id = s.class_id ORDER BY c.name, s.name")->fetch_all(MYSQLI_ASSOC);
?>
    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
            <h3 style="margin:0;">Generated Student Reports</h3>
            <form method="GET" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <input type="hidden" name="page" value="reports">
                <select name="report_exam_id">
                    <?php foreach ($report_exam_rows as $ex): ?>
                        <option value="<?= (int)$ex['id'] ?>" <?= $report_exam_id == (int)$ex['id'] ? 'selected' : '' ?>><?= e($ex['academic_year']) ?> Term <?= (int)$ex['term_no'] ?> - <?= e($ex['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn small" type="submit">Load</button>
            </form>
        </div>
        <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;">
            <button class="btn small" type="button" onclick="printAllReports(<?= json_encode(array_map(function($s) use ($report_exam_id) { return 'report.php?student_id=' . (int)$s['id'] . '&exam_id=' . (int)$report_exam_id; }, $report_students)) ?>)">Print All Reports</button>
            <a class="btn small" href="report.php?class_id=0&exam_id=<?= (int)$report_exam_id ?>&mode=class" target="_blank" style="text-decoration:none;display:inline-block;">View Class Assessment</a>
        </div>
        <div class="tablewrap" style="margin-top:16px;">
            <table>
                <tr><th>Student</th><th>Class</th><th>Report</th><th>Quick Print</th></tr>
                <?php foreach ($report_students as $student): ?>
                    <tr>
                        <td><strong><?= e($student['name']) ?></strong></td>
                        <td><?= e($student['class_name'] ?? '-') ?></td>
                        <td><a class="lnk" href="report.php?student_id=<?= (int)$student['id'] ?>&exam_id=<?= (int)$report_exam_id ?>" target="_blank">View report</a></td>
                        <td><a class="lnk" href="#" onclick="printSingleReport('report.php?student_id=<?= (int)$student['id'] ?>&exam_id=<?= (int)$report_exam_id ?>'); return false;">Print</a></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>
    <script>
        function printSingleReport(url) {
            const w = window.open(url, '_blank', 'noopener');
            if (w) {
                setTimeout(function() {
                    try { w.focus(); w.print(); } catch (e) {}
                }, 700);
            }
        }

        function printAllReports(urls) {
            if (!urls || !urls.length) return;
            urls.forEach(function(url, index) {
                const w = window.open(url, '_blank', 'noopener');
                if (w) {
                    setTimeout(function() {
                        try { w.focus(); w.print(); } catch (e) {}
                    }, 800 + index * 1200);
                }
            });
        }
    </script>
<?php elseif ($page === 'teachers'):
    $tl = $conn->query("SELECT t.*, u.username AS login_name, (SELECT COUNT(*) FROM teacher_assignments a WHERE a.teacher_id = t.id AND a.term_id = $cur_term_id) AS loads,
        (SELECT GROUP_CONCAT(c.name SEPARATOR ', ') FROM classes c WHERE c.class_teacher_id = t.id) AS ct FROM teachers t LEFT JOIN users u ON u.id = t.user_id ORDER BY t.status, t.full_name")->fetch_all(MYSQLI_ASSOC);
?>
    <div class="card">
        <h3>Register New Teacher</h3>
        <form method="POST"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="add_teacher">
        <div class="grid">
            <div><label>Full Name *</label><input type="text" name="full_name" required></div>
            <div><label>Sex *</label><select name="sex" required><option value="">-- Select --</option><option>Male</option><option>Female</option></select></div>
            <div><label>Date of Birth</label><input type="date" name="dob"></div>
            <div><label>Phone *</label><input type="tel" name="phone" required></div>
            <div><label>Email</label><input type="email" name="email"></div>
            <div><label>National ID (NIN)</label><input type="text" name="nin"></div>
            <div><label>Qualification</label><input type="text" name="qualification" placeholder="e.g. B.Ed Science"></div>
            <div><label>Teaching Subjects / Specialization</label><input type="text" name="specialization" placeholder="e.g. Physics, Maths"></div>
            <div><label>Address</label><input type="text" name="address"></div>
            <div><label>Date Joined</label><input type="date" name="date_joined" value="<?= date('Y-m-d') ?>"></div>
        </div>
        <p><button class="btn" type="submit">Add Teacher</button></p></form>
    </div>
    <div class="card"><h3>All Teachers (<?= count($tl) ?>)</h3>
        <div class="tablewrap"><table>
            <tr><th>No.</th><th>Name</th><th>Sex</th><th>Phone</th><th>Specialization</th><th>Class Teacher Of</th><th>Subject Loads</th><th>Status</th><th>Login Account</th></tr>
            <?php foreach ($tl as $t): ?>
            <tr>
                <td><?= e($t['teacher_no']) ?></td>
                <td><strong><?= e($t['full_name']) ?></strong><br><small style="color:var(--muted)"><?= e($t['qualification']) ?></small></td>
                <td><?= e($t['sex']) ?></td><td><?= e($t['phone']) ?></td><td><?= e($t['specialization'] ?? '-') ?></td>
                <td><?= e($t['ct'] ?? '-') ?></td><td><?= (int)$t['loads'] ?></td>
                <td><form method="POST" style="display:flex;gap:6px"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="set_teacher_status"><input type="hidden" name="teacher_id" value="<?= (int)$t['id'] ?>">
                    <select name="status" onchange="this.form.submit()" style="padding:4px"><?php foreach (['Active','On Leave','Left'] as $s) echo "<option" . ($s == $t['status'] ? ' selected' : '') . ">$s</option>"; ?></select></form></td>
                <td>
                <?php if ($t['login_name']): ?>
                    <strong><?= e($t['login_name']) ?></strong>
                    <details><summary>Reset password</summary>
                        <form method="POST"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="reset_password"><input type="hidden" name="teacher_id" value="<?= (int)$t['id'] ?>">
                        <input type="text" name="password" placeholder="New temporary password" minlength="6" required>
                        <button type="button" class="btn small" onclick="genPw(this)">Generate</button>
                        <button type="submit" class="btn small">Reset</button></form>
                    </details>
                <?php else: ?>
                    <details><summary>Create login</summary>
                        <form method="POST"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="create_login"><input type="hidden" name="teacher_id" value="<?= (int)$t['id'] ?>">
                        <input type="text" name="username" placeholder="Username" value="<?= e(strtolower(preg_replace('/[^A-Za-z]/', '', strtok($t['full_name'], ' ')))) ?>" required>
                        <input type="text" name="password" placeholder="Temporary password" minlength="6" required>
                        <button type="button" class="btn small" onclick="genPw(this)">Generate</button>
                        <button type="submit" class="btn small">Create</button></form>
                    </details>
                <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; if (!$tl): ?><tr><td colspan="9" style="text-align:center;color:var(--muted)">No teachers yet.</td></tr><?php endif; ?>
        </table></div>
    </div>

<?php elseif ($page === 'delete_teacher'):
    $delete_teachers = rows($conn, "SELECT id, teacher_no, full_name, specialization FROM teachers ORDER BY full_name");
?>
    <div class="card">
        <div class="tablewrap"><table>
            <tr><th>Teacher No.</th><th>Name</th><th>Specialization</th><th>Action</th></tr>
            <?php foreach ($delete_teachers as $teacher): ?>
            <tr>
                <td><?= e($teacher['teacher_no']) ?></td>
                <td><strong><?= e($teacher['full_name']) ?></strong></td>
                <td><?= e($teacher['specialization'] ?? '-') ?></td>
                <td><form method="POST" onsubmit="return confirm(<?= e(json_encode('Are you sure you want to permanently delete ' . $teacher['full_name'] . '? This will remove their assignments and disable their login.')) ?>);">
                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="delete_teacher">
                    <input type="hidden" name="teacher_id" value="<?= (int)$teacher['id'] ?>">
                    <button class="btn small red" type="submit">Delete</button>
                </form></td>
            </tr>
            <?php endforeach; if (!$delete_teachers): ?><tr><td colspan="4" style="text-align:center;color:var(--muted)">No teachers found.</td></tr><?php endif; ?>
        </table></div>
    </div>

<?php elseif ($page === 'assignments'):
    $subjects = $conn->query("SELECT id, name FROM subjects ORDER BY name")->fetch_all(MYSQLI_ASSOC);
    $terms = $conn->query("SELECT id, CONCAT(academic_year, ' Term ', term_no) AS label FROM terms ORDER BY academic_year DESC, term_no DESC")->fetch_all(MYSQLI_ASSOC);
    $al = $conn->query("SELECT a.id, t.full_name, c.name cn, s.name sn, a.periods_per_week p, CONCAT(tm.academic_year,' T',tm.term_no) tl
        FROM teacher_assignments a JOIN teachers t ON t.id=a.teacher_id JOIN classes c ON c.id=a.class_id JOIN subjects s ON s.id=a.subject_id JOIN terms tm ON tm.id=a.term_id
        ORDER BY tm.academic_year DESC, tm.term_no DESC, c.id, s.name")->fetch_all(MYSQLI_ASSOC);
?>
    <div class="two">
    <div class="card"><h3>Assign Subject to Teacher</h3>
        <?php if (!$teachers_active): ?><p style="color:var(--bad)">Add at least one active teacher first.</p><?php endif; ?>
        <form method="POST"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="assign_subject">
        <div class="grid" style="grid-template-columns:1fr 1fr">
            <div><label>Teacher *</label><select name="teacher_id" required><option value="">--</option><?= opt($teachers_active, 'id', 'full_name') ?></select></div>
            <div><label>Subject *</label><select name="subject_id" required><option value="">--</option><?= opt($subjects, 'id', 'name') ?></select></div>
            <div><label>Class *</label><select name="class_id" required><option value="">--</option><?= opt($classes, 'id', 'name') ?></select></div>
            <div><label>Term *</label><select name="term_id" required><?= opt($terms, 'id', 'label', $cur_term_id) ?></select></div>
            <div><label>Periods / Week</label><input type="number" name="periods" min="1" max="40"></div>
        </div>
        <p><button class="btn" type="submit">Assign</button></p></form>
    </div>
    <div class="card"><h3>Class Teachers</h3>
        <?php foreach ($classes as $c): ?>
        <form method="POST" style="display:flex;gap:8px;align-items:center;margin-bottom:8px">
            <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="assign_class_teacher"><input type="hidden" name="class_id" value="<?= (int)$c['id'] ?>">
            <strong style="width:50px"><?= e($c['name']) ?></strong>
            <select name="teacher_id"><option value="">-- None --</option><?= opt($teachers_active, 'id', 'full_name', $c['class_teacher_id']) ?></select>
            <button class="btn small" type="submit">Save</button>
        </form>
        <?php endforeach; ?>
    </div>
    </div>
    <div class="card"><h3>Current Assignments</h3>
        <div class="tablewrap"><table>
            <tr><th>Term</th><th>Class</th><th>Subject</th><th>Teacher</th><th>Periods/Wk</th><th></th></tr>
            <?php foreach ($al as $a): ?>
            <tr><td><?= e($a['tl']) ?></td><td><?= e($a['cn']) ?></td><td><?= e($a['sn']) ?></td><td><strong><?= e($a['full_name']) ?></strong></td><td><?= e($a['p'] ?? '-') ?></td>
                <td><form method="POST" onsubmit="return confirm('Remove this assignment?')"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="remove_assignment"><input type="hidden" name="assignment_id" value="<?= (int)$a['id'] ?>"><button class="btn small red" type="submit">Remove</button></form></td></tr>
            <?php endforeach; if (!$al): ?><tr><td colspan="6" style="text-align:center;color:var(--muted)">Nothing assigned yet.</td></tr><?php endif; ?>
        </table></div>
    </div>
<?php endif; ?>
</main>
<script>
function genPw(btn){
    var chars='ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    var pw=''; for(var i=0;i<8;i++){ pw+=chars.charAt(Math.floor(Math.random()*chars.length)); }
    var f=btn.closest('form').querySelector('input[name="password"]'); f.value=pw; f.type='text';
}
</script>
</body>
</html>
