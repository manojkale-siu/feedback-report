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


$type = isset($_POST['type']) ? $_POST['type'] : 'analysis';

/* -------------------------
   ANALYSIS
   ------------------------- */
$courseNamesMap = []; // courseId -> course fullname (used in analysis table header)

if ($type == 'analysis') {

    // Sanitize course IDs (works for "3011" or "3011,3012")
    $courseIds = implode(',', array_filter(array_map('intval', explode(',', $_SESSION['courseId'] ?? ''))));
    if ($courseIds === '') {
        $courseIds = '0';
    }

    // NA = activity is restricted to group(s) the student is not in
    // 0  = student can access but has not submitted
    // 1  = student has submitted
    $analysisSql = <<<SQL
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
        WHEN cm.availability LIKE '%"type":"group"%'
         AND NOT EXISTS (
                SELECT 1
                FROM {$prefix}groups_members gm
                JOIN {$prefix}groups g ON g.id = gm.groupid AND g.courseid = cc.id
                WHERE gm.userid = u.id
                  AND cm.availability LIKE CONCAT('%"type":"group","id":', gm.groupid, '}%')
             )
        THEN 'NA'
        WHEN EXISTS (
                SELECT 1 FROM {$prefix}feedback_completed fc
                WHERE fc.feedback = f.id AND fc.userid = u.id
             )
        THEN '1'
        ELSE '0'
    END AS feedbackcompleted
FROM {$prefix}course cc
JOIN {$prefix}feedback f        ON f.course = cc.id
JOIN {$prefix}modules m         ON m.name = 'feedback'
JOIN {$prefix}course_modules cm ON cm.instance = f.id
                               AND cm.module = m.id
                               AND cm.course = cc.id
                               AND cm.deletioninprogress = 0
JOIN {$prefix}context con       ON con.instanceid = cc.id AND con.contextlevel = 50
JOIN (SELECT DISTINCT contextid, userid
      FROM {$prefix}role_assignments
      WHERE roleid = 5) ra      ON ra.contextid = con.id
JOIN {$prefix}user u            ON u.id = ra.userid
                               AND u.deleted = 0
                               AND u.suspended = 0
WHERE cc.id IN ({$courseIds})
  AND f.name NOT LIKE '%Faculty%Feedback%Curriculum%'
  AND EXISTS (
        SELECT 1
        FROM {$prefix}user_enrolments ue
        JOIN {$prefix}enrol e ON e.id = ue.enrolid
        WHERE e.courseid = cc.id AND ue.userid = u.id
          AND ue.status = 0 AND e.status = 0
  )
ORDER BY cc.id, f.id, u.id
SQL;

    $programs = $conn->query($analysisSql);
    if ($programs === false) {
        die('Analysis query failed: ' . htmlspecialchars($conn->error));
    }
    $data = $programs->fetch_all(MYSQLI_ASSOC);

    // Build pivot arrays
    $studentFeedbackList = [];        // course -> feedbackId -> feedback name
    $studentAccessibleFeedback = [];  // student -> course -> feedbackId -> status

    foreach ($data as $row) {
        $userid       = $row['userid'];
        $courseid     = $row['courseid'];
        $feedbackid   = $row['feedbackid'];
        $feedbackname = $row['feedbackname'];
        $completed    = $row['feedbackcompleted'];

        // course names for the header
        $courseNamesMap[$courseid] = $row['coursename'];

        // course -> feedback list (table header)
        $studentFeedbackList[$courseid][$feedbackid] = $feedbackname;

        // student -> course -> feedback status
        $studentAccessibleFeedback[$userid][$courseid][$feedbackid] = $completed;
    }

    if ($debugging) {
        echo "<hr><pre>Query Analysis part : ";
        echo htmlspecialchars($analysisSql);
        echo "<br>Query result (data) Analysis part : ";
        print_r($data);
        echo "</pre><br><br><br>";
    }
}

