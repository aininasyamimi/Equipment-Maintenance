<?php
ob_start();
session_start();

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once $_SERVER['DOCUMENT_ROOT'] . '/common/constant.php';

if (!isset($_SESSION['logged']) || $_SESSION['logged'] != 1) {
    header("Location:" . BASE_URL . "/Dashboard/Authenticate/login.php?gotourl=" . base64_encode(BASE_URL . "/equipment_maintenance/"));
    die;
}

require_once 'api/common.php';

$mode      = isset($_GET['mode']) ? $_GET['mode'] : 'add';
$record    = [];
$checklist = [];

$checklist_definition = [
    "CHECK_BATTERY_WATER"    => "1. CHECK BATTERY WATER (add if necessary)",
    "CHECK_HYDRAULIC_OIL"    => "2. CHECK HYDRAULIC OIL",
    "CHECK_HYDRAULIC_HOSE"   => "3. CHECK HYDRAULIC HOSE",
    "CHECK_BRAKE_OIL"        => "4. CHECK BRAKE OIL",
    "CHECK_BRAKE_CONDITIONS" => "5. CHECK BRAKE CONDITIONS",
    "CHECK_HAND_BRAKE"       => "6. CHECK HAND BRAKE",
    "PADDLE_BRAKE_MOVING"    => "7. PADDLE BRAKE & MOVING?",
    "CHECK_ALL_TYRES"        => "8. CHECK ALL TYRES WEAR & TEAR",
    "CHECK_LOAD_BACK"        => "9. CHECK LOAD BACK REST CONDITION",
    "CHECK_FORK_CONDITION"   => "10. CHECK FORK CONDITION",
    "APPLY_GREASE"           => "11. APPLY GREASE",
];

if (($mode == 'edit' || $mode == 'view' || $mode == 'approve') && isset($_GET['id'])) {
    $id     = intval(base64_decode($_GET['id']));
    $record = Common::view_em_by_id($id);

    if (empty($record)) {
        header("Location:" . BASE_URL . "/equipment_maintenance/?msg=" . urlencode("Record not found."));
        die;
    }

    $current_user_id = $_SESSION['EMP_ID'] ?? '';
    $status          = $record['EQUIPMENT_STATUS'] ?? '';
    $is_locked       = in_array($status, ['Approved', 'Rejected']);

    if ($mode == 'edit') {
        if ($is_locked) {
            header("Location:" . BASE_URL . "/equipment_maintenance/?msg=" . urlencode("This record has been {$status} and can no longer be edited."));
            die;
        }
        if ($current_user_id != ($record['CREATED_BY'] ?? '') && $current_user_id != ($record['CHECKED_BY'] ?? '')) {
            header("Location:" . BASE_URL . "/equipment_maintenance/?msg=" . urlencode("You do not have permission to edit this record."));
            die;
        }
    }

    if ($mode == 'approve') {
        if ($current_user_id != ($record['CHECKED_BY'] ?? '')) {
            header("Location:" . BASE_URL . "/equipment_maintenance/?msg=" . urlencode("Only the assigned checker can approve or reject this record."));
            die;
        }
        if ($is_locked) {
            $encoded_id = base64_encode($id);
            header("Location:" . BASE_URL . "/equipment_maintenance/add.php?mode=view&id={$encoded_id}&msg=" . urlencode("This record has already been {$status}."));
            die;
        }
    }

    $checklist = $record['checklist_details'] ?? [];
}

