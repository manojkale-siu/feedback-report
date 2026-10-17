<?php

require_once('db.php');
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $initialData = isset($_POST['initialData']) ? json_decode($_POST['initialData'], true) : '';
    $programname = isset($_POST['programLabel']) ? $_POST['programLabel'] : ($initialData["programLabel"] ?? '');
    $programId = isset($_POST['programSelect']) ? $_POST['programSelect'] : ($initialData["programSelect"] ?? '');
    $batchname = isset($_POST['batchLabel']) ? $_POST['batchLabel'] : ($initialData["batchLabel"] ?? '');
    $batchId = isset($_POST['batchSelect']) ? $_POST['batchSelect'] : ($initialData["batchSelect"] ?? '');
    $semestername = isset($_POST['semLabel']) ? $_POST['semLabel'] : ($initialData["semLabel"] ?? '');
    $semesterId = isset($_POST['semesterSelect']) ? $_POST['semesterSelect'] : ($initialData["semesterSelect"] ?? '');
    $roleId = isset($_POST['role']) ? $_POST['role'] : ($initialData["role"] ?? '');
    $serializedPrograms = isset($_POST['serializedPrograms']) ? $_POST['serializedPrograms'] : ($initialData["serializedPrograms"] ?? '');
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

// collecting course ids from $sessions 
$cidArray = array();
if (is_array($sessions)) {
    // sessions might be either an array of session objects or nested - handle both cases:
    // if sessions is numeric-indexed containing course objects with key 'courseid'
    if (!empty($sessions) && isset($sessions[0]) && is_array($sessions[0]) && isset($sessions[0]['courseid'])) {
        $cidArray = array_column($sessions, 'courseid');
    } else {
        // fallback: attempt to find 'courseid' recursively
        foreach ($sessions as $s) {
            if (is_array($s) && isset($s['courseid'])) {
                $cidArray[] = $s['courseid'];
            } elseif (is_array($s)) {
                // nested map (program->batch->semester structure)
                foreach ($s as $v1) {
                    if (is_array($v1)) {
                        foreach ($v1 as $v2) {
                            if (is_array($v2) && isset($v2['courseid'])) {
                                $cidArray[] = $v2['courseid'];
                            } elseif (is_array($v2)) {
                                foreach ($v2 as $v3) {
                                    if (is_array($v3) && isset($v3['courseid'])) {
                                        $cidArray[] = $v3['courseid'];
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}
$cidArray = array_filter($cidArray);
$cidArray = array_unique($cidArray);
$cid = implode(',', $cidArray);
if (empty($cid)) {
    $cid = '0';
}

if($debugging) {
    
    echo "<pre> Printing Course Ids. <br>";
    print_r($cid);
    echo "<br> </pre>";
}

$_SESSION['courseId'] = $cid;

// $programname  = $_SESSION['initialData']['programSelect']  ?? $_POST['programname_hidden'];
// $batchname    = $_SESSION['initialData']['batchSelect']    ?? $_POST['batchname_hidden'];
// $semestername = $_SESSION['initialData']['semesterSelect'] ?? $_POST['semestername_hidden'];

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


$type = '';
// if (isset($_POST['type'])) {
//     $type = $_POST['type'];
// }

$type = isset($_POST['type']) ? $_POST['type'] : 'analysis';

/* -------------------------
   ANALYSIS 
   ------------------------- */
if($type == 'analysis') {

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
        ORDER BY cc.id, f.id, u.id; ");
        
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
    
    if($debugging) {
        echo "<hr><pre>Query Analysis part : ";
        echo "SELECT 
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
        ORDER BY cc.id, f.id, u.id; <br>";
        
        echo "<br>Query result (data) Analysis part : ";
        print_r($data);
        echo "</pre><br><br><br>";
        
        
    }
}

/* -------------------------
   RESPONSES TYPE
   ------------------------- */
if ($type == 'responses') {

    // 1) load feedback template questions (same as your old file)
    $feedbackQuestionsArray = [];

    $feedbackTemplate = $conn->query("SELECT id from {$prefix}course where fullname = 'Feedback Templates' or fullname = 'Feedback Template'");
    if ($feedbackTemplate->num_rows > 0) {
        $feedbackTemplateId = $feedbackTemplate->fetch_assoc()['id'];
        $feedbackIdQ = $conn->query("SELECT id from {$prefix}feedback where course=$feedbackTemplateId and name LIKE '%Student Feedback - Curriculum%' ");
        if ($feedbackIdQ->num_rows > 0) {
            $feedbackIdVal = $feedbackIdQ->fetch_assoc()['id'];
            $feedbackQuestions = $conn->query("SELECT id, name from {$prefix}feedback_item where feedback = $feedbackIdVal  ORDER BY 
                                                    CAST( SUBSTRING_INDEX(name, '.', 1) AS UNSIGNED) ASC;");
            if ($feedbackQuestions->num_rows > 0) {
                while ($question = mysqli_fetch_assoc($feedbackQuestions)) {
                    $feedbackQuestionsArray[$question['id']] = $question['name'];
                }
            }
        } else {
            // If template not found, continue but responses table will be empty columns
            // echo "Student Feedback template not found.";
        }
    } else {
        // echo "Feedback Template Course Not Found";
    }
    
    if($debugging) {
        echo "<pre><hr> Printing feedback Questions : <br>";
        print_r($feedbackQuestions);
        echo "<br> </pre>";
    }
    

    // 2) Recreate the student feedback availability mapping
    $studentAccessibleFeedback = [];
    $studentFeedbackList = [];
    $facultyFeedbackList = [];
    $studentEnrolledToCoursesList = [];

    // Fetch faculty enrolments to build faculty -> course -> group availability
    $facultyEnrolledCoursesQuery = $conn->query("SELECT concat(u.firstname, ' ', u.lastname) as fullName, ra.roleid, ra.userid as userid, cc.id as CourseId 
                                from {$prefix}role_assignments ra 
                                join {$prefix}context con on con.id = ra.contextid 
                                join {$prefix}course cc on cc.id = con.instanceid 
                                join {$prefix}user u on u.id = ra.userid 
                                where con.contextlevel = 50 and ra.roleid=3 and cc.id in ($cid) order by cc.id");

    $facultyWithGroupDetails = [];
    $groupsInCourse = [];

    // build groups in course
    $groups = $conn->query("SELECT g.id as groupid, g.name as groupName, g.courseid as CourseId
                                    from {$prefix}groups g 
                                    join {$prefix}groups_members gm on gm.groupid = g.id 
                                    where g.courseid in ($cid) group by g.courseid, g.id");

    while($grp = mysqli_fetch_assoc($groups)) {
        $groupsInCourse[$grp['CourseId']][] = $grp['groupName'];
    }
    
    if($debugging) {
        echo "<pre><hr> Printing groups in Course : <br>";
        print_r($groupsInCourse);
        echo "<br> </pre>";
    }

    while($facultyDetails = mysqli_fetch_assoc($facultyEnrolledCoursesQuery)) {

        $isGroupPresentInCourse = $conn->query("SELECT id from {$prefix}groups where courseid = {$facultyDetails['CourseId']}");

        if($isGroupPresentInCourse -> num_rows > 0) {
            $facultyAssignedGroups = $conn->query("SELECT g.id as groupid, g.name as groupName 
                                    from {$prefix}groups g 
                                    join {$prefix}groups_members gm on gm.groupid = g.id 
                                    where g.courseid={$facultyDetails['CourseId']} and gm.userid={$facultyDetails['userid']}");

            if($facultyAssignedGroups -> num_rows > 0)  {
                while($facultyGroupDetails = mysqli_fetch_assoc($facultyAssignedGroups)) {
                    $facultyWithGroupDetails[$facultyDetails['CourseId']][$facultyDetails['fullName']][$facultyGroupDetails['groupName']] = $facultyGroupDetails['groupName'];
                }
            } else{
                $facultyWithGroupDetails[$facultyDetails['CourseId']][$facultyDetails['fullName']] = "NGA";
            }

        } else{
            $facultyWithGroupDetails[$facultyDetails['CourseId']][$facultyDetails['fullName']] = "CDNG";
        }

    }
    
    
    if($debugging) {
        echo "<pre><hr> Printing faculty With Group Details : <br>";
        print_r($facultyWithGroupDetails);
        echo "<br> </pre>";
    }
    
    

    // Build student enrolled course list
    $studentEnrolledCoursesQuery = $conn->query("SELECT ra.roleid, ra.userid as userid, cc.id as CourseId from {$prefix}role_assignments ra 
                                    join {$prefix}context con on con.id = ra.contextid
                                    join {$prefix}course cc on cc.id = con.instanceid
                                    where con.contextlevel = 50 and ra.roleid = 5 and cc.id in ($cid) order by cc.id");

    while($userRow = mysqli_fetch_assoc($studentEnrolledCoursesQuery)) {
        $studentEnrolledToCoursesList[$userRow['userid']][$userRow['CourseId']] = $userRow['CourseId'];
    }

    // Helper: feedback module id
    $feedbackModuleid = $conn -> query("SELECT id from {$prefix}modules where name='feedback'");
    $feedbackModuleid = $feedbackModuleid -> fetch_assoc()['id'];

    // iterate per student and course to build accessibility mapping
    foreach($studentEnrolledToCoursesList as $studentId => $enrolledCourseList) {
        foreach($enrolledCourseList as $courseId) {

            // if course has faculty mapping
            if(isset($facultyWithGroupDetails[$courseId])) {
                foreach($facultyWithGroupDetails[$courseId] as $facultyName => $facultyGroupAvailability) {

                    if(is_array($facultyGroupAvailability)) {
                        // student groups in this course
                        $studentEnrolledGroup = $conn->query("SELECT g.name as GroupName from {$prefix}groups g
                                        join {$prefix}groups_members gm on gm.groupid = g.id 
                                        where g.courseid = $courseId and gm.userid = $studentId");

                        if($studentEnrolledGroup -> num_rows > 0) {
                            $studentGroup = [];
                            while($group = mysqli_fetch_assoc($studentEnrolledGroup)) {
                                $studentGroup[] = $group['GroupName'];

                                $isFeedbackInCourse = $conn->query("SELECT f.id as id, f.name as feedbackName from {$prefix}feedback f
                                            join {$prefix}course_modules cm on cm.instance=f.id
                                            where (f.name like '%Student Feedback - Curriculum%' and f.name like '%{$group['GroupName']}%' and f.name like '%$facultyName%') and f.course=$courseId and cm.module=$feedbackModuleid and cm.deletioninprogress=0");

                                if($isFeedbackInCourse -> num_rows > 0) {
                                    while($feedback = mysqli_fetch_assoc($isFeedbackInCourse)) {
                                        $isStudentSubmittedFeedback = $conn->query("SELECT id from {$prefix}feedback_completed where userid = $studentId and feedback={$feedback['id']}");
                                        if($isStudentSubmittedFeedback -> num_rows > 0) {
                                            $studentAccessibleFeedback[$studentId][$courseId][$feedback['id']] = 1;
                                        } else {
                                            $studentAccessibleFeedback[$studentId][$courseId][$feedback['id']] = 0;
                                        }
                                    }
                                }
                            }

                            // For groups that student is NOT in -> mark NA for those group-specific feedbacks
                            if(isset($groupsInCourse[$courseId])) {
                                foreach ($groupsInCourse[$courseId] as $groupName) {
                                    if (!in_array($groupName, $studentGroup)) {
                                        $isFeedbackInCourse = $conn->query("
                                            SELECT f.id as id, f.name as feedbackName 
                                            FROM {$prefix}feedback f
                                            JOIN {$prefix}course_modules cm ON cm.instance = f.id
                                            WHERE f.name LIKE '%Student Feedback - Curriculum%'
                                              AND f.name LIKE '%$groupName%'
                                              AND f.name LIKE '%$facultyName%'
                                              AND f.course = $courseId
                                              AND cm.module = $feedbackModuleid
                                              AND cm.deletioninprogress = 0
                                              GROUP BY f.id;
                                        ");

                                        if ($isFeedbackInCourse->num_rows > 0) {
                                            while($row = $isFeedbackInCourse->fetch_assoc()) {
                                                $feedbackId = $row['id'];
                                                if (isset($feedbackId) && !isset($studentAccessibleFeedback[$studentId][$courseId][$feedbackId])) {
                                                    $studentAccessibleFeedback[$studentId][$courseId][$feedbackId] = 'NA';
                                                }
                                            }
                                        }
                                    }
                                }
                            }

                            // Also add curriculum feedback ids (course level) either 1/0/NA depending on group membership and submitted
                            $curriculumFeedbacks = $conn->query("SELECT f.id, f.name as feedbackName from {$prefix}feedback f
                                    join {$prefix}course_modules cm on cm.instance=f.id
                                    where f.course=$courseId and cm.module=$feedbackModuleid and cm.deletioninprogress=0");

                            while($feedback = mysqli_fetch_assoc($curriculumFeedbacks)) {
                                $isStudentSubmittedFeedback = $conn->query("SELECT id from {$prefix}feedback_completed where userid = $studentId and feedback={$feedback['id']}");

                                if(!isset($studentAccessibleFeedback[$studentId][$courseId][$feedback['id']])) {

                                    if($isStudentSubmittedFeedback -> num_rows > 0)  {
                                        $studentAccessibleFeedback[$studentId][$courseId][$feedback['id']] = 1;
                                    } else{
                                        // if groupsInCourse exists and student isn't part of group and feedback name indicates group-specific -> NA else 0
                                        if(isset($groupsInCourse[$courseId])) {
                                            $markedNA = false;
                                            foreach($groupsInCourse[$courseId] as $groupName) {
                                                if(!in_array($groupName, $studentGroup ?? [])) {
                                                    $formattedFeedbackName = strtolower(str_replace(' ', '', $feedback['feedbackName']));
                                                    $formattedGroupName = strtolower(str_replace(' ', '', $groupName));

                                                    if((strpos($formattedFeedbackName, "studentfeedback-curriculum") !== false) && (strpos($formattedFeedbackName, $formattedGroupName) !== false)) {
                                                        if(!isset($studentAccessibleFeedback[$studentId][$courseId][$feedback['id']])) {
                                                            $studentAccessibleFeedback[$studentId][$courseId][$feedback['id']] = 'NA';
                                                            $markedNA = true;
                                                        }
                                                    }
                                                }
                                            }
                                            if(!$markedNA && !isset($studentAccessibleFeedback[$studentId][$courseId][$feedback['id']])) {
                                                $studentAccessibleFeedback[$studentId][$courseId][$feedback['id']] = 0;
                                            }
                                        } else {
                                            // no groups in course
                                            if(!isset($studentAccessibleFeedback[$studentId][$courseId][$feedback['id']])) {
                                                $studentAccessibleFeedback[$studentId][$courseId][$feedback['id']] = 0;
                                            }
                                        }
                                    }
                                }
                            }

                        } else {
                            // student is not assigned to any group in course
                            $studentAccessibleFeedback[$studentId][$courseId] = "Student is Not Assigned in a Group for Course";
                        }

                    } else if($facultyGroupAvailability == 'NGA'){
                        // faculty not assigned to a group (NGA)
                        $isFeedbackInCourse = $conn->query("SELECT f.id as id, f.name from {$prefix}feedback f
                                            join {$prefix}course_modules cm on cm.instance=f.id
                                            where f.name like '%Student Feedback - Curriculum%' and f.name like '%$facultyName%' and f.course=$courseId and cm.module=$feedbackModuleid and cm.deletioninprogress=0");
                        if($isFeedbackInCourse -> num_rows > 0) {
                            while($feedback = mysqli_fetch_assoc($isFeedbackInCourse)) {
                                $isStudentSubmittedFeedback = $conn->query("SELECT id from {$prefix}feedback_completed where userid = $studentId and feedback={$feedback['id']}");
                                if($isStudentSubmittedFeedback -> num_rows > 0)  {
                                    $studentAccessibleFeedback[$studentId][$courseId][$feedback['id']] = 1;
                                } else{
                                    $studentAccessibleFeedback[$studentId][$courseId][$feedback['id']] = 0;
                                }
                            }
                        }
                    } else {
                        // CDNG or generic faculty
                        $isFeedbackInCourse = $conn->query("SELECT f.id as id, f.name from {$prefix}feedback f
                                            join {$prefix}course_modules cm on cm.instance=f.id
                                            where f.name like '%Student Feedback - Curriculum%' and f.name like '%$facultyName%' and f.course=$courseId and cm.module=$feedbackModuleid and cm.deletioninprogress=0");

                        if($isFeedbackInCourse -> num_rows > 0) {
                            while($feedback = mysqli_fetch_assoc($isFeedbackInCourse)) {
                                $isStudentSubmittedFeedback = $conn->query("SELECT id from {$prefix}feedback_completed where userid = $studentId and feedback={$feedback['id']}");
                                if($isStudentSubmittedFeedback -> num_rows > 0)  {
                                    $studentAccessibleFeedback[$studentId][$courseId][$feedback['id']] = 1;
                                } else{
                                    $studentAccessibleFeedback[$studentId][$courseId][$feedback['id']] = 0;
                                }
                            }
                        }
                    }

                    // At this point faculty-specifics processed for this faculty
                } // foreach faculty in course
            } // if facultyWithGroupDetails exists

            // Finally ensure any course-level (curriculum) feedbacks recorded
            $curriculumFeedbacks = $conn->query("SELECT f.id, f.name from {$prefix}feedback f
                            join {$prefix}course_modules cm on cm.instance=f.id
                            where f.course=$courseId and cm.module=$feedbackModuleid and cm.deletioninprogress=0");

            while($feedback = mysqli_fetch_assoc($curriculumFeedbacks)) {
                $isStudentSubmittedFeedback = $conn->query("SELECT id from {$prefix}feedback_completed where userid = $studentId and feedback={$feedback['id']}");
                if(!isset($studentAccessibleFeedback[$studentId][$courseId][$feedback['id']])) {
                    if($isStudentSubmittedFeedback -> num_rows > 0)  {
                        $studentAccessibleFeedback[$studentId][$courseId][$feedback['id']] = 1;
                    } else{
                        $studentAccessibleFeedback[$studentId][$courseId][$feedback['id']] = 0;
                    }
                }
            }

            // if still nothing was set for this student-course pair, mark FNA
            if(!isset($studentAccessibleFeedback[$studentId][$courseId]) || empty($studentAccessibleFeedback[$studentId][$courseId])) {
                // If something was explicitly set above as string statuses then keep that; else mark FNA
                if(!isset($studentAccessibleFeedback[$studentId][$courseId])) {
                    $studentAccessibleFeedback[$studentId][$courseId] = 'FNA';
                }
            }

        } // foreach course
    } // foreach student

    // Build studentFeedbackList and facultyFeedbackList per course for header usage
    foreach($facultyWithGroupDetails as $courseid => $facultyDetails) {
        $check = false;
        // For Student Feedback Faculty
        foreach($facultyDetails as $facultyName => $groups) {
            $feedbackInCourse = $conn->query("SELECT f.id, f.name from {$prefix}feedback f
                                        join {$prefix}course_modules cm on cm.instance=f.id
                                        where f.course=$courseid and f.name like '%$facultyName%'and cm.module=$feedbackModuleid and cm.deletioninprogress=0 order by f.id");

            while($feedback = mysqli_fetch_assoc($feedbackInCourse)) {
                $studentFeedbackList[$courseid][$feedback['id']] = $feedback['name'];
                $check = true;
            }
        }

        // For Curriculum Feedbacks of student and Faculty
        $curriculumFeedbacks = $conn->query("SELECT f.id, f.name from {$prefix}feedback f
                                        join {$prefix}course_modules cm on cm.instance=f.id
                                        where f.course=$courseid and cm.module=$feedbackModuleid and cm.deletioninprogress=0 order by f.id");

        if($curriculumFeedbacks -> num_rows > 0) {
            while($feedback = mysqli_fetch_assoc($curriculumFeedbacks)) {
                if(!isset($studentFeedbackList[$courseid][$feedback['id']])) {
                    if (strpos($feedback['name'], 'Student Feedback - Curriculum') === false) {
                        $studentFeedbackList[$courseid][$feedback['id']] = $feedback['name'];
                    } else{
                        $studentFeedbackList[$courseid][$feedback['id']][] = $feedback['name'];
                        $facultyFeedbackList[$courseid][$feedback['id']] = $feedback['name'];
                    }
                    $check = true;
                }
            }
        }

        if(!$check) {
            $studentFeedbackList[$courseid] = "Feedback Not Available";
        }
    }

    // sort keys for consistent display
    ksort($studentFeedbackList);
    foreach ($studentFeedbackList as &$subArray) {
        if (is_array($subArray)) ksort($subArray);
    }
    function sortKeysRecursively(&$array) {
        if (is_array($array)) {
            ksort($array);
            foreach ($array as &$value) {
                if (is_array($value)) {
                    sortKeysRecursively($value);
                }
            }
        }
    }
    sortKeysRecursively($studentAccessibleFeedback);
    
    if($debugging) {
        echo "<hr><pre> Printing Student Accessible Feedback : <br>";
        print_r($studentAccessibleFeedback);
        echo "<br> </pre>";
    }

} 

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
            <form method="post" id='reportTypeForm'>
                <select name="type" id="type">
                    <option value="consolidated" <?php if($type=="consolidated") echo "selected"; ?>>Consolidated Report</option>
                    <option value="responses" target="_blank" <?php if($type=="responses") echo "selected"; ?>>Responses Report</option>
                </select>

                <input type="hidden" name="initialData"
                    value="<?php echo htmlspecialchars(json_encode($initialData)); ?>">
                
                <input type="hidden" name="programname_hidden" value="<?php echo($programname); ?>">
                <input type="hidden" name="batchname_hidden" value="<?php echo($batchname); ?>">
                <input type="hidden" name="semestername_hidden" value="<?php echo($semestername); ?>">
                
            </form>

            <div class="headings">
                <h1><?php echo htmlspecialchars($programname); ?></h1>
                <h1><?php echo htmlspecialchars($batchname); ?></h1>
                <h1><?php echo htmlspecialchars($semestername); ?></h1>
            </div>

            
            
            <?php 
                if($type == 'responses') {
            ?>
                    <button onclick="exportToExcel(['myTable', 'summaryLikertTable', 'summaryYesNoTable'], 'Feedback_Report')">Download Excel</button>
            <?php
                } else {
            ?>
                    <button onclick="exportTableToExcel('myTable', 'Feedback Report')">Download Excel</button>
            <?php
                }
            ?>
            
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
                                echo "<th>" . htmlspecialchars($questionName) . "</th>";
                            }
                            ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $srno = 1;

                        // Loop students with accessible feedback
                        foreach ($studentAccessibleFeedback as $studentId => $courseDetails) {
                            $studentDetails = $conn->query("SELECT username, concat(firstname, ' ', lastname) as fullname 
                                                    FROM {$prefix}user 
                                                    WHERE id = $studentId")->fetch_assoc();

                            $courseCount = is_array($courseDetails) ? count($courseDetails) : 0;  // How many courses this student has
                            if ($courseCount <= 0) continue;
                            $firstCourse = true;

                            foreach ($courseDetails as $courseId => $feedbackList) {
                                $courseName = $conn->query("SELECT fullname 
                                                    FROM {$prefix}course 
                                                    WHERE id = $courseId")->fetch_assoc()['fullname'];

                                echo "<tr>";

                                if ($firstCourse) {
                                    echo "<td rowspan='$courseCount' id='value'>" . $srno . "</td>";
                                    echo "<td rowspan='$courseCount' id='value'>" . htmlspecialchars($studentDetails['username']) . " - " . htmlspecialchars($studentDetails['fullname']) . "</td>";
                                    $firstCourse = false;
                                }

                                echo "<td id='value'>" . htmlspecialchars($courseName) . "</td>";

                                // For safety: check if feedbackList is an array
                                $responses = false;
                                if (is_array($feedbackList) && !empty($feedbackList)) {

                                    $feedbackIdsArr = array_keys($feedbackList);
                                    // keep only numeric ids
                                    $feedbackIdsArr = array_filter($feedbackIdsArr, function($v){
                                        return is_numeric($v);
                                    });
                                    if (!empty($feedbackIdsArr)) {
                                        $feedbackIds = implode(',', $feedbackIdsArr);

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
                                }

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
                                        echo "<td id='value'>" . htmlspecialchars($answers[$qname] ?? "-") . "</td>";  // Match by text
                                    }

                                } else {
                                    // No responses for this student+course -> print blanks for each question
                                    foreach ($feedbackQuestionsArray as $qid => $qname) {
                                        echo "<td id='value'>-</td>";
                                    }
                                }

                                echo "</tr>";
                            }
                            $srno++;
                        }
                        ?>
                    </tbody>
                </table>
            </div>

            <!-- Summary section: unchanged (you already have it below) -->
            <div class="data" style="margin-top:30px;">
                <h2 style='text-align:center;'>Summary of Student Responses</h2>

                <?php
                // Collect all feedback IDs from $studentAccessibleFeedback (student -> courses -> feedback list)
                $allFeedbackIds = [];
                foreach ($studentAccessibleFeedback as $studentId => $courseDetails) {
                    if (!is_array($courseDetails)) continue;
                    foreach ($courseDetails as $courseId => $feedbackList) {
                        if (is_array($feedbackList)) {
                            $allFeedbackIds = array_merge($allFeedbackIds, array_keys($feedbackList));
                        }
                    }
                }
                $allFeedbackIds = array_unique(array_filter($allFeedbackIds, 'is_numeric'));

                if (!empty($allFeedbackIds)) {
                    $allResponses = $conn->query("
                        SELECT fi.id as questionId, fi.name as question, fv.value, fi.typ
                        FROM {$prefix}feedback_item fi
                        JOIN {$prefix}feedback_value fv ON fv.item = fi.id
                        JOIN {$prefix}feedback_completed fc ON fc.id = fv.completed
                        JOIN {$prefix}feedback f ON f.id = fc.feedback AND f.name LIKE '%Student Feedback%Curriculum%'
                        WHERE fc.feedback IN (" . implode(',', $allFeedbackIds) . ")
                        ORDER BY CAST(SUBSTRING_INDEX(fi.name, '.', 1) AS UNSIGNED) ASC
                    ");
                } else {
                    $allResponses = false;
                }

                $summaryLikert = [];
                $summaryYesNo = [];

                if ($allResponses) {
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
                }
                ?>

                <!-- Likert Scale Table -->
                <?php if (!empty($summaryLikert)) { ?>
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
                                    echo "<td> " . htmlspecialchars($qname) . " </td>";
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
                                        echo "<td> " . htmlspecialchars($qname) . " </td>";
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

            <!-- consolidated (fallback) content kept unchanged -->
            <div class="data">
                <table border='1'>
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
                        // placeholder for previous consolidated/faculty style display - keep as before
                        echo "<tr><td colspan='10'>Select a report type</td></tr>";
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
        
        function exportToExcel(tableIDs, filename = '') {
            if (!Array.isArray(tableIDs)) tableIDs = [tableIDs]; // support single or multiple tables
            
            let combinedHTML = '';
            let foundAny = false;
        
            tableIDs.forEach(id => {
                const table = document.getElementById(id);
                if (table) {
                    foundAny = true;
                    const clonedTable = table.cloneNode(true);
        
                    // Remove unwanted elements (buttons, links)
                    clonedTable.querySelectorAll('button, a').forEach(el => el.remove());
        
                    // Add a small gap between tables in Excel
                    combinedHTML += `<h3 style="margin-top:20px;">${table.getAttribute('data-title') || id}</h3>`;
                    combinedHTML += clonedTable.outerHTML + '<br><br>';
                }
            });
        
            if (!foundAny) {
                alert("No tables found to export!");
                return;
            }
        
            const html = `
                <html xmlns:x="urn:schemas-microsoft-com:office:excel">
                <head>
                    <meta charset="UTF-8">
                    <xml>
                        <x:ExcelWorkbook>
                            <x:ExcelWorksheets>
                                <x:ExcelWorksheet>
                                    <x:Name>${filename || 'Sheet1'}</x:Name>
                                    <x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions>
                                </x:ExcelWorksheet>
                            </x:ExcelWorksheets>
                        </x:ExcelWorkbook>
                    </xml>
                </head>
                <body>
                    ${combinedHTML}
                </body>
                </html>
            `;
        
            const blob = new Blob([html], { type: "application/vnd.ms-excel" });
            const url = URL.createObjectURL(blob);
            const a = document.createElement("a");
            a.href = url;
            a.download = (filename ? filename : 'Feedback_Report') + ".xls";
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        }

        document.getElementById('type').addEventListener("change", function () {
            const type = this.value;
            document.getElementById('reportTypeForm').submit();
        });

    </script>
</body>
</html>
