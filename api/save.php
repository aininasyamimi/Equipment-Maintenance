<?php
ob_start();
session_start();

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');

require_once $_SERVER['DOCUMENT_ROOT'] . '/common/constant.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/db/connection.php';

$response = [
    'success' => false,
    'message' => 'An unknown error occurred.',
    'errors'  => []
];

ob_clean();

if (!isset($_SESSION['logged']) || $_SESSION['logged'] != 1) {
    $response['message'] = 'User not authenticated.';
    echo json_encode($response);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response['message'] = 'Invalid request method.';
    echo json_encode($response);
    exit;
}

try {

    $mode            = $_POST['mode'] ?? 'add';
    $record_id       = intval($_POST['record_id'] ?? 0);
    $current_user_id = $_SESSION['EMP_ID'] ?? '';

    // =========================================
    // ===== APPROVE / REJECT MODE =============
    // =========================================
    if ($mode === 'approve' || $mode === 'reject') {

        if ($record_id <= 0) throw new Exception("Invalid record ID.");

        // Load the record to verify the current user is the CHECKED_BY person
        $chk_sql  = "SELECT CHECKED_BY, EQUIPMENT_STATUS FROM EQUIPMENT_MAINTENANCE WHERE ID = :id";
        $chk_stmt = oci_parse($dbcon, $chk_sql);
        if (!$chk_stmt) { $err = oci_error($dbcon); throw new Exception("Parse failed: " . $err['message']); }
        oci_bind_by_name($chk_stmt, ":id", $record_id);
        oci_execute($chk_stmt);
        $chk_row = oci_fetch_array($chk_stmt, OCI_ASSOC);
        oci_free_statement($chk_stmt);

        if (!$chk_row) {
            $response['message'] = 'Record not found.';
            echo json_encode($response);
            exit;
        }

        // Only the CHECKED_BY person can approve or reject
        if ($current_user_id != $chk_row['CHECKED_BY']) {
            $response['message'] = 'Only the assigned checker can approve or reject this record.';
            echo json_encode($response);
            exit;
        }

        // Prevent double-approving/rejecting a locked record
        $locked_statuses = ['Approved', 'Rejected'];
        if (in_array($chk_row['EQUIPMENT_STATUS'], $locked_statuses)) {
            $response['message'] = 'This record has already been ' . strtolower($chk_row['EQUIPMENT_STATUS']) . ' and cannot be changed.';
            echo json_encode($response);
            exit;
        }

        if ($mode === 'approve') {

            $new_status = 'Approved';
            $upd_sql    = "UPDATE EQUIPMENT_MAINTENANCE SET
                            EQUIPMENT_STATUS  = :status,
                            APPROVED_BY       = :approved_by,
                            REJECTION_REASON  = NULL,
                            UPDATED_AT        = SYSDATE
                           WHERE ID = :id";

            $upd_stmt = oci_parse($dbcon, $upd_sql);
            if (!$upd_stmt) { $err = oci_error($dbcon); throw new Exception("Parse failed: " . $err['message']); }

            oci_bind_by_name($upd_stmt, ":status",      $new_status);
            oci_bind_by_name($upd_stmt, ":approved_by", $current_user_id);
            oci_bind_by_name($upd_stmt, ":id",          $record_id);

        } else {

            // Reject — rejection reason is required
            $rejection_reason = trim($_POST['rejection_reason'] ?? '');
            if ($rejection_reason === '') {
                $response['message'] = 'Rejection reason is required.';
                echo json_encode($response);
                exit;
            }

            $new_status = 'Rejected';
            $upd_sql    = "UPDATE EQUIPMENT_MAINTENANCE SET
                            EQUIPMENT_STATUS  = :status,
                            APPROVED_BY       = :approved_by,
                            REJECTION_REASON  = :rejection_reason,
                            UPDATED_AT        = SYSDATE
                           WHERE ID = :id";

            $upd_stmt = oci_parse($dbcon, $upd_sql);
            if (!$upd_stmt) { $err = oci_error($dbcon); throw new Exception("Parse failed: " . $err['message']); }

            oci_bind_by_name($upd_stmt, ":status",           $new_status);
            oci_bind_by_name($upd_stmt, ":approved_by",        $current_user_id);
            oci_bind_by_name($upd_stmt, ":rejection_reason", $rejection_reason);
            oci_bind_by_name($upd_stmt, ":id",               $record_id);
        }

        if (!oci_execute($upd_stmt, OCI_COMMIT_ON_SUCCESS)) {
            $err = oci_error($upd_stmt);
            throw new Exception("Status update failed: " . $err['message']);
        }
        oci_free_statement($upd_stmt);

        $response['success'] = true;
        $response['message'] = ($mode === 'approve') ? 'Record approved successfully.' : 'Record rejected.';
        echo json_encode($response);
        exit;
    }

    // =========================================
    // ===== ADD / EDIT MODE ===================
    // =========================================

    $equipment       = trim($_POST['equipment'] ?? '');
    $brand           = trim($_POST['brand'] ?? '');
    $voltage         = (isset($_POST['voltage']) && $_POST['voltage'] !== '') ? floatval($_POST['voltage']) : null;
    $serial_number   = trim($_POST['serial_number'] ?? '');
    $chasis_number   = trim($_POST['chasis_number'] ?? '');
    $truck_weight    = (isset($_POST['truck_weight']) && $_POST['truck_weight'] !== '') ? floatval($_POST['truck_weight']) : null;
    $inspection_date = trim($_POST['date'] ?? '');
    $inspection_time = trim($_POST['time'] ?? '');
    $remarks         = trim($_POST['remark'] ?? '');
    $checked_by      = trim($_POST['checked_by'] ?? '');
    $equipment_status = 'Pending Approval';

    $checklist_keys = [
        'CHECK_BATTERY_WATER','CHECK_HYDRAULIC_OIL','CHECK_HYDRAULIC_HOSE',
        'CHECK_BRAKE_OIL','CHECK_BRAKE_CONDITIONS','CHECK_HAND_BRAKE',
        'PADDLE_BRAKE_MOVING','CHECK_ALL_TYRES','CHECK_LOAD_BACK',
        'CHECK_FORK_CONDITION','APPLY_GREASE',
    ];

    $checklist = [];
    foreach ($checklist_keys as $key) {
        $val = isset($_POST[$key]) ? trim($_POST[$key]) : '';
        $checklist[$key] = in_array($val, ['YES', 'NO']) ? $val : '';
    }

    // Validation
    if ($equipment === '')       $response['errors'][] = "Equipment is required.";
    if ($brand === '')           $response['errors'][] = "Brand is required.";
    if ($serial_number === '')   $response['errors'][] = "Serial Number is required.";
    if ($chasis_number === '')   $response['errors'][] = "Chasis Number is required.";
    if ($voltage === null)       $response['errors'][] = "Voltage is required.";
    if ($truck_weight === null)  $response['errors'][] = "Truck Weight is required.";
    if ($inspection_date === '') $response['errors'][] = "Date is required.";
    if ($inspection_time === '') $response['errors'][] = "Time is required.";
    if ($remarks === '')         $response['errors'][] = "Remarks is required.";
    if ($checked_by === '')      $response['errors'][] = "Checked By is required.";

    if (!empty($response['errors'])) {
        $response['message'] = "Validation failed.";
        echo json_encode($response);
        exit;
    }

    if ($mode === 'add') {

        $new_id = 0;

        $insert_sql = "INSERT INTO EQUIPMENT_MAINTENANCE (
            EQUIPMENT, BRAND, VOLTAGE, SERIAL_NUMBER, CHASIS_NUMBER,
            TRUCK_WEIGHT, INSPECTION_DATE, INSPECTION_TIME, REMARKS,
            CHECKED_BY, CREATED_AT, UPDATED_AT, EQUIPMENT_STATUS, CREATED_BY
        ) VALUES (
            :equipment, :brand, :voltage, :serial_number, :chasis_number,
            :truck_weight,
            TO_DATE(:inspection_date, 'YYYY-MM-DD'),
            TO_TIMESTAMP(:inspection_time, 'HH24:MI'),
            :remarks,
            :checked_by, SYSDATE, SYSDATE,
            :equipment_status,
            :created_by
        ) RETURNING ID INTO :new_id";

        $stmt = oci_parse($dbcon, $insert_sql);
        if (!$stmt) { $err = oci_error($dbcon); throw new Exception("Parse failed: " . $err['message']); }

        oci_bind_by_name($stmt, ":equipment",        $equipment);
        oci_bind_by_name($stmt, ":brand",            $brand);
        oci_bind_by_name($stmt, ":voltage",          $voltage);
        oci_bind_by_name($stmt, ":serial_number",    $serial_number);
        oci_bind_by_name($stmt, ":chasis_number",    $chasis_number);
        oci_bind_by_name($stmt, ":truck_weight",     $truck_weight);
        oci_bind_by_name($stmt, ":inspection_date",  $inspection_date);
        oci_bind_by_name($stmt, ":inspection_time",  $inspection_time);
        oci_bind_by_name($stmt, ":remarks",          $remarks);
        oci_bind_by_name($stmt, ":checked_by",       $checked_by);
        oci_bind_by_name($stmt, ":equipment_status", $equipment_status);
        oci_bind_by_name($stmt, ":created_by",       $_SESSION['EMP_ID']);
        oci_bind_by_name($stmt, ":new_id",           $new_id, -1, SQLT_INT);

        if (!oci_execute($stmt, OCI_NO_AUTO_COMMIT)) { $err = oci_error($stmt); throw new Exception("Insert failed: " . $err['message']); }
        oci_free_statement($stmt);
        $master_id = $new_id;

    } else {

        if ($record_id <= 0) throw new Exception("Invalid record ID for update.");

        // Permission + lock check
        $check_sql  = "SELECT CREATED_BY, CHECKED_BY, EQUIPMENT_STATUS FROM EQUIPMENT_MAINTENANCE WHERE ID = :record_id";
        $stmt_check = oci_parse($dbcon, $check_sql);
        if (!$stmt_check) { $err = oci_error($dbcon); throw new Exception("Parse failed: " . $err['message']); }
        oci_bind_by_name($stmt_check, ":record_id", $record_id);
        oci_execute($stmt_check);
        $perm_row = oci_fetch_array($stmt_check, OCI_ASSOC);
        oci_free_statement($stmt_check);

        if (!$perm_row) {
            $response['message'] = 'Record not found.';
            echo json_encode($response);
            exit;
        }

        // Block editing locked records
        if (in_array($perm_row['EQUIPMENT_STATUS'], ['Approved', 'Rejected'])) {
            $response['message'] = 'This record has been ' . strtolower($perm_row['EQUIPMENT_STATUS']) . ' and can no longer be edited.';
            echo json_encode($response);
            exit;
        }

        if ($current_user_id != $perm_row['CREATED_BY'] && $current_user_id != $perm_row['CHECKED_BY']) {
            $response['message'] = 'You do not have permission to edit this record.';
            echo json_encode($response);
            exit;
        }

        $update_sql = "UPDATE EQUIPMENT_MAINTENANCE SET
            EQUIPMENT        = :equipment,
            BRAND            = :brand,
            VOLTAGE          = :voltage,
            SERIAL_NUMBER    = :serial_number,
            CHASIS_NUMBER    = :chasis_number,
            TRUCK_WEIGHT     = :truck_weight,
            INSPECTION_DATE  = TO_DATE(:inspection_date, 'YYYY-MM-DD'),
            INSPECTION_TIME  = TO_TIMESTAMP(:inspection_time, 'HH24:MI'),
            REMARKS          = :remarks,
            CHECKED_BY       = :checked_by,
            UPDATED_AT       = SYSDATE,
            UPDATED_BY       = :updated_by,
            EQUIPMENT_STATUS = :equipment_status
        WHERE ID = :record_id";

        $stmt = oci_parse($dbcon, $update_sql);
        if (!$stmt) { $err = oci_error($dbcon); throw new Exception("Update parse failed: " . $err['message']); }

        oci_bind_by_name($stmt, ":equipment",        $equipment);
        oci_bind_by_name($stmt, ":brand",            $brand);
        oci_bind_by_name($stmt, ":voltage",          $voltage);
        oci_bind_by_name($stmt, ":serial_number",    $serial_number);
        oci_bind_by_name($stmt, ":chasis_number",    $chasis_number);
        oci_bind_by_name($stmt, ":truck_weight",     $truck_weight);
        oci_bind_by_name($stmt, ":inspection_date",  $inspection_date);
        oci_bind_by_name($stmt, ":inspection_time",  $inspection_time);
        oci_bind_by_name($stmt, ":remarks",          $remarks);
        oci_bind_by_name($stmt, ":checked_by",       $checked_by);
        oci_bind_by_name($stmt, ":record_id",        $record_id);
        oci_bind_by_name($stmt, ":equipment_status", $equipment_status);
        oci_bind_by_name($stmt, ":updated_by", $current_user_id);

        if (!oci_execute($stmt, OCI_NO_AUTO_COMMIT)) { $err = oci_error($stmt); throw new Exception("Update failed: " . $err['message']); }
        oci_free_statement($stmt);
        $master_id = $record_id;

        $del = oci_parse($dbcon, "DELETE FROM EQUIPMENT_MAINTENANCE_CHECKLIST WHERE MASTER_ID = :id");
        if (!$del) { $err = oci_error($dbcon); throw new Exception("Delete parse failed: " . $err['message']); }
        oci_bind_by_name($del, ":id", $master_id);
        oci_execute($del, OCI_NO_AUTO_COMMIT);
        oci_free_statement($del);
    }

    // Insert checklist
    $cl_sql = "INSERT INTO EQUIPMENT_MAINTENANCE_CHECKLIST (
        MASTER_ID,
        CHECK_BATTERY_WATER, CHECK_HYDRAULIC_OIL, CHECK_HYDRAULIC_HOSE,
        CHECK_BRAKE_OIL, CHECK_BRAKE_CONDITIONS, CHECK_HAND_BRAKE,
        PADDLE_BRAKE_MOVING, CHECK_ALL_TYRES, CHECK_LOAD_BACK,
        CHECK_FORK_CONDITION, APPLY_GREASE, CREATED_AT
    ) VALUES (
        :master_id,
        :a, :b, :c, :d, :e, :f, :g, :h, :i, :j, :k,
        SYSDATE
    )";

    $stmt = oci_parse($dbcon, $cl_sql);
    if (!$stmt) { $err = oci_error($dbcon); throw new Exception("Checklist parse failed: " . $err['message']); }

    oci_bind_by_name($stmt, ":master_id", $master_id);
    oci_bind_by_name($stmt, ":a", $checklist['CHECK_BATTERY_WATER']);
    oci_bind_by_name($stmt, ":b", $checklist['CHECK_HYDRAULIC_OIL']);
    oci_bind_by_name($stmt, ":c", $checklist['CHECK_HYDRAULIC_HOSE']);
    oci_bind_by_name($stmt, ":d", $checklist['CHECK_BRAKE_OIL']);
    oci_bind_by_name($stmt, ":e", $checklist['CHECK_BRAKE_CONDITIONS']);
    oci_bind_by_name($stmt, ":f", $checklist['CHECK_HAND_BRAKE']);
    oci_bind_by_name($stmt, ":g", $checklist['PADDLE_BRAKE_MOVING']);
    oci_bind_by_name($stmt, ":h", $checklist['CHECK_ALL_TYRES']);
    oci_bind_by_name($stmt, ":i", $checklist['CHECK_LOAD_BACK']);
    oci_bind_by_name($stmt, ":j", $checklist['CHECK_FORK_CONDITION']);
    oci_bind_by_name($stmt, ":k", $checklist['APPLY_GREASE']);

    if (!oci_execute($stmt, OCI_NO_AUTO_COMMIT)) { $err = oci_error($stmt); throw new Exception("Checklist insert failed: " . $err['message']); }
    oci_free_statement($stmt);

    oci_commit($dbcon);

    $response['success']   = true;
    $response['master_id'] = $master_id;
    $response['message']   = ($mode === 'add') ? 'Record added successfully.' : 'Record updated successfully.';

    echo json_encode($response);
    exit;

} catch (Exception $e) {
    oci_rollback($dbcon);
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
