<?php
require __DIR__ . '/auth.php';
$me  = require_teacher();
$tid = (int)$_SESSION['teacher_id'];

$cur = $conn->query("SELECT id, academic_year, term_no FROM terms WHERE is_current = 1 LIMIT 1")->fetch_assoc();
if (!$cur) $cur = $conn->query("SELECT id, academic_year, term_no FROM terms ORDER BY academic_year DESC, term_no DESC LIMIT 1")->fetch_assoc();
$term_id = (int)($cur['id'] ?? 0);

$stmt = $conn->prepare("SELECT a.id, c.name AS cn, s.name AS sn, a.periods_per_week AS p,
        (SELECT COUNT(*) FROM students st WHERE st.class_id = a.class_id AND st.status = 'Active') AS n,
        (SELECT COUNT(*) FROM student_marks m JOIN students st2 ON st2.id = m.student_id
          WHERE m.subject_id = a.subject_id AND st2.class_id = a.class_id
            AND m.exam_id IN (SELECT id FROM exams WHERE term_id = a.term_id)) AS marks_n
    FROM teacher_assignments a
    JOIN classes c ON c.id = a.class_id
    JOIN subjects s ON s.id = a.subject_id
    WHERE a.teacher_id = ? AND a.term_id = ?
    ORDER BY c.id, s.name");
$stmt->bind_param("ii", $tid, $term_id);
$stmt->execute();
$work = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$ct = $conn->query("SELECT name FROM classes WHERE class_teacher_id = $tid ORDER BY id")->fetch_all(MYSQLI_ASSOC);

$flash = $_SESSION['tflash'] ?? null; unset($_SESSION['tflash']);

teacher_head('My Classes', $me['full_name']);
?>
<h1>Welcome, <?= e($me['full_name']) ?></h1>
<div class="sub"><?= $cur ? e($cur['academic_year']) . ' · Term ' . e($cur['term_no']) : 'No term set' ?>
    <?php if ($ct): ?> · Class teacher of <strong><?= e(implode(', ', array_column($ct, 'name'))) ?></strong><?php endif; ?></div>

<?php if ($flash): ?><div class="alert <?= e($flash[0]) ?>"><?= e($flash[1]) ?></div><?php endif; ?>

<h3>My assigned work — enter marks</h3>
<?php if (!$work): ?>
    <div class="card"><p class="muted" style="margin:0">The administrator has not assigned you any subject for this term yet.</p></div>
<?php else: ?>
<div class="cards">
    <?php foreach ($work as $w): ?>
    <div class="card accent">
        <h3><?= e($w['sn']) ?></h3>
        <div class="muted"><?= e($w['cn']) ?> · <?= (int)$w['n'] ?> students<?= $w['p'] ? ' · ' . (int)$w['p'] . ' periods/week' : '' ?></div>
        <p class="muted" style="margin:10px 0 14px"><?= (int)$w['marks_n'] ?> marks recorded this term</p>
        <a class="btn" href="teacher_marks.php?assignment=<?= (int)$w['id'] ?>">Enter / Edit Marks</a>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php teacher_foot(); ?>
