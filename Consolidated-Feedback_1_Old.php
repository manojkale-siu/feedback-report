<?php

require_once('db.php');
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
    $programname  = $_SESSION['initialData']['programSelect']  ?? '';
    $batchname    = $_SESSION['initialData']['batchSelect']    ?? '';
    $semestername = $_SESSION['initialData']['semesterSelect'] ?? '';
}

$type = '';
if (isset($_POST['type'])) {
    $type = $_POST['type'];
    echo "$type <hr>";
}

if($type == 'analysis'){

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
    CASE
        WHEN ra.userid IS NULL THEN 'NA'                           
        WHEN g.id IS NOT NULL AND gm.userid IS NULL THEN 'NA'      
        WHEN fc.id IS NULL THEN '0'                                
        ELSE '1'                                                   
    END AS feedbackcompleted
FROM {$prefix}course cc
JOIN {$prefix}feedback f 
    ON f.course = cc.id
JOIN {$prefix}context con 
    ON con.instanceid = cc.id 
   AND con.contextlevel = 50
JOIN {$prefix}role_assignments ra 
    ON ra.contextid = con.id 
   AND ra.roleid = 5
JOIN {$prefix}user u 
    ON u.id = ra.userid
LEFT JOIN {$prefix}feedback_completed fc 
    ON fc.feedback = f.id 
   AND fc.userid = u.id
LEFT JOIN {$prefix}groups g
    ON g.courseid = cc.id 
   AND f.name LIKE CONCAT('%', g.name, '%') 
LEFT JOIN {$prefix}groups_members gm
    ON gm.groupid = g.id 
   AND gm.userid = u.id 
WHERE cc.id IN (" . $_SESSION['courseId'] . ") and f.name not like '%Faculty%Feedback%Curriculum%'
ORDER BY cc.id, f.id, u.id;
     "
);

$data = $programs->fetch_all(MYSQLI_ASSOC);


// Build pivot arrays
$studentFeedbackList = [];        // course -> feedbacks
$studentAccessibleFeedback = [];  // student -> course -> feedbackId -> status

foreach ($data as $row) {
    $userid   = $row['userid'];
    $username = $row['username'];
    $fullname = $row['firstname'] . " " . $row['lastname'];
    $courseid = $row['courseid'];
    $coursename = $row['coursename'];
    $feedbackid = $row['feedbackid'];
    $feedbackname = $row['feedbackname'];
    $completed = $row['feedbackcompleted'];

    // Build course → feedback list (for table header)
    if (!isset($studentFeedbackList[$courseid])) {
        $studentFeedbackList[$courseid] = [];
    }
    $studentFeedbackList[$courseid][$feedbackid] = $feedbackname;

    // Build student → course → feedback completion
    if (!isset($studentAccessibleFeedback[$userid])) {
        $studentAccessibleFeedback[$userid] = [];
    }
    if (!isset($studentAccessibleFeedback[$userid][$courseid])) {
        $studentAccessibleFeedback[$userid][$courseid] = [];
    }
    $studentAccessibleFeedback[$userid][$courseid][$feedbackid] = $completed;




}
}

//NEW CODE 

