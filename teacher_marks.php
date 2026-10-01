<?php
require __DIR__ . '/auth.php';
$me  = require_teacher();
$tid = (int)$_SESSION['teacher_id'];
$uid = (int)$_SESSION['user_id'];
$aid = (int)($_GET['assignment'] ?? 0);

// 1. The teacher may ONLY open work the admin assigned to them
$st = $conn->prepare("SELECT a.id, a.class_id, a.subject_id, a.term_id, c.name AS cn, s.name AS sn,
        CONCAT(t.academic_year, ' Term ', t.term_no) AS tl
    FROM teacher_assignments a
    JOIN classes c ON c.id = a.class_id
    JOIN subjects s ON s.id = a.subject_id
    JOIN terms t ON t.id = a.term_id
    WHERE a.id = ? AND a.teacher_id = ?");
$st->bind_param("ii", $aid, $tid);
$st->execute();
$as = $st->get_result()->fetch_assoc();
$st->close();

if (!$as) {
    $_SESSION['tflash'] = ['error', 'You are not assigned to that class and subject.'];
    header('Location: teacher_dashboard.php'); exit();
}
$class_id   = (int)$as['class_id'];
$subject_id = (int)$as['subject_id'];

// 2. Exams of the assigned term
$exams = [];
$r = $conn->query("SELECT id, name FROM exams WHERE term_id = " . (int)$as['term_id'] . " ORDER BY id");
while ($r && $x = $r->fetch_assoc()) $exams[(int)$x['id']] = $x['name'];
$exam_id = (int)($_POST['exam_id'] ?? $_GET['exam_id'] ?? 0);
if (!isset($exams[$exam_id])) { $exam_id = $exams ? (int)array_keys($exams)[0] : 0; }

// 3. ONLY the active students the admin registered in this class
$st = $conn->prepare("SELECT id, name, admission_no, photo FROM students WHERE class_id = ? AND status = 'Active' ORDER BY name");
$st->bind_param("i", $class_id);
$st->execute();
$students = [];
foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $row) $students[(int)$row['id']] = $row;
$st->close();

$bounds = [];
$r = $conn->query("SELECT grade, min_score FROM grade_boundaries ORDER BY min_score DESC");
while ($r && $x = $r->fetch_assoc()) $bounds[] = ['g' => $x['grade'], 'min' => (float)$x['min_score']];
function gradeOf($score, $bounds) { foreach ($bounds as $b) { if ($score >= $b['min']) return $b['g']; } return '-'; }

$message = ''; $msgType = 'success'; $show_posted = false;

// 4. Save / update / delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $exam_id) {
    check_csrf();

    if (isset($_POST['delete_student'])) {
        $sid = (int)$_POST['delete_student'];
        if (isset($students[$sid])) {
            $d = $conn->prepare("DELETE FROM student_marks WHERE student_id = ? AND subject_id = ? AND exam_id = ?");
            $d->bind_param("iii", $sid, $subject_id, $exam_id);
            $d->execute();
            $_SESSION['tflash'] = ['success', 'Mark deleted for ' . $students[$sid]['name'] . '.'];
        } else {
            $_SESSION['tflash'] = ['error', 'That student is not in your class.'];
        }
        header('Location: teacher_marks.php?assignment=' . $aid . '&exam_id=' . $exam_id); exit();
    }

    $posted = $_POST['score'] ?? [];
    $bad = '';
    foreach ($students as $sid => $stu) {
        $raw = trim($posted[$sid] ?? '');
        if ($raw === '') continue;
        if (!is_numeric($raw) || (float)$raw < 0 || (float)$raw > 100) { $bad = $stu['name']; break; }
    }

    if ($bad !== '') {
        $message = "Invalid mark for " . $bad . ". Marks must be a number from 0 to 100. Nothing was saved.";
        $msgType = 'error'; $show_posted = true;
    } else {
        $conn->begin_transaction();
        $ok = true;
        $ins = $conn->prepare("INSERT INTO student_marks (student_id, subject_id, exam_id, score, entered_by) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE score = VALUES(score), entered_by = VALUES(entered_by)");
        $del = $conn->prepare("DELETE FROM student_marks WHERE student_id = ? AND subject_id = ? AND exam_id = ?");
        foreach ($students as $sid => $stu) {
            $raw = trim($posted[$sid] ?? '');
            if ($raw === '') {
                $del->bind_param("iii", $sid, $subject_id, $exam_id);
                if (!$del->execute()) { $ok = false; break; }
            } else {
                $score = (float)$raw;
                $ins->bind_param("iiidi", $sid, $subject_id, $exam_id, $score, $uid);
                if (!$ins->execute()) { $ok = false; break; }
            }
        }
        if ($ok) {
            $conn->commit();
            $_SESSION['tflash'] = ['success', 'Marks saved successfully.'];
            header('Location: teacher_marks.php?assignment=' . $aid . '&exam_id=' . $exam_id); exit();
        }
        $conn->rollback();
        $message = 'Could not save marks: ' . $conn->error; $msgType = 'error';
    }
}

