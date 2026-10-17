<?php

require_once('database.php');
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $initialData = isset($_POST['initialData']) ? json_decode($_POST['initialData'], true) : '';
    $programname = isset($_POST['programLabel']) ? $_POST['programLabel'] : $initialData["programLabel"];
    $programId = isset($_POST['programSelect']) ? $_POST['programSelect'] : $initialData["programSelect"];
    $batchname = isset($_POST['batchLabel']) ? $_POST['batchLabel'] : $initialData["batchLabel"];
    $batchId = isset($_POST['batchSelect']) ? $_POST['batchSelect'] : $initialData["batchSelect"];
    $semestername = isset($_POST['semLabel']) ? $_POST['semLabel'] : $initialData["semLabel"];
    $semesterId = isset($_POST['semesterSelect']) ? $_POST['semesterSelect'] : $initialData["semesterSelect"];
    $roleId = isset($_POST['role']) ? $_POST['role'] : $initialData["role"];
    $serializedPrograms = isset($_POST['serializedPrograms']) ? $_POST['serializedPrograms'] : $initialData["serializedPrograms"];
    $programArray = json_decode($serializedPrograms, true);
    $sessions = $programArray;

    $initialData = ["programSelect" => $programname, "programId" => $programId,"batchSelect" => $batchname,"batchId" => $batchId, "semesterSelect" => $semestername, "semesterId" => $semesterId,"role" => $roleId, "serializedPrograms" => $serializedPrograms];

    if($debugging) {
        echo "<pre> POST DATA : ";
        print_r($_POST);
        echo "<hr>";
        
        echo "InitialData : ";
        print_r($initialData);
        
        echo "<br></pre>";
    }
    
}


$_SESSION['initialData'] = $initialData;


// echo"<pre>";
// print_r($_SESSION['initialData']);
// echo"</pre>";

$cidArray = array();
$cidArray = array_column($sessions, 'courseid');
$cid = implode(',', $cidArray);
$_SESSION['courseId'] = $cid;

$programname  = '';
$batchname    = '';
$semestername = '';
if (!empty($_SESSION['initialData'])) {
    // $programname  = $_SESSION['initialData']['programSelect']  ?? '';
    // $batchname    = $_SESSION['initialData']['batchSelect']    ?? '';
    // $semestername = $_SESSION['initialData']['semesterSelect'] ?? '';
    
    // Safely extract program, batch, and semester names with fallback and sanitization
$programname  = !empty($_SESSION['initialData']['programSelect'])
    ? trim($_SESSION['initialData']['programSelect'])
    : (!empty($_POST['programname_hidden']) ? trim($_POST['programname_hidden']) : '');

$batchname = !empty($_SESSION['initialData']['batchSelect'])
    ? trim($_SESSION['initialData']['batchSelect'])
    : (!empty($_POST['batchname_hidden']) ? trim($_POST['batchname_hidden']) : '');

$semestername = !empty($_SESSION['initialData']['semesterSelect'])
    ? trim($_SESSION['initialData']['semesterSelect'])
    : (!empty($_POST['semestername_hidden']) ? trim($_POST['semestername_hidden']) : '');

}

$type = '';
if (isset($_POST['type'])) {
    $type = $_POST['type'];
}
$responseFeedbackOptions = [
    'faculty_curriculum' => [
        'label' => 'Faculty Feedback - Curriculum',
        'name_pattern' => '%Faculty Feedback%Curriculum%',
    ],
];
$selectedResponseFeedbackKey = $_POST['response_feedback'] ?? '';
$selectedResponseFeedback = $responseFeedbackOptions[$selectedResponseFeedbackKey] ?? null;
$responseFeedbackNamePattern = $selectedResponseFeedback['name_pattern'] ?? '';
$data = [];
$questions = [];
$grouped = [];