if ($type == 'responses') {

    $courseTemplate = $conn->query("SELECT id from {$prefix}course_categories where name like '%Course Template%'")->fetch_assoc()['id'];

    $feedbackQuestionsArray = [];

    $feedbackTemplate = $conn->query("SELECT id from {$prefix}course where fullname = 'Feedback Templates' or fullname = 'Feedback Template'");
    if ($feedbackTemplate->num_rows > 0) {
        $feedbackTemplateId = $feedbackTemplate->fetch_assoc()['id'];
        $feedbackId = $conn->query("SELECT id from {$prefix}feedback where course=$feedbackTemplateId and name LIKE '%Student Feedback%Curriculum' ");
        if ($feedbackId->num_rows > 0) {
            $feedbackId = $feedbackId->fetch_assoc()['id'];
            $feedbackQuestions = $conn->query("SELECT id, name from {$prefix}feedback_item where feedback = $feedbackId  ORDER BY 
                                                    CAST( SUBSTRING_INDEX(name, '.', 1) AS UNSIGNED) ASC;");

            echo "";

            if ($feedbackQuestions->num_rows > 0) {
                while ($question = mysqli_fetch_assoc($feedbackQuestions)) {
                    $feedbackQuestionsArray[$question['id']] = $question['name'];
                }
            }
        } else {
            echo "Faculty Feedback Curriculum Not Found in Feedback Template Course";
            exit;
        }
    } else {
        echo "Feedback Template Course Not Found";
        exit;
    }

}

// NEW CODE ENDS HERE

//  echo "<pre>";

// // echo "STUDENT <br> ------------------ <br>";
// // print_r($_POST);
// // echo "----------------------------------------<br>";

// print_r($data);
// // echo "----------------------------------------<br>";

//  echo "</pre>";


?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consolidated Feedback</title>
    <link rel="stylesheet" href="feedbacktable.css">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>

    <style>
        #myTable th:first-child,
#myTable td:first-child,
#myTable th:nth-child(2),
#myTable td:nth-child(2),
#myTable th:nth-child(3),
#myTable td:nth-child(3) {
    position: sticky;
    left: 0;
    background: #fff;
    z-index: 2;
}
.feedback-table th {
    white-space: nowrap;
    text-align: center;
    vertical-align: bottom;
    padding: 5px;
}

.feedback-table th.rotate {
    writing-mode: vertical-rl;
    transform: rotate(180deg);
    white-space: nowrap;
    width: 30px;
}
.feedback-table {
    border-collapse: collapse;
    width: 100%;
    font-size: 13px;
}

.feedback-table th,
.feedback-table td {
    border: 1px solid #ddd;
    padding: 6px;
    text-align: center;
}

.feedback-table th {
    background: #f4f6f9;
    font-weight: bold;
    color: #333;
}

