<?php
session_start();
require __DIR__ . '/db.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

$admin_id   = (int)$_SESSION['admin_id'];
$student_id = (int)($_GET['student_id'] ?? 0);
$exam_id    = (int)($_GET['exam_id'] ?? 0);
$message    = "";

// 1. FETCH EXAMS SYSTEM
$exams = [];
$res = $conn->query("SELECT e.id, e.name, t.academic_year, t.term_no, t.is_current FROM exams e JOIN terms t ON t.id = e.term_id ORDER BY t.academic_year DESC, t.term_no DESC");
if (!$res) {
    die("Database Setup Error: " . htmlspecialchars($conn->error) . ". Ensure you have imported the SQL file tables.");
}
while ($r = $res->fetch_assoc()) {
    $exams[(int)$r['id']] = $r;
}
if (!isset($exams[$exam_id])) {
    $exam_id = 0;
    foreach ($exams as $id => $e) { if ($e['is_current']) { $exam_id = $id; break; } }
    if (!$exam_id && $exams) $exam_id = array_key_first($exams);
}

// 2. GRADE CONFIGURATION MATRIX
$bounds = [];
$res = $conn->query("SELECT grade, min_score FROM grade_boundaries ORDER BY min_score DESC");
while ($res && $r = $res->fetch_assoc()) {
    $bounds[] = ['g' => $r['grade'], 'min' => (float)$r['min_score']];
}
function gradeOf($score, $bounds) {
    foreach ($bounds as $b) { if ($score >= $b['min']) return $b['g']; }
    return '-';
}

// 3. STUDENT SELECTOR
$student = null;
if ($student_id > 0) {
    $st = $conn->prepare("SELECT s.id, s.name, s.photo, c.name AS class_name FROM students s LEFT JOIN classes c ON c.id = s.class_id WHERE s.id = ?");
    $st->bind_param("i", $student_id);
    $st->execute();
    $student = $st->get_result()->fetch_assoc();
    $st->close();
}

// 4. SUBJECT MAP
$subjects = [];
$res = $conn->query("SELECT id, name, type FROM subjects ORDER BY type, name");
while ($res && $r = $res->fetch_assoc()) {
    $subjects[(int)$r['id']] = $r;
}

// 5. PROCESS MARK DATA SAVE REQUEST
if ($student && $exam_id && $_SERVER["REQUEST_METHOD"] == "POST") {
    $posted = $_POST['score'] ?? [];

    $ins = $conn->prepare("INSERT INTO student_marks (student_id, subject_id, exam_id, score, entered_by) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE score = VALUES(score), entered_by = VALUES(entered_by)");
    $del = $conn->prepare("DELETE FROM student_marks WHERE student_id = ? AND subject_id = ? AND exam_id = ?");

    if (!$ins || !$del) {
        $message = "<div style='color:#991b1b;padding:15px;background:#fee2e2;border-radius:4px;margin-bottom:15px;'>
                        <strong>Database Schema Mismatch Error:</strong><br>
                        The database rejected the save structure. Your <code>student_marks</code> table is missing required columns.<br>
                        <strong>MySQL system code error message:</strong> " . htmlspecialchars($conn->error) . "<br>
                        <em>Fix: Run the SQL database update query provided in Step 1 in phpMyAdmin.</em>
                    </div>";
    } else {
        // Validate ALL marks before saving anything.
        $invalid = false;
        $invalid_subject = '';

        foreach ($subjects as $sid => $sub) {
            $raw = trim($posted[$sid] ?? '');

            if ($raw === '') {
                continue;
            }

            if (!is_numeric($raw)) {
                $invalid = true;
                $invalid_subject = $sub['name'];
                break;
            }

            $score = (float)$raw;

            if ($score < 0 || $score > 100) {
                $invalid = true;
                $invalid_subject = $sub['name'];
                break;
            }
        }

        if ($invalid) {
            $message = "<div style='color:#991b1b;padding:12px;background:#fee2e2;border:1px solid #fca5a5;border-radius:5px;margin-bottom:15px;'>
                            <strong>Invalid marks input!</strong><br>
                            Marks for <strong>" . htmlspecialchars($invalid_subject) . "</strong>
                            must be between <strong>0 and 100</strong>.<br>
                            Please enter a valid mark.
                        </div>";
        } else {
            $conn->begin_transaction();
            $ok = true;

            foreach ($subjects as $sid => $sub) {
                $raw = trim($posted[$sid] ?? '');

                if ($raw === '') {
                    $del->bind_param("iii", $student_id, $sid, $exam_id);
                    if (!$del->execute()) {
                        $ok = false;
                        break;
                    }
                } else {
                    $score = (float)$raw;
                    $ins->bind_param("iiidi", $student_id, $sid, $exam_id, $score, $admin_id);
                    if (!$ins->execute()) {
                        $ok = false;
                        break;
                    }
                }
            }

            if ($ok) {
                $conn->commit();
                $message = "<div style='color:green;padding:10px;background:#dcfce7;border-radius:4px;margin-bottom:15px;'>Marks saved successfully!</div>";
            } else {
                $conn->rollback();
                $message = "<div style='color:red;padding:10px;background:#fee2e2;border-radius:4px;margin-bottom:15px;'>Error executing database update operations: " . htmlspecialchars($conn->error) . "</div>";
            }
        }
    }
}

