<?php
session_start();
require __DIR__ . '/db.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

$student_id = (int)($_GET['student_id'] ?? 0);
$exam_id    = (int)($_GET['exam_id'] ?? 0);
$class_id   = (int)($_GET['class_id'] ?? 0);
$report_mode = strtolower((string)($_GET['mode'] ?? ($student_id > 0 ? 'student' : 'class')));

function officialGradeBounds() {
    return [
        ['g' => 'D1', 'min' => 75, 'pts' => 1, 'remark' => 'Distinction'],
        ['g' => 'D2', 'min' => 70, 'pts' => 2, 'remark' => 'Distinction'],
        ['g' => 'C3', 'min' => 65, 'pts' => 3, 'remark' => 'Credit'],
        ['g' => 'C4', 'min' => 60, 'pts' => 4, 'remark' => 'Credit'],
        ['g' => 'C5', 'min' => 55, 'pts' => 5, 'remark' => 'Credit'],
        ['g' => 'C6', 'min' => 50, 'pts' => 6, 'remark' => 'Credit'],
        ['g' => 'P7', 'min' => 45, 'pts' => 7, 'remark' => 'Pass'],
        ['g' => 'P8', 'min' => 40, 'pts' => 8, 'remark' => 'Pass'],
        ['g' => 'F9', 'min' => 0,  'pts' => 9, 'remark' => 'Fail'],
    ];
}

function gradeInfo($score, $bounds) {
    foreach ($bounds as $b) {
        if ($score >= $b['min']) return $b;
    }
    return end($bounds);
}

function reportAverageLabel($value) {
    return rtrim(rtrim(number_format((float)$value, 2, '.', ''), '0'), '.');
}

