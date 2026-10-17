<?php
require_once "db.php";


//For the fetching the batch of particular the program
if (isset($_POST['programId'])) {
    $programId = intval($_POST['programId']);

    // Fetch batches for the selected program
    $query = "SELECT c.id as batchid ,c.name as batchname  From {$prefix}course_categories c where c.parent=$programId";
    $result = $conn->query($query);

    if ($result && $result->num_rows > 0) {
        echo "<option value=''>Select Batch</option>";
        while ($row = $result->fetch_assoc()) {
            echo "<option value='" . htmlspecialchars($row['batchid']) . "'>" . htmlspecialchars($row['batchname']) . "</option>";
        }
    } else {
        echo "<option value=''>No batches available</option>";
    }
}


//For the fetching the batch of particular the program
if (isset($_POST['batchId'])) {
    $batchId = intval($_POST['batchId']);

    // Fetch batches for the selected program
    $query = "SELECT c.id as semid ,c.name as semname  From {$prefix}course_categories c where c.parent=$batchId";
    $result = $conn->query($query);

    if ($result && $result->num_rows > 0) {
        echo "<option value=''>Select Semester</option>";
        while ($row = $result->fetch_assoc()) {
            echo "<option value='" . htmlspecialchars($row['semid']) . "'>" . htmlspecialchars($row['semname']) . "</option>";
        }
    } else {
        echo "<option value=''>No Semester available</option>";
    }
}

if (isset($_POST['semId'])) {
    $semId = intval($_POST['semId']);

    // Fetch batches for the selected program
    $query = "SELECT c.id as courseid,c.fullname as coursename from {$prefix}course c 
join {$prefix}attendance a on a.course=c.id
where c.category in (SELECT id from {$prefix}course_categories cc where cc.parent=$semId) or c.category=$semId
GROUP BY c.id
ORDER BY  c.id";

$result = $conn->query($query);

    $response = [];

    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $response[] = [
                "courseid"   => htmlspecialchars($row['courseid']),
                "coursename" => htmlspecialchars($row['coursename'])
            ];
        }
    }
    
    // Return JSON
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
    
}
?>