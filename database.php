<?php

// Shared diagnostic switch. Keep false in production unless investigating an issue.
$debugging = false;

function feedback_report_debug(string $message, array $context = []): void {
    global $debugging;

    if (!$debugging) {
        return;
    }

    $suffix = $context === [] ? '' : ' ' . json_encode($context, JSON_UNESCAPED_SLASHES);
    error_log('[Feedback Report] ' . $message . $suffix);
}

// __DIR__ keeps this path correct whether database.php is loaded directly or from ajax/options.php.
$moodleConfigPath = __DIR__ . '/../../config.php';
if (!is_readable($moodleConfigPath)) {
    feedback_report_debug('Moodle config.php was not found.', ['path' => $moodleConfigPath]);
    http_response_code(500);
    exit($debugging ? 'Moodle config.php was not found. Check the PHP error log.' : 'Unable to load the report configuration.');
}

require_once $moodleConfigPath;

$server = $CFG->dbhost;
$dbname = $CFG->dbname;
$username = $CFG->dbuser;
$password = $CFG->dbpass;
$prefix = $CFG->prefix;

$conn = mysqli_connect($server, $username, $password, $dbname);

if (!$conn) {
    feedback_report_debug('Database connection failed.', ['mysqli_error' => mysqli_connect_error()]);
    http_response_code(500);
    exit($debugging ? 'Database connection failed. Check the PHP error log.' : 'Unable to connect to the report database.');
}

feedback_report_debug('Database connection established.');
