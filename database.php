<?php
require_once ('../../config.php');


$server = $CFG->dbhost;
$dbname = $CFG->dbname;
$username = $CFG->dbuser;
$password = $CFG->dbpass;
$prefix = $CFG->prefix;

$debugging = false; // make it false when live other wise make it false, so it will enable and disable all the printed query visible and hide vice versa. TRUE -> visible , FALSE -> HIDE

$conn = mysqli_connect($server, $username, $password, $dbname);

if (!$conn) {
    die("Connection failed" . "");
}

?>