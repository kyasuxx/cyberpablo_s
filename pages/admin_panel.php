<?php
session_start();
require_once 'config/connection.php';


$trend_labels = [];
$trend_data = [];
$month_sql = "SELECT DATE_FORMAT(incident_date, '%M') as month_name, COUNT(*) as count
              FROM incidents
              WHERE incident_date IS NOT NULL
              GROUP BY DATE_FORMAT(incident_date, '%Y-%m')
              ORDER BY incident_date ASC";
$m_result = $conn->query($month_sql);
while($row = $m_result->fetch_assoc()) {
    $trend_labels[] = $row['month_name'];
    $trend_data[] = $row['count'];
}

$brgy_labels = [];
$brgy_data = [];
$b_sql = "SELECT official_name, COUNT(*) as count
          FROM incidents
          JOIN barangays ON incidents.barangay_id = barangays.id
          GROUP BY barangay_id
          ORDER BY count DESC LIMIT 5";
$b_result = $conn->query($b_sql);
while($row = $b_result->fetch_assoc()) {
    $brgy_labels[] = $row['official_name'];
    $brgy_data[] = $row['count'];
}

$type_labels = [];
$type_data = [];
$t_sql = "SELECT incident_type, COUNT(*) as count FROM incidents GROUP BY incident_type";
$t_result = $conn->query($t_sql);
while($row = $t_result->fetch_assoc()) {
    $type_labels[] = $row['incident_type'];
    $type_data[] = $row['count'];
}

// --- PART 1.5: BUILD BARANGAY DICTIONARY FOR ENTITY EXTRACTION ---
$barangay_dictionary = [];
$all_b_sql = $conn->query("SELECT official_name FROM barangays");
if ($all_b_sql) {
    while($r = $all_b_sql->fetch_assoc()) {
        $clean_b = strtolower($r['official_name']);
        $clean_b = preg_replace('/[^a-z0-9 ]+/', ' ', $clean_b);
        $clean_b = preg_replace('/\b(brgy|barangay)\b/', '', $clean_b);
        $clean_b = preg_replace('/\b(sto|santo)\b/', 'santo', $clean_b);
        $clean_b = preg_replace('/\b(sta|santa)\b/', 'santa', $clean_b);
        $clean_b = trim(preg_replace('/\s+/', ' ', $clean_b));
        if (!empty($clean_b)) {
            $barangay_dictionary[] = $clean_b;
        }
    }
}

usort($barangay_dictionary, function($a, $b) {
    return strlen($b) - strlen($a);
});


// --- PART 2: ADVANCED ENTITY RESOLUTION ENGINE ---

$rejected_pairs = [];
$rej_result = $conn->query("SELECT case_a, case_b FROM rejected_links");
if ($rej_result) {
    while($r = $rej_result->fetch_assoc()){
        $rejected_pairs[] = $r['case_a'] . '-' . $r['case_b'];
        $rejected_pairs[] = $r['case_b'] . '-' . $r['case_a'];
    }
}

