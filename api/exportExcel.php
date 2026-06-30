<?php
ob_start();
session_start();

require_once $_SERVER['DOCUMENT_ROOT'] . '/common/constant.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/db/connection.php';

if (!isset($_SESSION['logged']) || $_SESSION['logged'] != 1) {
    http_response_code(403);
    exit('Unauthorized');
}

// ─── Filters (GET params, same names as the list page uses) ──────────────────
$status_filter  = isset($_GET['status_filter']) ? trim($_GET['status_filter']) : '';
$valid_statuses = ['Pending Approval', 'Approved', 'Rejected'];
$use_filter     = in_array($status_filter, $valid_statuses);

$my_list      = isset($_GET['my_list']) ? intval($_GET['my_list']) : 0;
$current_user = isset($_SESSION['EMP_ID']) ? trim($_SESSION['EMP_ID']) : '';
$use_my_list  = ($my_list === 1 && $current_user !== '');

$date_from     = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to       = isset($_GET['date_to'])   ? trim($_GET['date_to'])   : '';
$date_pattern  = '/^\d{4}-\d{2}-\d{2}$/';
$use_date_from = ($date_from !== '' && preg_match($date_pattern, $date_from));
$use_date_to   = ($date_to   !== '' && preg_match($date_pattern, $date_to));

// ─── Build WHERE ──────────────────────────────────────────────────────────────
$where_parts = [];
if ($use_filter)    $where_parts[] = "em.EQUIPMENT_STATUS = :status_filter";
if ($use_my_list)   $where_parts[] = "(em.CREATED_BY = :current_user OR em.CHECKED_BY = :current_user)";
if ($use_date_from) $where_parts[] = "em.INSPECTION_DATE >= TO_DATE(:date_from, 'YYYY-MM-DD')";
if ($use_date_to)   $where_parts[] = "em.INSPECTION_DATE <= TO_DATE(:date_to, 'YYYY-MM-DD')";
$where_clause = $where_parts ? "WHERE " . implode(" AND ", $where_parts) : "";

// ─── Query (no pagination — export all matching rows) ────────────────────────
$sql = "
    SELECT
        em.ID,
        em.EQUIPMENT,
        em.BRAND,
        em.SERIAL_NUMBER,
        em.CHASIS_NUMBER,
        em.VOLTAGE,
        em.TRUCK_WEIGHT,
        TO_CHAR(em.INSPECTION_DATE, 'DD/MM/YYYY') AS INSPECTION_DATE,
        TO_CHAR(em.INSPECTION_TIME, 'HH24:MI')    AS INSPECTION_TIME,
        em.EQUIPMENT_STATUS,
        em.REMARKS,
        (SELECT EMPLOYEE_NAME FROM EMPLOYEE_MASTER
         WHERE EMP_ID = em.CHECKED_BY  AND ROWNUM = 1) AS CHECKED_BY_NAME,
        (SELECT EMPLOYEE_NAME FROM EMPLOYEE_MASTER
         WHERE EMP_ID = em.APPROVED_BY AND ROWNUM = 1) AS APPROVED_BY_NAME,
        (SELECT EMPLOYEE_NAME FROM EMPLOYEE_MASTER
         WHERE EMP_ID = em.UPDATED_BY  AND ROWNUM = 1) AS UPDATED_BY_NAME,
        TO_CHAR(em.CREATED_AT, 'DD/MM/YYYY HH:MI AM') AS CREATED_AT,
        TO_CHAR(em.UPDATED_AT, 'DD/MM/YYYY HH:MI AM') AS UPDATED_AT
    FROM EQUIPMENT_MAINTENANCE em
    $where_clause
    ORDER BY em.ID DESC
";

$stmt = oci_parse($dbcon, $sql);
if (!$stmt) {
    $err = oci_error($dbcon);
    die("Query parse error: " . $err['message']);
}

if ($use_filter)    oci_bind_by_name($stmt, ":status_filter", $status_filter);
if ($use_my_list)   oci_bind_by_name($stmt, ":current_user",  $current_user);
if ($use_date_from) oci_bind_by_name($stmt, ":date_from",     $date_from);
if ($use_date_to)   oci_bind_by_name($stmt, ":date_to",       $date_to);

$exec = oci_execute($stmt);
if (!$exec) {
    $err = oci_error($stmt);
    die("Query execute error: " . $err['message']);
}

$rows = [];
while (($row = oci_fetch_array($stmt, OCI_ASSOC + OCI_RETURN_NULLS + OCI_RETURN_LOBS)) !== false) {
    $rows[] = $row;
}
oci_free_statement($stmt);

// ─── PhpSpreadsheet ───────────────────────────────────────────────────────────
require_once $_SERVER['DOCUMENT_ROOT'] . '/common/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Font;

$spreadsheet = new Spreadsheet();
$sheet       = $spreadsheet->getActiveSheet();
$sheet->setTitle('Equipment Maintenance');

