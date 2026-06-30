<?php
ob_start();
session_start();

require_once $_SERVER['DOCUMENT_ROOT'] . '/common/constant.php';

if (!isset($_SESSION['logged']) || $_SESSION['logged'] != 1) {
    header("Location:" . BASE_URL . "/Dashboard/Authenticate/login.php?gotourl=" . base64_encode(BASE_URL . "/equipment_maintenance/"));
    die;
}

$alert_message = null;
if (isset($_GET['msg'])) {
    $alert_message = htmlspecialchars(urldecode($_GET['msg']));
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <title>GRAND TEN - EQUIPMENT MAINTENANCE</title>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1.0, user-scalable=no">
    <link rel="shortcut icon" href="assets/images/favicon.png" type="image/x-icon">
    <link rel="stylesheet" href="assets/css/bootstrap.min.css?v=1">
    <link rel="stylesheet" href="assets/css/main.css">
    <link rel="stylesheet" href="assets/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="assets/css/toastr.min.css">
    <style>
        /* scrollX clones the header into dataTables_scrollHead */
        .dataTables_scrollHead thead th,
        .dataTables_scrollHead thead th.sorting,
        .dataTables_scrollHead thead th.sorting_asc,
        .dataTables_scrollHead thead th.sorting_desc {
            background-color: #1a56a0 !important;
            color: #fff !important;
            font-weight: 600;
            white-space: nowrap;
        }

        /* sorting arrow color */
        .dataTables_scrollHead thead th.sorting::before,
        .dataTables_scrollHead thead th.sorting::after,
        .dataTables_scrollHead thead th.sorting_asc::after,
        .dataTables_scrollHead thead th.sorting_desc::after {
            color: #fff !important;
            opacity: 0.7;
        }
        .filter-row {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 14px 18px;
            margin-bottom: 16px;
        }
        .filter-row label {
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 4px;
            color: #495057;
            display: block;
        }
        .filter-row .form-control {
            font-size: 13px;
        }
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
                                <span>
                                    <img id="main_profile" class="img-circle" src="assets/images/profile.svg" alt="User Image">
                                </span>
                            </a>
                            <ul class="dropdown-menu settings-menu">
                                <li class="border-top-menu">
                                    <a href="pages/logout.php">
                                        <img src="assets/images/menu_logout.svg" class="side-icon" /> Logout
                                    </a>
                                </li>
                            </ul>
                        </li>
                    </ul>
                </div>
            </nav>
        </header>

        <div id="content-container" class="container-fluid mydesignform">
            <div class="card m-a-2">
                <div class="card-block">

                    <!-- Title + Buttons -->
                    <div class="row">
                        <div class='col-lg-6 col-md-6 col-sm-9 col-xs-12'>
                            <h5>EQUIPMENT MAINTENANCE CHECKLIST</h5>
                        </div>
                        <div class='col-lg-6 col-md-6 col-sm-9 col-xs-12' style="text-align:right;">
                            <button id="myListBtn" class="btn btn-primary" style="margin-right:6px;">MY LIST</button>
                            <a href="add.php" class="btn btn-success">ADD</a>
                            <!-- <button id="exportBtn" class="btn btn-warning" style="margin-left:6px;">
                                &#x25BC; EXPORT EXCEL
                            </button> -->
                        </div>
                    </div>

                    <br>

                    <!-- Filter Row -->
                    <div class="filter-row">
                        <div class="row align-items-end">

                            <!-- Status Filter -->
                            <div class="col-md-3 col-sm-6 col-xs-12" style="margin-bottom:8px;">
                                <label>Status</label>
                                <select id="statusFilter" class="form-control">
                                    <option value="">-- All Status --</option>
                                    <option value="Pending Approval">Pending Approval</option>
                                    <option value="Approved">Approved</option>
                                    <option value="Rejected">Rejected</option>
                                </select>
                            </div>

                            <!-- Date From -->
                            <div class="col-md-2 col-sm-6 col-xs-12" style="margin-bottom:8px;">
                                <label>Date From</label>
                                <input type="date" id="dateFrom" class="form-control">
                            </div>

                            <!-- Date To -->
                            <div class="col-md-2 col-sm-6 col-xs-12" style="margin-bottom:8px;">
                                <label>Date To</label>
                                <input type="date" id="dateTo" class="form-control">
                            </div>

                            <!-- Reset Button -->
                            <div class="col-md-2 col-sm-6 col-xs-12" style="margin-bottom:8px;">
                                <label>&nbsp;</label>
                                <button id="resetBtn" class="btn btn-default btn-block">Reset</button>
                            </div>

                        </div>
                    </div>

                    <hr/>

                    <div class="table-responsive" id="content-table" style="overflow-x: auto; width: 100%;">
                        <table id="equipment_maintenance_list" class="display nowrap" style="width:100%"></table>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <script src="assets/js/tether.min.js"></script>
    <script src="assets/js/jquery.min.js"></script>
    <script src="assets/js/bootstrap.min.js"></script>
    <script src="assets/js/jquery.dataTables.min.js"></script>
    <script src="assets/js/toastr.min.js"></script>

    <script>
        let table;
        let activeStatus = '';
        let myListActive = false;
        let activeDateFrom = '';
        let activeDateTo   = '';

        function getColumns() {
            return [
                { title: "ID",              data: "ID" },
                { title: "Equipment",       data: "EQUIPMENT" },
                { title: "Brand",           data: "BRAND" },
                { title: "Serial No",       data: "SERIAL_NUMBER" },
                { title: "Chasis No",       data: "CHASIS_NUMBER" },
                { title: "Inspection Date", data: "INSPECTION_DATE" },
                { title: "Inspection Time", data: "INSPECTION_TIME" },
                { title: "Checked By",      data: "CHECKED_BY_NAME" },
                { title: "Approved By",     data: "APPROVED_BY_NAME" },
                { title: "Updated By",      data: "UPDATED_BY_NAME" },
                { title: "Status",          data: "EQUIPMENT_STATUS", orderable: false },
                { title: "Action",          data: "ACTION",           orderable: false }
            ];
        }

        function initDataTable(pageLength) {
            if (table) table.destroy();
            $('#equipment_maintenance_list').remove();
            $('#content-table').html('<table id="equipment_maintenance_list" class="display nowrap" style="width:100%"></table>');

            table = $('#equipment_maintenance_list').DataTable({
                processing: true,
                serverSide: true,
                searching: false,
                lengthChange: true,
                paging: true,
                pageLength: pageLength ? parseInt(pageLength) : 10,
                order: [[0, 'desc']],
                scrollX: true,
                ajax: {
                    url: "api/getListData.php",
                    type: "POST",
                    dataType: "json",
                    data: function(d) {
                        d.status_filter = activeStatus;
                        d.my_list       = myListActive ? 1 : 0;
                        d.date_from     = activeDateFrom;
                        d.date_to       = activeDateTo;
                    }
                },
                columns: getColumns(),
                language: {
                    lengthMenu: "Show _MENU_ entries",
                    emptyTable: "No Records Found."
                }
            });
        }

        $(document).ready(function() {
            initDataTable();

            // Status filter
            $('#statusFilter').on('change', function() {
                activeStatus = $(this).val();
                table.ajax.reload();
            });

            // Date From
            $('#dateFrom').on('change', function() {
                activeDateFrom = $(this).val();
                // If date_to is set and less than date_from, clear it
                if (activeDateTo && activeDateTo < activeDateFrom) {
                    activeDateTo = '';
                    $('#dateTo').val('');
                }
                // Enforce min on dateTo input
                $('#dateTo').attr('min', activeDateFrom);
                table.ajax.reload();
            });

            // Date To
            $('#dateTo').on('change', function() {
                activeDateTo = $(this).val();
                table.ajax.reload();
            });

            // Reset button
            $('#resetBtn').on('click', function() {
                activeStatus   = '';
                activeDateFrom = '';
                activeDateTo   = '';
                myListActive   = false;
                $('#statusFilter').val('');
                $('#dateFrom').val('').removeAttr('min');
                $('#dateTo').val('').removeAttr('min');
                $('#myListBtn').removeClass('btn-warning').addClass('btn-primary').text('MY LIST');
                table.ajax.reload();
            });

            // My List toggle
            $('#myListBtn').on('click', function() {
                myListActive = !myListActive;
                if (myListActive) {
                    $(this).removeClass('btn-primary').addClass('btn-warning').text('ALL LIST');
                } else {
                    $(this).removeClass('btn-warning').addClass('btn-primary').text('MY LIST');
                }
                table.ajax.reload();
            });

            // Export Excel
            $('#exportBtn').on('click', function() {
           const params = new URLSearchParams({
               status_filter: activeStatus,
               my_list:       myListActive ? 1 : 0,
               date_from:     activeDateFrom,
               date_to:       activeDateTo,
           });
           window.location.href = 'api/exportExcel.php?' + params.toString();
            });

            const alertMessage = "<?= $alert_message; ?>";
            if (alertMessage) {
                toastr.error(alertMessage);
                setTimeout(function() {
                    window.history.replaceState({}, document.title, window.location.pathname);
                }, 2000);
            }
        });
    </script>
</body>
</html>