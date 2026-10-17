<?php
require_once ('../../config.php');
require_once "db.php";

require_login(0, true);
if (isloggedin()) {
    if (has_capability('moodle/role:manage', context_system::instance())) {


    } else {

        echo '<div style="color: red; text-align: center; font-weight: bold;">Access denied. You don\'t have the required role.</div>';
        exit;

    }
} else {

    redirect($CFG->wwwroot . '/login/index.php');
}


$programs = [];

$program = ("SELECT 
        cat1.id as pid,
        cat1.name AS PgmName,
        cat2.name AS Batch,
        cat3.name AS Semester,
        cat4.name AS Specialization,
        c.fullname AS Subject,
        c.id as c_id
        FROM  " . $prefix . "course_categories cat1
        LEFT JOIN  " . $prefix . "course_categories cat2 ON cat1.id = cat2.parent
        LEFT JOIN  " . $prefix . "course_categories cat3 ON cat2.id = cat3.parent
        LEFT JOIN  " . $prefix . "course_categories cat4 ON cat3.id = cat4.parent
        LEFT JOIN  " . $prefix . "course_categories cat5 ON cat4.id = cat5.parent
        LEFT JOIN  " . $prefix . "course c ON c.category IN (cat1.id, cat2.id, cat3.id, cat4.id, cat5.id)

        WHERE cat1.parent = 0 and c.id is not null ORDER BY c.fullname");

$program1 = $conn->query($program);

while ($pgm = $program1->fetch_assoc()) {
    $courses1 = ("SELECT c.id as attendanceid, d.id as sessionid,f.id as groupid,COUNT(DISTINCT d.id)as totalsession, COUNT(DISTINCT e.sessionid) as conductedsession ,c.course as courseid, f.name as groupname   
        FROM  " . $prefix . "attendance c 
        JOIN  " . $prefix . "attendance_sessions d ON c.id = d.attendanceid
        JOIN   " . $prefix . "attendance_log e ON e.sessionid = d.id
        LEFT JOIN   " . $prefix . "groups f ON c.course = f.courseid
        WHERE(d.groupid = f.id OR d.groupid = 0) AND c.course=" . $pgm['c_id'] . "
        GROUP BY f.id
        ORDER BY  c.course;");

    $course = $conn->query($courses1);

    foreach ($course as $crs) {
        switch ($course) {
            case $pgm['Semester'] == '':
                $pgm['Semester'] = 'No Semester';
            case $pgm['Batch'] == '':
                $pgm['Batch'] = 'No Batch';
            default:
                $programs[$pgm['PgmName']][$pgm['Batch']][$pgm['Semester']][$pgm['Subject']][$pgm['c_id']][$crs['groupname']]['conductedSessions'] = [$crs['conductedsession']];
        }
    }
}
// $_SESSION['programArray'] = $programs;

$courseTemplate = $conn->query("SELECT id from {$prefix}course_categories where name like '%Course Template%'")->fetch_assoc()['id'];
$feedbackQuery = $conn->query("SELECT id, fullname from {$prefix}course where category = $courseTemplate");
$stakeholderFeedbackList = [];
while($row = mysqli_fetch_assoc($feedbackQuery)) {
    if(strpos(strtolower($row['fullname']), 'parent') !== false || strpos(strtolower($row['fullname']), 'alumni') !== false || strpos(strtolower($row['fullname']), 'industry') !== false || strpos(strtolower($row['fullname']), 'survey') !== false) {
        $stakeholderFeedbackList[$row['id']] = $row['fullname'];
    }
}

$stakeHolderFeedbacks = [];
foreach($stakeholderFeedbackList as $courseId => $courseName) {
    $feedback = $conn->query("SELECT id, name from {$prefix}feedback where course=$courseId");
    if($feedback -> num_rows > 0) {
        while($row = mysqli_fetch_assoc($feedback)) {
            $stakeHolderFeedbacks[$courseId][$row['id']] = $row['name'];
        }
    }
}


function clean_utf8($mixed) {
    if (is_array($mixed)) {
        $clean = [];
        foreach ($mixed as $key => $value) {
            // Clean and normalize keys: trim and replace multiple whitespaces with single space
            $clean_key = is_string($key)
                ? trim(preg_replace('/\s+/', ' ', mb_convert_encoding($key, 'UTF-8', 'UTF-8')))
                : $key;

            // Recursively clean values
            $clean[$clean_key] = clean_utf8($value);
        }
        return $clean;
    } elseif (is_string($mixed)) {
        // Normalize string values: remove excessive whitespace and re-encode
        return trim(preg_replace('/\s+/', ' ', mb_convert_encoding($mixed, 'UTF-8', 'UTF-8')));
    } else {
        return $mixed;
    }
}

// Clean the $programs array
$programs_clean = clean_utf8($programs);

// Encode to JSON
$json = json_encode($programs_clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

// echo "<pre>";   
// print_r($stakeholderFeedbackList);
// echo "</pre>";
// echo "<pre>";
// print_r($stakeHolderFeedbacks);
// echo "</pre>";




?>


<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Feedback</title>
    <link rel="icon" type="image/x-icon" href="symicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-EVSTQN3/azprG1Anm3QDgpJLIm9Nao0Yz1ztcQTwFspd3yD65VohhpuuCOmLASjC" crossorigin="anonymous">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
    <link rel="stylesheet" href="index.css">
</head>

<body>

    <div class="main">
        <h1>Consolidated Feedback Report</h1>

        <div class="stakeholder-feedback">
            <div class="student" id="student">
                <input type="radio" name="feedbacktype" id="student-radio" value='student' checked>
                <label for="student">Student/Faculty</label>
            </div>
            <div class="other" id="alumni-industry">
                <input type="radio" name="feedbacktype" id="alumni-industry-radio" value='alumni-industry'>
                <label for="other">Alumni/Industry/Parent/Survey</label>
            </div>
        </div>
        <div class="content">
            <form action="main.php" method="post" class='studentform'>
                <label class="heading" for="programSelect">Name of Programme:</label>
                <div class="selection">
                    <select name="programSelect" id="programSelect" required>
                        <option value="">Select Program</option>
                        <?php foreach ($programs_clean as $program => $batches) { ?>
                            <option value="<?php echo $program; ?>">
                                <?php echo $program; ?>
                            </option>
                        <?php } ?>
                    </select>
                </div>

                <label class="heading" for="batchSelect">Name of Batch :</label>
                <div class="selection">
                    <select name="batchSelect" id="batchSelect" required>
                        <option value="">Select Batch</option>
                    </select>
                </div>

                <label class="heading" for="semesterSelect">Name of Semester :</label>
                <div class="selection">
                    <select name="semesterSelect" id="semesterSelect" required>
                        <option value="">Select Semester</option>
                    </select>
                </div>
                <label class="heading" for="role">Select Role :</label>
                <div class="selection">
                    <select name="role" id="role" required>
                        <option>Select the section</option>
                        <option value="5">Student</option>
                        <option value="3">Faculty</option>
                    </select>
                </div>

                <input type="hidden" name="serializedPrograms"
                    value="<?php echo htmlspecialchars(json_encode($programs_clean)); ?>">

                <input type="hidden" name="type"value="analysis">
                
                <button type="submit"> Get Data </button>
            </form>

            <!-- For Alumni / parent/ survey / Industry -->
            <form action="stakeholder.php" method="post" class='stakeholderForm' style="display: none;">
                <label class="heading" for="courseSelect">Name of Feedback Course:</label>
                <div class="selection">
                    <select name="courseSelect" id="courseSelect" required>
                        <option value="">Select Course</option>
                        <?php
                            foreach($stakeholderFeedbackList as $courseId => $courseName) {
                                echo "<option value='$courseId'>" . $courseName . "</td>"; 
                            }
                        ?>
                    </select>
                </div>

                <label class="heading" for="feedbackSelect">Name of Feedback :</label>
                <div class="selection">
                    <select name="feedbackSelect" id="feedbackSelect" required>4
                    <option value="">Select Feedback</option>
                    </select>
                </div>
                <button type="submit"> Get Data </button>
            </form>

        </div>
    </div>

    <script>
    
     document.querySelector('.studentform').addEventListener('submit', function() {

    let program  = document.getElementById('programSelect').value;
    let batch    = document.getElementById('batchSelect').value;
    let semester = document.getElementById('semesterSelect').value;
    let role     = document.getElementById('role').value;

    let data = {
        program: program,
        batch: batch,
        semester: semester,
        role: role
    };
    document.getElementById('selectedData').value = JSON.stringify(data);
  });

    document.getElementById('student').addEventListener("click", function () {
            const radioButton = document.getElementById(`student-radio`);
            if (radioButton) {
                radioButton.checked = true;
            }
            document.getElementsByClassName("studentform")[0].style.display = 'block';
            document.getElementsByClassName("studentform")[0].style.display = 'flex';
            document.getElementsByClassName("stakeholderForm")[0].style.display = 'none';
        })
        
        document.getElementById('alumni-industry').addEventListener("click", function () {
            const radioButton = document.getElementById(`alumni-industry-radio`);
            if (radioButton) {
                radioButton.checked = true;
            }
            console.log("inside")
            document.getElementsByClassName("studentform")[0].style.display = 'none';
            document.getElementsByClassName("stakeholderForm")[0].style.display = 'block';
            document.getElementsByClassName("stakeholderForm")[0].style.display = 'flex';
            
        })
        window.addEventListener("pageshow", function (event) {
            const alumniRadio = document.getElementById('alumni-industry-radio');
            if (alumniRadio) {
                alumniRadio.checked = false;
            }
            const studentRadio = document.getElementById('student-radio');
            if (studentRadio) {
                studentRadio.checked = true;
            }
        });
        
        document.getElementById('programSelect').addEventListener('change', function () {
            
            let selectedProgram = this.value.trim().replace(/\s+/g, ' ')
            console.log(selectedProgram)
            let batchSelect = document.getElementById('batchSelect')
            batchSelect.innerHTML = '<option value="">Select Batch</option>'

            if (selectedProgram !== '') {
                let batches = <?php echo $json; ?>;
                let programBatches = batches[selectedProgram]
                for (let batch in programBatches) {
                    batchSelect.innerHTML += '<option value="' + batch + '">' + batch + '</option>'
                }
            }
            
           
        })
        
        // document.getElementById('programSelect').addEventListener('change', function () {
        //     let selectedProgram = this.value;
        //     let batchSelect = document.getElementById('batchSelect');
        //     batchSelect.innerHTML = '<option value="">Select Batch</option>';

        //     if (selectedProgram !== '') {
        //         let batches = <?php echo json_encode($json); ?>;
        //         let programBatches = batches[selectedProgram];

        //         for (let batch in programBatches) {
        //             batchSelect.innerHTML += '<option value="' + batch + '">' + batch + '</option>';
        //         }
        //     }
        // });

        // document.getElementById('batchSelect').addEventListener('change', function () {
        //     let selectedBatch = this.value;
        //     let semesterSelect = document.getElementById('semesterSelect');
        //     semesterSelect.innerHTML = '<option value="">Select Semester</option>';

        //     if (selectedBatch !== '') {
        //         let selectedProgram = document.getElementById('programSelect').value;
        //         let batches = <?php echo json_encode($json); ?>;
        //         let semesters = batches[selectedProgram][selectedBatch];

        //         for (let semester in semesters) {
        //             semesterSelect.innerHTML += '<option value="' + semester + '">' + semester + '</option>';
        //         }
        //     }
        // });
        
        
        document.getElementById('batchSelect').addEventListener('change', function () {
            let selectedBatch = this.value
            let semesterSelect = document.getElementById('semesterSelect')
            semesterSelect.innerHTML = '<option value="">Select Semester</option>'

            if (selectedBatch !== '') {
                let selectedProgram = document.getElementById('programSelect').value.trim().replace(/\s+/g, ' ')
                 let batches = <?php echo $json; ?>;
                let semesters = batches[selectedProgram][selectedBatch]
                for (let semester in semesters) {
                    semesterSelect.innerHTML += '<option value="' + semester + '">' + semester + '</option>'
                }
            }
        })
        document.getElementById('courseSelect').addEventListener('change', function () {
            let selectedCourse = this.value;
            let feedbackSelect = document.getElementById('feedbackSelect');
            feedbackSelect.innerHTML = '<option value="">Select Feedback</option>';

            let feedbackList = <?php echo json_encode($stakeHolderFeedbacks); ?>;
            console.log("FList : ", feedbackList)
            console.log("selectedCourse", selectedCourse)
            if (feedbackSelect !== '') {
                for(let courseId in feedbackList) {
                    for(let feedbackId in feedbackList[courseId]){
                        if(courseId === selectedCourse) {
                            feedbackSelect.innerHTML += '<option value="' + feedbackId + '">' + feedbackList[courseId][feedbackId] + '</option>';
                        } else{
                            console.log("Not matched" , courseId , " ", selectedCourse)
                        }
                    }
                }
            }
        });
            
        
       
        
        
        

       

    </script>  

</body>

</html>