.feedback-table tr:nth-child(even) {
    background: #fafafa;
}
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <!-- <select name="role " id="role">
                <option value="5">Student</option>
                <option value="3">Teacher</option>
            </select> -->

            <!-- This is for Student - Student UI -->
            <!-- Newly added code starts here -->
            <form action="Consolidated-Feedback_1.php" method="post" id='reportTypeForm'>
                <select name="type" id="type">
                    <option value="consolidated">Consolidated Report</option>
                    <option value="responses">Responses Report</option>
                </select>

                <input type="hidden" name="initialData"
                    value="<?php echo htmlspecialchars(json_encode($initialData)); ?>">

            </form>
            <!-- Newly added code ends here -->


            <div class="headings">
                <h1><?php echo $programname; ?></h1>
                <h1><?php echo $batchname; ?></h1>
                <h1><?php echo $semestername; ?></h1>
            </div>


            <button onclick="exportTableToExcel('myTable', 'Feedback Report')">Download</button>
        </div>



        <?php if ($type == 'analysis') { ?>
           <div style="overflow-x:auto; max-width:100%;">
            <div class="data">
                <table border='1' id="myTable">
                    <thead>
                        <?php
                        echo "<tr>";
                        echo "<th> Sr.No </th>";
                        echo "<th> PRN </th>";
                        echo "<th> Full Name </th>";
                        foreach ($studentFeedbackList as $courseid => $feedbacks) {
                            $courseName = $conn->query("SELECT fullname from {$prefix}course where id = $courseid")->fetch_assoc()['fullname'];
                            if (is_array($feedbacks)) {
                                $isPresent = 0;

                                // Checking for is Faculty Feedback Curriculum is Present, where we created an Identity to know Faculty Curriculum is Present
                                foreach ($feedbacks as $feedbackId => $feedbackName) {
                                    // Identity of an Array for Faculty Feedback Curriculum
                                    if (is_array($feedbackName))
                                        $isPresent = 1;
                                }
                                echo "<th colspan='" . (count($feedbacks) - $isPresent) . "'>" . $courseName . "</th>";
                            } else {
                                echo "<th>" . $courseName . "</th>";
                            }
                        }
                        echo "<th rowspan='2'> Not Submitted </td>";
                        echo "<th rowspan='2'> Submitted </td>";
                        echo "</tr>";

                        echo "<tr>";
                        echo "<th></th>";
                        echo "<th></th>";
                        echo "<th></th>";
                        foreach ($studentFeedbackList as $courseid => $feedbacks) {
                            if (is_array($feedbacks)) {
                                foreach ($feedbacks as $feedbackId => $feedbackName) {
                                    if (!is_array($feedbackName))
                                        echo "<th>" . $feedbackName . "</th>";
                                }
                            } else {
                                echo "<th>" . $feedbacks . "</th>";

                            }
                        }
                        echo "</tr>";

                        ?>
                    </thead>
                    <tbody>
                        <?php
                        $sr_no = 1;
                        foreach ($studentAccessibleFeedback as $studentId => $courseDetails) {
                            $submittedCount = 0;
                            $notSubmittedCount = 0;
                            echo "<tr>";
                            $studentDetails = $conn->query("SELECT username, concat(firstname, ' ', lastname) as fullname from {$prefix}user where id=$studentId")->fetch_assoc();
                            echo "<td id='heading'>" . $sr_no . "</td>";
                            echo "<td id='heading'>" . $studentDetails['username'] . "</td>";
                            echo "<td id='heading'>" . $studentDetails['fullname'] . "</td>";

                            foreach ($studentFeedbackList as $courseid => $feedbackListInCourse) {
                                if (isset($courseDetails[$courseid])) {
                                    if (is_array($courseDetails[$courseid])) {
                                        foreach ($courseDetails[$courseid] as $feedbackId => $completedStatus) {
                                            if ((isset($feedbackListInCourse[$feedbackId])) && !is_array($feedbackListInCourse[$feedbackId])) {
                                                if ($completedStatus == 1) {
                                                    echo "<td id='value'><img src='check.png' alt='loading' data-export='Yes'/></td>";
                                                    $submittedCount++;
                                                } else if ($completedStatus === 'NA') {
                                                    echo "<td id='value'>NA</td>";
                                                } else {
                                                    echo "<td id='value'><img src='remove.png' alt='loading' data-export='No'/></td>";
                                                    $notSubmittedCount++;
                                                }
                                            }
                                        }
                                    } else {
                                        if (is_array($feedbackListInCourse)) {
                                            foreach ($feedbackListInCourse as $feedbackId => $feedbackname) {
                                                if (!is_array($feedbackname))
                                                    echo "<td id='value'> NFA </td>";
                                            }
                                        } else {
                                            echo "<td id='value'>FNA</td>";
                                        }
                                    }
                                } else {
                                    if (is_array($feedbackListInCourse)) {
                                        foreach ($feedbackListInCourse as $feedbackId => $feedbackname) {
                                            if (!is_array($feedbackname))
                                                echo "<td id='value'> - </td>";
                                        }
                                    } else {
                                        echo "<td id='value'>-</td>";

                                    }
                                }
                            }
                            echo "<td id='value'>" . $notSubmittedCount . "</td>";
                            echo "<td id='value'>" . $submittedCount . "</td>";
                            echo "</tr>";

                            $sr_no++;
                        }

                        ?>
                    </tbody>
                </table>
            </div>
            </div>

        <?php } elseif ($type == 'responses') { ?>

            <!-- NEW: Student Responses Report -->
            <div class="data">
                <table border='1' id="myTable">
                    <thead>
                        <tr>
                            <th>Sr No</th>
                            <th>Student Name</th>
                            <th>Course Name</th>
                            <?php
                            foreach ($feedbackQuestionsArray as $questionId => $questionName) {
                                echo "<th>" . $questionName . "</th>";
                            }
                            ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $srno = 1;

                        foreach ($studentAccessibleFeedback as $studentId => $courseDetails) {
                            $studentDetails = $conn->query("SELECT username, concat(firstname, ' ', lastname) as fullname 
                                                    FROM {$prefix}user 
                                                    WHERE id = $studentId")->fetch_assoc();

                            $courseCount = count($courseDetails);  // How many courses this student has
                            $firstCourse = true;

                            foreach ($courseDetails as $courseId => $feedbackList) {
                                $courseName = $conn->query("SELECT fullname 
                                                    FROM {$prefix}course 
                                                    WHERE id = $courseId")->fetch_assoc()['fullname'];

                                echo "<tr>";

                                if ($firstCourse) {
                                    echo "<td rowspan='$courseCount' id='value'>" . $srno . "</td>";
                                    echo "<td rowspan='$courseCount' id='value'>" . $studentDetails['username'] . " - " . $studentDetails['fullname'] . "</td>";
                                    $firstCourse = false;
                                }

                                echo "<td id='value'>" . $courseName . "</td>";

                                // echo "<td id='value'>" . $studentDetails['fullname'] . "</td>";
                    
                                //added if condition because for sometime it is giving us error for feedbackList contains : "Student is Not Assigned in a Group for Course"
                                $responses = false;
                                if (is_array($feedbackList) && !empty($feedbackList)) {

                                    $feedbackIds = implode(',', array_keys($feedbackList));

                                    $responses = $conn->query("SELECT fi.id as questionId, fi.name as question, fv.value, fi.typ, fi.presentation 
                                                    FROM {$prefix}feedback_item fi
                                                    JOIN {$prefix}feedback_value fv ON fv.item = fi.id
                                                    JOIN {$prefix}feedback_completed fc ON fc.id = fv.completed
                                                    JOIN {$prefix}feedback f ON f.id = fc.feedback AND f.name LIKE '%Student Feedback%Curriculum%'
                                                    WHERE fc.feedback IN ($feedbackIds)  
                                                    AND fc.userid=$studentId 
                                                    ORDER BY 
                                                    CAST( SUBSTRING_INDEX(fi.name, '.', 1) AS UNSIGNED) ASC;");
                                }
                                      
                                // Fetch student responses
                                // $responses = $conn->query("SELECT fi.id as questionId, fi.name as question, fv.value, fi.typ, fi.presentation 
                                //                     FROM {$prefix}feedback_item fi
                                //                     JOIN {$prefix}feedback_value fv ON fv.item = fi.id
                                //                     JOIN {$prefix}feedback_completed fc ON fc.id = fv.completed
                                //                     JOIN {$prefix}feedback f ON f.id = fc.feedback AND f.name LIKE '%Student Feedback%Curriculum'
                                //                     WHERE fc.feedback IN (" . implode(',', array_keys($feedbackList)) . ") 
                                //                     AND fc.userid=$studentId 
                                //                     ORDER BY 
                                //                     CAST( SUBSTRING_INDEX(fi.name, '.', 1) AS UNSIGNED) ASC;");
                                
                    
                                if ($responses && $responses->num_rows > 0) {
                                    $answers = [];

                                    while ($answer = mysqli_fetch_assoc($responses)) {

                                        if ($answer['typ'] === "multichoicerated") {
                                            switch ($answer['value']) {
                                                case "1":
                                                    $label = "Strongly Agree";
                                                    break;
                                                case "2":
                                                    $label = "Agree";
                                                    break;
                                                case "3":
                                                    $label = "Neutral";
                                                    break;
                                                case "4":
                                                    $label = "Disagree";
                                                    break;
                                                case "5":
                                                    $label = "Strongly Disagree";
                                                    break;
                                                case "":
                                                    $label = "NA";
                                                    break;
                                                default:
                                                    $label = $answer['value'];
                                                    break;
                                            }
                                        } elseif ($answer['typ'] === "multichoice") {
                                            // Yes / No type
                                            switch ($answer['value']) {
                                                case "1":
                                                    $label = "Yes";
                                                    break;
                                                case "2":
                                                    $label = "No";
                                                    break;
                                                case "":
                                                    $label = "NA";
                                                    break;
                                                default:
                                                    $label = $answer['value'];
                                                    break;
                                            }
                                        } else {
                                            // Other types (text, textarea, numeric, etc.)
                                            $label = $answer['value'];
                                            if ($label == "") {
                                                $label = "NA";
                                            }
                                        }

                                        $answers[$answer['question']] = $label;   // Keyed by question text
                                    }

                                    // Print in same order as feedbackQuestionsArray
                                    foreach ($feedbackQuestionsArray as $qid => $qname) {
                                        echo "<td id='value'>" . ($answers[$qname] ?? "-") . "</td>";  // Match by text
                                    }

                                } else {
                                    // echo "HELLO ELSE . <br>";
                                    // No responses
                                    foreach ($feedbackQuestionsArray as $qid => $qname) {
                                        echo "<td id='value'>-</td>";
                                    }
                                }

                                echo "</tr>";
                            }
                            $srno++;

                            // if ($srno == 5) {
                            //     exit;
                            // }
                    
                        }
                        ?>
                    </tbody>
                </table>
            </div>


            <!-- NEW: Aggregate Summary Tables -->
            <div class="data" style="margin-top:30px;">
                <h2 style='text-align:center;'>Summary of Student Responses</h2>

                <?php
                // Fetch all student responses for this feedback type
                // $allResponses = $conn->query("
                //     SELECT fi.id as questionId, fi.name as question, fv.value, fi.typ
                //     FROM {$prefix}feedback_item fi
                //     JOIN {$prefix}feedback_value fv ON fv.item = fi.id
                //     JOIN {$prefix}feedback_completed fc ON fc.id = fv.completed
                //     JOIN {$prefix}feedback f ON f.id = fc.feedback AND f.name LIKE '%Student Feedback%Curriculum'
                //     WHERE fc.userid IN (" . implode(',', array_keys($studentAccessibleFeedback)) . ")
                //     ORDER BY CAST(SUBSTRING_INDEX(fi.name, '.', 1) AS UNSIGNED) ASC
                // ");
            
                // Collect all feedback IDs from $studentAccessibleFeedback (student -> courses -> feedback list)
                $allFeedbackIds = [];
                foreach ($studentAccessibleFeedback as $studentId => $courseDetails) {
                    foreach ($courseDetails as $courseId => $feedbackList) {
                        if (is_array($feedbackList)) {
                            $allFeedbackIds = array_merge($allFeedbackIds, array_keys($feedbackList));
                        }
                        // $allFeedbackIds = array_merge($allFeedbackIds, array_keys($feedbackList));
                    }
                }
                $allFeedbackIds = array_unique($allFeedbackIds);

                if (!empty($allFeedbackIds)) {
                    $allResponses = $conn->query("
                        SELECT fi.id as questionId, fi.name as question, fv.value, fi.typ
                        FROM {$prefix}feedback_item fi
                        JOIN {$prefix}feedback_value fv ON fv.item = fi.id
                        JOIN {$prefix}feedback_completed fc ON fc.id = fv.completed
                        JOIN {$prefix}feedback f ON f.id = fc.feedback AND f.name LIKE '%Student Feedback%Curriculum'
                        WHERE fc.feedback IN (" . implode(',', $allFeedbackIds) . ")
                        ORDER BY CAST(SUBSTRING_INDEX(fi.name, '.', 1) AS UNSIGNED) ASC
                    ");
                } else {
                    $allResponses = false;
                }

                $summaryLikert = [];
                $summaryYesNo = [];

                while ($row = mysqli_fetch_assoc($allResponses)) {
                    $question = $row['question'];
                    $value = $row['value'];
                    $typ = $row['typ'];

                    if ($typ === "multichoicerated") {
                        // Likert scale
                        switch ($value) {
                            case "1":
                                $label = "Strongly Agree";
                                break;
                            case "2":
                                $label = "Agree";
                                break;
                            case "3":
                                $label = "Neutral";
                                break;
                            case "4":
                                $label = "Disagree";
                                break;
                            case "5":
                                $label = "Strongly Disagree";
                                break;
                            default:
                                $label = "NA";
                                break;
                        }

                        if (!isset($summaryLikert[$question])) {
                            $summaryLikert[$question] = [
                                "Strongly Agree" => 0,
                                "Agree" => 0,
                                "Neutral" => 0,
                                "Disagree" => 0,
                                "Strongly Disagree" => 0,
                            ];
                        }
                        if (isset($summaryLikert[$question][$label])) {
                            $summaryLikert[$question][$label]++;
                        }

                    } elseif ($typ === "multichoice") {
                        // Yes/No
                        switch ($value) {
                            case "1":
                                $label = "Yes";
                                break;
                            case "2":
                                $label = "No";
                                break;
                            default:
                                $label = "NA";
                                break;
                        }

                        if (!isset($summaryYesNo[$question])) {
                            $summaryYesNo[$question] = [
                                "Yes" => 0,
                                "No" => 0,
                            ];
                        }
                        if (isset($summaryYesNo[$question][$label])) {
                            $summaryYesNo[$question][$label]++;
                        }
                    }
                }
                ?>

                <!-- Likert Scale Table -->
                <?php if (!empty($summaryLikert)) { ?>
                    <!-- <h3>Likert Scale Questions</h3> -->
                    <br>
                    <table border='1' id="summaryLikertTable">
                        <thead>
                            <tr>
                                <th>Sr No</th>
                                <th>Question</th>
                                <th>Strongly Agree</th>
                                <th>Agree</th>
                                <th>Neutral</th>
                                <th>Disagree</th>
                                <th>Strongly Disagree</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $srnumber = 1;
                            foreach ($feedbackQuestionsArray as $qid => $qname) {
                                if (isset($summaryLikert[$qname])) {
                                    echo "<tr>";
                                    echo "<td>" . $srnumber . "</td>";
                                    echo "<td> $qname </td>";
                                    echo "<td>" . $summaryLikert[$qname]['Strongly Agree'] . "</td>";
                                    echo "<td>" . $summaryLikert[$qname]['Agree'] . "</td>";
                                    echo "<td>" . $summaryLikert[$qname]['Neutral'] . "</td>";
                                    echo "<td>" . $summaryLikert[$qname]['Disagree'] . "</td>";
                                    echo "<td>" . $summaryLikert[$qname]['Strongly Disagree'] . "</td>";
                                    echo "</tr>";

                                    $srnumber++;
                                }

                            }
                            ?>
                        </tbody>
                    </table>
                <?php } ?>

                <!-- Yes/No Table -->
                <?php if (!empty($summaryYesNo)) { ?>
                    <br>
                    <h3 style='text-align:center;'>Yes / No Questions</h3>
                    <br>
                    <center>
                        <table border='1' id="summaryYesNoTable">
                            <thead>
                                <tr>
                                    <th>Sr No</th>
                                    <th>Question</th>
                                    <th>Yes</th>
                                    <th>No</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $srnumberForYesNo = 1;
                                foreach ($feedbackQuestionsArray as $qid => $qname) {
                                    if (isset($summaryYesNo[$qname])) {
                                        echo "<tr>";
                                        echo "<td>" . $srnumberForYesNo . "</td>";
                                        echo "<td> $qname </td>";
                                        echo "<td>" . $summaryYesNo[$qname]['Yes'] . "</td>";
                                        echo "<td>" . $summaryYesNo[$qname]['No'] . "</td>";
                                        echo "</tr>";

                                        $srnumberForYesNo++;
                                    }

                                }
                                ?>
                            </tbody>
                        </table>
                    </center>
                <?php } ?>

            </div>

        <?php } else { ?>



            <div class="data">
                <table border='1' id="myTable">
                    <thead>
                        <tr>
                            <th>Course Name</th>
                            <th>Student Name</th>
                            <?php
                            foreach ($feedbackQuestionsArray as $questionId => $questionName) {
                                echo "<th>" . $questionName . "</th>";
                            }
                            ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        foreach ($courseFaculty as $courseId => $enrolledFaculty) {
                            $courseName = $conn->query("SELECT fullname from {$prefix}course where id = $courseId")->fetch_assoc()['fullname'];
                            echo "<tr>";
                            echo "<td rowspan='" . count($enrolledFaculty) . "' id='value'>" . $courseName . "</td>";
                            $check = false;
                            $totalRows = count($enrolledFaculty);
                            foreach ($enrolledFaculty as $facultyId => $curriculumId) {

                                if ($check) {
                                    echo "<tr>";
                                }
                                $facultyName = $conn->query("SELECT concat(firstname, ' ', lastname) as fullName from {$prefix}user where id = $facultyId")->fetch_assoc()['fullName'];
                                echo "<td id='value'>" . $facultyName . "</td>";

                                $responses = $conn->query("SELECT fi.id as questionId, fi.name as question, fv.value from {$prefix}feedback_item fi
                                                    join {$prefix}feedback_value fv on fv.item = fi.id
                                                    join {$prefix}feedback_completed fc on fc.id = fv.completed
                                                    where fc.feedback = $curriculumId and fc.userid=$facultyId");

                                if ($responses->num_rows > 0) {
                                    while ($answer = mysqli_fetch_assoc($responses)) {
                                        switch ($answer['value']) {
                                            case "1":
                                                echo "<td id='value'>" . "Strongly Agree" . "</td>";
                                                break;
                                            case "2":
                                                echo "<td id='value'>" . "Agree" . "</td>";
                                                break;
                                            case "3":
                                                echo "<td id='value'>" . "Neutral" . "</td>";
                                                break;
                                            case "4":
                                                echo "<td id='value'>" . "Disagree" . "</td>";
                                                break;
                                            case "5":
                                                echo "<td id='value'>" . "Strongly Disagree" . "</td>";
                                                break;
                                            default:
                                                echo "<td id='value'>" . $answer['value'] . "</td>";
                                                break;
                                        }
                                    }
                                } else {
                                    for ($i = 0; $i < count($feedbackQuestionsArray); $i++) {
                                        echo "<td id='value'> - </td>";
                                    }
                                }

                                if ($totalRows > 1) {
                                    echo "</tr>";
                                }
                                $check = true;
                                $totalRows--;

                            }
                            echo "</tr>";
                        }

                        ?>
                    </tbody>
                </table>
            </div>
        <?php } ?>





    </div>
    <script>
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

            const tableContent = matrix
                .map(row => row.map(cell => cell || "").join("\t"))
                .join("\n");

            const downloadFilename = filename ? `${filename}.xls` : "export.xls";

            const downloadLink = document.createElement("a");
            document.body.appendChild(downloadLink);

            const blob = new Blob([tableContent], { type: "application/vnd.ms-excel" });
            downloadLink.href = URL.createObjectURL(blob);
            downloadLink.download = downloadFilename;
            downloadLink.click();

            document.body.removeChild(downloadLink);
        }

        document.getElementById('type').addEventListener("change", function () {
            const type = this.value;
            console.log(type);

            document.getElementById('reportTypeForm').submit();
        });

    </script>
</body>

</html>