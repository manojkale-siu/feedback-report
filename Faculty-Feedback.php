<?php

    $type = '';
    if(isset($_POST['type'])) {
        $type = $_POST['type'];
    }

    if($type == 'responses') {

        $courseTemplate = $conn->query("SELECT id from {$prefix}course_categories where name like '%Course Template%'")->fetch_assoc()['id'];

        $feedbackQuestionsArray = [];

        $feedbackTemplate = $conn->query("SELECT id from {$prefix}course where fullname = 'Feedback Templates' or fullname = 'Feedback Template'");
        if($feedbackTemplate -> num_rows > 0) {
            $feedbackTemplateId = $feedbackTemplate->fetch_assoc()['id'];
            $feedbackId = $conn->query("SELECT id from {$prefix}feedback where course=$feedbackTemplateId and name like 'Faculty Feedback - Curriculum'");
            if($feedbackId -> num_rows > 0) {
                $feedbackId = $feedbackId -> fetch_assoc()['id'];
                $feedbackQuestions = $conn->query("SELECT id, name from {$prefix}feedback_item where feedback = $feedbackId");
                if($feedbackQuestions ->num_rows > 0) {
                    while($question = mysqli_fetch_assoc($feedbackQuestions)) {
                        $feedbackQuestionsArray[$question['id']] = $question['name'];
                    }
                }
            } else{
                echo "Faculty Feedback Curriculum Not Found in Feedback Template Course";
                exit;
            }
        } else{
            echo "Feedback Template Course Not Found";
            exit;
        }

    }

    // echo "<pre>";
    // print_r($courseFaculty);
    // echo "</pre>";


?>


<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Consolidated Feedback</title>
        <link rel="stylesheet" href="feedbacktable.css">
        <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
    </head>
    <body>
        <div class="container">
            <div class="header">

                <form action="main.php" method="post" id='reportTypeForm'>
                    <select name="type" id="type">
                        <option value="consolidated">Consolidated Report</option>
                        <option value="responses">Responses Report</option>
                    </select>

                    <input type="hidden" name="initialData" value="<?php echo htmlspecialchars(json_encode($initialData)); ?>">

                </form>
                
                <div class="headings">
                    <h1><?php echo $programname; ?></h1>
                    <h1><?php echo $batchname; ?></h1>
                    <h1><?php echo $semestername; ?></h1>
                    </div>
                    
                    
                    <button onclick="exportTableToExcel('myTable', '<?php echo $type; ?>' )">Download</button>
                </div>
                    