if ($type === 'responses' && $selectedResponseFeedback !== null) {

    $feedbackResponse=$conn->query("SELECT 
            u.id AS userid,
            u.username,
            u.firstname,
            u.lastname,
            cc.id AS courseid,
            cc.fullname AS coursename,
            f.id AS feedbackid,
            f.name AS feedbackname,
            fi.id AS questionid,
            fi.name AS questionname,
            fi.presentation AS questionpresentation,
            fv.value AS answer,
            IF(fc.id IS NULL, 0, 1) AS feedbackcompleted
        FROM {$prefix}role_assignments ra
        JOIN {$prefix}context con 
            ON con.id = ra.contextid 
           AND con.contextlevel = 50
        JOIN {$prefix}course cc 
            ON cc.id = con.instanceid
        JOIN {$prefix}feedback f 
            ON f.course = cc.id
           AND f.name LIKE '{$responseFeedbackNamePattern}'
           AND f.course IN (" . $_SESSION['courseId'] . ")
        JOIN {$prefix}feedback_completed fc
            ON fc.feedback = f.id 
           AND fc.userid = ra.userid
        JOIN {$prefix}user u 
            ON u.id = ra.userid
        JOIN {$prefix}feedback_value fv
            ON fv.completed = fc.id
        JOIN {$prefix}feedback_item fi
            ON fi.id = fv.item
        WHERE ra.roleid = 3
        ORDER BY u.id, cc.id,fi.id");
        
        
        
    $data = $feedbackResponse ? $feedbackResponse->fetch_all(MYSQLI_ASSOC) : [];
    
    if($debugging) {
        
        echo "<pre><br> responses Query : 
        SELECT 
            u.id AS userid,
            u.username,
            u.firstname,
            u.lastname,
            cc.id AS courseid,
            cc.fullname AS coursename,
            f.id AS feedbackid,
            f.name AS feedbackname,
            fi.id AS questionid,
            fi.name AS questionname,
            fi.presentation AS questionpresentation,
            fv.value AS answer,
            IF(fc.id IS NULL, 0, 1) AS feedbackcompleted
        FROM {$prefix}role_assignments ra
        JOIN {$prefix}context con 
            ON con.id = ra.contextid 
           AND con.contextlevel = 50
        JOIN {$prefix}course cc 
            ON cc.id = con.instanceid
        JOIN {$prefix}feedback f 
            ON f.course = cc.id
           AND f.name LIKE '{$responseFeedbackNamePattern}'
           AND f.course IN (" . $_SESSION['courseId'] . ")
        JOIN {$prefix}feedback_completed fc
            ON fc.feedback = f.id 
           AND fc.userid = ra.userid
        JOIN {$prefix}user u 
            ON u.id = ra.userid
        JOIN {$prefix}feedback_value fv
            ON fv.completed = fc.id
        JOIN {$prefix}feedback_item fi
            ON fi.id = fv.item
        WHERE ra.roleid = 3
        ORDER BY u.id, cc.id,fi.id
        <br>";
        
        echo"IF PART feedbackResponse data : ";
        print_r($data);
        echo"<br></pre>";
    }
    // Step 1: Extract unique questions
    
    $questions = [];
    foreach ($data as $row) {
        if (!empty($row['questionname']) && !isset($questions[$row['questionname']])) {
            $questions[$row['questionname']] = $row['questionname']; // key = text
        }
    }
    // Step 2: Group data by course and faculty
    $grouped = [];
    
    foreach ($data as $row) {
        $course  = $row['coursename'];
        $faculty = trim($row['firstname'] . " " . $row['lastname']);
        $qtext   = $row['questionname']; // use text, not id
    
        if (!isset($grouped[$course])) {
            $grouped[$course] = [];
        }
    
        if (!isset($grouped[$course][$faculty])) {
            $grouped[$course][$faculty] = [];
        }
    
        if (!empty($qtext)) {
            $grouped[$course][$faculty][$qtext] = [
                'answer' => $row['answer']
            ];
        }
    }

    if($debugging) {
        echo"<pre> Grouped : ";
        print_r($grouped);
        echo"<br></pre>";
    }

}
elseif ($type !== 'responses') {
    $programs = $conn->query("
            SELECT 
            u.id AS userid,
            u.username,
            u.firstname,
            u.lastname,
            cc.id AS courseid,
            cc.fullname AS coursename,
            f.id AS feedbackid,
            f.name AS feedbackname,
           IF(fc.id IS NULL, 0, 1) AS feedbackcompleted
        FROM {$prefix}role_assignments ra
        JOIN {$prefix}context con 
            ON con.id = ra.contextid 
           AND con.contextlevel = 50
        JOIN {$prefix}course cc 
            ON cc.id = con.instanceid
        JOIN {$prefix}feedback f 
            ON f.course = cc.id
           AND f.name LIKE '%Faculty Feedback - Curriculum%'
           AND f.course IN (" . $_SESSION['courseId'] . ")
        left join {$prefix}feedback_completed fc 
            ON fc.feedback = f.id 
           AND fc.userid = ra.userid
        JOIN {$prefix}user u 
            ON u.id = ra.userid
        WHERE ra.roleid = 3");

    $data = $programs->fetch_all(MYSQLI_ASSOC);
    
    if($debugging) {
        
        echo "<pre> Else part Query : 
            SELECT 
            u.id AS userid,
            u.username,
            u.firstname,
            u.lastname,
            cc.id AS courseid,
            cc.fullname AS coursename,
            f.id AS feedbackid,
            f.name AS feedbackname,
           IF(fc.id IS NULL, 0, 1) AS feedbackcompleted
        FROM {$prefix}role_assignments ra
        JOIN {$prefix}context con 
            ON con.id = ra.contextid 
           AND con.contextlevel = 50
        JOIN {$prefix}course cc 
            ON cc.id = con.instanceid
        JOIN {$prefix}feedback f 
            ON f.course = cc.id
           AND f.name LIKE '%Faculty Feedback - Curriculum%'
           AND f.course IN (" . $_SESSION['courseId'] . ")
        left join {$prefix}feedback_completed fc 
            ON fc.feedback = f.id 
           AND fc.userid = ra.userid
        JOIN {$prefix}user u 
            ON u.id = ra.userid
        WHERE ra.roleid = 3
        <br>    ";
        
        echo"ELSE PART Programs : ";
        print_r($data);
        echo"<br></pre>";
    }

}

//  echo "<pre>";
// print_r($data);
//  echo "</pre>";

?>


<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consolidated Feedback</title>
    <link rel="stylesheet" href="assets/css/report.css">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
</head>

<body>
    <div class="container">
        <div class="header">

            <form action="faculty-report.php" method="post" id='reportTypeForm'>
                <select name="type" id="type" >
                    <option value="consolidated" <?php if($type=="consolidated") echo "selected"; ?>>Consolidated Report</option>
                    <option value="responses" <?php if($type=="responses") echo "selected"; ?>>Responses Report</option>
                </select>

                <span id="responseFeedbackControl" <?php if ($type !== 'responses') { echo 'style="display:none;"'; } ?>>
                    <label for="responseFeedback">Feedback:</label>
                    <select name="response_feedback" id="responseFeedback">
                        <option value="">Select feedback</option>
                        <?php foreach ($responseFeedbackOptions as $feedbackKey => $feedbackOption) { ?>
                            <option value="<?php echo htmlspecialchars($feedbackKey); ?>" <?php if ($selectedResponseFeedbackKey === $feedbackKey) { echo 'selected'; } ?>>
                                <?php echo htmlspecialchars($feedbackOption['label']); ?>
                            </option>
                        <?php } ?>
                    </select>
                </span>
                
                <input type="hidden" name="initialData" value='<?php echo json_encode($_SESSION["initialData"]); ?>'>
                  
                <input type="hidden" name="programname_hidden" value="<?php echo($programname); ?>">
                <input type="hidden" name="batchname_hidden" value="<?php echo($batchname); ?>">
                <input type="hidden" name="semestername_hidden" value="<?php echo($semestername); ?>">
                  
            </form>

            <div class="headings">
                <h1><?php echo $programname; ?></h1>
                <h1><?php echo $batchname; ?></h1>
                <h1><?php echo $semestername; ?></h1>
            </div>

            <div id="reportSearchTools">
                <label for="reportSearch">Search:</label>
                <input type="search" id="reportSearch" placeholder="User, course, or feedback">
            </div>


            <?php if ($type !== 'responses' || !empty($grouped)) { ?>
                <button onclick="exportTableToExcel('myTable', '<?php echo $type; ?>' )">Download</button>
            <?php } ?>
        </div>

        <?php if ($type == 'analysis' || $type=="consolidated" ) { ?>

           <div class="data">
   <?php if (!empty($data)) { ?>
    <table border="1" id="myTable">
        <thead>
            <?php
            // Step 1: Collect unique feedbacks (course + feedback)
            $feedbackIds = [];
            $courseNames = [];
            $feedbackNames = [];

            foreach ($data as $row) {
                $fid = $row['feedbackid'];
                if (!in_array($fid, $feedbackIds)) {
                    $feedbackIds[] = $fid;
                    $courseNames[$fid] = $row['coursename'];
                    $feedbackNames[$fid] = $row['feedbackname'];
                }
            }

            // Header Row 1 (Course names)
            echo "<tr>";
            echo "<th>Username</th>";
            echo "<th>Full Name</th>";
            foreach ($feedbackIds as $fid) {
                echo "<th>" . $courseNames[$fid] . "</th>";
            }
            echo "<th>Submitted</th>";
            echo "<th>Not Submitted</th>";
            echo "</tr>";

            // Header Row 2 (Feedback names)
            echo "<tr>";
            echo "<th></th>";
            echo "<th></th>";
            foreach ($feedbackIds as $fid) {
                echo "<th>" . $feedbackNames[$fid] . "</th>";
            }
            echo "<th></th>";
            echo "<th></th>";
            echo "</tr>";
            ?>
        </thead>
        <tbody>
            <?php
            // Step 2: Group data by user
            $users = [];
            foreach ($data as $row) {
                $userid = $row['userid'];
                if (!isset($users[$userid])) {
                    $users[$userid] = [
                        'username' => $row['username'],
                        'fullname' => $row['firstname'] . " " . $row['lastname'],
                        'feedbacks' => []
                    ];
                }
                $users[$userid]['feedbacks'][$row['feedbackid']] = $row['feedbackcompleted'];
            }

            // Step 3: Print one row per user
            foreach ($users as $user) {
                $submitted = 0;
                $notSubmitted = 0;

                echo "<tr>";
                echo "<td id='heading'>" . $user['username'] . "</td>";
                echo "<td id='heading'>" . $user['fullname'] . "</td>";

                foreach ($feedbackIds as $fid) {
                    if (isset($user['feedbacks'][$fid])) {
                        if ($user['feedbacks'][$fid] == 1) {
                            echo "<td id='value'><img src='assets/images/check.png' alt='Yes' data-export='Yes'/></td>";
                            $submitted++;
                        } else {
                            echo "<td id='value'><img src='assets/images/remove.png' alt='No' data-export='No'/></td>";
                            $notSubmitted++;
                        }
                    } else {
                        echo "<td id='value'>-</td>";
                    }
                }

                echo "<td id='value'>" . $submitted . "</td>";
                echo "<td id='value'>" . $notSubmitted . "</td>";
                echo "</tr>";
            }
            ?>
        </tbody>
    </table>
<?php } else { ?>
    <div style="margin:20px; padding:20px; border:1px solid #ccc; background:#f9f9f9; text-align:center; font-size:18px; color:#555; border-radius:8px;">
        🚫 No records found for feedback submissions.
    </div>
<?php } ?>

</div>


        <?php } else { ?>
         <div class="data">
    <?php if (!empty($grouped)) { ?>
    <table border="1" id="myTable">
        <thead>
            <tr>
                <th>Course Name</th>
                <th>Faculty Name</th>
                <?php foreach ($questions as $qtext) { ?>
                    <th><?php echo $qtext; ?></th>
                <?php } ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($grouped as $course => $faculties) { ?>
            <?php 
                $courseRowCount = count($faculties);
                $coursePrinted = false;
            ?>
            <?php foreach ($faculties as $faculty => $answers) { ?>
                <tr>
                    <?php if (!$coursePrinted) { ?>
                        <td rowspan="<?php echo $courseRowCount; ?>" id='value'><?php echo $course; ?></td>
                        <?php $coursePrinted = true; ?>
                    <?php } ?>
                    <td id='value'><?php echo $faculty; ?></td>
                    <?php foreach ($questions as $qtext) { ?>
                        <td id='value'>
                            <?php
                                if (isset($answers[$qtext]['answer'])) {
                                    switch ($answers[$qtext]['answer']) {
                                        case 5: echo "Strongly Agree"; break;
                                        case 4: echo "Agree"; break;
                                        case 3: echo "Neutral"; break;
                                        case 2: echo "Disagree"; break;
                                        case 1: echo "Strongly Disagree"; break;
                                        default: echo $answers[$qtext]['answer'];
                                    }
                                } else {
                                    echo "-";
                                }
                            ?>
                        </td>
                    <?php } ?>
                </tr>
            <?php } ?>
        <?php } ?>
        </tbody>
    </table>
<?php } else { ?>
    <div style="margin:20px; padding:20px; border:1px solid #ccc; background:#f9f9f9; text-align:center; font-size:18px; color:#555; border-radius:8px;">
        <?php if ($selectedResponseFeedback === null) { ?>
            Select a feedback type to view faculty responses.
        <?php } else { ?>
            No responses have been submitted for <?php echo htmlspecialchars($selectedResponseFeedback['label']); ?> in the selected course(s).
        <?php } ?>
    </div>
<?php } ?>

</div>

        <?php } ?>
    </div>
    <script src="assets/js/report-search.js"></script>
    <script>

        let programname = '<?php echo $programname; ?>';
        let batchname = '<?php echo $batchname; ?>';
        let semestername = '<?php echo $semestername; ?>';


        function exportTableToExcel(tableID, filename = '') {
            const table = document.getElementById(tableID);
            const rows = table.querySelectorAll("tr");
            const matrix = [];
            let maxCols = 0;

            rows.forEach((row, rowIndex) => {
                const cells = row.querySelectorAll("th, td");
                let colIndex = 0;

                if (!matrix[rowIndex]) matrix[rowIndex] = [];

                cells.forEach(cell => {
                    // Skip filled cells in the current row
                    while (matrix[rowIndex][colIndex]) colIndex++;

                    const colspan = parseInt(cell.getAttribute("colspan")) || 1;
                    const rowspan = parseInt(cell.getAttribute("rowspan")) || 1;
                    const cellValue = cell.querySelector("img")
                        ? cell.querySelector("img").getAttribute("data-export")
                        : cell.textContent.trim();

                    // Fill the matrix for colspan and rowspan
                    for (let i = 0; i < rowspan; i++) {
                        for (let j = 0; j < colspan; j++) {
                            matrix[rowIndex + i] = matrix[rowIndex + i] || [];
                            matrix[rowIndex + i][colIndex + j] = i === 0 && j === 0 ? cellValue : "";
                        }
                    }

                    colIndex += colspan;
                });

                maxCols = Math.max(maxCols, colIndex);
            });
            const additionalRows = [
                [programname],
                [batchname],
                [semestername]
            ];

            // Ensure additional rows have the same column length as the table's max columns
            const formattedAdditionalRows = additionalRows.map(row =>
                row.concat(Array(maxCols - row.length).fill("")).join("\t")
            );
            const tableContent = [
                ...formattedAdditionalRows,
                ...matrix.map(row => row.map(cell => cell || "").join("\t"))
            ].join("\n");

            const downloadFilename = filename ? `${filename}.xls` : "export.xls";

            const downloadLink = document.createElement("a");
            document.body.appendChild(downloadLink);

            const blob = new Blob([tableContent], { type: "application/vnd.ms-excel" });
            downloadLink.href = URL.createObjectURL(blob);
            downloadLink.download = downloadFilename;
            downloadLink.click();

            document.body.removeChild(downloadLink);
        }

        const reportType = document.getElementById('type');
        const responseFeedback = document.getElementById('responseFeedback');
        const responseFeedbackControl = document.getElementById('responseFeedbackControl');
        const reportTypeForm = document.getElementById('reportTypeForm');

        reportType.addEventListener('change', function () {
            if (this.value === 'responses') {
                responseFeedbackControl.style.display = 'inline';
                if (responseFeedback.value !== '') {
                    reportTypeForm.submit();
                }
                return;
            }

            responseFeedbackControl.style.display = 'none';
            reportTypeForm.submit();
        });

        responseFeedback.addEventListener('change', function () {
            if (reportType.value === 'responses' && this.value !== '') {
                reportTypeForm.submit();
            }
        });

        initReportSearch({
            tableId: 'myTable',
            inputId: 'reportSearch',
            toolsId: 'reportSearchTools'
        });


    </script>
</body>

</html>
