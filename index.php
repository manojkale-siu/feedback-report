<?php
 require_once ('../../config.php');
require_once "database.php";

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

$programs = $conn->query("SELECT c.id as pid ,c.name as programname from {$prefix}course_categories c where c.parent=0 and c.name not in ('Category 1','Course templates','IR Conference','VAC-HPM')");

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

if($debugging) {
    echo "<pre> <br>Programs : ";
    if ($programs && $programs->num_rows > 0) {
        $programList = [];
        while ($row = $programs->fetch_assoc()) {
            $programList[] = $row;
        }
        print_r($programList);
    } else {
        echo "No programs found or query error.";
    }
    
    echo "<br>Course Template id : ";
    print_r($courseTemplate);
    
    echo "<br>stakeholderFeedbackList : ";
    print_r($stakeholderFeedbackList);
    
    echo "<br>stakeHolderFeedbacks : ";
    print_r($stakeHolderFeedbacks);
    
    echo "</pre>";
}

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
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="index.css">
</head>

<body>

    <div class="main">
        <?php
        if($debugging) { 
        ?>
            <h1 style="color:red;">TESTING PURPOSE ONLY</h1>
        <?php
        }
        ?>
        
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
            <form action="student-report.php" method="post" class='studentform' id='myForm'>
                <label class="heading" for="programSelect">Name of Programme:</label>
                <div class="selection">
                
                
                <select name="programSelect" id="programSelect" required onchange="getSelectedBatch()">
                    <option value="">Select Program</option>
                    <?php if (!empty($programs)) { ?>
                        <?php foreach ($programs as $row) { ?>
                            <option value="<?php echo htmlspecialchars($row['pid']); ?>">
                                <?php echo htmlspecialchars($row['programname']); ?>
                            </option>
                        <?php } ?>
                    <?php } else { ?>
                        <option value="">No programs available</option>
                    <?php } ?>
                </select>

                
                    <input type="hidden" name="programLabel" id="programLabel">
                </div>

                <label class="heading" for="batchSelect">Name of Batch :</label>
                <div class="selection">
                    <select name="batchSelect" id="batchSelect" required onchange="getSelectedSemester()">
                        <option value="">Select Batch</option>
                    </select>
                    <input type="hidden" name="batchLabel" id="batchLabel">
                </div>

                <label class="heading" for="semesterSelect">Name of Semester :</label>
                <div class="selection">
                    <select name="semesterSelect" id="semesterSelect" required onchange="getSelectedCourse()" >
                        <option value="">Select Semester</option>
                    </select>
                    <input type="hidden" name="semLabel" id="semLabel">
                </div>
                <label class="heading" for="role">Select Role :</label>
                <div class="selection">
                    <select name="role" id="role" required onchange="changeActionFile()">
                        <option>Select the section</option>
                        <option value="5">Student</option>
                        <option value="3">Faculty</option>
                    </select>
                </div>

                 <input type="hidden" name="serializedPrograms" id="serializedPrograms"> 

                <input type="hidden" name="type"value="analysis">
                
                <button type="submit" id="submit"> Get Data </button>
            </form>

            <!-- For Alumni / parent/ survey / Industry -->
            <form action="stakeholder-report.php" method="post" class='stakeholderForm' style="display:none">
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
        function changeActionFile() {
    let role = document.getElementById("role").value;
    let form = document.getElementById("myForm");

    if (role === "5") {
        form.action = "student-report.php";
    } else if (role === "3") {
        form.action = "faculty-report.php";
    } else {
        form.action = "student-report.php";
    }
}

            function getSelectedBatch() {
    let programId = document.getElementById("programSelect").value;

    if (programId) {
        // AJAX request to fetch batches
        $.ajax({
            url: "ajax/options.php",  // Fetch batches
            type: "POST",
            data: { programId: programId },
            success: function(response) {
                $("#batchSelect").html(response); // Populate batch dropdown
            },
            error: function() {
                alert("Error fetching batches!");
            }
        });
    } else {
        $("#batchSelect").html("<option value=''>Select Batch</option>");
    }
}

 function getSelectedSemester() {
    let batchId = document.getElementById("batchSelect").value;

    if (batchId) {
        // AJAX request to fetch batches
        $.ajax({
            url: "ajax/options.php",  // Fetch semesters
            type: "POST",
            data: { batchId:batchId },
            success: function(response) {
                $("#semesterSelect").html(response); // Populate batch dropdown
            },
            error: function() {
                alert("Error fetching batches!");
            }
        });
    } else {
        $("#semesterSelect").html("<option value=''>Select Semester</option>");
    }
}

function getSelectedCourse() {
    let semId = document.getElementById("semesterSelect").value;
    document.getElementById("programLabel").value = programSelect.selectedOptions[0].text;
    document.getElementById("batchLabel").value = batchSelect.selectedOptions[0].text;
    document.getElementById("semLabel").value = semesterSelect.selectedOptions[0].text;

    if (semId) {
        $.ajax({
            url: "ajax/options.php",
            type: "POST",
            data: { semId: semId },
            success: function(response) {
                console.log(response);

                if (!response || response.length === 0 || response === "[]") {
                    Swal.fire({
                        icon: "warning",
                        title: "No Courses Found",
                        text: "Courses are not found in this batch.",
                        confirmButtonText: "OK"
                    });
                    document.getElementById("serializedPrograms").value = "";
                    document.getElementById("submit").disabled = true;
                } else {
                    document.getElementById("serializedPrograms").value = JSON.stringify(response);
                    document.getElementById("submit").disabled = false;
                }
            },
            error: function(error) {
                console.log(error);
                Swal.fire({
                    icon: "error",
                    title: "Oops...",
                    text: "Something went wrong while fetching courses!",
                });
            }
        });
    } else {
        Swal.fire({
            icon: "info",
            title: "Select Semester",
            text: "Please select a semester before proceeding.",
        });
    }
}


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