<?php  if($type == 'analysis') {?>

        <div class="data">
            <table border="1" id="myTable">
                <thead>
                    <?php
                        echo "<tr>";
                        echo "<th>PRN</th>";
                        echo "<th>Full Name</th>";
                        foreach($courseIds as $id) {
                            $courseName = $conn->query("SELECT fullname from {$prefix}course where id = {$id}") -> fetch_assoc()['fullname'];

                            echo "<th>" . $courseName . "</th>";
                        }

                        echo "<th> Submitted</th>";
                        echo "<th>Not Submitted</th>";
                        echo "</tr>";

                        echo "<tr>";
                        echo "<th></th>";
                        echo "<th></th>";
                        foreach($courseIds as $id) {

                            $facultyCurriculumId = $conn->query("SELECT id, name from {$prefix}feedback where course = $id and name like '%Faculty Feedback - Curriculum%'");
                            if($facultyCurriculumId -> num_rows > 0) {
                                while($feedback = mysqli_fetch_assoc($facultyCurriculumId)) {
                                    echo "<th>" . $feedback['name'] . "</th>";
                                }
                            } else{
                                echo "<th> No Feedback Available</th>";
                            }

                        }
                        echo "<th></th>";
                        echo "<th></th>";
                        echo "</tr>";

                    ?>
                </thead>
                <tbody>
                    <?php

                        foreach($facultyAccessibleFeedback as $facultyName => $assignedCourses) {
                            echo "<tr>";
                            $submitted = 0;
                            $notSubmitted = 0;
                            $employeeId = $conn->query("SELECT username from  {$prefix}user where concat(firstname, ' ', lastname) like '%$facultyName%'")->fetch_assoc()['username'];
                            echo "<td id='heading'>" . $employeeId . "</td>";                            
                            echo "<td id='heading'>" . $facultyName . "</td>";   
                            foreach($courseIds as $index => $Id) {
                                if(isset($assignedCourses[$Id])) {
                                    if(is_array($assignedCourses[$Id])) {
                                        foreach($assignedCourses[$Id] as $feedbackId => $status) {
                                            if($status == 1) {
                                                echo "<td id='value'> <img src='check.png' alt='loading' data-export='Yes'/> </td>";
                                                $submitted ++;
                                            } else{
                                                echo "<td id='value'> <img src='remove.png' alt='loading' data-export='No'/> </td>";
                                                $notSubmitted ++;
                                            }
                                        }
                                    } else{
                                        echo "<td id='value'>NFA</td>";
                                    }

                                } else{
                                    echo "<td id='value'> - </td>";
                                }
                            }
                            echo "<td id='value'>" . $submitted . "</td>";                         
                            echo "<td id='value'>" . $notSubmitted . "</td>";                         
                            echo "</tr>";
                        }

                    ?>
                </tbody>
            </table>
        </div>

<?php } else {?>

        <div class="data">
            <table border='1' id="myTable">
                <thead>
                    <tr>
                        <th>Course Name</th>
                        <th>Faculty Name</th>
                    <?php
                        foreach($feedbackQuestionsArray as $questionId => $questionName) {
                            echo "<th>" . $questionName . "</th>";
                        }
                    ?>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    foreach($courseFaculty as $courseId => $enrolledFaculty) {
                        $courseName = $conn->query("SELECT fullname from {$prefix}course where id = $courseId")->fetch_assoc()['fullname'];
                        echo "<tr>";
                        echo "<td rowspan='" . count($enrolledFaculty) . "' id='value'>" . $courseName . "</td>";
                        $check = false;
                        $totalRows = count($enrolledFaculty);
                        foreach($enrolledFaculty as $facultyId => $curriculumId) {

                            if($check) {
                                echo "<tr>";
                            }
                            $facultyName = $conn->query("SELECT concat(firstname, ' ', lastname) as fullName from {$prefix}user where id = $facultyId")->fetch_assoc()['fullName'];
                            echo"<td id='value'>" . $facultyName . "</td>";

                            $responses = $conn->query("SELECT fi.id as questionId, fi.name as question, fv.value from {$prefix}feedback_item fi
                                                    join {$prefix}feedback_value fv on fv.item = fi.id
                                                    join {$prefix}feedback_completed fc on fc.id = fv.completed
                                                    where fc.feedback = $curriculumId and fc.userid=$facultyId");
                            
                            if($responses -> num_rows>0) {
                                while($answer = mysqli_fetch_assoc($responses)) {
                                    switch($answer['value']) {
                                        case "1": echo "<td id='value'>" . "Strongly Agree" . "</td>"; break;
                                        case "2": echo "<td id='value'>" . "Agree" . "</td>"; break;
                                        case "3": echo "<td id='value'>" . "Neutral" . "</td>"; break;
                                        case "4": echo "<td id='value'>" . "Disagree" . "</td>"; break;
                                        case "5": echo "<td id='value'>" . "Strongly Disagree" . "</td>"; break;
                                        default : echo "<td id='value'>" . $answer['value'] . "</td>"; break;
                                    }
                                }
                            } else{
                                for ($i=0; $i <count($feedbackQuestionsArray); $i++) { 
                                    echo "<td id='value'> - </td>";
                                }
                            }

                            if($totalRows > 1) {
                                echo "</tr>";
                            }
                            $check = true;
                            $totalRows-- ;

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

        document.getElementById('type').addEventListener("change", function () {
            const type = this.value;
            console.log(type)

            document.getElementById('reportTypeForm').submit();
        })


    </script>
</body>
</html>


