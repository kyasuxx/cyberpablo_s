<?php
session_start();
require_once 'config/connection.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
$current_user_id = $_SESSION['user_id'];
$is_admin = ($_SESSION['role'] === 'admin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Tactical POI Management - CyberPablo</title>
    <link rel="stylesheet" href="../assets/css/dashboard.css"> 
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        .map-wrapper { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); border: 1px solid #e0e0e0; margin-top: 20px; }
        #poi-map { height: 70vh; width: 100%; border-radius: 6px; border: 1px solid #ccc; cursor: crosshair; }
        
        /* Custom Modal Styles */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); z-index: 2000; justify-content: center; align-items: center; }
        .pin-modal { background: white; padding: 25px; border-radius: 8px; width: 350px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
        .pin-modal h3 { margin-top: 0; color: #003366; }
        .pin-input { width: 100%; padding: 10px; margin-bottom: 15px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        
        /* Color Picker Specific Styling */
        input[type="color"].pin-input { height: 45px; padding: 2px; cursor: pointer; }

        .btn-group { display: flex; justify-content: flex-end; gap: 10px; }
        .btn-save { background: #003366; color: white; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer; }
        .btn-cancel { background: #e0e0e0; color: #333; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer; }
        
        /* Popup Buttons */
        .popup-actions { margin-top: 10px; display: flex; gap: 5px; }
        .btn-edit { background: #ff9800; color: white; border: none; padding: 4px 8px; border-radius: 3px; font-size: 11px; cursor: pointer; }
        .btn-del { background: #d32f2f; color: white; border: none; padding: 4px 8px; border-radius: 3px; font-size: 11px; cursor: pointer; }
    </style>
</head>
<body>
    <?php require_once 'header.php'; ?>

    <div class="container" style="padding: 20px; max-width: 1200px; margin: auto;">
        <div>
            <h1 style="color: #d32f2f; margin-bottom: 5px;"><i class="fa-solid fa-location-crosshairs"></i> Tactical POI Management</h1>
            <p style="color: #666; margin-top: 0;">Click anywhere on the map to drop a new Tactical Point of Interest. Click existing pins to Edit or Delete them.</p>
        </div>

        <div class="map-wrapper">
            <div id="poi-map"></div>
        </div>
    </div>

    <div class="modal-overlay" id="pinModal">
        <div class="pin-modal">
            <h3 id="modalTitle">Add Tactical Pin</h3>
            <input type="hidden" id="pinId">
            <input type="hidden" id="pinLat">
            <input type="hidden" id="pinLng">
            
            <label style="font-size: 12px; color: #666; font-weight: bold;">Location Label</label>
            <input type="text" id="pinLabel" class="pin-input" placeholder="Type a custom location name...">
            
            <label style="font-size: 12px; color: #666; font-weight: bold;">Marker Color</label>
            <input type="color" id="pinColor" class="pin-input" value="#d32f2f">
            
            <div class="btn-group">
                <button type="button" class="btn-cancel" onclick="closeModal()">Cancel</button>
                <button type="button" class="btn-save" id="saveBtn" onclick="savePin()">Save Pin</button>
            </div>
        </div>
    </div>

    <script>
        const currentUserId = <?= $current_user_id ?>;
        const isAdmin = <?= $is_admin ? 'true' : 'false' ?>;

        var map = L.map('poi-map').setView([14.0706, 121.3255], 13);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '© OpenStreetMap'
        }).addTo(map);

        // NEW DYNAMIC SVG RENDERING SYSTEM (Supports any Hex Color from the Color Wheel)
        function getIcon(hexColor) {
            const svgIcon = `
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 36" width="30" height="45">
                <path fill="${hexColor}" stroke="#ffffff" stroke-width="2" d="M12 0C5.373 0 0 5.373 0 12c0 8.4 12 24 12 24s12-15.6 12-24c0-6.627-5.373-12-12-12z"/>
                <circle cx="12" cy="11" r="5" fill="#ffffff" fill-opacity="0.8"/>
            </svg>`;

            return L.divIcon({
                html: svgIcon,
                className: '', // Removes Leaflet's default white square styling
                iconSize: [30, 45],
                iconAnchor: [15, 45],
                popupAnchor: [0, -40]
            });
        }

        let markersLayer = L.layerGroup().addTo(map);

        function loadPins() {
            markersLayer.clearLayers();
            fetch('api_pin.php')
            .then(res => res.json())
            .then(data => {
                if(data.success) {
                    data.data.forEach(pin => {
                        let canEdit = (isAdmin || parseInt(pin.user_id) === currentUserId);
                        
                        let popupHTML = `
                            <strong style="font-size:14px;">${pin.title}</strong><br>
                            <small style="color:#666;">By: ${pin.username}</small>
                        `;

                        if (canEdit) {
                            let safeTitle = pin.title.replace(/'/g, "\\'");
                            popupHTML += `
                                <div class="popup-actions">
                                    <button class="btn-edit" onclick="openEditModal(${pin.id}, '${safeTitle}', '${pin.color}')"><i class="fa-solid fa-pen"></i> Edit</button>
                                    <button class="btn-del" onclick="deletePin(${pin.id})"><i class="fa-solid fa-trash"></i></button>
                                </div>
                            `;
                        }

                        L.marker([pin.lat, pin.lng], {icon: getIcon(pin.color)})
                         .addTo(markersLayer)
                         .bindPopup(popupHTML);
                    });
                }
            });
        }
        loadPins();

        // MAP CLICK: Open Modal for New Pin
        map.on('click', function(e) {
            document.getElementById('modalTitle').innerText = "Add Tactical Pin";
            document.getElementById('pinId').value = "";
            document.getElementById('pinLabel').value = "";
            document.getElementById('pinColor').value = "#d32f2f"; // Default Red
            document.getElementById('pinLat').value = e.latlng.lat;
            document.getElementById('pinLng').value = e.latlng.lng;
            document.getElementById('pinModal').style.display = "flex";
        });

        function openEditModal(id, title, color) {
            map.closePopup();
            document.getElementById('modalTitle').innerText = "Edit Tactical Pin";
            document.getElementById('pinId').value = id;
            document.getElementById('pinLabel').value = title;
            document.getElementById('pinColor').value = color; // Pre-load existing hex color
            document.getElementById('pinModal').style.display = "flex";
        }

        function closeModal() {
            document.getElementById('pinModal').style.display = "none";
        }

        // UPGRADED SAVE FUNCTION WITH ROBUST ERROR CATCHING
        function savePin() {
            let id = document.getElementById('pinId').value;
            let title = document.getElementById('pinLabel').value.trim();
            let color = document.getElementById('pinColor').value; // Grabs the hex value
            let lat = document.getElementById('pinLat').value;
            let lng = document.getElementById('pinLng').value;

            if (title === "") { alert("Please enter a label."); return; }

            let formData = new FormData();
            formData.append('action', id === "" ? 'create' : 'update');
            formData.append('title', title);
            formData.append('color', color);
            
            if (id === "") {
                formData.append('lat', lat);
                formData.append('lng', lng);
            } else {
                formData.append('pin_id', id);
            }

            const btn = document.getElementById('saveBtn');
            btn.innerText = "Saving..."; btn.disabled = true;

            // Notice we use .text() first to catch raw PHP errors
            fetch('api_pin.php', { method: 'POST', body: formData })
            .then(res => res.text())
            .then(text => {
                btn.innerText = "Save Pin"; btn.disabled = false;
                try {
                    let data = JSON.parse(text);
                    if (data.success) {
                        closeModal();
                        loadPins();
                    } else { alert("API Warning: " + data.message); }
                } catch (e) {
                    console.error("RAW PHP ERROR:", text);
                    alert("FATAL ERROR: The server failed to save. Check the F12 Browser Console to see the exact PHP/SQL error.");
                }
            });
        }

        function deletePin(id) {
            if(confirm("Are you sure you want to delete this tactical pin?")) {
                let formData = new FormData();
                formData.append('action', 'delete');
                formData.append('pin_id', id);

                fetch('api_pin.php', { method: 'POST', body: formData })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        map.closePopup();
                        loadPins();
                    } else { alert(data.message); }
                });
            }
        }
    </script>
</body>
</html>