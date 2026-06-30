<?php
date_default_timezone_set('Asia/Kuala_Lumpur');
require $_SERVER['DOCUMENT_ROOT'] . '/common/EmailHelper.php';

use Common\Email\EmailHelper;

class Common
{
    public static function employee_list($emp_no = ''): array
    {
        include $_SERVER['DOCUMENT_ROOT'] . '/db/connection.php';

        $tmpQuery = "SELECT DISTINCT emp_id, 
                            NVL2(emp_short_name, emp_short_name, employee_name) AS employee_display_name, 
                            employee_name, 
                            email_id, 
                            department, 
                            emp_short_name
                    FROM employee_master
                    WHERE NVL(emp_status,'N') = 'N'
                    AND NVL(virtual_flag,'N') = 'N'";

        if ($emp_no) {
            $tmpQuery .= " AND (emp_id LIKE :searchTerm OR LOWER(employee_name) LIKE LOWER(:searchTerm))";
        }

        $tmpQuery .= " ORDER BY employee_name FETCH FIRST 20 ROWS ONLY";

        $query = oci_parse($dbcon, $tmpQuery);

        if ($emp_no) {
            $likeTerm = '%' . $emp_no . '%';
            oci_bind_by_name($query, ':searchTerm', $likeTerm);
        }

        oci_execute($query);

        $allRecords = [];
        while (($row = oci_fetch_array($query, OCI_ASSOC + OCI_RETURN_NULLS)) !== false) {
            $allRecords[] = $row;
        }

        return $allRecords;
    }


    // ─── Get record + checklist by ID ────────────────────────────────────────────
    public static function view_em_by_id($record_id = ''): array
    {
        if (!$record_id) return [];

        include $_SERVER['DOCUMENT_ROOT'] . '/db/connection.php';

        $sql = "
            SELECT
                em.*,
                checker.EMPLOYEE_NAME  AS CHECKED_BY_NAME,
                approver.EMPLOYEE_NAME AS VERIFIED_BY_NAME,
                updater.EMPLOYEE_NAME  AS UPDATED_BY_NAME,

                TO_CHAR(em.INSPECTION_DATE, 'YYYY-MM-DD')      AS INSPECTION_DATE_RAW,
                TO_CHAR(em.INSPECTION_TIME, 'HH24:MI')         AS INSPECTION_TIME_RAW,
                TO_CHAR(em.CREATED_AT, 'DD/MM/YYYY HH:MI AM')  AS CREATED_AT_DISPLAY,
                TO_CHAR(em.UPDATED_AT, 'DD/MM/YYYY HH:MI AM')  AS UPDATED_AT_DISPLAY

            FROM EQUIPMENT_MAINTENANCE em
            LEFT JOIN EMPLOYEE_MASTER checker  ON checker.EMP_ID  = em.CHECKED_BY
            LEFT JOIN EMPLOYEE_MASTER approver ON approver.EMP_ID = em.APPROVED_BY
            LEFT JOIN EMPLOYEE_MASTER updater  ON updater.EMP_ID  = em.UPDATED_BY
            WHERE em.ID = :id
        ";

        $stmt = oci_parse($dbcon, $sql);
        if (!$stmt) {
            $err = oci_error($dbcon);
            error_log("view_em_by_id parse error: " . $err['message']);
            return [];
        }

        oci_bind_by_name($stmt, ":id", $record_id);

        $exec = oci_execute($stmt);
        if (!$exec) {
            $err = oci_error($stmt);
            error_log("view_em_by_id execute error: " . $err['message']);
            oci_free_statement($stmt);
            return [];
        }

        // OCI_RETURN_LOBS converts CLOB columns (e.g. REMARKS) to plain PHP strings.
        $row = oci_fetch_array($stmt, OCI_ASSOC + OCI_RETURN_NULLS + OCI_RETURN_LOBS);
        oci_free_statement($stmt);

        if (!$row) return [];

        // ── Checklist ──────────────────────────────────────────────────────────
        $sql_cl  = "SELECT * FROM EQUIPMENT_MAINTENANCE_CHECKLIST WHERE MASTER_ID = :id";
        $stmt_cl = oci_parse($dbcon, $sql_cl);
        if (!$stmt_cl) {
            $err = oci_error($dbcon);
            error_log("view_em_by_id checklist parse error: " . $err['message']);
            $row['checklist_details'] = [];
            return $row;
        }

        oci_bind_by_name($stmt_cl, ":id", $record_id);

        $exec_cl = oci_execute($stmt_cl);
        if (!$exec_cl) {
            $err = oci_error($stmt_cl);
            error_log("view_em_by_id checklist execute error: " . $err['message']);
            oci_free_statement($stmt_cl);
            $row['checklist_details'] = [];
            return $row;
        }

        $cl = oci_fetch_array($stmt_cl, OCI_ASSOC + OCI_RETURN_NULLS + OCI_RETURN_LOBS);
        oci_free_statement($stmt_cl);

        $row['checklist_details'] = $cl ?: [];

        return $row;
    }