// ---------- EXAMS ----------
$exams = [];
$res = $conn->query("SELECT e.id, e.name AS exam_name, t.academic_year, t.term_no, t.is_current
                     FROM exams e JOIN terms t ON t.id = e.term_id
                     ORDER BY t.academic_year DESC, t.term_no DESC, e.id");
if (!$res) {
    die("Database not ready: " . htmlspecialchars($conn->error) . ". Import school_database.sql first.");
}
while ($r = $res->fetch_assoc()) $exams[(int)$r['id']] = $r;
if (!$exams) die("No exams found. Import school_database.sql first.");

if (!isset($exams[$exam_id])) {
    $exam_id = 0;
    if ($student_id > 0) {
        $q = $conn->prepare("SELECT exam_id FROM student_marks WHERE student_id = ? ORDER BY entered_at DESC LIMIT 1");
        if ($q) {
            $q->bind_param("i", $student_id);
            $q->execute();
            $row = $q->get_result()->fetch_assoc();
            if ($row && isset($exams[(int)$row['exam_id']])) $exam_id = (int)$row['exam_id'];
            $q->close();
        }
    }
    if (!$exam_id) {
        foreach ($exams as $id => $e) if ($e['is_current']) { $exam_id = $id; break; }
        if (!$exam_id) $exam_id = array_key_first($exams);
    }
}
$exam = $exams[$exam_id];

// ---------- GRADE BOUNDARIES ----------
$bounds = [];
$res = $conn->query("SELECT grade, min_score, points, remark FROM grade_boundaries ORDER BY min_score DESC");
while ($res && $r = $res->fetch_assoc()) {
    $bounds[] = ['g' => $r['grade'], 'min' => (float)$r['min_score'], 'pts' => (int)$r['points'], 'remark' => $r['remark']];
}
if (!$bounds) {
    $bounds = officialGradeBounds();
}

// ---------- STUDENT PICKER ----------
if ($student_id <= 0 && $report_mode !== 'class') {
    $list = $conn->query("SELECT s.id, s.name, s.class_id, c.name AS class_name FROM students s LEFT JOIN classes c ON c.id = s.class_id ORDER BY s.name");
    ?>
    <!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Select Student</title>
    <style>
        * { box-sizing:border-box; }
        body { font-family:Arial,sans-serif; margin:16px; background:#f5f5f5; }
        .tablewrap { max-width:900px; overflow-x:auto; }
        table { width:100%; min-width:480px; border-collapse:collapse; background:#fff; }
        th,td { border:1px solid #ddd; padding:10px; text-align:left; }
        th { background:#333; color:#fff; }
        a { color:#4f46e5; font-weight:bold; text-decoration:none; }
        @media (max-width:600px) { body { margin:10px; } }
    </style></head><body>
    <p><a href="dashboard.php">&larr; Back to Dashboard</a></p>
    <h2>Select a Student to View the Report Card</h2>
    <div class="tablewrap"><table><tr><th>ID</th><th>Name</th><th>Class</th><th>Action</th></tr>
    <?php if ($list && $list->num_rows): while ($r = $list->fetch_assoc()): ?>
        <tr>
            <td><?php echo (int)$r['id']; ?></td>
            <td><?php echo htmlspecialchars($r['name']); ?></td>
            <td><?php echo htmlspecialchars($r['class_name'] ?? '-'); ?></td>
            <td>
                <a href="report.php?student_id=<?php echo (int)$r['id']; ?>&amp;exam_id=<?php echo $exam_id; ?>">View Student Report</a>
                | <a href="report.php?class_id=<?php echo (int)($r['class_id'] ?? 0); ?>&amp;exam_id=<?php echo $exam_id; ?>&amp;mode=class">Class Assessment</a>
            </td>
        </tr>
    <?php endwhile; else: ?><tr><td colspan="4">No students registered yet.</td></tr><?php endif; ?>
    </table></div></body></html>
    <?php
    exit();
}

// ---------- CLASS REPORT ----------
if ($report_mode === 'class') {
    if ($class_id <= 0) {
        $class_row = $conn->query("SELECT id, name FROM classes ORDER BY name LIMIT 1")->fetch_assoc();
        if ($class_row) { $class_id = (int)$class_row['id']; }
    }

    if ($class_id <= 0) {
        die("<p style='font-family:sans-serif;text-align:center;margin-top:50px;color:red;'>No class found. <a href='dashboard.php'>Back</a></p>");
    }

    $class_info = $conn->prepare("SELECT id, name FROM classes WHERE id = ?");
    $class_info->bind_param("i", $class_id);
    $class_info->execute();
    $class_record = $class_info->get_result()->fetch_assoc();
    $class_info->close();
    $class_name = $class_record['name'] ?? 'Class';

    $students = [];
    $student_list = $conn->prepare("SELECT s.id, s.name, s.photo, s.class_id, c.name AS class_name
                                   FROM students s LEFT JOIN classes c ON c.id = s.class_id
                                   WHERE s.class_id = ? ORDER BY s.name");
    $student_list->bind_param("i", $class_id);
    $student_list->execute();
    $student_result = $student_list->get_result();
    while ($row = $student_result->fetch_assoc()) {
        $students[] = $row;
    }
    $student_list->close();

    $class_students = array_column($students, 'id');
    $class_summary = [];
    if ($class_students) {
        $placeholders = implode(',', array_fill(0, count($class_students), '?'));
        $subject_sql = "SELECT s.id AS subject_id, s.name AS subject_name, s.type AS subject_type,
                               ROUND(AVG(m.score), 2) AS avg_score,
                               MIN(m.score) AS min_score,
                               MAX(m.score) AS max_score,
                               COUNT(m.id) AS entries
                        FROM student_marks m
                        JOIN subjects s ON s.id = m.subject_id
                        WHERE m.exam_id = ? AND m.student_id IN ($placeholders)
                        GROUP BY s.id, s.name, s.type
                        ORDER BY s.type, s.name";

        $stmt = $conn->prepare($subject_sql);
        $params = [$exam_id];
        foreach ($class_students as $sid) { $params[] = $sid; }
        $types = array_fill(0, count($params), 'i');
        $types[0] = 'i';
        $stmt->bind_param(implode('', $types), ...$params);
        $stmt->execute();
        $subject_rows = $stmt->get_result();
        while ($row = $subject_rows->fetch_assoc()) {
            $class_summary[] = $row;
        }
        $stmt->close();
    }

    $student_report_rows = [];
    foreach ($students as $student_row) {
        $student_id_for_class = (int)$student_row['id'];
        $student_scores = [];
        $subject_query = $conn->prepare("SELECT m.score, s.name AS subject_name, s.type AS subject_type
                                        FROM student_marks m JOIN subjects s ON s.id = m.subject_id
                                        WHERE m.student_id = ? AND m.exam_id = ?");
        $subject_query->bind_param("ii", $student_id_for_class, $exam_id);
        $subject_query->execute();
        $mark_rows = $subject_query->get_result();
        while ($mark_row = $mark_rows->fetch_assoc()) {
            $student_scores[] = $mark_row;
        }
        $subject_query->close();

        $score_total = 0;
        foreach ($student_scores as $mark) { $score_total += (float)$mark['score']; }
        $avg_score = count($student_scores) ? round($score_total / count($student_scores), 2) : 0;
        $grade = gradeInfo($avg_score, $bounds);

        $student_report_rows[] = [
            'student_id' => $student_id_for_class,
            'name' => $student_row['name'],
            'avg_score' => $avg_score,
            'grade' => $grade,
            'total' => $score_total,
            'count' => count($student_scores),
        ];
    }

    usort($student_report_rows, function ($a, $b) {
        if ((float)$a['avg_score'] === (float)$b['avg_score']) return strcmp($a['name'], $b['name']);
        return (float)$b['avg_score'] <=> (float)$a['avg_score'];
    });

    foreach ($student_report_rows as $index => &$student_data) {
        $student_data['position'] = $index + 1;
    }
    unset($student_data);

    $class_average = 0;
    if ($student_report_rows) {
        $class_average = array_sum(array_column($student_report_rows, 'avg_score')) / count($student_report_rows);
    }

    $class_grade_count = array_fill_keys(array_map(fn($b) => $b['g'], $bounds), 0);
    foreach ($student_report_rows as $row) {
        $class_grade_count[$row['grade']['g']] = ($class_grade_count[$row['grade']['g']] ?? 0) + 1;
    }
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Official Class Assessment Report</title>
        <style>
            * { box-sizing:border-box; }
            body { font-family: Arial, sans-serif; margin: 25px; background: #f8fafc; color: #1f2937; }
            .page { max-width: 1100px; margin: 0 auto; background: #fff; border: 1px solid #d1d5db; padding: 30px; }
            .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #1f2937; padding-bottom: 15px; margin-bottom: 20px; }
            .brand h1 { margin: 0; font-size: 1.7rem; text-transform: uppercase; }
            .brand p { margin: 5px 0 0; font-size: 0.95rem; }
            .meta { text-align: right; font-size: 0.9rem; }
            .summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px; margin-bottom: 25px; }
            .summary .tile { background: #f1f5f9; border: 1px solid #cbd5e1; padding: 12px; text-align: center; }
            .summary .tile strong { display: block; font-size: 1.6rem; margin-top: 6px; }
            table { width: 100%; border-collapse: collapse; margin-top: 12px; }
            .tablewrap { overflow-x:auto; }
            th, td { border: 1px solid #cbd5e1; padding: 8px 10px; text-align: left; }
            th { background: #e2e8f0; }
            .grade-pill { display: inline-block; padding: 4px 8px; background: #e0f2fe; border-radius: 12px; font-weight: bold; }
            .small { font-size: 0.85rem; }
            .actions { margin-top: 18px; display: flex; gap: 12px; }
            a.button { display: inline-block; text-decoration: none; padding: 8px 14px; border-radius: 4px; border: 1px solid #4f46e5; color: #4f46e5; }
            .btn-primary { background: #4f46e5; color: white; }
            @media (max-width: 600px) {
                body { margin: 8px; }
                .page { padding: 14px; }
                .header { align-items: flex-start; flex-direction: column; }
                .brand h1 { font-size: 1.35rem; }
                .meta { text-align: left; }
                .summary { grid-template-columns: repeat(2, minmax(0, 1fr)); }
                .tablewrap table { min-width: 540px; }
                .actions { flex-wrap: wrap; }
            }
            @media print { body { background: #fff; margin: 0; } .actions { display: none; } }
        </style>
    </head>
    <body>
        <div class="page">
            <div class="header">
                <div class="brand">
                    <h1>Official Class Assessment Report</h1>
                    <p>Uganda New Curriculum / <?php echo htmlspecialchars($class_name); ?></p>
                </div>
                <div class="meta">
                    <div><strong>Academic Year:</strong> <?php echo htmlspecialchars($exam['academic_year']); ?></div>
                    <div><strong>Term:</strong> Term <?php echo (int)$exam['term_no']; ?></div>
                    <div><strong>Exam:</strong> <?php echo htmlspecialchars($exam['exam_name']); ?></div>
                </div>
            </div>

            <div class="summary">
                <div class="tile"><span>Students</span><strong><?php echo count($students); ?></strong></div>
                <div class="tile"><span>Class Avg.</span><strong><?php echo reportAverageLabel($class_average); ?></strong></div>
                <div class="tile"><span>Top Score</span><strong><?php echo $student_report_rows ? reportAverageLabel(max(array_column($student_report_rows, 'avg_score'))) : '0'; ?></strong></div>
                <div class="tile"><span>Best Grade</span><strong><?php echo $student_report_rows ? htmlspecialchars($student_report_rows[0]['grade']['g']) : '-'; ?></strong></div>
                <div class="tile"><span>Pass Rate</span><strong><?php echo $student_report_rows ? reportAverageLabel((count(array_filter($student_report_rows, fn($r) => (float)$r['avg_score'] >= 40)) / count($student_report_rows)) * 100) . '%' : '0%'; ?></strong></div>
            </div>

            <h3>Student Performance Summary</h3>
            <div class="tablewrap"><table>
                <thead>
                    <tr>
                        <th>Position</th>
                        <th>Student</th>
                        <th>Average</th>
                        <th>Grade</th>
                        <th>Remark</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($student_report_rows as $row): ?>
                        <tr>
                            <td><?php echo (int)$row['position']; ?></td>
                            <td><?php echo htmlspecialchars($row['name']); ?></td>
                            <td><?php echo reportAverageLabel($row['avg_score']); ?></td>
                            <td><span class="grade-pill"><?php echo htmlspecialchars($row['grade']['g']); ?></span></td>
                            <td><?php echo htmlspecialchars($row['grade']['remark']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>

            <h3 style="margin-top: 30px;">Subject Analysis</h3>
            <div class="tablewrap"><table>
                <thead>
                    <tr>
                        <th>Subject</th>
                        <th>Type</th>
                        <th>Class Average</th>
                        <th>Lowest</th>
                        <th>Highest</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($class_summary)): foreach ($class_summary as $subject): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($subject['subject_name']); ?></td>
                            <td><?php echo htmlspecialchars($subject['subject_type']); ?></td>
                            <td><?php echo reportAverageLabel($subject['avg_score']); ?></td>
                            <td><?php echo reportAverageLabel($subject['min_score']); ?></td>
                            <td><?php echo reportAverageLabel($subject['max_score']); ?></td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="5">No subject summary available for this exam yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table></div>

            <div class="actions">
                <a href="report.php?student_id=<?php echo $student_id; ?>&amp;exam_id=<?php echo $exam_id; ?>" class="button">Student Report</a>
                <a href="report.php?class_id=<?php echo $class_id; ?>&amp;exam_id=<?php echo $exam_id; ?>&amp;mode=class" class="button btn-primary">Refresh Class Report</a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit();
}

// ---------- STUDENT REPORT ----------
$st = $conn->prepare("SELECT s.id, s.name, s.photo, s.class_id, c.name AS class_name
                      FROM students s LEFT JOIN classes c ON c.id = s.class_id WHERE s.id = ?");
$st->bind_param("i", $student_id);
$st->execute();
$student = $st->get_result()->fetch_assoc();
$st->close();
if (!$student) die("<p style='font-family:sans-serif;text-align:center;margin-top:50px;color:red;'>Student not found. <a href='dashboard.php'>Back</a></p>");

$scores = [];
$mk = $conn->prepare("SELECT m.score, s.name AS subject_name, s.type AS subject_type, IFNULL(u.username, '-') AS teacher
                      FROM student_marks m
                      JOIN subjects s ON s.id = m.subject_id
                      LEFT JOIN users u ON u.id = m.entered_by
                      WHERE m.student_id = ? AND m.exam_id = ?
                      ORDER BY s.type, s.name");
if ($mk) {
    $mk->bind_param("ii", $student_id, $exam_id);
    $mk->execute();
    $rs = $mk->get_result();
    while ($r = $rs->fetch_assoc()) $scores[] = $r;
    $mk->close();
}
$count = count($scores);
$total = 0;
foreach ($scores as $s) $total += (float)$s['score'];
$average = $count ? round($total / $count, 2) : 0;
$overall = $count ? gradeInfo($average, $bounds) : null;

$position = null; $class_size = 0;
$pq = $conn->prepare("SELECT m.student_id, SUM(m.score) AS total FROM student_marks m
                      JOIN students s ON s.id = m.student_id
                      WHERE m.exam_id = ? AND s.class_id = ? GROUP BY m.student_id");
if ($pq && $count) {
    $cid = $student['class_id'] !== null ? (int)$student['class_id'] : 0;
    $pq->bind_param("ii", $exam_id, $cid);
    $pq->execute();
    $rs = $pq->get_result();
    $position = 1;
    while ($r = $rs->fetch_assoc()) {
        $class_size++;
        if ((int)$r['student_id'] !== $student_id && (float)$r['total'] > $total) $position++;
    }
    $pq->close();
}

$remark = "No marks recorded for this exam yet.";
if ($count) {
    if ($average >= 75) $remark = "Excellent performance. Keep up the outstanding work.";
    elseif ($average >= 70) $remark = "Very good performance. Aim even higher.";
    elseif ($average >= 60) $remark = "Good performance. Keep working hard.";
    elseif ($average >= 50) $remark = "Fair performance. More effort is needed.";
    elseif ($average >= 40) $remark = "Below expectation. Please work harder and seek help.";
    else $remark = "Poor performance. Serious improvement is required.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report Card - <?php echo htmlspecialchars($student['name']); ?></title>
    <style>
        * { box-sizing:border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #fafafa; color: #333; margin: 0; padding: 20px; }
        .navigation-bar { max-width: 850px; margin: 0 auto 15px auto; display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; }
        .btn { padding: 8px 16px; text-decoration: none; border-radius: 4px; font-weight: bold; font-size: 0.9rem; border: none; cursor: pointer; }
        .btn-back { background: #e0e0e0; color: #333; }
        .btn-print { background: #4f46e5; color: white; }
        .report-card { background: white; max-width: 850px; margin: 0 auto; padding: 40px; border: 2px solid #333; box-shadow: 0 4px 12px rgba(0,0,0,0.05); box-sizing: border-box; }
        .school-header-wrapper { display: flex; align-items: center; justify-content: space-between; border-bottom: 3px double #333; padding-bottom: 20px; margin-bottom: 25px; gap: 15px; }
        .logo-side { width: 130px; text-align: left; }
        .photo-side { width: 130px; text-align: right; }
        .school-badge { width: 120px; height: auto; object-fit: contain; display: block; }
        .student-photo-box { width: 110px; height: 120px; border: 1px solid #333; border-radius: 4px; overflow: hidden; background: #f8fafc; display: inline-flex; align-items: center; justify-content: center; }
        .student-photo { width: 100%; height: 100%; object-fit: cover; }
        .center-branding { flex: 1; text-align: center; }
        .center-branding h2 { margin: 0; font-size: 1.25rem; color: #475569; text-transform: uppercase; font-weight: 700; }
        .center-branding h1 { margin: 2px 0 0 0; font-size: 1.8rem; color: #002060; text-transform: uppercase; font-weight: 800; }
        .center-branding p { margin: 6px 0 0 0; font-size: 1.05rem; color: #334155; font-weight: 600; }
        .report-title { margin-top: 12px; font-size: 1.1rem; font-weight: bold; color: #1e293b; text-transform: uppercase; background: #f1f5f9; padding: 5px 12px; border-radius: 4px; display: inline-block; border: 1px solid #cbd5e1; }
        .student-profile { width: 100%; margin-bottom: 30px; }
        .profile-table { width: 100%; border-collapse: collapse; }
        .profile-table td { padding: 8px 4px; border: none; font-size: 1rem; vertical-align: middle; }
        .marks-table { width: 100%; border-collapse: collapse; margin-bottom: 25px; }
        .marks-table th, .marks-table td { border: 1px solid #333; padding: 9px 12px; text-align: left; font-size: 0.95rem; }
        .marks-table th { background: #f1f5f9; color: #1e293b; font-weight: bold; }
        .text-center { text-align: center; }
        .section-row td { background: #e2e8f0; font-weight: bold; text-transform: uppercase; font-size: 0.8rem; letter-spacing: .5px; }
        .summary-block { display: grid; grid-template-columns: repeat(5, 1fr); gap: 1px; background: #333; border: 1px solid #333; margin-bottom: 20px; }
        .summary-cell { background: white; padding: 12px; text-align: center; font-size: 0.95rem; }
        .summary-cell strong { display: block; font-size: 1.2rem; margin-top: 4px; }
        .remark-box { border: 1px solid #333; padding: 12px; margin-bottom: 20px; }
        .key { font-size: 0.8rem; color: #475569; }
        .key span { display: inline-block; margin-right: 12px; }
        .signature-section { display: flex; justify-content: space-between; margin-top: 50px; padding-top: 10px; }
        .sig-box { text-align: center; width: 42%; font-size: 0.95rem; }
        .sig-line { border-top: 1px dashed #333; margin-bottom: 8px; height: 35px; }
        @media (max-width: 600px) {
            body { padding: 8px; }
            .navigation-bar { align-items: stretch; flex-direction: column; }
            .navigation-bar form, .navigation-bar select { width:100%; }
            .report-card { padding: 12px; }
            .school-header-wrapper { flex-wrap: wrap; align-items: flex-start; gap: 8px; }
            .logo-side, .photo-side { width: calc(50% - 4px); }
            .school-badge { width: 58px; }
            .student-photo-box { width: 58px; height: 68px; }
            .center-branding { order: 3; flex-basis: 100%; }
            .center-branding h2 { font-size: 1rem; }
            .center-branding h1 { font-size: 1.25rem; }
            .center-branding p { font-size: .9rem; }
            .student-profile { overflow-x:auto; }
            .profile-table { min-width: 560px; }
            .marks-table { min-width: 620px; }
            .report-card { overflow-x:auto; }
            .summary-block { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .signature-section { gap: 16px; flex-direction: column; margin-top: 30px; }
            .sig-box { width:100%; }
        }
        @media print {
            body { background: white; padding: 0; }
            .navigation-bar { display: none; }
            .report-card { border: none; box-shadow: none; padding: 10px; max-width: 100%; }
        }
    </style>
</head>
<body>

<div class="navigation-bar">
    <a href="report.php?class_id=<?php echo $student['class_id']; ?>&amp;exam_id=<?php echo $exam_id; ?>&amp;mode=class" class="btn btn-back">&larr; Class Assessment</a>
    <form method="GET" action="report.php">
        <input type="hidden" name="student_id" value="<?php echo $student_id; ?>">
        <label>Exam:
            <select name="exam_id" onchange="this.form.submit()">
                <?php foreach ($exams as $id => $e): ?>
                    <option value="<?php echo $id; ?>" <?php echo $id == $exam_id ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($e['academic_year'] . " Term " . $e['term_no'] . " - " . $e['exam_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
    </form>
    <button onclick="window.print()" class="btn btn-print">Print Report Card</button>
</div>

<div class="report-card">
    <div class="school-header-wrapper">
        <div class="logo-side"><img src="uploads/school_badge.png" alt="School Emblem" class="school-badge"></div>
        <div class="center-branding">
            <h2>Zika Foundation Schools</h2>
            <h1>Dreamland Bugaaki High Sch</h1>
            <p> Uganda New Curriculum Assessment Record</p>
            <div class="report-title">Official Student Report Card</div>
        </div>
        <div class="photo-side">
            <div class="student-photo-box">
                <?php if (!empty($student['photo']) && file_exists($student['photo'])): ?>
                    <img src="<?php echo htmlspecialchars($student['photo']); ?>" alt="Student Photo" class="student-photo">
                <?php else: ?>
                    <span style="font-size:0.7rem;color:#64748b;text-align:center;padding:5px;">No Passport Photo</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="student-profile">
        <table class="profile-table">
            <tr>
                <td style="width:18%;"><strong>Student Name:</strong></td>
                <td style="border-bottom:1px solid #333;font-weight:bold;color:#1e293b;font-size:1.1rem;"><?php echo htmlspecialchars($student['name']); ?></td>
                <td style="width:5%;"></td>
                <td style="width:15%;"><strong>Student ID:</strong></td>
                <td style="border-bottom:1px solid #333;font-family:monospace;font-size:1.05rem;"><?php echo str_pad((string)$student['id'], 4, '0', STR_PAD_LEFT); ?></td>
            </tr>
            <tr>
                <td><strong>Class:</strong></td>
                <td style="border-bottom:1px solid #333;"><?php echo htmlspecialchars($student['class_name'] ?? 'Not Assigned'); ?></td>
                <td></td>
                <td><strong>Term:</strong></td>
                <td style="border-bottom:1px solid #333;"><?php echo htmlspecialchars($exam['academic_year'] . " - Term " . $exam['term_no'] . " (" . $exam['exam_name'] . ")"); ?></td>
            </tr>
        </table>
    </div>

    <div style="overflow-x:auto"><table class="marks-table">
        <thead>
            <tr>
                <th style="width:6%;">No.</th>
                <th>Subject</th>
                <th class="text-center" style="width:12%;">Score (%)</th>
                <th class="text-center" style="width:10%;">Grade</th>
                <th class="text-center" style="width:10%;">Points</th>
                <th style="width:16%;">Remark</th>
                <th style="width:14%;">Teacher</th>
            </tr>
        </thead>
        <tbody>
        <?php if ($count): $n = 0; $lastType = ''; foreach ($scores as $s):
            $g = gradeInfo((float)$s['score'], $bounds);
            if ($s['subject_type'] !== $lastType): $lastType = $s['subject_type']; ?>
                <tr class="section-row"><td colspan="7"><?php echo htmlspecialchars($lastType); ?> Subjects</td></tr>
            <?php endif; $n++; ?>
            <tr>
                <td class="text-center"><?php echo $n; ?></td>
                <td><?php echo htmlspecialchars($s['subject_name']); ?></td>
                <td class="text-center"><?php echo reportAverageLabel((float)$s['score']); ?></td>
                <td class="text-center"><strong><?php echo htmlspecialchars($g['g']); ?></strong></td>
                <td class="text-center"><?php echo (int)$g['pts']; ?></td>
                <td><?php echo htmlspecialchars($g['remark']); ?></td>
                <td><?php echo htmlspecialchars($s['teacher']); ?></td>
            </tr>
        <?php endforeach; else: ?>
            <tr><td colspan="7" class="text-center" style="padding:25px;color:#666;">
                No marks have been recorded for this student in the selected exam.
                <a href="marks.php?student_id=<?php echo $student_id; ?>&amp;exam_id=<?php echo $exam_id; ?>">Enter marks</a>
            </td></tr>
        <?php endif; ?>
        </tbody>
    </table></div>

    <div class="summary-block">
        <div class="summary-cell">Subjects<strong><?php echo $count; ?></strong></div>
        <div class="summary-cell">Total Marks<strong><?php echo reportAverageLabel($total); ?></strong></div>
        <div class="summary-cell">Average<strong><?php echo reportAverageLabel($average); ?></strong></div>
        <div class="summary-cell">Overall Grade<strong><?php echo $overall ? htmlspecialchars($overall['g']) : '-'; ?></strong></div>
        <div class="summary-cell">Position<strong><?php echo $position ? $position . " / " . $class_size : '-'; ?></strong></div>
    </div>

    <div class="remark-box"><strong>General Remark:</strong> <?php echo htmlspecialchars($remark); ?></div>

    <div class="key"><strong>Grading key:</strong>
        <?php foreach ($bounds as $b): ?>
            <span><?php echo htmlspecialchars($b['g']); ?> = <?php echo (float)$b['min']; ?>+</span>
        <?php endforeach; ?>
    </div>

    <div class="signature-section">
        <div class="sig-box"><div class="sig-line"></div>Class Teacher's Signature</div>
        <div class="sig-box"><div class="sig-line"></div>Headteacher's Signature &amp; Stamp</div>
    </div>
</div>
</body>
</html>
