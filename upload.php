<?php

ini_set("max_execution_time", "0");
ini_set("max_input_time", "0");
ini_set("post_max_size", "5000M");
ini_set("upload_max_filesize", "4900M");
ini_set("memory_limit", "5500M");
ini_set("file_uploads", "10000");/**/
ini_set("session.gc_maxlifetime", "172800");
ini_set("session.cache_expire", "2880");

date_default_timezone_set("UTC");
putenv("TZ=UTC");

mb_internal_encoding("UTF-8");

header('Access-Control-Allow-Origin: http://www.1337upload.net');
header('Content-Type: application/json; charset=UTF-8');

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] == 'OPTIONS')
{
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD']))
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']))
        header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");
}

require_once __DIR__ . '/lib/qqUploadedFileForm.php';
require_once __DIR__ . '/lib/qqUploadedFileXhr.php';
require_once __DIR__ . '/lib/Uploader.php';

// Our response function
function jsonResponse($error = null, $success = null, $additionalData = [])
{
    $return = [];
    if ($error) $return['error'] = $error;
    if ($success) $return['success'] = $success;

    $return = array_merge($return, $additionalData);

    return json_encode($return);
}

// Start by making sure we have the required input variables
if ( ! isset($_POST['loginHash']) && ! isset($_GET['loginHash'])) exit(jsonResponse('Missing input variable (1).'));
if ( ! isset($_POST['HIDDEN']) && ! isset($_GET['HIDDEN']))       exit(jsonResponse('Missing input variable (2).'));

if (isset($_GET['loginHash']) && ! isset($_POST['loginHash'])) $_POST['loginHash'] = $_GET['loginHash'];
if (isset($_GET['HIDDEN']) && ! isset($_POST['HIDDEN'])) $_POST['HIDDEN'] = $_GET['HIDDEN'];

if ( ! isset($_POST['qqfile']) && ! isset($_GET['qqfile']) && empty($_FILES)) exit(jsonResponse('No file to upload.'));

// Get our configuration and database object
$config = include __DIR__ . '/settings.php';
$conn   = include __DIR__ . '/database.php';

// Make sure we're logged in
$query = $conn->prepare("SELECT leetup_users.*, leetup_ranks.filesize, leetup_ranks.rank_upLimit FROM leetup_users
                         LEFT JOIN leetup_ranks ON leetup_users.rank = leetup_ranks.id
                         WHERE leetup_users.password = :password
                         LIMIT 1");
$query->execute(['password' => $_POST['loginHash']]);

$user = $query->fetch();
if ($user === null) exit(jsonResponse('Not logged in.'));

// Get the allowed file extensions
$allowedExtensions = [];

$query = $conn->prepare("SELECT * FROM leetup_filetypes");
$query->execute();

while ($row = $query->fetch())
{
    $allowedExtensions[] = $row['extension'];
}

// Get the maximum allowed file size
$sizeLimit = $user['filesize'];

// Create the uploader and handle the upload
$uploader = new Uploader($conn, $user, $allowedExtensions, $config['reservedNames']);

try
{
    $fileName = $uploader->handleUpload($config['filePath'], intval($_POST['HIDDEN']));
    exit(jsonResponse(null, true, [
        'file' => $fileName
    ]));
}
catch (\Exception $e)
{
    exit(jsonResponse($e->getMessage()));
}