if ($type == 'responses') {

    // -------------------------------------------------------
    // 0) Basic validation & sanitize $cid (avoid SQL errors)
    // -------------------------------------------------------
    if (empty($cid)) {
        echo "<div style='color:red;'>No course selected.</div>";
        return;
    }
    // sanitize comma separated course ids
    $cid_list = array_filter(array_map('intval', explode(',', $cid)));
    if (empty($cid_list)) {
        echo "<div style='color:red;'>Invalid course ids.</div>";
        return;
    }
    $cid_in = implode(',', $cid_list);

    // -------------------------------------------------------
    // 1) load feedback template questions (same as your old file)
    // -------------------------------------------------------
    $feedbackQuestionsArray = [];

    $feedbackTemplate = $conn->query("SELECT id FROM {$prefix}course WHERE fullname = 'Feedback Templates' OR fullname = 'Feedback Template'");
    if ($feedbackTemplate && $feedbackTemplate->num_rows > 0) {
        $feedbackTemplateId = (int)$feedbackTemplate->fetch_assoc()['id'];
        $feedbackIdQ = $conn->query("SELECT id FROM {$prefix}feedback WHERE course={$feedbackTemplateId} AND name LIKE '%Student Feedback- Faculty%'");
        if ($feedbackIdQ && $feedbackIdQ->num_rows > 0) {
            $feedbackIdVal = (int)$feedbackIdQ->fetch_assoc()['id'];
            $feedbackQuestions = $conn->query("
                SELECT id, name
                FROM {$prefix}feedback_item
                WHERE feedback = {$feedbackIdVal}
                ORDER BY CAST(SUBSTRING_INDEX(name, '.', 1) AS UNSIGNED) ASC
            ");
            if ($feedbackQuestions && $feedbackQuestions->num_rows > 0) {
                while ($question = mysqli_fetch_assoc($feedbackQuestions)) {
                    $feedbackQuestionsArray[$question['id']] = $question['name'];
                }
            }
        } else {
            // Student Feedback template not found — continue, UI will show empty columns.
        }
    } else {
        // Feedback Template Course Not Found
    }

    if ($debugging) {
        echo "<pre><hr> Printing feedback Questions : <br>";
        print_r($feedbackQuestionsArray);
        echo "<br> </pre>";
    }

    // -------------------------------------------------------
    // 2) Prepare containers
    // -------------------------------------------------------
    $studentAccessibleFeedback = []; // student -> course -> feedbackid -> status (1/0/'NA'/'FNA'/string)
    $studentFeedbackList = [];       // course -> feedbackid -> name
    $facultyFeedbackList = [];       // course -> feedbackid -> name (for faculty-level curriculum entries)
    $studentEnrolledToCoursesList = [];

    // -------------------------------------------------------
    // 3) Fetch faculty enrolments (roleid = 3) and student enrolments (roleid = 5)
    // -------------------------------------------------------
    $facultyEnrolledCoursesQuery = $conn->query("
        SELECT CONCAT(u.firstname, ' ', u.lastname) AS fullName, ra.roleid, ra.userid AS userid, cc.id AS CourseId
        FROM {$prefix}role_assignments ra
        JOIN {$prefix}context con ON con.id = ra.contextid
        JOIN {$prefix}course cc ON cc.id = con.instanceid
        JOIN {$prefix}user u ON u.id = ra.userid
        WHERE con.contextlevel = 50
          AND ra.roleid = 3
          AND cc.id IN ({$cid_in})
        ORDER BY cc.id
    ");

    // Build minimal faculty array so we can map users -> courses
    $facultyList = [];
    if ($facultyEnrolledCoursesQuery) {
        while ($r = mysqli_fetch_assoc($facultyEnrolledCoursesQuery)) {
            $facultyList[$r['CourseId']][$r['userid']] = $r['fullName']; // courseId -> userid -> fullname
        }
    }

    // Build student enrolled courses (single query)
    $studentEnrolledCoursesQuery = $conn->query("
        SELECT ra.userid AS userid, cc.id AS CourseId
        FROM {$prefix}role_assignments ra
        JOIN {$prefix}context con ON con.id = ra.contextid
        JOIN {$prefix}course cc ON cc.id = con.instanceid
        WHERE con.contextlevel = 50
          AND ra.roleid = 5
          AND cc.id IN ({$cid_in})
        ORDER BY cc.id
    ");
    if ($studentEnrolledCoursesQuery) {
        while ($userRow = mysqli_fetch_assoc($studentEnrolledCoursesQuery)) {
            $studentEnrolledToCoursesList[$userRow['userid']][$userRow['CourseId']] = $userRow['CourseId'];
        }
    }

    // -------------------------------------------------------
    // 4) Helper: feedback module id (single query)
    // -------------------------------------------------------
    $feedbackModuleRes = $conn->query("SELECT id FROM {$prefix}modules WHERE name = 'feedback'");
    $feedbackModuleid = $feedbackModuleRes ? (int)$feedbackModuleRes->fetch_assoc()['id'] : 0;

    // -------------------------------------------------------
    // 5) PRELOAD: groups, group memberships, feedbacks, submissions, users, courses
    // -------------------------------------------------------

    // 5.a) All groups + members for selected courses
    $allGroupsInCourses = [];    // courseId -> [ {groupid, groupName, memberid}, ... ]
    $groupsSql = "
        SELECT g.id AS groupid, g.name AS groupName, g.courseid AS courseId, gm.userid AS memberid
        FROM {$prefix}groups g
        JOIN {$prefix}groups_members gm ON gm.groupid = g.id
        WHERE g.courseid IN ({$cid_in})
    ";
    $groupsQuery = $conn->query($groupsSql);
    if ($groupsQuery) {
        while ($row = $groupsQuery->fetch_assoc()) {
            $allGroupsInCourses[$row['courseId']][] = $row; // preserve memberid for building lookups
        }
    }

    // 5.b) Build groupsInCourse (names only)
    $groupsInCourse = [];
    foreach ($allGroupsInCourses as $cid_k => $rows) {
        foreach ($rows as $r) {
            $groupsInCourse[$cid_k][$r['groupid']] = $r['groupName'];
        }
        $groupsInCourse[$cid_k] = array_values($groupsInCourse[$cid_k] ?? []);
    }

    if ($debugging) {
        echo "<pre><hr> Printing groupsInCourse : <br>";
        print_r($groupsInCourse);
        echo "<br> </pre>";
    }

    // 5.c) studentGroups lookup: studentId -> courseId -> [groupNames]
    $studentGroups = [];
    foreach ($allGroupsInCourses as $cId => $rows) {
        foreach ($rows as $r) {
            $studentGroups[$r['memberid']][$cId][] = $r['groupName'];
        }
    }

    // 5.d) facultyGroups lookup: facultyId -> courseId -> [groupNames]
    $facultyGroups = [];
    foreach ($allGroupsInCourses as $cId => $rows) {
        foreach ($rows as $r) {
            $facultyGroups[$r['memberid']][$cId][] = $r['groupName'];
        }
    }

    // 5.e) Feedbacks in courses (single query)
    $feedbackInCourses = []; // courseId -> [ {feedbackid, feedbackName}, ... ]
    $feedbacksSql = "
        SELECT f.id AS feedbackid, f.name AS feedbackName, f.course AS courseid
        FROM {$prefix}feedback f
        JOIN {$prefix}course_modules cm ON cm.instance = f.id
        WHERE f.course IN ({$cid_in})
          AND cm.module = {$feedbackModuleid}
          AND cm.deletioninprogress = 0
    ";
    $feedbacksQuery = $conn->query($feedbacksSql);
    if ($feedbacksQuery) {
        while ($f = $feedbacksQuery->fetch_assoc()) {
            $feedbackInCourses[$f['courseid']][] = $f;
        }
    }

    // 5.f) Preload feedback submissions: userid -> feedbackid -> true
    $feedbackSubmissions = [];
    $allFeedbackIds = [];
    foreach ($feedbackInCourses as $crows) {
        foreach ($crows as $fr) {
            $allFeedbackIds[] = (int)$fr['feedbackid'];
        }
    }
    $allFeedbackIds = array_unique($allFeedbackIds);
    if (!empty($allFeedbackIds)) {
        $feedbackIdsIn = implode(',', $allFeedbackIds);
        $submittedQuery = $conn->query("
            SELECT userid, feedback
            FROM {$prefix}feedback_completed
            WHERE feedback IN ({$feedbackIdsIn})
        ");
        if ($submittedQuery) {
            while ($s = $submittedQuery->fetch_assoc()) {
                $feedbackSubmissions[$s['userid']][(int)$s['feedback']] = true;
            }
        }
    }

    // 5.g) Preload user names for all students involved
    $userIds = array_keys($studentEnrolledToCoursesList);
    $users = [];
    if (!empty($userIds)) {
        $userIdsIn = implode(',', array_map('intval', $userIds));
        $usersQuery = $conn->query("
            SELECT id, username, CONCAT(firstname, ' ', lastname) AS fullname
            FROM {$prefix}user
            WHERE id IN ({$userIdsIn})
        ");
        if ($usersQuery) {
            while ($u = $usersQuery->fetch_assoc()) {
                $users[(int)$u['id']] = $u;
            }
        }
    }

    // 5.h) Preload course names for selected courses
    $courses = [];
    $coursesQuery = $conn->query("SELECT id, fullname FROM {$prefix}course WHERE id IN ({$cid_in})");
    if ($coursesQuery) {
        while ($c = $coursesQuery->fetch_assoc()) {
            $courses[(int)$c['id']] = $c['fullname'];
        }
    }

    // -------------------------------------------------------
    // 6) Build facultyWithGroupDetails using preloaded facultyList & facultyGroups
    // -------------------------------------------------------
    $facultyWithGroupDetails = []; // courseId -> facultyFullName -> groups or 'NGA' / 'CDNG'

    foreach ($facultyList as $courseId => $facultyUsers) {
        $hasGroupsInCourse = !empty($allGroupsInCourses[$courseId]);
        foreach ($facultyUsers as $facultyUserId => $facultyFullName) {
            if ($hasGroupsInCourse) {
                if (!empty($facultyGroups[$facultyUserId][$courseId])) {
                    foreach ($facultyGroups[$facultyUserId][$courseId] as $gname) {
                        $facultyWithGroupDetails[$courseId][$facultyFullName][$gname] = $gname;
                    }
                } else {
                    $facultyWithGroupDetails[$courseId][$facultyFullName] = "NGA"; // Not Group Assigned
                }
            } else {
                $facultyWithGroupDetails[$courseId][$facultyFullName] = "CDNG"; // Course Does Not Have Groups
            }
        }
    }

    if ($debugging) {
        echo "<pre><hr> Printing faculty With Group Details : <br>";
        print_r($facultyWithGroupDetails);
        echo "<br> </pre>";
    }

    // -------------------------------------------------------
    // 7) Iterate per student and course to build accessibility mapping
    // -------------------------------------------------------
    foreach ($studentEnrolledToCoursesList as $studentId => $enrolledCourseList) {
        foreach ($enrolledCourseList as $courseId) {

            if (!isset($studentAccessibleFeedback[$studentId][$courseId])) {
                $studentAccessibleFeedback[$studentId][$courseId] = [];
            }

            if (isset($facultyWithGroupDetails[$courseId])) {
                foreach ($facultyWithGroupDetails[$courseId] as $facultyName => $facultyGroupAvailability) {

                    if (is_array($facultyGroupAvailability)) {
                        $studentGroup = $studentGroups[$studentId][$courseId] ?? [];

                        if (!empty($studentGroup)) {
                            foreach ($studentGroup as $groupName) {
                                if (isset($feedbackInCourses[$courseId])) {
                                    foreach ($feedbackInCourses[$courseId] as $f) {
                                        if (stripos($f['feedbackName'], 'Student Feedback- Faculty') !== false
                                            && stripos($f['feedbackName'], $facultyName) !== false
                                            && stripos($f['feedbackName'], $groupName) !== false
                                        ) {
                                            $feedbackId = (int)$f['feedbackid'];
                                            $isSubmitted = !empty($feedbackSubmissions[$studentId][$feedbackId]);
                                            $studentAccessibleFeedback[$studentId][$courseId][$feedbackId] = $isSubmitted ? 1 : 0;
                                        }
                                    }
                                }
                            }

                            if (isset($groupsInCourse[$courseId]) && !empty($groupsInCourse[$courseId])) {
                                foreach ($groupsInCourse[$courseId] as $gName) {
                                    if (!in_array($gName, $studentGroup)) {
                                        if (isset($feedbackInCourses[$courseId])) {
                                            foreach ($feedbackInCourses[$courseId] as $f) {
                                                if (stripos($f['feedbackName'], 'Student Feedback- Faculty') !== false
                                                    && stripos($f['feedbackName'], $facultyName) !== false
                                                    && stripos($f['feedbackName'], $gName) !== false
                                                ) {
                                                    $fid = (int)$f['feedbackid'];
                                                    if (!isset($studentAccessibleFeedback[$studentId][$courseId][$fid])) {
                                                        $studentAccessibleFeedback[$studentId][$courseId][$fid] = 'NA';
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            }

                            if (isset($feedbackInCourses[$courseId])) {
                                foreach ($feedbackInCourses[$courseId] as $f) {
                                    $fid = (int)$f['feedbackid'];
                                    if (!isset($studentAccessibleFeedback[$studentId][$courseId][$fid])) {
                                        $isSubmitted = !empty($feedbackSubmissions[$studentId][$fid]);
                                        $fNameLower = strtolower(preg_replace('/\s+/', '', $f['feedbackName']));
                                        $markedNA = false;
                                        if (!empty($groupsInCourse[$courseId])) {
                                            foreach ($groupsInCourse[$courseId] as $gName) {
                                                $gLower = strtolower(preg_replace('/\s+/', '', $gName));
                                                if (strpos($fNameLower, 'studentfeedback-faculty') !== false && strpos($fNameLower, $gLower) !== false) {
                                                    $studentAccessibleFeedback[$studentId][$courseId][$fid] = 'NA';
                                                    $markedNA = true;
                                                    break;
                                                }
                                            }
                                        }
                                        if (!$markedNA) {
                                            $studentAccessibleFeedback[$studentId][$courseId][$fid] = $isSubmitted ? 1 : 0;
                                        }
                                    }
                                }
                            }

                        } else {
                            $studentAccessibleFeedback[$studentId][$courseId] = "Student is Not Assigned in a Group for Course";
                        }

                    } elseif ($facultyGroupAvailability === 'NGA') {
                        if (isset($feedbackInCourses[$courseId])) {
                            foreach ($feedbackInCourses[$courseId] as $f) {
                                if (stripos($f['feedbackName'], 'Student Feedback- Faculty') !== false
                                    && stripos($f['feedbackName'], $facultyName) !== false
                                ) {
                                    $fid = (int)$f['feedbackid'];
                                    $isSubmitted = !empty($feedbackSubmissions[$studentId][$fid]);
                                    $studentAccessibleFeedback[$studentId][$courseId][$fid] = $isSubmitted ? 1 : 0;
                                }
                            }
                        }

                    } else {
                        if (isset($feedbackInCourses[$courseId])) {
                            foreach ($feedbackInCourses[$courseId] as $f) {
                                if (stripos($f['feedbackName'], 'Student Feedback- Faculty') !== false
                                    && stripos($f['feedbackName'], $facultyName) !== false
                                ) {
                                    $fid = (int)$f['feedbackid'];
                                    $isSubmitted = !empty($feedbackSubmissions[$studentId][$fid]);
                                    $studentAccessibleFeedback[$studentId][$courseId][$fid] = $isSubmitted ? 1 : 0;
                                }
                            }
                        }
                    }
                } // foreach faculty in course
            } // if facultyWithGroupDetails exists

            // Ensure any course-level (curriculum) feedbacks recorded
            if (isset($feedbackInCourses[$courseId]) && is_array($studentAccessibleFeedback[$studentId][$courseId])) {
                foreach ($feedbackInCourses[$courseId] as $f) {
                    $fid = (int)$f['feedbackid'];
                    if (!isset($studentAccessibleFeedback[$studentId][$courseId][$fid])) {
                        $isSubmitted = !empty($feedbackSubmissions[$studentId][$fid]);
                        $studentAccessibleFeedback[$studentId][$courseId][$fid] = $isSubmitted ? 1 : 0;
                    }
                }
            }

            if (!isset($studentAccessibleFeedback[$studentId][$courseId])) {
                $studentAccessibleFeedback[$studentId][$courseId] = 'FNA';
            }

        } // foreach course
    } // foreach student

    // -------------------------------------------------------
    // 8) Build studentFeedbackList and facultyFeedbackList per course for header usage
    // -------------------------------------------------------
    foreach ($facultyWithGroupDetails as $courseid => $facultyDetails) {
        $check = false;

        foreach ($facultyDetails as $facultyName => $groups) {
            if (isset($feedbackInCourses[$courseid])) {
                foreach ($feedbackInCourses[$courseid] as $f) {
                    if (stripos($f['feedbackName'], $facultyName) !== false) {
                        $studentFeedbackList[$courseid][(int)$f['feedbackid']] = $f['feedbackName'];
                        $check = true;
                    }
                }
            }
        }

        if (isset($feedbackInCourses[$courseid])) {
            foreach ($feedbackInCourses[$courseid] as $f) {
                $fid = (int)$f['feedbackid'];
                if (!isset($studentFeedbackList[$courseid][$fid])) {
                    if (stripos($f['feedbackName'], 'Student Feedback- Faculty') === false) {
                        $studentFeedbackList[$courseid][$fid] = $f['feedbackName'];
                    } else {
                        $studentFeedbackList[$courseid][$fid][] = $f['feedbackName'];
                        $facultyFeedbackList[$courseid][$fid] = $f['feedbackName'];
                    }
                    $check = true;
                }
            }
        }

        if (!$check) {
            $studentFeedbackList[$courseid] = "Feedback Not Available";
        }
    }

    // -------------------------------------------------------
    // 9) sort keys for consistent display
    // -------------------------------------------------------
    ksort($studentFeedbackList);
    foreach ($studentFeedbackList as &$subArray) {
        if (is_array($subArray)) ksort($subArray);
    }
    unset($subArray);

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

    if ($debugging) {
        echo "<hr><pre> Printing Student Accessible Feedback : <br>";
        print_r($studentAccessibleFeedback);
        echo "<br> </pre>";
    }
} // end if responses

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
                    <option value="analysis" <?php if($type=="analysis") echo "selected"; ?>>Analysis Report</option>
                    <option value="consolidated" <?php if($type=="consolidated") echo "selected"; ?>>Consolidated Report</option>
                    <option value="responses" <?php if($type=="responses") echo "selected"; ?>>Responses Report</option>
                </select>

                <input type="hidden" name="initialData"
                    value="<?php echo htmlspecialchars(json_encode($initialData)); ?>">
                
                <input type="hidden" name="programname_hidden" value="<?php echo htmlspecialchars($programname); ?>">
                <input type="hidden" name="batchname_hidden" value="<?php echo htmlspecialchars($batchname); ?>">
                <input type="hidden" name="semestername_hidden" value="<?php echo htmlspecialchars($semestername); ?>">
                
            </form>

            <div class="headings">
                <h1><?php echo htmlspecialchars($programname); ?></h1>
                <h1><?php echo htmlspecialchars($batchname); ?></h1>
                <h1><?php echo htmlspecialchars($semestername); ?></h1>
            </div>

            <?php if($type == 'responses') { ?>
                <button onclick="exportToExcel(['myTable', 'summaryLikertTable', 'summaryYesNoTable'], 'Feedback_Report')">Download Excel</button>
            <?php } else { ?>
                <button onclick="exportTableToExcel('myTable', 'Feedback Report')">Download Excel</button>
            <?php } ?>
            
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
                            // Course name from query results; fallback to DB if missing
                            $courseName = $courseNamesMap[$courseid] ?? null;
                            if ($courseName === null) {
                                $cRes = $conn->query("SELECT fullname FROM {$prefix}course WHERE id = " . (int)$courseid);
                                $cRow = $cRes ? $cRes->fetch_assoc() : null;
                                $courseName = $cRow['fullname'] ?? '-';
                            }
                            if (is_array($feedbacks)) {
                                $isPresent = 0;
                                foreach ($feedbacks as $feedbackId => $feedbackName) {
                                    if (is_array($feedbackName))
                                        $isPresent = 1;
                                }
                                echo "<th colspan='" . (count($feedbacks) - $isPresent) . "'>" . htmlspecialchars($courseName) . "</th>";
                            } else {
                                echo "<th>" . htmlspecialchars($courseName) . "</th>";
                            }
                        }
                        echo "<th rowspan='2'> Not Submitted </th>";
                        echo "<th rowspan='2'> Submitted </th>";
                        echo "</tr>";

                        echo "<tr>";
                        echo "<th></th>";
                        echo "<th></th>";
                        echo "<th></th>";
                        foreach ($studentFeedbackList as $courseid => $feedbacks) {
                            if (is_array($feedbacks)) {
                                foreach ($feedbacks as $feedbackId => $feedbackName) {
                                    if (!is_array($feedbackName))
                                        echo "<th>" . htmlspecialchars($feedbackName) . "</th>";
                                }
                            } else {
                                echo "<th>" . htmlspecialchars($feedbacks) . "</th>";
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
                            $sRes = $conn->query("SELECT username, CONCAT(firstname, ' ', lastname) AS fullname FROM {$prefix}user WHERE id = " . (int)$studentId);
                            $studentDetails = ($sRes ? $sRes->fetch_assoc() : null) ?? ['username' => '-', 'fullname' => '-'];
                            echo "<td id='heading'>" . $sr_no . "</td>";
                            echo "<td id='heading'>" . htmlspecialchars($studentDetails['username']) . "</td>";
                            echo "<td id='heading'>" . htmlspecialchars($studentDetails['fullname']) . "</td>";

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

    <!-- Student Responses Report -->
    <div class="data" >
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

                foreach ($studentAccessibleFeedback as $studentId => $courseDetails) {
                    $studentDetails = $users[$studentId] ?? null;
                    if (!$studentDetails) {
                        $tmp = $conn->query("SELECT username, CONCAT(firstname, ' ', lastname) AS fullname FROM {$prefix}user WHERE id = " . (int)$studentId);
                        $studentDetails = ($tmp ? $tmp->fetch_assoc() : null) ?? ['username' => '-', 'fullname' => '-'];
                    }

                    $courseCount = is_array($courseDetails) ? count($courseDetails) : 0;
                    if ($courseCount <= 0) continue;
                    $firstCourse = true;

                    foreach ($courseDetails as $courseId => $feedbackList) {
                        $courseName = $courses[$courseId] ?? null;
                        if ($courseName === null) {
                            $tmpc = $conn->query("SELECT fullname FROM {$prefix}course WHERE id = " . (int)$courseId);
                            $tmpcRow = $tmpc ? $tmpc->fetch_assoc() : null;
                            $courseName = $tmpcRow['fullname'] ?? '-';
                        }

                        echo "<tr>";

                        if ($firstCourse) {
                            echo "<td rowspan='$courseCount' id='value'>" . $srno . "</td>";
                            echo "<td rowspan='$courseCount' id='value'>" . htmlspecialchars($studentDetails['username']) . " - " . htmlspecialchars($studentDetails['fullname']) . "</td>";
                            $firstCourse = false;
                        }

                        echo "<td id='value'>" . htmlspecialchars($courseName) . "</td>";

                        $responses = false;
                        if (is_array($feedbackList) && !empty($feedbackList)) {

                            $feedbackIdsArr = array_keys($feedbackList);
                            $feedbackIdsArr = array_filter($feedbackIdsArr, function ($v) {
                                return is_numeric($v);
                            });
                            if (!empty($feedbackIdsArr)) {
                                $feedbackIds = implode(',', array_map('intval', $feedbackIdsArr));

                                $responses = $conn->query("
                                    SELECT fi.id AS questionId, fi.name AS question, fv.value, fi.typ, fi.presentation
                                    FROM {$prefix}feedback_item fi
                                    JOIN {$prefix}feedback_value fv ON fv.item = fi.id
                                    JOIN {$prefix}feedback_completed fc ON fc.id = fv.completed
                                    JOIN {$prefix}feedback f ON f.id = fc.feedback
                                    WHERE fc.feedback IN ({$feedbackIds})  
                                      AND fc.userid = " . (int)$studentId . "
                                    ORDER BY CAST(SUBSTRING_INDEX(fi.name, '.', 1) AS UNSIGNED) ASC
                                ");
                            }
                        }

                        if ($responses && $responses->num_rows > 0) {
                            $answers = [];

                            while ($answer = mysqli_fetch_assoc($responses)) {
                                if ($answer['typ'] === "multichoicerated") {
                                    switch ($answer['value']) {
                                        case "1": $label = "Strongly Agree"; break;
                                        case "2": $label = "Agree"; break;
                                        case "3": $label = "Neutral"; break;
                                        case "4": $label = "Disagree"; break;
                                        case "5": $label = "Strongly Disagree"; break;
                                        case "":  $label = "NA"; break;
                                        default:  $label = $answer['value']; break;
                                    }
                                } elseif ($answer['typ'] === "multichoice") {
                                    switch ($answer['value']) {
                                        case "1": $label = "Yes"; break;
                                        case "2": $label = "No"; break;
                                        case "":  $label = "NA"; break;
                                        default:  $label = $answer['value']; break;
                                    }
                                } else {
                                    $label = $answer['value'];
                                    if ($label == "") $label = "NA";
                                }

                                $answers[$answer['question']] = $label;
                            }

                            foreach ($feedbackQuestionsArray as $qid => $qname) {
                                echo "<td id='value'>" . htmlspecialchars($answers[$qname] ?? "-") . "</td>";
                            }

                        } else {
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

    <!-- Summary section -->
    <div class="data" style="margin-top:30px;">
        <h2 style='text-align:center;'>Summary of Student Responses</h2>

        <?php
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
                SELECT fi.id AS questionId, fi.name AS question, fv.value, fi.typ
                FROM {$prefix}feedback_item fi
                JOIN {$prefix}feedback_value fv ON fv.item = fi.id
                JOIN {$prefix}feedback_completed fc ON fc.id = fv.completed
                JOIN {$prefix}feedback f ON f.id = fc.feedback
                WHERE fc.feedback IN (" . implode(',', array_map('intval', $allFeedbackIds)) . ")
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
                    switch ($value) {
                        case "1": $label = "Strongly Agree"; break;
                        case "2": $label = "Agree"; break;
                        case "3": $label = "Neutral"; break;
                        case "4": $label = "Disagree"; break;
                        case "5": $label = "Strongly Disagree"; break;
                        default: $label = "NA"; break;
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
                    switch ($value) {
                        case "1": $label = "Yes"; break;
                        case "2": $label = "No"; break;
                        default: $label = "NA"; break;
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
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td colspan='2'>Select a report type</td></tr>
                    </tbody>
                </table>
            </div>

        <?php } ?>

    </div>

    <script>
        function exportTableToExcel(tableID, filename = '') {
            const table = document.getElementById(tableID);
            if (!table) {
                alert("No table found to export!");
                return;
            }
            const rows = table.querySelectorAll("tr");
            const matrix = [];
            let maxCols = 0;

            rows.forEach((row, rowIndex) => {
                const cells = row.querySelectorAll("th, td");
                let colIndex = 0;

                if (!matrix[rowIndex]) matrix[rowIndex] = [];

                cells.forEach(cell => {
                    while (matrix[rowIndex][colIndex]) colIndex++;

                    const colspan = parseInt(cell.getAttribute("colspan")) || 1;
                    const rowspan = parseInt(cell.getAttribute("rowspan")) || 1;
                    const cellValue = cell.querySelector("img")
                        ? cell.querySelector("img").getAttribute("data-export")
                        : cell.textContent.trim();

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
            if (!Array.isArray(tableIDs)) tableIDs = [tableIDs];
            
            let combinedHTML = '';
            let foundAny = false;
        
            tableIDs.forEach(id => {
                const table = document.getElementById(id);
                if (table) {
                    foundAny = true;
                    const clonedTable = table.cloneNode(true);
                    clonedTable.querySelectorAll('button, a').forEach(el => el.remove());
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
            document.getElementById('reportTypeForm').submit();
        });
    </script>
</body>
</html>