<?php
ob_start();
session_start();

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

if (!isset($_SESSION['logged']) || $_SESSION['logged'] != 1) {
    header("Location:" . BASE_URL . "/Dashboard/Authenticate/login.php?gotourl=" . base64_encode(BASE_URL . "/equipment_maintenance/"));
    die;
}

$id = '';
if (isset($_GET['id']) && $_GET['id'] != "") {
    $id = base64_decode($_GET['id']);
}

if (empty($id)) {
    header("Location:" . BASE_URL . "/db/index.html");
}

require_once 'api/common.php';

$pdf_html = Common::pdf_generate_em($id);


?>
