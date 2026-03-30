<?php
session_start();
require_once 'config/connection.php';


if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: dashboard.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Delete Case - CyberPablo</title>
    <link rel="stylesheet" href="../assets/css/cases.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/delete_cases.css">
</head>
<body>
    <?php require_once 'header.php'; ?>

    <div class="config-wrapper">
        <div class="config-page-header">
            <h1><i class="fa-solid fa-triangle-exclamation"></i> Administrator Override</h1>
            <p>Permanently delete a case and all associated evidence. Changes made here affect all system records.</p>
        </div>
        <div class="config-card">
            <div class="danger-action-row">
                <p class="row-label"><i class="fa-solid fa-folder-minus"></i> Delete Case Record</p>
                <p class="row-desc">
                    Enter the target case number below to permanently remove it from the system. All database records,
                    status history, and physical evidence files will be destroyed.
                    <strong>This action cannot be undone.</strong> It will be recorded in the official audit log.
                </p>

                <input
                    type="text"
                    id="targetCaseNo"
                    class="case-input"
                    placeholder="e.g. CYBER-2026-0001"
                    autocomplete="off"
                >
                <br>
                <button type="button" class="btn-danger" onclick="triggerDeletePrompt()">
                    <i class="fa-solid fa-trash"></i> Permanently Delete Case
                </button>
            </div>

        </div>
    </div>
    <div class="modal-overlay" id="confirmModal">
        <div class="delete-modal">
            <div class="modal-header">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <h3>Confirm Permanent Deletion</h3>
            </div>
            <div class="modal-body">
                <p>You are about to permanently delete the following case. This cannot be reversed:</p>
                <span class="case-highlight" id="confirmCaseDisplay">—</span>
                <p style="margin-bottom: 0; font-size: 13px; color: #9ca3af;">
                    All records, evidence files, and status history will be destroyed and logged in the audit trail.
                </p>
                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="closeModal()">Cancel</button>
                    <button type="button" class="btn-danger" onclick="executeDelete()" id="confirmBtn">
                        <i class="fa-solid fa-trash"></i> Yes, Delete
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        let currentCaseToDelete = "";

        function triggerDeletePrompt() {
            const caseInput = document.getElementById('targetCaseNo').value.trim().toUpperCase();
            if (caseInput === "") {
                alert("Please enter a Case Number first.");
                return;
            }
            currentCaseToDelete = caseInput;
            document.getElementById('confirmCaseDisplay').innerText = currentCaseToDelete;
            document.getElementById('confirmModal').style.display = 'flex';
        }

        function closeModal() {
            document.getElementById('confirmModal').style.display = 'none';
            currentCaseToDelete = "";
        }

        function executeDelete() {
            const btn = document.getElementById('confirmBtn');
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Deleting...';
            btn.disabled = true;

            let formData = new FormData();
            formData.append('case_no', currentCaseToDelete);

            fetch('api_delete_case.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                closeModal();
                btn.innerHTML = '<i class="fa-solid fa-trash"></i> Yes, Delete';
                btn.disabled = false;

                if (data.success) {
                    alert(`Case ${currentCaseToDelete} successfully deleted.`);
                    document.getElementById('targetCaseNo').value = "";
                } else {
                    alert("Error: " + data.message);
                }
            })
            .catch(error => {
                closeModal();
                btn.innerHTML = '<i class="fa-solid fa-trash"></i> Yes, Delete';
                btn.disabled = false;
                alert("System error: Could not communicate with the server.");
            });
        }
    </script>
</body>
</html>