<?php
// spatial_helper.php

/**
 * Checks if a Point (Lat/Lng) falls inside a Polygon (array of Lng/Lat coordinates)
 * Uses the Ray-Casting Algorithm
 */
function isPointInPolygon($pointLat, $pointLng, $polygon) {
    $inside = false;
    $j = count($polygon) - 1;
    
    for ($i = 0; $i < count($polygon); $i++) {
        // GeoJSON stores coordinates as [Longitude, Latitude]
        $xi = $polygon[$i][0]; // Polygon Longitude
        $yi = $polygon[$i][1]; // Polygon Latitude
        $xj = $polygon[$j][0]; // Previous Longitude
        $yj = $polygon[$j][1]; // Previous Latitude

        $intersect = (($yi > $pointLat) != ($yj > $pointLat))
            && ($pointLng < ($xj - $xi) * ($pointLat - $yi) / ($yj - $yi) + $xi);
            
        if ($intersect) {
            $inside = !$inside;
        }
        $j = $i;
    }
    return $inside;
}

/**
 * Takes a coordinate and checks it against the GeoJSON file to find the true Barangay
 */
function getTrueBarangayFromGeoJSON($lat, $lng, $geojson_path) {
    if (!file_exists($geojson_path)) return false;
    
    $json_data = file_get_contents($geojson_path);
    $geo_array = json_decode($json_data, true);

    if (!isset($geo_array['features'])) return false;

    foreach ($geo_array['features'] as $feature) {
        $brgyName = $feature['properties']['adm4_en']; // The barangay name in your JSON
        $geometryType = $feature['geometry']['type'];
        $coordinates = $feature['geometry']['coordinates'];

        if ($geometryType === 'Polygon') {
            if (isPointInPolygon($lat, $lng, $coordinates[0])) {
                return $brgyName;
            }
        } elseif ($geometryType === 'MultiPolygon') {
            foreach ($coordinates as $polygon) {
                if (isPointInPolygon($lat, $lng, $polygon[0])) {
                    return $brgyName;
                }
            }
        }
    }
    return false; // The pin is outside San Pablo City entirely!
}
?>