// 6. CURRENT GRADES RETRIEVAL
$marks = [];
if ($student && $exam_id) {
    $st = $conn->prepare("SELECT subject_id, score FROM student_marks WHERE student_id = ? AND exam_id = ?");
    if ($st) {
        $st->bind_param("ii", $student_id, $exam_id);
        $st->execute();
        $rs = $st->get_result();
        while ($r = $rs->fetch_assoc()) $marks[(int)$r['subject_id']] = (float)$r['score'];
        $st->close();
    }
}

$count = count($marks);
$total = array_sum($marks);
$avg   = $count ? round($total / $count, 2) : 0;

$next_id = 0;
if ($student) {
    $r = $conn->query("SELECT id FROM students WHERE id > $student_id ORDER BY id LIMIT 1");
    if ($r && $row = $r->fetch_assoc()) $next_id = (int)$row['id'];
}

$list = $student ? null : $conn->query("SELECT s.id, s.name, s.photo, c.name AS class_name FROM students s LEFT JOIN classes c ON c.id = s.class_id ORDER BY s.id");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Enter Student Marks</title>
<style>
    * { box-sizing: border-box; }
    body { font-family: Arial, sans-serif; margin: 14px; background: #f5f5f5; }
    .box { background: #fff; padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 1px 4px rgba(0,0,0,.1); }
    .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 15px; }
    .field { margin-bottom: 10px; }
    .field label { display: block; font-size: 0.85rem; color: #555; margin-bottom: 4px; }
    .field input { width: 70%; padding: 6px; border: 1px solid #ccc; border-radius: 4px; }
    .g { font-weight: bold; color: #4f46e5; margin-left: 10px; }
    table { width: 100%; border-collapse: collapse; background: #fff; }
    th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
    th { background: #333; color: #fff; }
    .tablewrap { overflow-x: auto; }
    @media (max-width: 600px) {
        body { margin: 10px; }
        .box { padding: 14px; }
        .grid { grid-template-columns: minmax(0, 1fr); }
        .field input { width: 100%; }
        .tablewrap table { min-width: 500px; }
    }
</style>
</head>
<body>
<p><a href="dashboard.php">&larr; Back to Dashboard</a></p>

<?php if (!$student): ?>
    <h2>Select a Student to Enter Marks</h2>
    <div class="tablewrap"><table>
        <tr><th>ID</th><th>Name</th><th>Class</th><th>Action</th></tr>
        <?php if ($list && $list->num_rows): while ($row = $list->fetch_assoc()): ?>
            <tr>
                <td><?php echo $row['id']; ?></td>
                <td><?php echo htmlspecialchars($row['name']); ?></td>
                <td><?php echo htmlspecialchars($row['class_name'] ?? '-'); ?></td>
                <td><a href="marks.php?student_id=<?php echo $row['id']; ?>">Enter Marks</a> &nbsp;|&nbsp; <a href="report.php?student_id=<?php echo $row['id']; ?>">Report Card</a></td>
            </tr>
        <?php endwhile; else: ?>
            <tr><td colspan="4">No students registered yet.</td></tr>
        <?php endif; ?>
    </table></div>
<?php else: ?>
    <div class="box">
        <h2>Marks Entry: <?php echo htmlspecialchars($student['name']); ?></h2>
        <p>Class: <?php echo htmlspecialchars($student['class_name'] ?? 'None'); ?></p>
        <form method="GET" action="marks.php">
            <input type="hidden" name="student_id" value="<?php echo $student['id']; ?>">
            <label>Exam Period: 
                <select name="exam_id" onchange="this.form.submit()">
                    <?php foreach ($exams as $id => $e): ?>
                        <option value="<?php echo $id; ?>" <?php echo $id == $exam_id ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($e['academic_year'] . " T" . $e['term_no'] . " - " . $e['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </form>
    </div>

    <?php echo $message; ?>

    <form method="POST" action="marks.php?student_id=<?php echo $student['id']; ?>&exam_id=<?php echo $exam_id; ?>">
        <div class="box">
            <h3>Subject Scores</h3>
            <div class="grid">
                <?php foreach ($subjects as $sid => $sub): 
                    $val = isset($marks[$sid]) ? $marks[$sid] : ''; ?>
                    <div class="field">
                        <label><?php echo htmlspecialchars($sub['name']); ?> (<?php echo $sub['type']; ?>)</label>
                        <input type="number" step="0.1" min="0" max="100" placeholder="-" name="score[<?php echo $sid; ?>]" value="<?php echo htmlspecialchars($val); ?>" oninput="validateScore(this); updateLiveGrade(this)">
                        <span class="g"><?php echo $val !== '' ? gradeOf($val, $bounds) : ''; ?></span>
                        <span class="error-message" style="display:none;color:#dc2626;font-size:0.85rem;margin-left:10px;"></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="box">
            <p>Total Recorded Subjects: <strong><?php echo $count; ?></strong></p>
            <p>Total Marks: <strong><?php echo $total; ?></strong></p>
            <p>Term Average: <strong><?php echo $avg; ?></strong></p>
            <button type="submit" id="saveButton" style="background:#4f46e5;color:white;padding:10px 20px;border:0;border-radius:4px;cursor:pointer;">Save Marks</button>
            <a href="report.php?student_id=<?php echo $student['id']; ?>&exam_id=<?php echo $exam_id; ?>" target="_blank" style="margin-left:20px;font-weight:bold;">View Report Card</a>
            <?php if ($next_id): ?>
                <a href="marks.php?student_id=<?php echo $next_id; ?>&exam_id=<?php echo $exam_id; ?>" style="margin-left:20px;">Next Student &rarr;</a>
            <?php endif; ?>
        </div>
    </form>

    <script>
        const bounds = <?php echo json_encode($bounds); ?>;

        function validateScore(input) {
            const value = input.value.trim();
            const error = input.parentNode.querySelector('.error-message');

            if (value === '') {
                input.setCustomValidity('');
                input.style.borderColor = '';
                if (error) { error.textContent = ''; error.style.display = 'none'; }
                return true;
            }

            const score = Number(value);

            if (!Number.isFinite(score) || score < 0 || score > 100) {
                const msg = score > 100
                    ? 'Invalid marks input! Marks cannot be greater than 100.'
                    : score < 0
                        ? 'Invalid marks input! Marks cannot be less than 0.'
                        : 'Invalid marks input! Please enter a number between 0 and 100.';
                input.setCustomValidity(msg);
                input.style.borderColor = '#dc2626';
                if (error) { error.textContent = msg; error.style.display = 'inline'; }
                return false;
            }

            input.setCustomValidity('');
            input.style.borderColor = '#16a34a';
            if (error) { error.textContent = ''; error.style.display = 'none'; }
            return true;

        }

        function updateLiveGrade(input) {
            const out = input.parentNode.querySelector('.g');
            const value = input.value.trim();

            if (value === '') {
                out.textContent = '';
                updateSaveButton();
                return;
            }

            const score = Number(value);

            if (!Number.isFinite(score) || score < 0 || score > 100) {
                out.textContent = '';
                updateSaveButton();
                return;
            }

            const match = bounds.find(x => score >= Number(x.min));
            out.textContent = match ? match.g : '';

            updateSaveButton();
        }

        // Disable Save Marks immediately if ANY entered mark is invalid.
        function updateSaveButton() {
            const form = document.querySelector('form[method="POST"]');
            const saveButton = document.getElementById('saveButton');

            if (!form || !saveButton) return;

            const inputs = form.querySelectorAll('input[name^="score["]');
            let allValid = true;

            inputs.forEach(function(input) {
                if (!validateScore(input)) {
                    allValid = false;
                }
            });

            saveButton.disabled = !allValid;

            if (!allValid) {
                saveButton.style.background = '#9ca3af';
                saveButton.style.cursor = 'not-allowed';
            } else {
                saveButton.style.background = '#4f46e5';
                saveButton.style.cursor = 'pointer';
            }
        }

        // Validate while typing and update the Save button.
        document.querySelectorAll('input[name^="score["]').forEach(function(input) {
            input.addEventListener('input', function() {
                validateScore(input);
                updateLiveGrade(input);
                updateSaveButton();
            });
        });

        // Stop the form from submitting if ANY mark is invalid.
        document.querySelector('form[method="POST"]').addEventListener('submit', function(event) {
            const inputs = this.querySelectorAll('input[name^="score["]');
            let valid = true;
            let firstInvalid = null;

            inputs.forEach(function(input) {
                if (!validateScore(input)) {
                    valid = false;
                    if (!firstInvalid) {
                        firstInvalid = input;
                    }
                }
            });

            if (!valid) {
                event.preventDefault();
                alert('Invalid marks input! Marks must be between 0 and 100.');

                if (firstInvalid) {
                    firstInvalid.focus();
                }

                updateSaveButton();
            }
        });

        // Set the correct Save button state when the page loads.
        updateSaveButton();
    </script>
<?php endif; ?>
</body>
</html>