// ─── Header row ───────────────────────────────────────────────────────────────
$headers = [
    'A' => 'ID',
    'B' => 'Equipment',
    'C' => 'Brand',
    'D' => 'Serial No',
    'E' => 'Chasis No',
    'F' => 'Voltage',
    'G' => 'Truck Weight (kg)',
    'H' => 'Inspection Date',
    'I' => 'Inspection Time',
    'J' => 'Status',
    'K' => 'Checked By',
    'L' => 'Approved By',
    'M' => 'Updated By',
    'N' => 'Remarks',
    'O' => 'Created At',
    'P' => 'Updated At',
];

foreach ($headers as $col => $label) {
    $sheet->setCellValue($col . '1', $label);
}

// Header style — dark blue background, white bold text, centered
$headerStyle = [
    'font' => [
        'bold'  => true,
        'color' => ['argb' => 'FFFFFFFF'],
        'name'  => 'Arial',
        'size'  => 10,
    ],
    'fill' => [
        'fillType'   => Fill::FILL_SOLID,
        'startColor' => ['argb' => 'FF1A56A0'],
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical'   => Alignment::VERTICAL_CENTER,
        'wrapText'   => false,
    ],
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color'       => ['argb' => 'FFFFFFFF'],
        ],
    ],
];
$sheet->getStyle('A1:P1')->applyFromArray($headerStyle);
$sheet->getRowDimension(1)->setRowHeight(22);

// ─── Data rows ────────────────────────────────────────────────────────────────
$dataFields = [
    'A' => 'ID',
    'B' => 'EQUIPMENT',
    'C' => 'BRAND',
    'D' => 'SERIAL_NUMBER',
    'E' => 'CHASIS_NUMBER',
    'F' => 'VOLTAGE',
    'G' => 'TRUCK_WEIGHT',
    'H' => 'INSPECTION_DATE',
    'I' => 'INSPECTION_TIME',
    'J' => 'EQUIPMENT_STATUS',
    'K' => 'CHECKED_BY_NAME',
    'L' => 'APPROVED_BY_NAME',
    'M' => 'UPDATED_BY_NAME',
    'N' => 'REMARKS',
    'O' => 'CREATED_AT',
    'P' => 'UPDATED_AT',
];

// Status color map
$statusColors = [
    'Approved'         => ['bg' => 'FFD1E7DD', 'font' => 'FF0A3622'],
    'Rejected'         => ['bg' => 'FFF8D7DA', 'font' => 'FF58151C'],
    'Pending Approval' => ['bg' => 'FFFFF3CD', 'font' => 'FF856404'],
];

$rowNum = 2;
foreach ($rows as $row) {
    foreach ($dataFields as $col => $field) {
        $value = is_object($row[$field]) ? $row[$field]->load() : ($row[$field] ?? '');
        $sheet->setCellValue($col . $rowNum, $value);
    }

    // Zebra-stripe rows
    $bgColor = ($rowNum % 2 === 0) ? 'FFF0F4FA' : 'FFFFFFFF';

    $rowStyle = [
        'fill' => [
            'fillType'   => Fill::FILL_SOLID,
            'startColor' => ['argb' => $bgColor],
        ],
        'font'      => ['name' => 'Arial', 'size' => 10],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        'borders'   => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color'       => ['argb' => 'FFD0D7E0'],
            ],
        ],
    ];
    $sheet->getStyle('A' . $rowNum . ':P' . $rowNum)->applyFromArray($rowStyle);

    // Status cell — coloured pill style (cell background)
    $status = $row['EQUIPMENT_STATUS'] ?? '';
    if (isset($statusColors[$status])) {
        $sc = $statusColors[$status];
        $sheet->getStyle('J' . $rowNum)->applyFromArray([
            'fill' => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['argb' => $sc['bg']],
            ],
            'font' => [
                'bold'  => true,
                'color' => ['argb' => $sc['font']],
            ],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
    }

    $sheet->getRowDimension($rowNum)->setRowHeight(18);
    $rowNum++;
}

// ─── Column widths ────────────────────────────────────────────────────────────
$colWidths = [
    'A' => 8,  'B' => 18, 'C' => 14, 'D' => 16, 'E' => 16,
    'F' => 10, 'G' => 18, 'H' => 16, 'I' => 14, 'J' => 18,
    'K' => 22, 'L' => 22, 'M' => 22, 'N' => 30, 'O' => 22, 'P' => 22,
];
foreach ($colWidths as $col => $width) {
    $sheet->getColumnDimension($col)->setWidth($width);
}

// Freeze the header row
$sheet->freezePane('A2');

// ─── Output ───────────────────────────────────────────────────────────────────
$filename = 'Equipment_Maintenance_' . date('Ymd_His') . '.xlsx';

ob_end_clean();
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;