// Determine what the current user can do
$current_user_id = $_SESSION['EMP_ID'] ?? '';
$status          = $record['EQUIPMENT_STATUS'] ?? '';
$is_locked       = in_array($status, ['Approved', 'Rejected']);
$is_checker      = ($current_user_id == ($record['CHECKED_BY'] ?? ''));
$is_creator      = ($current_user_id == ($record['CREATED_BY'] ?? ''));
$can_approve     = ($mode == 'approve' && $is_checker && !$is_locked);
$is_view_only    = ($mode == 'view' || $mode == 'approve' || $is_locked || (!$is_creator && !$is_checker && $mode == 'edit'));
$show_pdf_btn    = ($mode === 'view' || $mode === 'approve' || $is_locked) && !empty($record['ID']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <title>GRAND TEN - <?= strtoupper($mode); ?> EQUIPMENT MAINTENANCE</title>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="shortcut icon" href="assets/images/favicon.png" type="image/x-icon">
    <link rel="stylesheet" href="assets/css/bootstrap.min.css?v=1">
    <link rel="stylesheet" href="assets/css/main.css">
    <link rel="stylesheet" href="assets/css/all.min.css" crossorigin="anonymous"/>
    <link rel="stylesheet" href="assets/css/jquery-ui.min.css">
    <link rel="stylesheet" href="assets/css/toastr.min.css">
    <link rel="stylesheet" href="assets/css/select2.min.css">
    <style>
        .form-control.error, .error-border { border-color: red !important; }
        label.error { color: red; font-size: 13px; margin-top: 5px; }
        .required-star { color: red; }
        table.checklist-table th,
        table.checklist-table td { text-align: center; vertical-align: middle; }
        table.checklist-table td:first-child { text-align: left; }

        /* Status badge */
        .status-badge {
            display: inline-block;
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            letter-spacing: 0.5px;
        }
        .status-pending  { background: #fff3cd; color: #856404; border: 1px solid #ffc107; }
        .status-approved { background: #d1e7dd; color: #0a3622; border: 1px solid #198754; }
        .status-rejected { background: #f8d7da; color: #58151c; border: 1px solid #dc3545; }

        /* Rejection reason box */
        .rejection-box {
            background: #fff5f5;
            border: 1px solid #f5c6cb;
            border-radius: 6px;
            padding: 12px 16px;
            margin-bottom: 16px;
        }
        .rejection-box label { color: #dc3545; font-weight: 600; }

        /* Approve/Reject action bar */
        .approval-bar {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 16px 20px;
            margin-top: 20px;
        }
        .approval-bar h6 { margin-bottom: 12px; font-weight: 600; color: #495057; }

        /* Lock notice */
        .lock-notice {
            background: #e9ecef;
            border-left: 4px solid #6c757d;
            padding: 10px 16px;
            border-radius: 0 6px 6px 0;
            margin-bottom: 16px;
            font-size: 14px;
            color: #495057;
        }
        .lock-notice.approved { border-left-color: #198754; background: #f0fdf4; color: #0a3622; }
        .lock-notice.rejected { border-left-color: #dc3545; background: #fff5f5; color: #58151c; }

        /* Rejected By field */
        .rejected-by-input {
            border-color: #dc3545 !important;
            background: #fff5f5 !important;
        }
        .rejected-by-label { color: #dc3545; font-weight: 600; }

        /* PDF button */
        .btn-pdf {
            background-color: #dc3545;
            color: #fff;
            border: none;
        }
        .btn-pdf:hover { background-color: #b02a37; color: #fff; }
    </style>
</head>

<body>
<div class="wrapper">
    <header class="main-header-top hidden-print">
        <a href="<?= BASE_URL; ?>/equipment_maintenance/" class="logo">
            <img class="img-fluid able-logo" src="assets/images/yty_banner2.svg" alt="Theme-logo">
        </a>
        <nav class="navbar navbar-static-top">
            <div class="navbar-custom-menu f-right">
                <ul class="top-nav">
                    <li class="dropdown">
                        <span id="time"></span>
                        <span><b><?= ucwords(strtolower($_SESSION['EMP_NAME'])); ?></b></span>
                        <a href="#!" data-toggle="dropdown" class="dropdown-toggle drop icon-circle drop-image">
                            <span><img id="main_profile" class="img-circle" src="assets/images/profile.svg" alt="User Image"></span>
                        </a>
                        <ul class="dropdown-menu settings-menu">
                            <li class="border-top-menu">
                                <a href="pages/logout.php"><img src="assets/images/menu_logout.svg" class="side-icon"/> Logout</a>
                            </li>
                        </ul>
                    </li>
                </ul>
            </div>
        </nav>
    </header>

    <div id="content-container" class="container content-wrapper">
        <div class="card m-a-2">
            <div class="card-block">

                <!-- Title + Status badge -->
                <div class="col-12 d-flex align-items-center gap-3 mb-1">
                    <h5 class="mb-0">MONTHLY EQUIPMENT MAINTENANCE CHECKLIST - <?= strtoupper($mode); ?></h5>
                    <?php if (!empty($status)): ?>
                        <span class="status-badge status-<?= strtolower($status === 'Pending Approval' ? 'pending' : $status); ?>">
                            <?= htmlspecialchars($status); ?>
                        </span>
                    <?php endif; ?>
                </div>
                <hr/>

                <!-- Lock notice for approved/rejected records -->
                <?php if ($is_locked): ?>
                    <div class="lock-notice <?= strtolower($status); ?>">
                        <?php if ($status === 'Approved'): ?>
                            <i class="fas fa-check-circle"></i>
                            This record was <strong>approved</strong> by <?= htmlspecialchars($record['VERIFIED_BY_NAME'] ?? 'N/A'); ?> and is now locked.
                        <?php else: ?>
                            <i class="fas fa-times-circle"></i>
                            This record was <strong>rejected</strong> by <?= htmlspecialchars($record['VERIFIED_BY_NAME'] ?? 'N/A'); ?>.
                            Reason: <em><?= htmlspecialchars($record['REJECTION_REASON'] ?? 'N/A'); ?></em>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- Document header row -->
                <div class="row text-center mb-3">
                    <div class="col-md-8">
                        <h5><strong>MONTHLY EQUIPMENT MAINTENANCE CHECKLIST</strong></h5>
                        <p>Document No: QP-16-MEMC &nbsp;|&nbsp; Revision No.: 1</p>
                    </div>
                    <div class="col-md-4 text-end">
                        <img alt="logo" src="assets/images/grandten_logo.png">
                    </div>
                </div>
                <hr/>

                <form method="post" id="emcForm">
                    <input type="hidden" name="mode"      value="<?= $mode === 'approve' ? 'edit' : $mode; ?>">
                    <input type="hidden" name="record_id" value="<?= $record['ID'] ?? ''; ?>">

                    <!-- Row 1: Equipment / Brand / Voltage -->
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label>EQUIPMENT <span class="required-star">*</span></label>
                            <select name="equipment" id="equipment" class="form-control"
                                <?= $is_view_only ? 'disabled' : ''; ?>>
                                <option value="">-- Select Equipment --</option>
                                <option value="Reach - Truck" <?= (($record['EQUIPMENT'] ?? '') == 'Reach - Truck') ? 'selected' : ''; ?>>Reach - Truck</option>
                                <option value="Forklift"      <?= (($record['EQUIPMENT'] ?? '') == 'Forklift')      ? 'selected' : ''; ?>>Forklift</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label>BRAND <span class="required-star">*</span></label>
                            <input type="text" name="brand" class="form-control"
                                value="<?= htmlspecialchars($record['BRAND'] ?? ''); ?>"
                                <?= $is_view_only ? 'readonly' : ''; ?>>
                        </div>
                        <div class="col-md-4">
                            <label>VOLTAGE <span class="required-star">*</span></label>
                            <input type="number" name="voltage" class="form-control"
                                value="<?= htmlspecialchars($record['VOLTAGE'] ?? ''); ?>"
                                <?= $is_view_only ? 'readonly' : ''; ?> step="1">
                        </div>
                    </div>
                    <br>

                    <!-- Row 2: Serial No / Chasis No / Truck Weight -->
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label>SERIAL NO <span class="required-star">*</span></label>
                            <input type="text" name="serial_number" class="form-control"
                                value="<?= htmlspecialchars($record['SERIAL_NUMBER'] ?? ''); ?>"
                                <?= $is_view_only ? 'readonly' : ''; ?>>
                        </div>
                        <div class="col-md-4">
                            <label>CHASIS NO <span class="required-star">*</span></label>
                            <input type="text" name="chasis_number" class="form-control"
                                value="<?= htmlspecialchars($record['CHASIS_NUMBER'] ?? ''); ?>"
                                <?= $is_view_only ? 'readonly' : ''; ?>>
                        </div>
                        <div class="col-md-4">
                            <label>TRUCK WEIGHT (KG) <span class="required-star">*</span></label>
                            <input type="number" name="truck_weight" class="form-control"
                                value="<?= htmlspecialchars($record['TRUCK_WEIGHT'] ?? ''); ?>"
                                <?= $is_view_only ? 'readonly' : ''; ?> step="1">
                        </div>
                    </div>
                    <br>

                    <!-- Row 3: Date / Time -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label>DATE <span class="required-star">*</span></label>
                            <input type="date" name="date" class="form-control"
                                value="<?= htmlspecialchars($record['INSPECTION_DATE_RAW'] ?? ''); ?>"
                                <?= $is_view_only ? 'readonly' : ''; ?>>
                        </div>
                        <div class="col-md-6">
                            <label>TIME <span class="required-star">*</span></label>
                            <input type="time" name="time" class="form-control"
                                value="<?= htmlspecialchars($record['INSPECTION_TIME_RAW'] ?? ''); ?>"
                                <?= $is_view_only ? 'readonly' : ''; ?>>
                        </div>
                    </div>
                    <br>

                    <!-- Checklist -->
                    <div class="mb-3">
                        <label class="fw-bold">CHECKLIST</label>
                        <table class="table table-bordered checklist-table">
                            <thead>
                                <tr>
                                    <th style="width:70%;">Item</th>
                                    <th>YES</th>
                                    <th>NO</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($checklist_definition as $key => $label): ?>
                                <tr>
                                    <td><?= $label ?></td>
                                    <td>
                                        <input type="radio" name="<?= $key ?>" value="YES"
                                            <?= (($checklist[$key] ?? '') == 'YES') ? 'checked' : ''; ?>
                                            <?= $is_view_only ? 'disabled' : ''; ?>>
                                    </td>
                                    <td>
                                        <input type="radio" name="<?= $key ?>" value="NO"
                                            <?= (($checklist[$key] ?? '') == 'NO') ? 'checked' : ''; ?>
                                            <?= $is_view_only ? 'disabled' : ''; ?>>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Remarks -->
                    <div class="mb-3">
                        <label>REMARKS <span class="required-star">*</span></label>
                        <textarea name="remark" class="form-control" rows="3"
                            <?= $is_view_only ? 'readonly' : ''; ?>><?= htmlspecialchars($record['REMARKS'] ?? ''); ?></textarea>
                    </div>
                    <br>

                    <!-- Checked By / Approved By / Rejected By -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label>Checked By <span class="required-star">*</span></label>
                            <?php if ($is_view_only || $mode === 'approve'): ?>
                                <input type="text" class="form-control" readonly
                                    value="<?= htmlspecialchars($record['CHECKED_BY_NAME'] ?? ''); ?>">
                            <?php else: ?>
                                <select name="checked_by" id="checked_by"
                                    class="form-control select2-employee" style="width:100%;"
                                    data-value="<?= htmlspecialchars($record['CHECKED_BY'] ?? ''); ?>"
                                    data-text="<?= htmlspecialchars($record['CHECKED_BY_NAME'] ?? ''); ?>">
                                    <option value="">-- Select Employee --</option>
                                    <?php if (!empty($record['CHECKED_BY'])): ?>
                                        <option value="<?= htmlspecialchars($record['CHECKED_BY']); ?>" selected>
                                            <?= htmlspecialchars($record['CHECKED_BY_NAME'] ?? ''); ?>
                                        </option>
                                    <?php endif; ?>
                                </select>
                            <?php endif; ?>
                        </div>

                        <?php if ($status === 'Approved'): ?>
                        <div class="col-md-6">
                            <label>Approved By</label>
                            <input type="text" class="form-control" readonly
                                value="<?= htmlspecialchars($record['VERIFIED_BY_NAME'] ?? ''); ?>">
                        </div>

                        <?php elseif ($status === 'Rejected'): ?>
                        <div class="col-md-6">
                            <label class="rejected-by-label"><i class="fas fa-times-circle"></i> Rejected By</label>
                            <input type="text" class="form-control rejected-by-input" readonly
                                value="<?= htmlspecialchars($record['VERIFIED_BY_NAME'] ?? ''); ?>">
                        </div>
                        <div class="col-md-12 mt-3">
                            <div class="rejection-box">
                                <label><i class="fas fa-exclamation-circle"></i> Rejection Reason</label>
                                <textarea class="form-control mt-1" rows="3" readonly><?= htmlspecialchars($record['REJECTION_REASON'] ?? ''); ?></textarea>
                            </div>
                        </div>

                        <?php else: ?>
                        <div class="col-md-6">
                            <label>Approved By</label>
                            <input type="text" class="form-control" disabled
                                value="" placeholder="Not approved yet">
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Submit button (add/edit mode only) -->
                    <?php if (!$is_view_only && !$can_approve): ?>
                        <button type="button" id="submitBtn" class="btn btn-primary">Submit</button>
                    <?php endif; ?>
                </form>
                <br>

                <!-- PDF Button — shown in view/approve mode and for locked records -->
                <?php if ($show_pdf_btn): ?>
                <div class="mt-3">
                    <a href="pdfGenerate.php?id=<?= base64_encode($record['ID'] ?? '') ?>"
                       target="_blank"
                       class="btn btn-pdf">
                        <i class="fas fa-file-pdf"></i> Generate PDF
                    </a>
                </div>
                <?php endif; ?>

                <!-- ===== APPROVAL ACTION BAR ===== -->
                <?php if ($can_approve): ?>
                <div class="approval-bar">
                    <h6><i class="fas fa-clipboard-check"></i> Approval Action</h6>
                    <p class="text-muted mb-3" style="font-size:14px;">
                        Review the checklist above and approve or reject this record.
                        Once actioned, the record will be locked and cannot be edited.
                    </p>

                    <!-- Rejection reason box (hidden until Reject is clicked) -->
                    <div id="rejectionReasonBox" class="rejection-box" style="display:none;">
                        <label for="rejection_reason">Rejection Reason <span class="required-star">*</span></label>
                        <textarea id="rejection_reason" class="form-control mt-1" rows="3"
                            placeholder="Please provide a reason for rejection..."></textarea>
                        <small class="text-muted">This reason will be visible to the record creator.</small>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="button" id="approveBtn" class="btn btn-success">
                            <i class="fas fa-check"></i> Approve
                        </button>
                        <button type="button" id="rejectBtn" class="btn btn-danger">
                            <i class="fas fa-times"></i> Reject
                        </button>
                        <button type="button" id="confirmRejectBtn" class="btn btn-danger" style="display:none;">
                            <i class="fas fa-times"></i> Confirm Rejection
                        </button>
                        <button type="button" id="cancelRejectBtn" class="btn btn-secondary" style="display:none;">
                            Cancel
                        </button>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>
    </div>
</div>

<script src="assets/js/jquery.min.js"></script>
<script src="assets/js/bootstrap.min.js"></script>
<script src="assets/js/jquery-ui.min.js"></script>
<script src="assets/js/jquery.validate.min.js"></script>
<script src="assets/js/toastr.min.js"></script>
<script src="assets/js/select2.min.js"></script>

<script>
$(document).ready(function () {

    initEmployeeSelect2(".select2-employee");

    <?php if (!$is_view_only && !$can_approve): ?>
    // ===== NORMAL SUBMIT (add/edit) =====
    $("#emcForm").validate({
        ignore: [],
        rules: {
            equipment:     { required: true },
            brand:         { required: true },
            voltage:       { required: true },
            serial_number: { required: true },
            chasis_number: { required: true },
            truck_weight:  { required: true },
            date:          { required: true },
            time:          { required: true },
            remark:        { required: true, maxlength: 2000 },
            checked_by:    { required: true }
        },
        messages: {
            equipment:     { required: "Equipment is required" },
            brand:         { required: "Brand is required" },
            voltage:       { required: "Voltage is required" },
            serial_number: { required: "Serial Number is required" },
            chasis_number: { required: "Chasis Number is required" },
            truck_weight:  { required: "Truck Weight is required" },
            date:          { required: "Date is required" },
            time:          { required: "Time is required" },
            remark:        { required: "Remarks is required", maxlength: "Max 2000 characters" },
            checked_by:    { required: "Checked By is required" }
        },
        errorClass: "error",
        highlight:   el => $(el).addClass("error-border"),
        unhighlight: el => $(el).removeClass("error-border"),
        errorPlacement: (error, element) => error.insertAfter(element),
        submitHandler: () => false
    });

    $("#submitBtn").click(function (e) {
        e.preventDefault();
        if ($("#emcForm").valid()) {
            ajaxSubmit("<?= $mode === 'approve' ? 'edit' : $mode; ?>");
        } else {
            $(".error-border").first().focus();
        }
    });
    <?php endif; ?>

    <?php if ($can_approve): ?>
    // ===== APPROVE =====
    $("#approveBtn").click(function () {
        if (!confirm("Are you sure you want to APPROVE this record? This action cannot be undone.")) return;
        sendApprovalAction("approve", "");
    });

    // ===== REJECT — show reason box first =====
    $("#rejectBtn").click(function () {
        $("#rejectionReasonBox").slideDown(200);
        $("#rejectBtn").hide();
        $("#confirmRejectBtn, #cancelRejectBtn").show();
        $("#approveBtn").prop("disabled", true);
    });

    // ===== CANCEL REJECT =====
    $("#cancelRejectBtn").click(function () {
        $("#rejectionReasonBox").slideUp(200);
        $("#confirmRejectBtn, #cancelRejectBtn").hide();
        $("#rejectBtn").show();
        $("#approveBtn").prop("disabled", false);
        $("#rejection_reason").val("");
    });

    // ===== CONFIRM REJECT =====
    $("#confirmRejectBtn").click(function () {
        const reason = $("#rejection_reason").val().trim();
        if (reason === "") {
            toastr.error("Please enter a rejection reason.");
            $("#rejection_reason").focus();
            return;
        }
        if (!confirm("Are you sure you want to REJECT this record? This action cannot be undone.")) return;
        sendApprovalAction("reject", reason);
    });

    function sendApprovalAction(action, reason) {
        const formData = new FormData();
        formData.append("mode",      action);
        formData.append("record_id", "<?= $record['ID'] ?? ''; ?>");
        if (reason) formData.append("rejection_reason", reason);

        $("#approveBtn, #rejectBtn, #confirmRejectBtn, #cancelRejectBtn").prop("disabled", true);

        $.ajax({
            url: "api/save.php",
            type: "POST",
            data: formData,
            contentType: false,
            processData: false,
            dataType: "json",
            success: function (res) {
                if (res.success) {
                    toastr.success(res.message || "Done");
                    setTimeout(() => { window.location.href = "index.php"; }, 1500);
                } else {
                    toastr.error(res.message || "Error occurred");
                    $("#approveBtn, #rejectBtn").prop("disabled", false);
                    if (action === "reject") {
                        $("#confirmRejectBtn, #cancelRejectBtn").prop("disabled", false);
                    }
                }
            },
            error: function () {
                toastr.error("Server error. Check DevTools > Network > Response for details.");
                $("#approveBtn, #rejectBtn, #confirmRejectBtn, #cancelRejectBtn").prop("disabled", false);
            }
        });
    }
    <?php endif; ?>

    <?php if ($is_view_only): ?>
    $('#emcForm input, #emcForm select, #emcForm textarea, #emcForm button').prop('disabled', true);
    <?php endif; ?>

    // Show alert message if redirected with ?msg=
    const urlParams = new URLSearchParams(window.location.search);
    const msg = urlParams.get('msg');
    if (msg) toastr.info(decodeURIComponent(msg));
});

// ===== AJAX SUBMIT (add/edit) =====
function ajaxSubmit(mode) {
    var formData = new FormData($("#emcForm")[0]);
    formData.set("mode", mode);

    $.ajax({
        url: "api/save.php",
        type: "POST",
        data: formData,
        contentType: false,
        processData: false,
        dataType: "json",
        success: function (res) {
            if (res.success) {
                toastr.success(res.message || "Saved successfully");
                if (res.master_id) {
                    const encodedId = btoa(res.master_id.toString());
                    window.open("pdfGenerate.php?id=" + encodedId, "_blank");
                }
                setTimeout(() => { window.location.href = "index.php"; }, 1500);
            } else {
                if (res.errors && res.errors.length > 0) {
                    toastr.error(res.errors.join('<br>'));
                } else {
                    toastr.error(res.message || "Error occurred");
                }
            }
        },
        error: () => toastr.error("Server error. Check DevTools > Network > Response for details.")
    });
}

function initEmployeeSelect2(selector) {
    $(selector).each(function () {
        const $this         = $(this);
        const existingValue = $this.data('value');
        const existingText  = $this.data('text');

        $this.select2({
            width: "100%",
            placeholder: "-- Select Employee --",
            minimumInputLength: 2,
            ajax: {
                url: "api/employee_search.php",
                type: "GET",
                dataType: "json",
                delay: 250,
                data: params => ({ q: params.term }),
                processResults: data => ({ results: data.results }),
                cache: true
            }
        });

        if (existingValue && existingText) {
            const option = new Option(existingText, existingValue, true, true);
            $this.append(option).trigger('change');
        }
    });
}
</script>
</body>
</html>