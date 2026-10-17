<?php

    $isRolePresent = false;
    if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['roleid'])) {
        $isRolePresent = true;
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
</head>
<body>
    <div class="container">
        <div class="header">
            <!-- <select name="role " id="role">
                <option value="5">Student</option>
                <option value="3">Teacher</option>
            </select> -->

            <div class="headings">
                <h1><?php echo $programname; ?></h1>
                <h1><?php echo $batchname; ?></h1>
                <h1><?php echo $semestername; ?></h1>
            </div>
            

            <button onclick="exportTableToExcel('myTable', 'Consolidated - Feedback Report')">Download</button>
        </div>

        <div class="data">
            <table border='1' id="myTable">
                <thead>
                    <?php
                        echo "<tr>";
                        echo "<th> Sr.No </th>";
                        echo "<th> PRN </th>";
                        echo "<th> Full Name </th>";
                        foreach($studentFeedbackList as $courseid => $feedbacks) {
                            $courseName = $conn->query("SELECT fullname from {$prefix}course where id = $courseid") -> fetch_assoc()['fullname'];
                            if(is_array($feedbacks)) {
                                $isPresent = 0;

                                // Checking for is Faculty Feedback Curriculum is Present, where we created an Identity to know Faculty Curriculum is Present
                                foreach($feedbacks as $feedbackId => $feedbackName) {
                                    // Identity of an Array for Faculty Feedback Curriculum
                                    if(is_array($feedbackName)) 
                                        $isPresent = 1;
                                }
                                echo "<th colspan='" . (count($feedbacks) - $isPresent) . "'>" . $courseName . "</th>";
                            } else{
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
                        foreach($studentFeedbackList as $courseid => $feedbacks) {
                            if(is_array($feedbacks)) {
                                foreach($feedbacks as $feedbackId => $feedbackName) {
                                    if(!is_array($feedbackName))
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
                        foreach($studentAccessibleFeedback as $studentId => $courseDetails) {
                            $submittedCount=0;
                            $notSubmittedCount=0;
                            echo "<tr>";
                            $studentDetails = $conn->query("SELECT username, concat(firstname, ' ', lastname) as fullname from {$prefix}user where id=$studentId")->fetch_assoc();
                            echo "<td id='heading'>" . $sr_no . "</td>";
                            echo "<td id='heading'>" . $studentDetails['username'] . "</td>";
                            echo "<td id='heading'>" . $studentDetails['fullname'] . "</td>";

                            foreach($studentFeedbackList as $courseid => $feedbackListInCourse) {
                                if(isset($courseDetails[$courseid])){
                                    if(is_array($courseDetails[$courseid])) {
                                        foreach($courseDetails[$courseid] as $feedbackId => $completedStatus) {
                                            if((isset($feedbackListInCourse[$feedbackId])) && !is_array($feedbackListInCourse[$feedbackId])) {
                                                if($completedStatus == 1) {
                                                    echo "<td id='value'><img src='check.png' alt='loading' data-export='Yes'/></td>";
                                                    $submittedCount++;
                                                } else if($completedStatus === 'NA'){
                                                    echo "<td id='value'>NA</td>";
                                                } else{
                                                    echo "<td id='value'><img src='remove.png' alt='loading' data-export='No'/></td>";
                                                    $notSubmittedCount++;
                                                }
                                            }
                                        }
                                    } else{
                                        if(is_array($feedbackListInCourse)) {
                                            foreach($feedbackListInCourse as $feedbackId => $feedbackname) {
                                                if(!is_array($feedbackname))
                                                    echo "<td id='value'> NFA </td>";
                                            }
                                        } else{
                                            echo "<td id='value'>FNA</td>";
                                        }
                                    }
                                } else{
                                    if(is_array($feedbackListInCourse)) {
                                        foreach($feedbackListInCourse as $feedbackId => $feedbackname) {
                                            if(!is_array($feedbackname))
                                                echo "<td id='value'> - </td>";
                                        }
                                    } else{
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
    </script>
</body>
</html>