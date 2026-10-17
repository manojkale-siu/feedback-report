<?php

    require_once('db.php');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $courseSelect = $_POST['courseSelect'];
        $feedbackSelect = $_POST['feedbackSelect'];
    }
    
    $submittedData = [];
    $courseName = $conn->query("SELECT fullname from {$prefix}course where id = $courseSelect") -> fetch_assoc()['fullname'];
    if(strpos($courseName, 'Parent') !== false || strpos($courseName, 'Survey') !== false) {
        $isFeedbackSubmitted = $conn ->query("SELECT fc.id, fc.userid, concat(u.firstname, ' ', u.lastname) as fullName, u.username as prn, email
                                            FROM {$prefix}feedback_completed fc
                                            JOIN {$prefix}user u ON u.id = fc.userid 
                                            WHERE fc.feedback = {$feedbackSelect}");

        while($row = mysqli_fetch_assoc($isFeedbackSubmitted)) {
            $submittedData[$row['prn']][] = $row['fullName'];
            $submittedData[$row['prn']][] = $row['email'];
        }
        
    } else {
        if(strpos($courseName, "Industry") !== false) {
            $nameEmailArray = [];
            $nameQuery = $conn->query("SELECT fi.id, fi.name, fv.value as answer, fv.completed as completed from {$prefix}feedback_item fi 
                        join {$prefix}feedback_value fv on fv.item = fi.id
                        where fi.name like '%1.Name%' and fi.feedback = $feedbackSelect order by fv.completed");

            $emailQuery = $conn->query("SELECT fi.id, fi.name, fv.value as answer, fv.completed as completed from {$prefix}feedback_item fi 
                        join {$prefix}feedback_value fv on fv.item = fi.id
                        where fi.name like '%Email Id%' and fi.feedback = $feedbackSelect order by fv.completed");
            

            if($nameQuery->num_rows > 0 && $emailQuery -> num_rows > 0) {
                while($row = mysqli_fetch_assoc($nameQuery)) {
                    $nameEmailArray[$row['completed']]['name'] = $row['answer']; 
                }
                while($row = mysqli_fetch_assoc($emailQuery)) {
                    $nameEmailArray[$row['completed']]['email'] = $row['answer']; 
                }

                foreach($nameEmailArray as $completedId => $userDetails) {
                    if($userDetails['name'] == '') {
                        $submittedData['name'][$completedId] = "Name not available";
                    } else{
                        $submittedData['name'][$completedId] = $userDetails['name'];
                    }

                    if($userDetails['email'] == '') {
                        $submittedData['email'][$completedId] = "Email Not Available";
                    } else{
                        $submittedData['email'][$completedId] = $userDetails['email'];
                    }
                }

            } else{
                echo "<script>alert('No one has submitted the feedback.')</script>";
            }

        } else if(strpos($courseName, "Alumni")){
                $nameEmailArray = [];
                $nameQuery = $conn->query("SELECT fi.id, fi.name, fv.value as answer, fv.completed as completed from {$prefix}feedback_item fi 
                            join {$prefix}feedback_value fv on fv.item = fi.id
                            where fi.name like '%Name of the Alumnus%' and fi.feedback = $feedbackSelect");

                            
                $emailQuery = $conn->query("SELECT fi.id, fi.name, fv.value as answer, fv.completed as completed from {$prefix}feedback_item fi 
                            join {$prefix}feedback_value fv on fv.item = fi.id
                            where fi.name like '%Email id%' and fi.feedback = $feedbackSelect");

                if($nameQuery->num_rows > 0 && $emailQuery -> num_rows > 0) {
                    while($row = mysqli_fetch_assoc($nameQuery)) {
                        $nameEmailArray[$row['completed']]['name'] = $row['answer']; 
                    }
                    while($row = mysqli_fetch_assoc($emailQuery)) {
                        $nameEmailArray[$row['completed']]['email'] = $row['answer']; 
                    }
                    foreach($nameEmailArray as $completedId => $userDetails) {
                        if($userDetails['name'] == '') {
                            $submittedData['name'][$completedId] = "Name not available";
                        } else{
                            $submittedData['name'][$completedId] = $userDetails['name'];
                        }
    
                        if($userDetails['email'] == '') {
                            $submittedData['email'][$completedId] = "Email Not Available";
                        } else{
                            $submittedData['email'][$completedId] = $userDetails['email'];
                        }
                    }
                } else{
                    echo "<script>alert('No one has submitted the feedback.')</script>";
                }

        } else{
            echo "Something Went Wrong";
            exit;
        }

    }


    // echo "<pre>";
    // print_r($submittedData);
    // echo "</pre>";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stakeholder Feedback</title>
    <link rel="stylesheet" href="stakeholder.css">
</head>

<body>

    <div class="header">
        <?php 
            $feedbackName = $conn->query("SELECT name from {$prefix}feedback where id = $feedbackSelect") -> fetch_assoc()['name'];
            echo "<h1>" . $feedbackName . "</h1>";
            echo "<h2> Feedback Submitted Report</h2>";
        ?>

    </div>
    <?php if(count($submittedData) == 2) { ?>
        <div class="container">
            <table border='1' id='myTable'>
                <thead>
                    <tr>
                        <th>Sr. No</th>
                        <th>Full name</th>
                        <th>Email Id</th>
                        <th>Submitted Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php  
                    $sr_no=1;
                        foreach($submittedData['name'] as $key => $name) {
                            echo "<tr>";
                            echo "<td>" . $sr_no . "</td>";
                            echo "<td>" .$name . "</td>";
                            echo "<td>" .$submittedData['email'][$key] . "</td>";
                            $submittedDate = $conn->query("SELECT FROM_UNIXTIME(timemodified) AS readable_date from {$prefix}feedback_completed where id=$key") -> fetch_assoc()['readable_date'];
                            echo "<td>" .$submittedDate . "</td>";
                            echo "</tr>";
                            $sr_no++;
                        }
                    ?>
                </tbody>
            </table>
    
            <button onclick="exportTableToExcel('myTable', '<?php echo $feedbackName; ?>')">Download</button>
        </div>
    <?php } else { ?>
        <div class="container">
            <table border='1' id='myTable'>
                <thead>
                    <tr>
                        <th>Sr. No</th>
                        <th>PRN</th>
                        <th>Full Name</th>
                        <th>Email</th>
                        <th>Submitted Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $sr_no=0;
                        foreach($submittedData as $prn => $data) {
                            echo "<tr>";
                            echo "<td>" . ++$sr_no . "</td>";
                            echo "<td>" .$prn . "</td>";
                            echo "<td>" .$data[0] . "</td>";
                            echo "<td>" .$data[1] . "</td>";
                    
                            $submittedDate = $conn->query("SELECT FROM_UNIXTIME(fc.timemodified) as readabledate from {$prefix}feedback_completed fc join {$prefix}user u on u.id = fc.userid where u.username = '$prn'") -> fetch_assoc()['readabledate'];
                            echo "<td>" .$submittedDate . "</td>";
                            echo "</tr>";
                        }
                    ?>
                </tbody>
            </table>
    
            <button onclick="exportTableToExcel('myTable', '<?php echo $feedbackName; ?>')">Download</button>
        </div>
    <?php } ?>

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