function calculateDistanceKM($lat1, $lon1, $lat2, $lon2) {
    if (!$lat1 || !$lon1 || !$lat2 || !$lon2) return 9999;
    $earthRadius = 6371;
    $latDelta = deg2rad($lat2 - $lat1);
    $lonDelta = deg2rad($lon2 - $lon1);
    $angle = 2 * asin(sqrt(pow(sin($latDelta / 2), 2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * pow(sin($lonDelta / 2), 2)));
    return $angle * $earthRadius;
}

function extractSuspectBarangay($addr, $dict) {
    if (empty($addr)) return '';
    $addr = strtolower(trim($addr));
    $addr = preg_replace('/[^a-z0-9 ]+/', ' ', $addr);
    $addr = preg_replace('/\b(sto|santo)\b/', 'santo', $addr);
    $addr = preg_replace('/\b(sta|santa)\b/', 'santa', $addr);
    $addr = trim(preg_replace('/\s+/', ' ', $addr));

    foreach ($dict as $b_name) {
        // Uses strict word boundaries to prevent "San" matching inside "San Pablo"
        if (preg_match('/\b' . preg_quote($b_name, '/') . '\b/', $addr)) {
            return $b_name;
        }
    }
    return '';
}

$cases = [];
$name_counts = [];
$invalid_identifiers = ['unidentified', 'unknown', 'n/a', 'none', 'unknown suspect', 'pending', '0', 'null', 'alias'];
$stopwords = ['victim', 'suspect', 'money', 'account', 'scam', 'online', 'bank', 'cash', 'report', 'police', 'person', 'using', 'through', 'pesos', 'from', 'that', 'with', 'were', 'told', 'said', 'asked', 'the', 'and', 'was'];

$result = $conn->query("SELECT id, case_no, incident_type, incident_date, status, accused, accused_address, accused_contact, modus_operandi, barangay_id, lat, lng, complainant FROM incidents");
while ($row = $result->fetch_assoc()) {
    $raw_accused = strtolower(trim($row['accused']));
    $check_accused = trim(str_replace(['(', ')', '[', ']', '"', "'", '*'], '', $raw_accused));

    $row['is_unidentified'] = in_array($check_accused, $invalid_identifiers) || empty($check_accused) || strlen($check_accused) < 3;
    $row['clean_accused'] = $check_accused;

    if (!$row['is_unidentified']) {
        $name_counts[$check_accused] = ($name_counts[$check_accused] ?? 0) + 1;
    }

    $clean_modus = preg_replace('/[^a-z0-9 ]+/', '', strtolower($row['modus_operandi']));
    $words = explode(' ', $clean_modus);
    $row['tokens'] = array_unique(array_filter($words, function($w) use ($stopwords) {
        return strlen($w) > 3 && !in_array($w, $stopwords);
    }));

    $row['clean_phone'] = preg_replace('/[^0-9]/', '', $row['accused_contact']);
    $row['clean_complainant'] = strtolower(trim($row['complainant']));


    $row['extracted_brgy'] = extractSuspectBarangay($row['accused_address'] ?? '', $barangay_dictionary);

    $cases[] = $row;
}

$syndicate_links = [];
$count = count($cases);

for ($i = 0; $i < $count; $i++) {
    for ($j = $i + 1; $j < $count; $j++) {
        $c1 = $cases[$i];
        $c2 = $cases[$j];

        if ($c1['is_unidentified'] && $c2['is_unidentified']) continue;
        if (in_array($c1['case_no'] . '-' . $c2['case_no'], $rejected_pairs)) continue;

        $score = 0;
        $reasons = [];
        $has_core_evidence = false;

        if (!empty($c1['clean_phone']) && strlen($c1['clean_phone']) >= 7 && $c1['clean_phone'] === $c2['clean_phone']) {
            $score += 50;
            $reasons[] = "Shared Phone Number";
            $has_core_evidence = true;
        }


        $raw_a = strtolower(trim($c1['accused_address'] ?? ''));
        $raw_b = strtolower(trim($c2['accused_address'] ?? ''));

        if (!empty($c1['extracted_brgy']) && !empty($c2['extracted_brgy'])) {
            // They both explicitly contain the SAME official barangay
            if ($c1['extracted_brgy'] === $c2['extracted_brgy']) {
                $score += 40;
                $reasons[] = "Shared Suspect Address (Brgy. " . ucwords($c1['extracted_brgy']) . ")";
                $has_core_evidence = true;
            }
        } elseif (!empty($raw_a) && !empty($raw_b) && strlen($raw_a) > 10 && !str_contains($raw_a, 'unknown') && !str_contains($raw_a, 'none')) {
            // Fallback: If no barangay was found, require a hyper-strict 90% match on the raw text
            similar_text($raw_a, $raw_b, $percent);
            if ($percent >= 90) {
                $score += 40;
                $reasons[] = "Shared Suspect Address (Exact Match)";
                $has_core_evidence = true;
            }
        }

        if (!$c1['is_unidentified'] && !$c2['is_unidentified']) {
            $name1 = $c1['clean_accused'];
            $name2 = $c2['clean_accused'];

            if ($name1 === $name2) {
                if ($name_counts[$name1] >= 4) {
                    $score += 25;
                    $reasons[] = "Frequent Name Match";
                } else {
                    $score += 50;
                    $reasons[] = "Exact Suspect Name Match";
                }
                $has_core_evidence = true;
            } elseif (metaphone($name1) == metaphone($name2) || levenshtein($name1, $name2) <= 2) {
                $score += 30;
                $reasons[] = "Fuzzy/Alias Name Match";
                $has_core_evidence = true;
            }
        }

        if (!empty($c1['clean_complainant']) && $c1['clean_complainant'] === $c2['clean_complainant'] && !in_array($c1['clean_complainant'], $invalid_identifiers)) {
            $score += 30;
            $reasons[] = "Serial Victim Target Match";
            $has_core_evidence = true;
        }

        $intersection = array_intersect($c1['tokens'], $c2['tokens']);
        $match_count = count($intersection);
        if ($match_count >= 3) {
            $pts = ($match_count >= 5) ? 30 : 15;
            $score += $pts;
            $reasons[] = "MO Pattern: $match_count keywords";
            $has_core_evidence = true;
        }

        if (!$has_core_evidence) continue;

        $type1 = strtolower(trim($c1['incident_type']));
        $type2 = strtolower(trim($c2['incident_type']));

        if ($type1 === $type2 && $type1 !== 'others' && $type1 !== 'not listed' && $type1 !== '') {
            $score += 10;
            if (str_starts_with($type1, 'others -')) {
                $reasons[] = "Exact Custom Crime Match";
            } else {
                $reasons[] = "Identical Crime Classification";
            }
        }

        $distance = calculateDistanceKM($c1['lat'], $c1['lng'], $c2['lat'], $c2['lng']);
        if ($distance <= 2.0) {
            $score += 20;
            $reasons[] = "Spatial Proximity < 2km";
        }

        if (!empty($c1['incident_date']) && !empty($c2['incident_date'])) {
            $days_apart = abs(strtotime($c1['incident_date']) - strtotime($c2['incident_date'])) / 86400;
            if ($days_apart <= 7) {
                $score += 10;
                $reasons[] = "Timeline Cluster: $days_apart days apart";
            }
        }

        if ($score >= 35) {
            $syndicate_links[] = [
                'score' => min(100, $score),
                'case_a' => $c1,
                'case_b' => $c2,
                'reasons' => $reasons,
                'distance' => round($distance, 2)
            ];
        }
    }
}

usort($syndicate_links, function($a, $b) { return $b['score'] <=> $a['score']; });

$unique_linked_cases = [];
foreach ($syndicate_links as $link) {
    if ($link['score'] >= 45) {
        $unique_linked_cases[] = $link['case_a']['case_no'];
        $unique_linked_cases[] = $link['case_b']['case_no'];
    }
}
$total_linked_cases = count(array_unique($unique_linked_cases));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Intelligence Analytics - CyberPablo</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="../assets/css/link_analysis.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <?php require_once 'header.php'; ?>

    <div class="header">
        <div>
            <h1 class="page-title">Intelligence Analytics</h1>
            <p class="page-subtitle">Multi-Factor Entity Resolution & Network Mapping</p>
        </div>
        <div class="header-stat-box">
            <span class="stat-number"><?= $total_linked_cases ?></span>
            <div class="stat-label">Cases Linked to Patterns</div>
            <?php if ($role === 'admin'): ?>
            <button type="button" class="btn-export" onclick="triggerOTP()" id="exportBtn" style="border:none; cursor:pointer;">Export Report</button>
            <?php endif; ?>
        </div>
    </div>

    <div class="chart-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-bottom: 25px;">
        <div class="card" style="background: white; padding: 20px; border-radius: 8px; border: 1px solid #e0e0e0;">
            <h3 style="margin-top: 0; font-size: 15px; color: #003366;">Monthly Crime Trend</h3>
            <div class="trend-container" style="height: 250px;"><canvas id="trendChart"></canvas></div>
        </div>
        <div class="card" style="background: white; padding: 20px; border-radius: 8px; border: 1px solid #e0e0e0;">
            <h3 style="margin-top: 0; font-size: 15px; color: #003366;">Crime Distribution</h3>
            <div class="pie-container" style="height: 250px;"><canvas id="pieChart"></canvas></div>
        </div>
        <div class="card" style="background: white; padding: 20px; border-radius: 8px; border: 1px solid #e0e0e0;">
            <h3 style="margin-top: 0; font-size: 15px; color: #003366;">Top Affected Barangays</h3>
            <div class="bar-container" style="height: 250px;"><canvas id="barChart"></canvas></div>
        </div>
    </div>

    <div style="display: flex; justify-content: space-between; align-items: center;">
        <h2 class="section-title" style="">Algorithmic Case Match Suggestions</h2>
    </div>

    <div class="modern-filters-card">
        <div class="search-bar-row">
            <input type="text" id="searchInput" onkeyup="debounceSearch()" class="main-search-input" placeholder="Search Case No, Names, Phone Number, or MO keywords...">
            <button type="button" class="btn-search" onclick="filterCases()">Search</button>
            <button type="button" class="btn-toggle-filters" onclick="toggleAdvancedFilters()">Advanced Filters</button>
            <button type="button" class="btn-clear" onclick="clearFilters()">Clear</button>
        </div>

        <div id="advancedFilters" class="advanced-filters-grid" style="display: none;">
            <div class="filter-group">
                <label>Confidence Tier</label>
                <select id="filterConfidence" class="modern-select" onchange="filterCases()">
                    <option value="all">All Visible Tiers</option>
                    <option value="high">High Confidence (75%+)</option>
                    <option value="medium">Medium Confidence (45-74%)</option>
                    <option value="low">Low Confidence (35-44%)</option>
                </select>
            </div>

            <div class="filter-group">
                <label>Network Size</label>
                <select id="filterNetwork" class="modern-select" onchange="filterCases()">
                    <option value="all">All Network Sizes</option>
                    <option value="pair">Isolated Pairs (Linked to 1)</option>
                    <option value="cluster">Syndicate Clusters (Linked to 2+)</option>
                </select>
            </div>

            <div class="filter-group">
                <label>Core Evidence Trigger</label>
                <select id="filterEvidence" class="modern-select" onchange="filterCases()">
                    <option value="all">All Evidence Types</option>
                    <option value="phone">Shared Phone Number</option>
                    <option value="address">Shared Suspect Address</option>
                    <option value="name">Exact / Fuzzy Name Match</option>
                    <option value="pattern">Modus Operandi Pattern</option>
                    <option value="spatial">Spatial Proximity (< 2km)</option>
                </select>
            </div>

            <div class="filter-group">
                <label>Case Status</label>
                <select id="filterStatus" class="modern-select" onchange="filterCases()">
                    <option value="all">All Statuses</option>
                    <option value="open">Involves Open/Active Case</option>
                    <option value="closed">Only Closed Cases</option>
                </select>
            </div>
        </div>
    </div>

    <p style="font-size: 13px; color: #666; margin-bottom: 20px; text-align: center;">
        The system assigns confidence scores based on multi-factor heuristic overlaps. <strong>All algorithmic correlations must be manually reviewed and confirmed by an authorized investigator.</strong>
    </p>

    <div style="margin-bottom: 40px;">

        <?php if (empty($syndicate_links)): ?>
            <div class="card"><p class="empty-msg" style="color: #666; padding: 20px; text-align:center;">No cross-case connections detected in the current database.</p></div>
        <?php else: ?>
            <?php foreach ($syndicate_links as $index => $link):
                if ($link['score'] >= 75) {
                    $conf_class = 'conf-high';
                    $border_class = 'border-high';
                    $conf_text = 'HIGH CONFIDENCE';
                    $card_visibility = '';
                } elseif ($link['score'] >= 45) {
                    $conf_class = 'conf-med';
                    $border_class = 'border-med';
                    $conf_text = 'MEDIUM CONFIDENCE';
                    $card_visibility = '';
                } else {
                    $conf_class = 'conf-low';
                    $border_class = 'border-low';
                    $conf_text = 'LOW CONFIDENCE (REVIEW)';
                    $card_visibility = 'low-conf-card';
                }

                $shared_phone = ($link['case_a']['clean_phone'] === $link['case_b']['clean_phone'] && !empty($link['case_a']['clean_phone']));

                // Set the UI flag so it highlights yellow
                $a_brgy = $link['case_a']['extracted_brgy'];
                $b_brgy = $link['case_b']['extracted_brgy'];
                $shared_address = false;
                if (!empty($a_brgy) && !empty($b_brgy) && $a_brgy === $b_brgy) {
                    $shared_address = true;
                } else {
                    $raw_a = strtolower(trim($link['case_a']['accused_address']));
                    $raw_b = strtolower(trim($link['case_b']['accused_address']));
                    if (!empty($raw_a) && !empty($raw_b) && strlen($raw_a) > 10) {
                        similar_text($raw_a, $raw_b, $percent);
                        if ($percent >= 90) $shared_address = true;
                    }
                }

                $shared_name = (!$link['case_a']['is_unidentified'] && !$link['case_b']['is_unidentified'] &&
                               (strtolower(trim($link['case_a']['accused'])) === strtolower(trim($link['case_b']['accused']))));
                $shared_victim = ($link['case_a']['clean_complainant'] === $link['case_b']['clean_complainant'] && !empty($link['case_a']['clean_complainant']));


                $mo_overlap_count = count(array_intersect($link['case_a']['tokens'], $link['case_b']['tokens']));
                $shared_modus = ($mo_overlap_count >= 3);
                $mo_mismatch = ($mo_overlap_count < 2 && !empty($link['case_a']['tokens']) && !empty($link['case_b']['tokens']));
            ?>
            <div class="dossier-card <?= $border_class ?> <?= $card_visibility ?>" id="match-card-<?= $index ?>">
                <div class="dossier-header">
                    <div>
                        <strong style="font-size: 11px; color: #888; text-transform: uppercase; display:block; margin-bottom: 5px;">Signals Detected:</strong>
                        <?php foreach ($link['reasons'] as $reason): ?>
                            <span class="reason-tag"><?= $reason ?></span>
                        <?php endforeach; ?>
                    </div>
                    <div class="confidence-badge <?= $conf_class ?>">
                        <?= $conf_text ?> (<?= $link['score'] ?>%)
                    </div>
                </div>

                <div class="case-comparison">
                    <div class="case-box">
                        <div style="display:flex; justify-content: space-between; align-items:center;">
                            <h4><?= htmlspecialchars($link['case_a']['case_no']) ?></h4>
                            <span class="case-status <?= str_contains(strtolower($link['case_a']['status']), 'open') ? 'status-open' : (str_contains(strtolower($link['case_a']['status']), 'investigation') ? 'status-investigating' : 'status-closed') ?>">
                                <?= htmlspecialchars($link['case_a']['status']) ?>
                            </span>
                        </div>

                        <?php if (!$link['case_a']['is_unidentified']): ?>
                        <div class="case-data-row <?= $shared_name ? 'highlight-match' : '' ?>">
                            <span style="color:#666; font-size:10px; display:block; font-weight:normal; text-transform: uppercase;">Suspect Identity</span>
                            <?= htmlspecialchars($link['case_a']['accused']) ?>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($link['case_a']['clean_phone'])): ?>
                        <div class="case-data-row <?= $shared_phone ? 'highlight-match' : '' ?>">
                            <span style="color:#666; font-size:10px; display:block; font-weight:normal; text-transform: uppercase;">Contact Traced</span>
                            <?= htmlspecialchars($link['case_a']['accused_contact']) ?>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($link['case_a']['accused_address'])): ?>
                        <div class="case-data-row <?= $shared_address ? 'highlight-match' : '' ?>">
                            <span style="color:#666; font-size:10px; display:block; font-weight:normal; text-transform: uppercase;">Known Address</span>
                            <?= htmlspecialchars($link['case_a']['accused_address']) ?>
                        </div>
                        <?php endif; ?>

                        <div class="case-data-row <?= $shared_victim ? 'highlight-match' : '' ?>">
                            <span style="color:#666; font-size:10px; display:block; font-weight:normal; text-transform: uppercase;">Complainant / Victim</span>
                            <?= htmlspecialchars($link['case_a']['complainant'] ?: 'Not Specified') ?>
                        </div>

                        <div class="case-data-row <?= $shared_modus ? 'highlight-match' : ($mo_mismatch ? 'mismatch-match' : '') ?>">
                            <span style="color:#666; font-size:10px; display:block; font-weight:normal; text-transform: uppercase;">Modus Operandi</span>
                            <div style="font-style: italic;">"<?= nl2br(htmlspecialchars($link['case_a']['modus_operandi'] ?: 'Not Specified')) ?>"</div>
                        </div>

                        <div class="case-meta" style="margin-top: 10px; border-top: 1px solid #ddd; padding-top: 10px;">
                            <span><strong>Type:</strong> <?= htmlspecialchars(str_replace('Republic Act No. 10175 ', '', $link['case_a']['incident_type'])) ?></span>
                            <span><strong>Date:</strong> <?= htmlspecialchars(date('M d, Y', strtotime($link['case_a']['incident_date']))) ?></span>
                        </div>
                    </div>

                    <div class="link-connector">
                        <div class="link-line"></div>
                        <div class="link-icon">&#8644;</div>
                        <div class="link-line"></div>
                        <div style="position:absolute; bottom: -10px; font-size: 10px; color: #888; font-weight: bold; width: 60px; text-align: center;">
                            <?= $link['distance'] < 9999 ? $link['distance'] . ' km apart' : '' ?>
                        </div>
                    </div>

                    <div class="case-box">
                        <div style="display:flex; justify-content: space-between; align-items:center;">
                            <h4><?= htmlspecialchars($link['case_b']['case_no']) ?></h4>
                            <span class="case-status <?= str_contains(strtolower($link['case_b']['status']), 'open') ? 'status-open' : (str_contains(strtolower($link['case_b']['status']), 'investigation') ? 'status-investigating' : 'status-closed') ?>">
                                <?= htmlspecialchars($link['case_b']['status']) ?>
                            </span>
                        </div>

                        <?php if (!$link['case_b']['is_unidentified']): ?>
                        <div class="case-data-row <?= $shared_name ? 'highlight-match' : '' ?>">
                            <span style="color:#666; font-size:10px; display:block; font-weight:normal; text-transform: uppercase;">Suspect Identity</span>
                            <?= htmlspecialchars($link['case_b']['accused']) ?>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($link['case_b']['clean_phone'])): ?>
                        <div class="case-data-row <?= $shared_phone ? 'highlight-match' : '' ?>">
                            <span style="color:#666; font-size:10px; display:block; font-weight:normal; text-transform: uppercase;">Contact Traced</span>
                            <?= htmlspecialchars($link['case_b']['accused_contact']) ?>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($link['case_b']['accused_address'])): ?>
                        <div class="case-data-row <?= $shared_address ? 'highlight-match' : '' ?>">
                            <span style="color:#666; font-size:10px; display:block; font-weight:normal; text-transform: uppercase;">Known Address</span>
                            <?= htmlspecialchars($link['case_b']['accused_address']) ?>
                        </div>
                        <?php endif; ?>

                        <div class="case-data-row <?= $shared_victim ? 'highlight-match' : '' ?>">
                            <span style="color:#666; font-size:10px; display:block; font-weight:normal; text-transform: uppercase;">Complainant / Victim</span>
                            <?= htmlspecialchars($link['case_b']['complainant'] ?: 'Not Specified') ?>
                        </div>

                        <div class="case-data-row <?= $shared_modus ? 'highlight-match' : ($mo_mismatch ? 'mismatch-match' : '') ?>">
                            <span style="color:#666; font-size:10px; display:block; font-weight:normal; text-transform: uppercase;">Modus Operandi</span>
                            <div style="font-style: italic;">"<?= nl2br(htmlspecialchars($link['case_b']['modus_operandi'] ?: 'Not Specified')) ?>"</div>
                        </div>

                        <div class="case-meta" style="margin-top: 10px; border-top: 1px solid #ddd; padding-top: 10px;">
                            <span><strong>Type:</strong> <?= htmlspecialchars(str_replace('Republic Act No. 10175 ', '', $link['case_b']['incident_type'])) ?></span>
                            <span><strong>Date:</strong> <?= htmlspecialchars(date('M d, Y', strtotime($link['case_b']['incident_date']))) ?></span>
                        </div>
                    </div>
                </div>

                <div class="unified-action-bar">
                    <div style="font-size: 12px; color: #555; margin-bottom: 8px;">
                        <strong style="color:#d32f2f;">Action Required:</strong> Review overlapping data points to verify identity.
                    </div>
                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                        <a href="cases.php?search=<?= urlencode($link['case_a']['case_no']) ?>" target="_blank" class="btn-investigate">View Case A</a>
                        <a href="cases.php?search=<?= urlencode($link['case_b']['case_no']) ?>" target="_blank" class="btn-investigate">View Case B</a>

                        <a href="dashboard.php?case_a=<?= urlencode($link['case_a']['case_no']) ?>&case_b=<?= urlencode($link['case_b']['case_no']) ?>&lat=<?= $link['case_a']['lat'] ?>&lng=<?= $link['case_a']['lng'] ?>" target="_blank" class="btn-investigate" style="background: #6f42c1; border-color: #5e35b1; color: white;">
                            <i class="fa-solid fa-map-location-dot"></i> Map Both
                        </a>

                        <button class="btn-reject" onclick="rejectMatch('<?= $link['case_a']['case_no'] ?>', '<?= $link['case_b']['case_no'] ?>', 'match-card-<?= $index ?>')">Reject</button>
                        <button class="btn-confirm" onclick="confirmMatch('<?= $link['case_a']['case_no'] ?>', '<?= $link['case_b']['case_no'] ?>', 'match-card-<?= $index ?>')">Confirm</button>
                    </div>
                </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

