<!-- NEW: Crime Type Statistics -->
        <?php if ($crime_stats->num_rows > 0): ?>
        <div class="crime-stats">
            <h3>Crime Type Distribution <?= ($date_from || $date_to || $month || $year) ? '(Filtered Period)' : '(All Time)' ?></h3>
            <?php 
            $max_count = 0;
            $crime_data = [];
            while ($stat = $crime_stats->fetch_assoc()) {
                $crime_data[] = $stat;
                if ($stat['count'] > $max_count) $max_count = $stat['count'];
            }
            
            foreach ($crime_data as $stat): 
                $percentage = ($max_count > 0) ? ($stat['count'] / $max_count * 100) : 0;
            ?>
            <div class="crime-stat-item">
                <span style="min-width: 150px; font-weight: 600;"><?= htmlspecialchars($stat['incident_type']) ?></span>
                <div class="crime-stat-bar">
                    <div class="crime-stat-fill" style="width: <?= $percentage ?>%;"></div>
                </div>
                <span class="crime-stat-count"><?= $stat['count'] ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>


<a href="link_analysis.php" class="nav-item <?= $current_page == 'link_analysis.php' ? 'active' : '' ?>">
            Intelligence
        </a>

<li>
                        <a href="admin_panel.php" class="<?= $current_page == 'admin_panel.php' ? 'active' : '' ?>">
                            <span class="menu-title"><i class="fa-solid fa-house"></i>Dashboard Home</span>
                            <!-- <span class="menu-desc">Return to central panel</span> -->
                        </a>
                    </li>