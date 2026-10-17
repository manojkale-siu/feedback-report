<?php
    // session_start();
    //require_once ('../config.php');
    require_once('db.php');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $initialData = isset($_POST['initialData']) ? json_decode($_POST['initialData'], true) : '';

        $programname = isset($_POST['programSelect']) ? $_POST['programSelect'] : $initialData["programSelect"];
        $batchname = isset($_POST['batchSelect']) ? $_POST['batchSelect'] : $initialData["batchSelect"];
        $semestername = isset($_POST['semesterSelect']) ? $_POST['semesterSelect'] : $initialData["semesterSelect"];
        $roleId = isset($_POST['role']) ? $_POST['role'] : $initialData["role"];
        $serializedPrograms = isset($_POST['serializedPrograms']) ? $_POST['serializedPrograms'] : $initialData["serializedPrograms"];
        $programArray = json_decode($serializedPrograms, true);
        $sessions = $programArray[$programname][$batchname][$semestername];

        $initialData = ["programSelect" => $programname, "batchSelect" => $batchname, "semesterSelect" => $semestername, "role" => $roleId, "serializedPrograms"=> $serializedPrograms];
    }


    $cidArray = array();
    foreach ($sessions as $subjectname => $val) {
        foreach ($val as $cid => $cArray) {
            $cidArray[] = $cid;
        }
    }

    $cid = implode(',', $cidArray);
    $facultyEnrolledCoursesQuery = $conn->query("SELECT concat(u.firstname, ' ', u.lastname) as fullName, ra.roleid, ra.userid as userid, cc.id as CourseId 
                                from {$prefix}role_assignments ra 
                                join {$prefix}context con on con.id = ra.contextid 
                                join {$prefix}course cc on cc.id = con.instanceid 
                                join {$prefix}user u on u.id = ra.userid 
                                where con.contextlevel = 50 and ra.roleid=3 and cc.id in ($cid) order by cc.id");
    
    $facultyWithGroupDetails = [];
    $groupsInCourse = [];

    $groups = $conn->query("SELECT g.id as groupid, g.name as groupName, g.courseid as CourseId
                                    from {$prefix}groups g 
                                    join {$prefix}groups_members gm on gm.groupid = g.id 
                                    where g.courseid in ($cid) group by g.courseid, g.id");


    while($grp = mysqli_fetch_assoc($groups)) {
        $groupsInCourse[$grp['CourseId']][] = $grp['groupName'];
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
    $studentFeedbackList = [];
    $facultyFeedbackList = [];
    $studentEnrolledToCoursesList = [];
    $studentAccessibleFeedback = [];
    if($roleId === '5') {
        $studentEnrolledCoursesQuery = $conn->query("SELECT ra.roleid, ra.userid as userid, cc.id as CourseId from {$prefix}role_assignments ra 
                                    join {$prefix}context con on con.id = ra.contextid
                                    join {$prefix}course cc on cc.id = con.instanceid
                                    where con.contextlevel = 50 and ra.roleid = 5 and cc.id in ($cid) order by cc.id");

        
        while($userRow = mysqli_fetch_assoc($studentEnrolledCoursesQuery)) {
            $studentEnrolledToCoursesList[$userRow['userid']][$userRow['CourseId']] = $userRow['CourseId'];
        }

        $feedbackModuleid = $conn -> query("SELECT id from {$prefix}modules where name='feedback'");
        $feedbackModuleid = $feedbackModuleid -> fetch_assoc()['id'];

        
        foreach($studentEnrolledToCoursesList as $studentId => $enrolledCourseList) {
            // if($studentId == 561) {
                foreach($enrolledCourseList as $courseId) {

                    if(isset($facultyWithGroupDetails[$courseId])) {
                        $studentEnrolledGroup = $conn->query("SELECT g.name as GroupName from {$prefix}groups g
                                join {$prefix}groups_members gm on gm.groupid = g.id 
                                where g.courseid = $courseId and gm.userid = $studentId");
    
                        foreach($facultyWithGroupDetails[$courseId] as $facultyName => $facultyGroupAvailability) {
    
                            if(is_array($facultyGroupAvailability)) {
    
                                if($studentEnrolledGroup -> num_rows > 0) {
                                    $studentGroup = [];
                                    while($group = mysqli_fetch_assoc($studentEnrolledGroup)) {
                                        $studentGroup[] = $group['GroupName'];
                                        $check = false;
                                        $isFeedbackInCourse = $conn->query("SELECT f.id as id, f.name as feedbackName from {$prefix}feedback f
                                            join {$prefix}course_modules cm on cm.instance=f.id
                                            where (f.name like '%Student Feedback- Faculty%' and f.name like '%{$group['GroupName']}%' and f.name like '%$facultyName%') and f.course=$courseId and cm.module=$feedbackModuleid and cm.deletioninprogress=0");
                                        
                                        if($isFeedbackInCourse -> num_rows > 0) {
                                            while($feedback = mysqli_fetch_assoc($isFeedbackInCourse)) {

                                                $isStudentSubmittedFeedback = $conn->query("SELECT id from {$prefix}feedback_completed where userid = $studentId and feedback={$feedback['id']}");
                                                if($isStudentSubmittedFeedback -> num_rows > 0) {
                                                    $studentAccessibleFeedback[$studentId][$courseId][$feedback['id']] = 1; 
                                                } else{
                                                    
                                                    $studentAccessibleFeedback[$studentId][$courseId][$feedback['id']] = 0; 
                                                    
                                                }
                                                $check = true;
                                            }
                                        } 
                                    }
                                    foreach ($groupsInCourse[$courseId] as $groupName) {
                                        if (!in_array($groupName, $studentGroup)) {
                                            $isFeedbackInCourse = $conn->query("
                                                SELECT f.id as id, f.name as feedbackName 
                                                FROM {$prefix}feedback f
                                                JOIN {$prefix}course_modules cm ON cm.instance = f.id
                                                WHERE f.name LIKE '%Student Feedback- Faculty%'
                                                  AND f.name LIKE '%$groupName%'
                                                  AND f.name LIKE '%$facultyName%'
                                                  AND f.course = $courseId
                                                  AND cm.module = $feedbackModuleid
                                                  AND cm.deletioninprogress = 0
                                            ");
                                    
                                            if ($isFeedbackInCourse && $isFeedbackInCourse->num_rows > 0) {
                                                $feedbackData = $isFeedbackInCourse->fetch_assoc();
                                                $feedbackId = $feedbackData['id'];
                                    
                                                if (isset($feedbackId)) {
                                                    $studentAccessibleFeedback[$studentId][$courseId][$feedbackId] = 'NA';
                                                }
                                            }
                                        }
                                    }
                                    


                                    $curriculumFeedbacks = $conn->query("SELECT f.id, f.name as feedbackName from {$prefix}feedback f
                                    join {$prefix}course_modules cm on cm.instance=f.id
                                    where f.course=$courseId and cm.module=$feedbackModuleid and cm.deletioninprogress=0");
                                    
                                    while($feedback = mysqli_fetch_assoc($curriculumFeedbacks)) {
                                        $isStudentSubmittedFeedback = $conn->query("SELECT id from {$prefix}feedback_completed where userid = $studentId and feedback={$feedback['id']}");

                                        if(!isset($studentAccessibleFeedback[$studentId][$courseId][$feedback['id']])) {

                                            if($isStudentSubmittedFeedback -> num_rows > 0)  {
                                                $studentAccessibleFeedback[$studentId][$courseId][$feedback['id']] = 1;
                                            } else{
                                                if(isset($groupsInCourse[$courseId])) {
                                                    foreach($groupsInCourse[$courseId] as $groupName) {
                                                        if(!in_array($groupName, $studentGroup)) {
                                                            $formattedFeedbackName = strtolower(str_replace(' ', '', $feedback['feedbackName']));
                                                            $formattedGroupName = strtolower(str_replace(' ', '', $groupName));

                                                            
                                                            if((strpos($formattedFeedbackName, "studentfeedback-faculty") !== false) && (strpos($formattedFeedbackName, $formattedGroupName) !== false)) {
                                                                if(!isset($studentAccessibleFeedback[$studentId][$courseId][$feedback['id']])) {
                                                                    $studentAccessibleFeedback[$studentId][$courseId][$feedback['id']] = 'NA'; 
                                                                }
                                                            } else{
                                                                if(!isset($studentAccessibleFeedback[$studentId][$courseId][$feedback['id']])) {
                                                                    $studentAccessibleFeedback[$studentId][$courseId][$feedback['id']] = 0; 
                                                                }
                                                            }
                                                        } else{
                                                            // echo $feedback['id'] . " = " . $groupName . "<br>";
                                                            if(!isset($studentAccessibleFeedback[$studentId][$courseId][$feedback['id']])) {
                                                                $studentAccessibleFeedback[$studentId][$courseId][$feedback['id']] = 0; 
                                                            }
    
                                                        }
                                                    }
                                                } 
                                            }
                                            $check = true;
                                        }
                                    }
                                    if(!isset($studentAccessibleFeedback[$studentId][$courseId]) && !$check){
                                        $studentAccessibleFeedback[$studentId][$courseId] = 'FNA';
                                    }
                                } else {
                                    $studentAccessibleFeedback[$studentId][$courseId] = "Student is Not Assigned in a Group for Course";
                                }
                            } else if($facultyGroupAvailability == 'NGA'){
                                $isFeedbackInCourse = $conn->query("SELECT f.id as id, f.name from {$prefix}feedback f
                                            join {$prefix}course_modules cm on cm.instance=f.id
                                            where f.name like '%Student Feedback- Faculty%' and f.name like '%$facultyName%' and f.course=$courseId and cm.module=$feedbackModuleid and cm.deletioninprogress=0");
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
    
    
                            } else{
                                $check = false;
                                $isFeedbackInCourse = $conn->query("SELECT f.id as id, f.name from {$prefix}feedback f
                                            join {$prefix}course_modules cm on cm.instance=f.id
                                            where f.name like '%Student Feedback- Faculty%' and f.name like '%$facultyName%' and f.course=$courseId and cm.module=$feedbackModuleid and cm.deletioninprogress=0");
    
                                if($isFeedbackInCourse -> num_rows > 0) {
                                    while($feedback = mysqli_fetch_assoc($isFeedbackInCourse)) {
                                        $isStudentSubmittedFeedback = $conn->query("SELECT id from {$prefix}feedback_completed where userid = $studentId and feedback={$feedback['id']}");
                                        if($isStudentSubmittedFeedback -> num_rows > 0)  {
                                            $studentAccessibleFeedback[$studentId][$courseId][$feedback['id']] = 1;
                                        } else{
                                            $studentAccessibleFeedback[$studentId][$courseId][$feedback['id']] = 0;
                                        }
                                        $check = true;
                                    }
                                } 
                            }
    
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
                                    $check = true;
                                }
                            }
                            if(!isset($studentAccessibleFeedback[$studentId][$courseId]) && !$check){
                                $studentAccessibleFeedback[$studentId][$courseId] = 'FNA';
                            }
    
                            
                        }
                    }
                }
            // }
        }


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
                        if (strpos($feedback['name'], 'Faculty Feedback - Curriculum') === false) {
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

        ksort($studentFeedbackList);

        foreach ($studentFeedbackList as &$subArray) {
            ksort($subArray);
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
        include "Consolidated-Feedback.php";
    } else{
        // faculty
        $courseIds = [];
        $facultyAccessibleFeedback = [];
        $courseFaculty = [];
        foreach($facultyWithGroupDetails as $courseId => $facultyDetails) {
            foreach($facultyDetails as $facultyName => $groupDetails) {
                $facultyCurriculumId = $conn->query("SELECT id, name from {$prefix}feedback where course = $courseId and name like '%Faculty Feedback - Curriculum%'");
    
    
                if($facultyCurriculumId -> num_rows > 0) {
                    $curriculumId = $facultyCurriculumId->fetch_assoc()['id'];
                    $isFeedbackSubmitted = $conn ->query("SELECT fc.id, fc.userid as UserId
                                            FROM {$prefix}feedback_completed fc
                                            JOIN {$prefix}user u ON u.id = fc.userid 
                                            WHERE fc.feedback = {$curriculumId} 
                                            AND CONCAT(u.firstname, ' ', u.lastname) LIKE '%{$facultyName}%'
                                            ");
                    $facultyId = $conn->query("SELECT id from {$prefix}user where CONCAT(firstname, ' ', lastname) like '%$facultyName%'")->fetch_assoc()['id'];
                    if($isFeedbackSubmitted -> num_rows > 0) {
                        $facultyAccessibleFeedback[$facultyName][$courseId][$curriculumId] = 1;
                    } else{
                        $facultyAccessibleFeedback[$facultyName][$courseId][$curriculumId] = 0;
                    }
                    $courseFaculty[$courseId][$facultyId] = $curriculumId;
                } else{
                    $facultyAccessibleFeedback[$facultyName][$courseId] = 'No Feedback Available';
    
                }

            }
            if(!in_array($courseId, $courseIds)) {
                $courseIds[] = $courseId;
            }   
        }

        include "Faculty-Feedback.php";
        
    }

    // echo "<br><br><br>Student Feedback list ";
    // echo "<pre>";
    // print_r($studentFeedbackList[146]);
    // echo "</pre>";
    // echo "<br><br><br>Student Feedback list ";
    // echo "<pre>";
    // print_r($studentFeedbackList);
    // echo "</pre>";

    // echo "Groups : ";
    // echo "<pre>";
    // print_r($groupsInCourse[161]);
    // echo "</pre>";

    // echo "Student : ";
    // echo "<pre>";
    // print_r($studentGroup);
    // echo "</pre>";




    // echo "<br><br><br> Faculty Accessible Feedback : ";
    // echo "<pre>";
    // print_r($facultyWithGroupDetails);
    // echo "</pre>";

    // echo "<br><br><br> Student Accessible Feedback : ";
    // echo "<pre>";
    // print_r($studentAccessibleFeedback);
    // echo "</pre>";


    

    

    


?>




