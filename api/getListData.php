<?php
ob_start();
session_start();

header('Content-Type: application/json; charset=utf-8');

require_once $_SERVER['DOCUMENT_ROOT'] . '/common/constant.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/db/connection.php';

$draw             = isset($_POST['draw'])   ? intval($_POST['draw'])   : 1;
$start            = isset($_POST['start'])  ? intval($_POST['start'])  : 0;
$length           = isset($_POST['length']) ? intval($_POST['length']) : 10;
$orderColumnIndex = intval($_POST['order'][0]['column'] ?? 0);
$orderDir         = ($_POST['order'][0]['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

// Status filter — whitelist validated
$status_filter  = isset($_POST['status_filter']) ? trim($_POST['status_filter']) : '';
$valid_statuses = ['Pending Approval', 'Approved', 'Rejected'];
$use_filter     = in_array($status_filter, $valid_statuses);

// My List filter
$my_list      = isset($_POST['my_list']) ? intval($_POST['my_list']) : 0;
$current_user = isset($_SESSION['EMP_ID']) ? trim($_SESSION['EMP_ID']) : '';
$use_my_list  = ($my_list === 1 && $current_user !== '');

// Date range filter — validate format YYYY-MM-DD
$date_from     = isset($_POST['date_from']) ? trim($_POST['date_from']) : '';
$date_to       = isset($_POST['date_to'])   ? trim($_POST['date_to'])   : '';
$date_pattern  = '/^\d{4}-\d{2}-\d{2}$/';
$use_date_from = ($date_from !== '' && preg_match($date_pattern, $date_from));
$use_date_to   = ($date_to   !== '' && preg_match($date_pattern, $date_to));

$columnMapping = [
    0 => "em.ID",
    1 => "em.EQUIPMENT",
    2 => "em.BRAND",
    3 => "em.SERIAL_NUMBER",
    4 => "em.CHASIS_NUMBER",
    5 => "em.INSPECTION_DATE",
    6 => "em.INSPECTION_TIME",
    7 => "em.CHECKED_BY",
    8 => "em.EQUIPMENT_STATUS",
];

$orderColumn = $columnMapping[$orderColumnIndex] ?? "em.ID";

// ─── Build WHERE parts ────────────────────────────────────────────────────────
$where_parts = [];
if ($use_filter)    $where_parts[] = "em.EQUIPMENT_STATUS = :status_filter";
if ($use_my_list)   $where_parts[] = "(em.CREATED_BY = :current_user OR em.CHECKED_BY = :current_user)";
if ($use_date_from) $where_parts[] = "em.INSPECTION_DATE >= TO_DATE(:date_from, 'YYYY-MM-DD')";
if ($use_date_to)   $where_parts[] = "em.INSPECTION_DATE <= TO_DATE(:date_to, 'YYYY-MM-DD')";
$where_clause = $where_parts ? "WHERE " . implode(" AND ", $where_parts) : "";

// ─── Helper to bind all active filters onto a statement ──────────────────────
function bindFilters($stmt, $use_filter, $status_filter, $use_my_list, $current_user, $use_date_from, $date_from, $use_date_to, $date_to) {
    if ($use_filter)    oci_bind_by_name($stmt, ":status_filter", $status_filter);
    if ($use_my_list)   oci_bind_by_name($stmt, ":current_user",  $current_user);
    if ($use_date_from) oci_bind_by_name($stmt, ":date_from",     $date_from);
    if ($use_date_to)   oci_bind_by_name($stmt, ":date_to",       $date_to);
}

// ─── Total count ──────────────────────────────────────────────────────────────
// Re-build where clause without alias prefix for plain count query
$count_parts = [];
if ($use_filter)    $count_parts[] = "EQUIPMENT_STATUS = :status_filter";
if ($use_my_list)   $count_parts[] = "(CREATED_BY = :current_user OR CHECKED_BY = :current_user)";
if ($use_date_from) $count_parts[] = "INSPECTION_DATE >= TO_DATE(:date_from, 'YYYY-MM-DD')";
if ($use_date_to)   $count_parts[] = "INSPECTION_DATE <= TO_DATE(:date_to, 'YYYY-MM-DD')";
$count_where = $count_parts ? "WHERE " . implode(" AND ", $count_parts) : "";

$count_sql  = "SELECT COUNT(*) AS TOTAL_COUNT FROM EQUIPMENT_MAINTENANCE $count_where";
$count_stmt = oci_parse($dbcon, $count_sql);
bindFilters($count_stmt, $use_filter, $status_filter, $use_my_list, $current_user, $use_date_from, $date_from, $use_date_to, $date_to);

oci_execute($count_stmt);
oci_fetch($count_stmt);
$totalRecords = oci_result($count_stmt, "TOTAL_COUNT");
oci_free_statement($count_stmt);

// ─── Paginated query ──────────────────────────────────────────────────────────
$paginated_query = "
    SELECT * FROM (
        SELECT
            em.ID,
            em.EQUIPMENT,
            em.BRAND,
            em.SERIAL_NUMBER,
            em.CHASIS_NUMBER,
            TO_CHAR(em.INSPECTION_DATE, 'DD/MM/YYYY')    AS INSPECTION_DATE,
            TO_CHAR(em.INSPECTION_TIME, 'HH24:MI')       AS INSPECTION_TIME,
            em.EQUIPMENT_STATUS,
            em.CHECKED_BY,
            em.APPROVED_BY,
            em.UPDATED_BY,
            (SELECT EMPLOYEE_NAME FROM EMPLOYEE_MASTER
             WHERE EMP_ID = em.CHECKED_BY  AND ROWNUM = 1) AS CHECKED_BY_NAME,
            (SELECT EMPLOYEE_NAME FROM EMPLOYEE_MASTER
             WHERE EMP_ID = em.APPROVED_BY AND ROWNUM = 1) AS APPROVED_BY_NAME,
            (SELECT EMPLOYEE_NAME FROM EMPLOYEE_MASTER
             WHERE EMP_ID = em.UPDATED_BY  AND ROWNUM = 1) AS UPDATED_BY_NAME,
            ROW_NUMBER() OVER (ORDER BY $orderColumn $orderDir) AS RN
        FROM EQUIPMENT_MAINTENANCE em
        $where_clause
    )
    WHERE RN BETWEEN :startrow + 1 AND :endrow
    ORDER BY RN
";

$endrow   = $start + $length;
$startrow = $start;

$stmt = oci_parse($dbcon, $paginated_query);
if (!$stmt) {
    $err = oci_error($dbcon);
    echo json_encode(['error' => $err['message']]);
    exit;
}

oci_bind_by_name($stmt, ":startrow", $startrow);
oci_bind_by_name($stmt, ":endrow",   $endrow);
bindFilters($stmt, $use_filter, $status_filter, $use_my_list, $current_user, $use_date_from, $date_from, $use_date_to, $date_to);

$exec = oci_execute($stmt);
if (!$exec) {
    $err = oci_error($stmt);
    echo json_encode(['error' => $err['message']]);
    exit;
}

// ─── Status badge ─────────────────────────────────────────────────────────────
function statusBadge($status) {
    $map = [
        'Pending Approval' => '#ffc107',  // amber/yellow
        'Approved'         => '#28a745',  // green
        'Rejected'         => '#fd7e14',  // orange
    ];

    $bg = $map[$status] ?? '#6c757d';

            // 'Draft': ['#6c757d', '#fff', '#545b62'],
            // 'Pending PIC Assignment': ['#fd7e14', '#fff', '#dc6502'],
            // 'Pending with PIC': ['#ffc107', '#000', '#e0a800'],
            // 'Investigation done by PIC — Post review pending': ['#6610f2', '#fff', '#520dc2'],
            // 'Pending for post review': ['#6610f2', '#fff', '#520dc2'],
            // 'Post Review Pending': ['#6610f2', '#fff', '#520dc2'],
            // 'Pending with PIC for Corrective action': ['#ff9800', '#000', '#e68900'],
            // 'Pending with Admin': ['#17a2b8', '#fff', '#138496'],
            // 'Incident Closed': ['#28a745', '#fff', '#1e7e34'],
            // 'Completed': ['#28a745', '#fff', '#1e7e34']

    $s = $map[$status] ?? [
        'color'  => '#333',
        'bg'     => '#e2e3e5',
        'border' => '#adb5bd',
    ];

    $style = "display:inline-block;"
           . "padding:5px 14px;"
           . "border-radius:10px;"
           . "border-color: #000000"
           . "font-size:12px;"
           . "font-weight:600;"
           . "color:#fff;"
           . "background:{$bg};"
           . "white-space:nowrap;";

    return "<span style='{$style}'>" . htmlspecialchars($status ?? '') . "</span>";
}

// ─── Build results ────────────────────────────────────────────────────────────
$results = [];
while (($row = oci_fetch_array($stmt, OCI_ASSOC + OCI_RETURN_NULLS)) !== false) {

    $encodedId = base64_encode($row['ID']);
    $status    = $row['EQUIPMENT_STATUS'];
    $locked    = in_array($status, ['Approved', 'Rejected']);

    // $btn = "display:inline-block;"
    //      . "padding:4px 12px;"
    //      . "border-radius:4px;"
    //      . "font-size:12px;"
    //      . "font-weight:600;"
    //      . "color:#fff;"
    //      . "text-decoration:none;"
    //      . "margin-right:4px;";

    // $links = "<a href='add.php?mode=view&id={$encodedId}' "
    //        . "style='{$btn}background:#0d6efd;'>View</a>";


    $links = "<a href='add.php?mode=view&id={$encodedId}' "
       . "class='btn btn-sm btn-info me-4' "
       . "title='View details' "
       . "aria-label='View details'>"
       . "<i class='fa-solid fa-eye' aria-hidden='true'></i>VIEW"
       . "</a>";

    //            $view_link = "<a href='incident_form.php?id=" . urlencode(Helpers::encodeId($incident_id)) . "&view=" . urlencode(Helpers::encodeId('1')) . "' class='btn btn-sm btn-info' title='View incident details' aria-label='View incident details'><i class='fa-solid fa-eye' aria-hidden='true' style='margin-right:6px;'></i>VIEW</a>";
    // $near_miss_close_link = "<a href='incident_form.php?id=" . urlencode(Helpers::encodeId($incident_id)) . "' class='btn btn-sm btn-warning' title='Edit and close near miss incident' aria-label='Edit and close near miss incident'><i class='fa-solid fa-edit' aria-hidden='true' style='margin-right:6px;'></i>EDIT/CLOSE</a>";
    // $edit_link = "<a href='incident_form.php?id=" . urlencode(Helpers::encodeId($incident_id)) . "#section2' class='btn btn-sm btn-info' title='View or edit investigation (Section 2)' aria-label='View or edit investigation (Section 2)'><i class='fa-solid fa-clipboard-check' aria-hidden='true' style='margin-right:6px;'></i>EDIT INVESTIGATION</a>";
    // $pdf_endpoint = $is_near_miss ? 'api/generate_pdf_near_miss.php' : 'api/generate_pdf.php';
    // $pdf_link  = "<a href='" . $pdf_endpoint . "?id=" . urlencode(Helpers::encodeId($incident_id)) . "' target='_blank' class='btn btn-sm btn-danger' title='Open incident PDF report' aria-label='Open incident PDF report'><i class='fa-solid fa-file-pdf' aria-hidden='true' style='margin-right:6px;'></i>PDF</a>";

    if (!$locked) {
    $links .= "<a href='add.php?mode=edit&id={$encodedId}' "
            . "class='btn btn-sm btn-secondary me-4' "
            . "title='Edit details' "
            . "aria-label='Edit details'>"
            . "<i class='fa-solid fa-pen-to-square' aria-hidden='true'></i>EDIT"
            . "</a>";

    $links .= "<a href='add.php?mode=approve&id={$encodedId}' "
            . "class='btn btn-sm btn-success me-4' "
            . "title='Approve' "
            . "aria-label='Approve'>"
            . "<i class='fa-solid fa-circle-check' aria-hidden='true'></i>APPROVE"
            . "</a>";
}

    $results[] = [
        "ID"               => $row['ID'],
        "EQUIPMENT"        => htmlspecialchars($row['EQUIPMENT']        ?? ''),
        "BRAND"            => htmlspecialchars($row['BRAND']            ?? ''),
        "SERIAL_NUMBER"    => htmlspecialchars($row['SERIAL_NUMBER']    ?? ''),
        "CHASIS_NUMBER"    => htmlspecialchars($row['CHASIS_NUMBER']    ?? ''),
        "INSPECTION_DATE"  => htmlspecialchars($row['INSPECTION_DATE']  ?? ''),
        "INSPECTION_TIME"  => htmlspecialchars($row['INSPECTION_TIME']  ?? ''),
        "CHECKED_BY_NAME"  => htmlspecialchars($row['CHECKED_BY_NAME']  ?? ''),
        "APPROVED_BY_NAME" => htmlspecialchars($row['APPROVED_BY_NAME'] ?? ''),
        "UPDATED_BY_NAME"  => htmlspecialchars($row['UPDATED_BY_NAME']  ?? ''),
        "EQUIPMENT_STATUS" => statusBadge($status),
        "ACTION"           => $links,
    ];
}

oci_free_statement($stmt);

echo json_encode([
    "draw"            => $draw,
    "recordsTotal"    => $totalRecords,
    "recordsFiltered" => $totalRecords,
    "data"            => $results,
]);
exit;