<?php
// ==========================================
// DATABASE CONNECTION
// ==========================================
mysqli_report(MYSQLI_REPORT_OFF);

$servername = "localhost";
$username   = "root";
$password   = "";
$database   = "school_system";   // NEW clean database
$port       = 3308;              // Your WampServer MySQL port

$conn = new mysqli($servername, $username, $password, $database, $port);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

function ensureSchoolExams($conn) {
    $tableCheck = $conn->query("SHOW TABLES LIKE 'exams'");
    if (!$tableCheck || $tableCheck->num_rows === 0) {
        return;
    }

    $hasExams = $conn->query("SELECT 1 FROM exams LIMIT 1");
    if ($hasExams && $hasExams->num_rows > 0) {
        return;
    }

    $terms = $conn->query("SELECT id, academic_year, term_no FROM terms ORDER BY academic_year DESC, term_no ASC");
    if (!$terms) {
        return;
    }

    $stmt = $conn->prepare("INSERT INTO exams (term_id, name) VALUES (?, ?)");
    if (!$stmt) {
        return;
    }

    while ($term = $terms->fetch_assoc()) {
        $termId = (int)$term['id'];
        $termName = 'Term ' . (int)$term['term_no'] . ' Assessment';
        $stmt->bind_param('is', $termId, $termName);
        $stmt->execute();
    }

    $stmt->close();
}

ensureSchoolExams($conn);