<div class="modal-overlay" id="otpModal">
    <div class="otp-modal">
        <h3 style="margin-top:0; color:#003366;">Security Verification</h3>
        <p style="font-size: 13px; color: #555;">An authorization code has been sent to the Admin email. Enter it below to download the Intelligence Report.</p>
        <input type="text" id="otpCode" class="otp-input" placeholder="000000" maxlength="6" autocomplete="off">
        <div id="otpError" style="color: #d32f2f; font-size: 12px; margin-bottom: 10px; display: none;">Invalid or expired code.</div>
        <button type="button" class="btn-verify" onclick="verifyOTP()" id="verifyBtn">Verify & Download</button>
        <button type="button" class="btn-cancel" onclick="closeModal()">Cancel</button>
    </div>
</div>

<script>
    let searchDebounceTimeout = null;
    function debounceSearch() {
        clearTimeout(searchDebounceTimeout);
        searchDebounceTimeout = setTimeout(function() {
            filterCases();
        }, 300);
    }

    let caseFrequencies = {};
    function calculateNetworkSizes() {
        caseFrequencies = {};
        document.querySelectorAll('.dossier-card').forEach(card => {
            let headers = card.querySelectorAll('h4');
            if(headers.length === 2) {
                let c1 = headers[0].textContent.trim();
                let c2 = headers[1].textContent.trim();
                caseFrequencies[c1] = (caseFrequencies[c1] || 0) + 1;
                caseFrequencies[c2] = (caseFrequencies[c2] || 0) + 1;
            }
        });
    }
    calculateNetworkSizes();

    function updateLinkedCasesCount() {
        let cards = document.querySelectorAll('.dossier-card');
        let uniqueCases = new Set();

        cards.forEach(card => {
            if (window.getComputedStyle(card).display !== 'none') {
                let caseHeaders = card.querySelectorAll('h4');
                if(caseHeaders.length === 2) {
                    uniqueCases.add(caseHeaders[0].textContent.trim());
                    uniqueCases.add(caseHeaders[1].textContent.trim());
                }
            }
        });

        let statNumberEl = document.querySelector('.stat-number');
        if (statNumberEl) {
            statNumberEl.innerText = uniqueCases.size;
        }
    }

    function toggleAdvancedFilters() {
        const grid = document.getElementById('advancedFilters');
        grid.style.display = (grid.style.display === 'none' || grid.style.display === '') ? 'grid' : 'none';
    }

    function clearFilters() {
        document.getElementById('searchInput').value = '';
        document.getElementById('filterConfidence').value = 'all';
        document.getElementById('filterNetwork').value = 'all';
        document.getElementById('filterEvidence').value = 'all';
        document.getElementById('filterStatus').value = 'all';
        filterCases();
    }

    function filterCases() {
        let input = document.getElementById('searchInput').value.toLowerCase();
        let confFilter = document.getElementById('filterConfidence').value;
        let netFilter = document.getElementById('filterNetwork').value;
        let evFilter = document.getElementById('filterEvidence').value;
        let statusFilter = document.getElementById('filterStatus').value;

        let cards = document.getElementsByClassName('dossier-card');

        for (let i = 0; i < cards.length; i++) {
            let card = cards[i];
            let isMatch = true;
            let cardText = card.textContent.toLowerCase();

            if (input !== "" && !cardText.includes(input)) isMatch = false;

            if (confFilter !== 'all') {
                if (confFilter === 'high' && !card.classList.contains('border-high')) isMatch = false;
                if (confFilter === 'medium' && !card.classList.contains('border-med')) isMatch = false;
                if (confFilter === 'low' && !card.classList.contains('border-low')) isMatch = false;
            } else {
                if (card.classList.contains('border-low') && input === "") isMatch = false;
            }

            if (evFilter !== 'all') {
                if (evFilter === 'phone' && !cardText.includes('shared phone')) isMatch = false;
                if (evFilter === 'address' && !cardText.includes('shared suspect address')) isMatch = false;
                if (evFilter === 'name' && !cardText.includes('name match')) isMatch = false;
                if (evFilter === 'pattern' && !cardText.includes('mo pattern')) isMatch = false;
                if (evFilter === 'spatial' && !cardText.includes('spatial proximity')) isMatch = false;
            }

            if (statusFilter !== 'all') {
                let hasOpen = cardText.includes('open') || cardText.includes('investigation');
                if (statusFilter === 'open' && !hasOpen) isMatch = false;
                if (statusFilter === 'closed' && hasOpen) isMatch = false;
            }

            if (netFilter !== 'all') {
                let headers = card.querySelectorAll('h4');
                if(headers.length === 2) {
                    let c1 = headers[0].textContent.trim();
                    let c2 = headers[1].textContent.trim();
                    let maxFreq = Math.max(caseFrequencies[c1], caseFrequencies[c2]);

                    if (netFilter === 'pair' && maxFreq > 1) isMatch = false;
                    if (netFilter === 'cluster' && maxFreq === 1) isMatch = false;
                }
            }

            if (isMatch) {
                card.style.display = 'block';
                applyHighlight(card, input);
            } else {
                card.style.display = 'none';
                removeHighlight(card);
            }
        }

        updateLinkedCasesCount();
    }

    function removeHighlight(card) {
        let marks = card.querySelectorAll('mark.search-highlight');
        marks.forEach(mark => {
            let parent = mark.parentNode;
            parent.replaceChild(document.createTextNode(mark.textContent), mark);
            parent.normalize();
        });
    }

    function applyHighlight(card, input) {
        removeHighlight(card);
        if (input === "") return;

        let dataRows = card.querySelectorAll('.case-data-row, h4');
        dataRows.forEach(row => {
            if(row.textContent.toLowerCase().includes(input)) {
                let escapedInput = input.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                let regex = new RegExp("(" + escapedInput + ")(?![^<]*>)", "gi");

                let html = row.innerHTML;
                row.innerHTML = html.replace(regex, '<mark class="search-highlight" style="background-color: #ffeb3b; padding: 0 2px; border-radius: 2px;">$1</mark>');
            }
        });
    }

    function rejectMatch(caseA, caseB, cardId) {
        document.getElementById(cardId).style.display = 'none';

        let formData = new FormData();
        formData.append('case_a', caseA);
        formData.append('case_b', caseB);
        fetch('api_reject_match.php', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            if(!data.success) {
                alert("Warning: Could not save rejection.");
                document.getElementById(cardId).style.display = 'block';
            } else {
                document.getElementById(cardId).remove();
                calculateNetworkSizes();
                updateLinkedCasesCount();
            }
        });
    }

    function confirmMatch(caseA, caseB, cardId) {
        let formData = new FormData();
        formData.append('case_a', caseA);
        formData.append('case_b', caseB);
        fetch('api_confirm_match.php', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            if(data.success) {
                alert("Case Link Confirmed! Audit log successfully updated.");
                let card = document.getElementById(cardId);
                card.style.opacity = '0.5';
                card.style.pointerEvents = 'none';
            } else { alert("Warning: Could not save confirmation."); }
        });
    }

    new Chart(document.getElementById('trendChart'), {
        type: 'line',
        data: {
            labels: <?= json_encode($trend_labels) ?>,
            datasets: [{ label: 'New Cases', data: <?= json_encode($trend_data) ?>, borderColor: '#003366', tension: 0.3, fill: true, backgroundColor: 'rgba(0, 51, 102, 0.1)' }]
        },
        options: { responsive: true, maintainAspectRatio: false }
    });

    new Chart(document.getElementById('pieChart'), {
        type: 'doughnut',
        data: {
            labels: <?= json_encode($type_labels) ?>,
            datasets: [{
                data: <?= json_encode($type_data) ?>,
                backgroundColor: ['#f44336', '#9c27b0', '#3f51b5', '#009688', '#ff9800', '#795548']
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            layout: { padding: { bottom: 10 } },
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { boxWidth: 12, font: { size: 10 }, padding: 10 }
                }
            }
        }
    });

    new Chart(document.getElementById('barChart'), {
        type: 'bar',
        data: {
            labels: <?= json_encode($brgy_labels) ?>,
            datasets: [{
                label: 'Total Incidents',
                data: <?= json_encode($brgy_data) ?>,
                backgroundColor: '#d94c23',
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            layout: { padding: { bottom: 25 } },
            scales: {
                x: { ticks: { maxRotation: 45, minRotation: 45, font: { size: 10 }, autoSkip: false } },
                y: { beginAtZero: true, ticks: { precision: 0 } }
            },
            plugins: { legend: { display: false } }
        }
    });

    function triggerOTP() {
        const btn = document.getElementById('exportBtn');
        btn.innerText = "Sending...";
        btn.disabled = true;

        fetch('api_otp.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=send' })
        .then(response => response.json())
        .then(data => {
            btn.innerText = "Export Report";
            btn.disabled = false;
            if(data.success) {
                document.getElementById('otpModal').style.display = 'flex';
                document.getElementById('otpCode').value = '';
                document.getElementById('otpError').style.display = 'none';
                document.getElementById('otpCode').focus();
            } else { alert("Error sending OTP: " + (data.message || "Please check mailer.")); }
        }).catch(error => {
            btn.innerText = "Export Report";
            btn.disabled = false;
            alert("System error communicating with the mail server.");
        });
    }

    function verifyOTP() {
        const code = document.getElementById('otpCode').value;
        const btn = document.getElementById('verifyBtn');
        const errorDiv = document.getElementById('otpError');
        if (code.length !== 6) { errorDiv.innerText = "Please enter a 6-digit code."; errorDiv.style.display = 'block'; return; }

        btn.innerText = "Verifying...";
        btn.disabled = true;

        fetch('api_otp.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=verify&otp=' + encodeURIComponent(code) })
        .then(response => response.json())
        .then(data => {
            btn.innerText = "Verify & Download";
            btn.disabled = false;
            if(data.success) {
                closeModal();
                window.location.href = 'export_intelligence.php';
            } else {
                errorDiv.innerText = data.message || "Invalid code.";
                errorDiv.style.display = 'block';
            }
        })
        .catch(error => {
            console.error("Verification Error:", error);
            btn.innerText = "Verify & Download";
            btn.disabled = false;
            errorDiv.innerText = "System error communicating with the server.";
            errorDiv.style.display = 'block';
        });
    }

    function closeModal() { document.getElementById('otpModal').style.display = 'none'; }
</script>
</body>
</html>
