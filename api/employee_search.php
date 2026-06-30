<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/db/connection.php';
require_once 'common.php';

header('Content-Type: application/json');

$term = isset($_GET['q']) ? trim($_GET['q']) : '';
$data = [];

try {
    if ($term !== '') {
        $employee_list = Common::employee_list($term);

        foreach ($employee_list as $row) {
            $data[] = [
                "id"   => $row['EMP_ID'],
                "text" => $row['EMPLOYEE_NAME']
            ];
        }
    }
    echo json_encode([
        "results" => $data
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        "results" => [],
        "error"   => $e->getMessage()
    ]);
}
