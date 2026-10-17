<?php

require_once __DIR__ . '/../database.php';

function options_request_error(string $message, int $statusCode = 400): void {
    global $debugging;

    feedback_report_debug($message);
    http_response_code($statusCode);
    exit($debugging ? $message : 'The requested options could not be loaded.');
}

feedback_report_debug('Options request received.', ['post_keys' => array_keys($_POST)]);

if (isset($_POST['programId'])) {
    $programId = (int) $_POST['programId'];
    $query = "SELECT c.id AS batchid, c.name AS batchname
              FROM {$prefix}course_categories c
              WHERE c.parent = {$programId}
              ORDER BY c.name";
    $result = $conn->query($query);

    if ($result === false) {
        feedback_report_debug('Batch lookup query failed.', ['mysqli_error' => $conn->error]);
        options_request_error('Batch lookup failed.', 500);
    }

    echo "<option value=''>Select Batch</option>";
    if ($result->num_rows === 0) {
        echo "<option value=''>No batches available</option>";
    } else {
        while ($row = $result->fetch_assoc()) {
            echo "<option value='" . htmlspecialchars($row['batchid']) . "'>" . htmlspecialchars($row['batchname']) . "</option>";
        }
    }
    exit;
}

if (isset($_POST['batchId'])) {
    $batchId = (int) $_POST['batchId'];
    $query = "SELECT c.id AS semid, c.name AS semname
              FROM {$prefix}course_categories c
              WHERE c.parent = {$batchId}
              ORDER BY c.name";
    $result = $conn->query($query);

    if ($result === false) {
        feedback_report_debug('Semester lookup query failed.', ['mysqli_error' => $conn->error]);
        options_request_error('Semester lookup failed.', 500);
    }

    echo "<option value=''>Select Semester</option>";
    if ($result->num_rows === 0) {
        echo "<option value=''>No semesters available</option>";
    } else {
        while ($row = $result->fetch_assoc()) {
            echo "<option value='" . htmlspecialchars($row['semid']) . "'>" . htmlspecialchars($row['semname']) . "</option>";
        }
    }
    exit;
}

if (isset($_POST['semId'])) {
    $semId = (int) $_POST['semId'];
    $query = "SELECT c.id AS courseid, c.fullname AS coursename
              FROM {$prefix}course c
              JOIN {$prefix}attendance a ON a.course = c.id
              WHERE c.category IN (SELECT id FROM {$prefix}course_categories WHERE parent = {$semId})
                 OR c.category = {$semId}
              GROUP BY c.id, c.fullname
              ORDER BY c.id";
    $result = $conn->query($query);

    if ($result === false) {
        feedback_report_debug('Course lookup query failed.', ['mysqli_error' => $conn->error]);
        options_request_error('Course lookup failed.', 500);
    }

    $response = [];
    while ($row = $result->fetch_assoc()) {
        $response[] = [
            'courseid' => htmlspecialchars($row['courseid']),
            'coursename' => htmlspecialchars($row['coursename']),
        ];
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

options_request_error('No valid options request was supplied.');