// 5. Current marks
$marks = [];
if ($exam_id) {
    $q = $conn->prepare("SELECT student_id, score FROM student_marks WHERE subject_id = ? AND exam_id = ?");
    $q->bind_param("ii", $subject_id, $exam_id);
    $q->execute();
    $rs = $q->get_result();
    while ($m = $rs->fetch_assoc()) { if (isset($students[(int)$m['student_id']])) $marks[(int)$m['student_id']] = (float)$m['score']; }
    $q->close();
}
$count = count($marks);
$avg = $count ? round(array_sum($marks) / $count, 1) : 0;
$hi  = $count ? max($marks) : 0;
$lo  = $count ? min($marks) : 0;

if (!$message && isset($_SESSION['tflash'])) { list($msgType, $message) = $_SESSION['tflash']; unset($_SESSION['tflash']); }

teacher_head('Enter Marks', $me['full_name']);
?>
<p><a href="teacher_dashboard.php" style="color:var(--brand);text-decoration:none">&larr; Back to my classes</a></p>
<h1><?= e($as['sn']) ?> — <?= e($as['cn']) ?></h1>
<div class="sub"><?= e($as['tl']) ?> · <?= count($students) ?> students</div>

<?php if ($message): ?><div class="alert <?= e($msgType) ?>"><?= e($message) ?></div><?php endif; ?>

<?php if (!$exams): ?>
    <div class="card">No exams are set up for this term. Please ask the administrator.</div>
<?php elseif (!$students): ?>
    <div class="card">No students have been registered in <?= e($as['cn']) ?> yet. Please ask the administrator.</div>
<?php else: ?>
<div class="card">
    <form method="GET" class="bar">
        <input type="hidden" name="assignment" value="<?= $aid ?>">
        <label><strong>Exam:</strong>
            <select name="exam_id" onchange="this.form.submit()">
                <?php foreach ($exams as $id => $nm): ?><option value="<?= $id ?>" <?= $id == $exam_id ? 'selected' : '' ?>><?= e($nm) ?></option><?php endforeach; ?>
            </select>
        </label>
    </form>

    <div class="stats">
        <div class="stat">Recorded <b><?= $count ?></b> / <?= count($students) ?></div>
        <div class="stat">Average <b><?= $avg ?></b></div>
        <div class="stat">Highest <b><?= $hi ?></b></div>
        <div class="stat">Lowest <b><?= $lo ?></b></div>
    </div>

    <form method="POST" action="teacher_marks.php?assignment=<?= $aid ?>&exam_id=<?= $exam_id ?>" id="marksForm">
        <?= csrf_field() ?>
        <input type="hidden" name="exam_id" value="<?= $exam_id ?>">
        <!-- Save button FIRST so pressing Enter in a box saves (never deletes) -->
        <div class="bar"><button type="submit" name="save" value="1" class="btn saveBtn">Save Marks</button>
            <span class="muted">Change a score to update it. Leave a box empty and save to remove that mark.</span></div>

        <div class="tablewrap"><table>
            <tr><th>#</th><th>Photo</th><th>Adm No</th><th>Student</th><th>Score (0-100)</th><th>Grade</th><th></th></tr>
            <?php $i = 0; foreach ($students as $sid => $stu): $i++;
                if ($show_posted) $val = trim($_POST['score'][$sid] ?? '');
                else $val = isset($marks[$sid]) ? $marks[$sid] : '';
            ?>
            <tr>
                <td><?= $i ?></td>
                <td><img class="avatar" src="<?= e($stu['photo']) ?>" alt=""></td>
                <td><?= e($stu['admission_no']) ?></td>
                <td><strong><?= e($stu['name']) ?></strong></td>
                <td><input class="score" type="number" step="0.1" min="0" max="100" name="score[<?= $sid ?>]" value="<?= e($val) ?>" placeholder="-"></td>
                <td><span class="grade"><?= ($val !== '' && is_numeric($val)) ? e(gradeOf((float)$val, $bounds)) : '' ?></span></td>
                <td><?php if (isset($marks[$sid])): ?>
                    <button type="submit" name="delete_student" value="<?= $sid ?>" class="btn small red" formnovalidate
                        onclick="return confirm('Delete this mark for <?= e(addslashes($stu['name'])) ?>?')">Delete</button>
                <?php endif; ?></td>
            </tr>
            <?php endforeach; ?>
        </table></div>

        <div class="bar" style="margin-top:14px"><button type="submit" name="save" value="1" class="btn saveBtn">Save Marks</button></div>
    </form>
</div>

<script>
var bounds = <?= json_encode($bounds) ?>;
var inputs = document.querySelectorAll('input.score');
function check() {
    var allOk = true;
    inputs.forEach(function (inp) {
        var v = inp.value.trim(), g = inp.parentNode.nextElementSibling.querySelector('.grade');
        var n = Number(v), ok = (v === '') || (isFinite(n) && n >= 0 && n <= 100);
        inp.classList.toggle('bad', !ok);
        if (!ok) allOk = false;
        var m = (v !== '' && ok) ? bounds.find(function (b) { return n >= Number(b.min); }) : null;
        g.textContent = m ? m.g : '';
    });
    document.querySelectorAll('.saveBtn').forEach(function (b) { b.disabled = !allOk; });
}
inputs.forEach(function (i) { i.addEventListener('input', check); });
check();
</script>
<?php endif; ?>
<?php teacher_foot(); ?>