    // ─── PDF Generator ───────────────────────────────────────────────────────────
    public static function pdf_generate_em($record_id = '', $type = 'I')
    {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/common/vendor/autoload.php';

        $record = self::view_em_by_id($record_id);
        $cl     = $record['checklist_details'] ?? [];

        $page_heading = 'EQUIPMENT MAINTENANCE CHECKLIST';
        $logo         = $_SERVER['DOCUMENT_ROOT'] . '/equipment_maintenance/assets/images/grandten_logo.png';

        if (!file_exists($logo)) {
            die("Logo not found: " . $logo);
        }

        $checklist_labels = [
            "CHECK_BATTERY_WATER"    => "CHECK BATTERY WATER",
            "CHECK_HYDRAULIC_OIL"    => "CHECK HYDRAULIC OIL",
            "CHECK_HYDRAULIC_HOSE"   => "CHECK HYDRAULIC HOSE",
            "CHECK_BRAKE_OIL"        => "CHECK BRAKE OIL",
            "CHECK_BRAKE_CONDITIONS" => "CHECK BRAKE CONDITIONS",
            "CHECK_HAND_BRAKE"       => "CHECK HAND BRAKE",
            "PADDLE_BRAKE_MOVING"    => "PADDLE BRAKE & MOVING",
            "CHECK_ALL_TYRES"        => "CHECK TYRES",
            "CHECK_LOAD_BACK"        => "CHECK LOAD BACK REST",
            "CHECK_FORK_CONDITION"   => "CHECK FORK",
            "APPLY_GREASE"           => "APPLY GREASE",
        ];

        // Safely cast all record fields to string to avoid OCI-Lob issues
        $safe = [];
        foreach ($record as $k => $v) {
            if (is_array($v)) continue; // skip checklist_details sub-array
            $safe[$k] = is_object($v) ? $v->load() : (string)($v ?? '');
        }

        $status           = $safe['EQUIPMENT_STATUS']  ?? '';
        $checked_by_name  = $safe['CHECKED_BY_NAME']   ?? '-';
        $verified_by_name = $safe['VERIFIED_BY_NAME']  ?? '-';
        $rejection_reason = $safe['REJECTION_REASON']  ?? '';

        // ── Build signature/footer block based on status ──────────────────────
        if ($status === 'Approved') {
            $signature_right = "
                <b>Approved By</b><br>
                " . htmlspecialchars($verified_by_name) . "
            ";
        } elseif ($status === 'Rejected') {
            $signature_right = "
                <b style='color:#cc0000;'>Rejected By</b><br>
                " . htmlspecialchars($verified_by_name) . "
                <br><br>
                <b style='color:#cc0000;'>Rejection Reason:</b><br>
                " . nl2br(htmlspecialchars($rejection_reason ?: '-')) . "
            ";
        } else {
            $signature_right = "
                <b>Approved By</b><br>
                <em style='color:#999;'>Pending Approval</em>
            ";
        }

        // ── Status badge for PDF ──────────────────────────────────────────────
        $status_color_map = [
            'Approved'         => ['bg' => '#d1e7dd', 'border' => '#198754', 'text' => '#0a3622'],
            'Rejected'         => ['bg' => '#f8d7da', 'border' => '#dc3545', 'text' => '#58151c'],
            'Pending Approval' => ['bg' => '#fff3cd', 'border' => '#ffc107', 'text' => '#856404'],
        ];
        $sc = $status_color_map[$status] ?? ['bg' => '#e2e3e5', 'border' => '#adb5bd', 'text' => '#333'];
        $status_badge = "<span style='display:inline-block;padding:3px 12px;border-radius:20px;"
                      . "background:{$sc['bg']};border:1px solid {$sc['border']};"
                      . "color:{$sc['text']};font-size:11px;font-weight:600;'>"
                      . htmlspecialchars($status)
                      . "</span>";

        $html = '
        <style>
            body { font-family: sans-serif; font-size: 12px; }
            .info-table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
            .info-table td { padding: 4px 6px; vertical-align: top; }
            .info-table td:first-child { font-weight: bold; width: 35%; }
            table.checklist { border-collapse: collapse; width: 100%; margin-top: 10px; }
            table.checklist th, table.checklist td { border: 1px solid #000; padding: 6px; }
            table.checklist th { background-color: #1a56a0; color: #fff; }
            .sig-table { width: 100%; border-collapse: collapse; margin-top: 24px; }
            .sig-table td { padding: 10px 12px; vertical-align: top; width: 50%; border-top: 1px solid #000; }
        </style>

        <!-- Document Header -->
        <table width="100%" border="0" cellspacing="0" cellpadding="5">
            <tr>
                <td width="75%" align="left">
                    <h3 style="margin:0;">' . htmlspecialchars($page_heading) . '</h3>
                    <p style="margin:4px 0 0;">Document No.: QP-16-MEMC &nbsp;|&nbsp; Revision No.: 1</p>
                </td>
                <td width="25%" align="right">
                    <img src="' . $logo . '" alt="Logo" height="55">
                </td>
            </tr>
        </table>

        <hr style="border:1px solid #1a56a0; margin:10px 0;">

        <!-- Status -->
        <p style="margin:4px 0 10px;"><b>Status:</b> ' . $status_badge . '</p>

        <!-- Info Table -->
        <table class="info-table">
            <tr>
                <td>ID</td>
                <td>' . htmlspecialchars($safe['ID'] ?? '') . '</td>
                <td><b>Equipment</b></td>
                <td>' . htmlspecialchars($safe['EQUIPMENT'] ?? '') . '</td>
            </tr>
            <tr>
                <td>Brand</td>
                <td>' . htmlspecialchars($safe['BRAND'] ?? '') . '</td>
                <td><b>Voltage</b></td>
                <td>' . htmlspecialchars($safe['VOLTAGE'] ?? '') . '</td>
            </tr>
            <tr>
                <td>Serial No</td>
                <td>' . htmlspecialchars($safe['SERIAL_NUMBER'] ?? '') . '</td>
                <td><b>Chasis No</b></td>
                <td>' . htmlspecialchars($safe['CHASIS_NUMBER'] ?? '') . '</td>
            </tr>
            <tr>
                <td>Truck Weight</td>
                <td>' . htmlspecialchars($safe['TRUCK_WEIGHT'] ?? '') . ' kg</td>
                <td><b>Inspection Date</b></td>
                <td>' . htmlspecialchars($safe['INSPECTION_DATE_RAW'] ?? '') . '</td>
            </tr>
            <tr>
                <td>Inspection Time</td>
                <td>' . htmlspecialchars($safe['INSPECTION_TIME_RAW'] ?? '') . '</td>
                <td></td>
                <td></td>
            </tr>
        </table>

        <hr style="border:0.5px solid #ccc; margin:10px 0;">

        <!-- Checklist Table -->
        <table class="checklist">
            <tr>
                <th width="65%" style="text-align:left;">Item</th>
                <th width="17.5%" style="text-align:center;">YES</th>
                <th width="17.5%" style="text-align:center;">NO</th>
            </tr>';

        foreach ($checklist_labels as $key => $label) {
            $value   = strtoupper(trim($cl[$key] ?? ''));
            $yes_dot = ($value === 'YES' || $value === 'Y') ? '&#x25CF;' : '&#x25CB;';
            $no_dot  = ($value === 'NO'  || $value === 'N') ? '&#x25CF;' : '&#x25CB;';

            $html .= "
            <tr>
                <td>" . htmlspecialchars($label) . "</td>
                <td style='text-align:center; font-size:16px;'>$yes_dot</td>
                <td style='text-align:center; font-size:16px;'>$no_dot</td>
            </tr>";
        }

        $html .= "
        </table>

        <br>
        <p><b>Remarks:</b><br>" . nl2br(htmlspecialchars($safe['REMARKS'] ?? '-')) . "</p>

        <!-- Signature / Approval block -->
        <table class='sig-table'>
            <tr>
                <td style='text-align:center;'>
                    <b>Checked By</b><br>
                    " . htmlspecialchars($checked_by_name) . "
                </td>
                <td style='text-align:center;'>
                    $signature_right
                </td>
            </tr>
        </table>
        ";

        // ── mPDF setup ────────────────────────────────────────────────────────
        $tmp_dir = $_SERVER['DOCUMENT_ROOT'] . '/webdocs/vendor_qualification_form/tmp/mpdf_custom/';

        if (!is_dir($tmp_dir)) {
            mkdir($tmp_dir, 0777, true);
        }

        if (!is_writable($tmp_dir)) {
            die("Temp directory not writable: " . $tmp_dir);
        }

        try {
            $mpdf = new \Mpdf\Mpdf([
                'mode'              => 'UTF-8',
                'format'            => 'A4',
                'margin_left'       => 12,
                'margin_right'      => 12,
                'margin_top'        => 12,
                'margin_bottom'     => 12,
                'default_font_size' => 11,
                'autoLangToFont'    => true,
                'tempDir'           => $tmp_dir,
            ]);

            $mpdf->SetDisplayMode('fullpage');
            $mpdf->SetTitle('Equipment Maintenance Checklist');
            $mpdf->SetAuthor('GRAND TEN HOLDINGS SDN. BHD.');

            $mpdf->WriteHTML($html);

            $fileName = time() . "_equipment_maintenance_" . $record_id;
            $type     = $_GET['type'] ?? 'I'; // I = inline, D = download

            $mpdf->Output($fileName . '.pdf', $type);

        } catch (\Mpdf\MpdfException $e) {
            echo 'Error generating PDF: ' . $e->getMessage();
            exit;
        }
    }
}