<?php
/*
Plugin Name: PVTL Server Logger
Plugin URI: https://pivotalagency.com.au
Description: Monitors website uptime and sends email notifications for downtime.
Version: 1.5
Author: Pivotal Agency Pty Ltd
Author URI: https://pivotalagency.com.au
License: GPLv2 or later
*/

// Exit if accessed directly
if ( !defined( 'ABSPATH' ) ) exit;

// Dynamic site URL retrieval
define( 'WDM_URL_TO_MONITOR', get_option('siteurl') );
define( 'WDM_LOG_FILE', plugin_dir_path( __FILE__ ) . '/downtime-log.txt' );
define( 'WDM_EMAIL', 'erin.w@pivotalagency.com.au' );
define( 'WDM_NOTIFICATION_INTERVAL', 900 ); // 15 minutes in seconds
define( 'WDM_CPU_THRESHOLD', 80 ); // CPU usage threshold in percentage
define( 'WDM_MEMORY_THRESHOLD', 80 ); // Memory usage threshold in percentage
define( 'WDM_IO_THRESHOLD', 80 ); // Disk I/O threshold in percentage

// Option name for storing last notification time
define( 'WDM_LAST_NOTIFICATION_OPTION', 'wdm_last_notification_time' );

// Function to check website and server status
function wdm_check_website_and_server_status() {
    $response = wp_remote_get( WDM_URL_TO_MONITOR );

    $timestamp = current_time( 'mysql' );
    $status_code = wp_remote_retrieve_response_code( $response );
    $cpu_load = sys_getloadavg()[0] * 100; // Simplified for 1-minute average CPU load
    $memory_usage = memory_get_usage(true) / (1024 * 1024); // Memory in MB
    $io_usage = wdm_get_io_usage(); // Disk I/O usage as a percentage of the 25 MB/s limit

    // Get the last notification time from the database
    $last_notification = get_option(WDM_LAST_NOTIFICATION_OPTION, 0);
    $time_since_last_notification = time() - $last_notification;

    // Log and notify on downtime or faults
    if ( $status_code != 200 || $cpu_load > WDM_CPU_THRESHOLD || $memory_usage > WDM_MEMORY_THRESHOLD || $io_usage > WDM_IO_THRESHOLD ) {
        $alert_condition = ($status_code != 200) ? "DOWN" : "RESOURCE SPIKE";
        $log_entry = "[{$timestamp}] {$alert_condition}\nURL: " . WDM_URL_TO_MONITOR . "\nStatus Code: {$status_code}\nCPU Load: {$cpu_load}%\nMemory Usage: {$memory_usage} MB\nDisk I/O: {$io_usage}%\n";
        file_put_contents( WDM_LOG_FILE, $log_entry, FILE_APPEND );

        // Send notification if outside the interval
        if ($time_since_last_notification > WDM_NOTIFICATION_INTERVAL) {
            $subject = ($alert_condition === "DOWN") ? "Alert: Website Down at {$timestamp}" : "Alert: Resource Spike on Server";
            $message = $log_entry;
            wp_mail( WDM_EMAIL, $subject, $message );

            // Update the last notification time
            update_option(WDM_LAST_NOTIFICATION_OPTION, time());
        }
    } else {
        // Log uptime if no alerts are triggered
        $log_entry = "[{$timestamp}] UP\nURL: " . WDM_URL_TO_MONITOR . "\n";
        file_put_contents( WDM_LOG_FILE, $log_entry, FILE_APPEND );
    }
}

// Function to monitor I/O usage as a rate in MB/s
function wdm_get_io_usage() {
    $io_data_file = plugin_dir_path( __FILE__ ) . 'logs/previous_io_data.txt';
    
    $current_usage = getrusage();
    $current_io_reads = $current_usage['ru_inblock'];
    $current_io_writes = $current_usage['ru_oublock'];
    $current_time = time();
    
    if (file_exists($io_data_file)) {
        $previous_data = json_decode(file_get_contents($io_data_file), true);
        $previous_io_reads = $previous_data['io_reads'];
        $previous_io_writes = $previous_data['io_writes'];
        $previous_time = $previous_data['time'];
    } else {
        $previous_io_reads = $current_io_reads;
        $previous_io_writes = $current_io_writes;
        $previous_time = $current_time;
    }

    $time_difference = max(1, $current_time - $previous_time);

    $io_read_delta = max(0, $current_io_reads - $previous_io_reads);
    $io_write_delta = max(0, $current_io_writes - $previous_io_writes);

    $total_io_kb = ($io_read_delta + $io_write_delta) * 4;
    $io_mb_per_sec = ($total_io_kb / 1024) / $time_difference;

    $io_usage_percent = min(100, ($io_mb_per_sec / 25) * 100);

    $io_data = json_encode([
        'io_reads' => $current_io_reads,
        'io_writes' => $current_io_writes,
        'time' => $current_time
    ]);
    file_put_contents($io_data_file, $io_data);

    return round($io_usage_percent, 2);
}

// Schedule the monitor to run every minute
function wdm_schedule_monitor() {
    if ( ! wp_next_scheduled( 'wdm_monitor_event' ) ) {
        wp_schedule_event( time(), 'every_minute', 'wdm_monitor_event' );
    }
}
add_action( 'wp', 'wdm_schedule_monitor' );

// Hook the monitor function to the scheduled event
add_action( 'wdm_monitor_event', 'wdm_check_website_and_server_status' );

// Custom cron interval (every minute)
function wdm_custom_cron_intervals( $schedules ) {
    $schedules['every_minute'] = array(
        'interval' => 60,
        'display'  => __( 'Every Minute' )
    );
    return $schedules;
}
add_filter( 'cron_schedules', 'wdm_custom_cron_intervals' );

// Clear scheduled events on plugin deactivation
function wdm_clear_schedule() {
    $timestamp = wp_next_scheduled( 'wdm_monitor_event' );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'wdm_monitor_event' );
    }
    delete_option(WDM_LAST_NOTIFICATION_OPTION);
}
register_deactivation_hook( __FILE__, 'wdm_clear_schedule' );

// Add a submenu page under Tools for the log viewer
function wdm_add_log_viewer_page() {
    add_submenu_page(
        'tools.php',                   // Parent slug (Tools menu)
        'Downtime Log Viewer',         // Page title
        'Downtime Logs',               // Menu title
        'manage_options',              // Capability
        'wdm-log-viewer',              // Menu slug
        'wdm_display_log_viewer_page'  // Function to display page content
    );
}
add_action('admin_menu', 'wdm_add_log_viewer_page');

// Function to display the log viewer page content
function wdm_display_log_viewer_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    echo '<div class="wrap">';
    echo '<h1>Downtime Log Viewer - HTTP Errors and Resource Spikes</h1>';
    echo '<p>Thresholds - CPU: ' . WDM_CPU_THRESHOLD . '%, Memory: ' . WDM_MEMORY_THRESHOLD . '%, I/O: ' . WDM_IO_THRESHOLD . '%</p>';

    $import_dir = plugin_dir_path(__FILE__) . 'imports/';
    if (!file_exists($import_dir)) {
        mkdir($import_dir, 0755, true);
    }

    $target_file = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['logfile'])) {
        $uploaded_file = $_FILES['logfile'];
        $target_file = $import_dir . basename($uploaded_file['name']);
        if (!move_uploaded_file($uploaded_file['tmp_name'], $target_file)) {
            echo '<p>Error uploading file. Please try again.</p>';
        }
    } elseif (isset($_GET['file'])) {
        $target_file = $import_dir . basename($_GET['file']);
    }

    if (isset($_POST['block_bots']) && !empty($_POST['bots_to_block'])) {
        $bots_to_block = $_POST['bots_to_block'];
        $result = add_bot_blocking_rules($bots_to_block);
        echo "<p>$result</p>";
    }

    if ($target_file && file_exists($target_file)) {
        $data = file($target_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        $errorCounts = ['3XX' => 0, '4XX' => 0, '5XX' => 0];
        $detailedErrors = ['3XX' => [], '4XX' => [], '5XX' => []];
        $errorCauses = [
            '301' => 'Moved Permanently: The resource has been moved to a new URL.',
            '302' => 'Found: Temporary redirection to a different URL.',
            '404' => 'Not Found: The requested resource does not exist on the server.',
            '403' => 'Forbidden: Access to the requested resource is denied.',
            '500' => 'Internal Server Error.',
            '502' => 'Bad Gateway: Server received an invalid response from an inbound server.',
            '503' => 'Service Unavailable.',
            '504' => 'Gateway Timeout.',
        ];

        $ipHits = [];
        $userAgentsDetected = [];

        foreach ($data as $line) {
            $parts = explode(" ", $line);
            $statusCode = isset($parts[8]) ? $parts[8] : null;
            $ipAddress = isset($parts[0]) ? $parts[0] : null;
            $userAgent = isset($parts[11]) ? implode(" ", array_slice($parts, 11)) : '';

            if ($statusCode) {
                $statusGroup = substr($statusCode, 0, 1) . 'XX';
                if (in_array($statusGroup, ['3XX', '4XX', '5XX'])) {
                    $errorCounts[$statusGroup]++;
                    if (!isset($detailedErrors[$statusGroup][$statusCode])) {
                        $detailedErrors[$statusGroup][$statusCode] = ['count' => 0, 'cause' => $errorCauses[$statusCode] ?? 'Unknown reason'];
                    }
                    $detailedErrors[$statusGroup][$statusCode]['count']++;
                }
            }

            if ($ipAddress) {
                $ipHits[$ipAddress] = ($ipHits[$ipAddress] ?? 0) + 1;
            }

            if (preg_match('/compatible;\s*([a-zA-Z0-9]+)(?:\/|;|\s)/i', $userAgent, $matches)) {
                $botName = $matches[1];
                $userAgentsDetected[] = $botName;
            }
        }

        echo "<h2>HTTP Error Summary</h2>";
        foreach ($errorCounts as $group => $count) {
            echo "<h3>$group Errors: $count</h3>";
            echo "<ul>";
            foreach ($detailedErrors[$group] as $code => $details) {
                echo "<li>$code: {$details['count']} occurrences - Cause: {$details['cause']}</li>";
            }
            echo "</ul>";
        }

        echo "<h2>IP Address Analysis (Top 25)</h2>";
        arsort($ipHits);
        $topIpHits = array_slice($ipHits, 0, 25, true);
        echo "<ul>";
        foreach ($topIpHits as $ip => $hitCount) {
            echo "<li>$ip: $hitCount requests</li>";
        }
        echo "</ul>";

        echo "<h3>Detected Bots</h3>";
        echo '<form method="post">';
        if (!empty($userAgentsDetected)) {
            echo "<ul>";
            foreach (array_unique($userAgentsDetected) as $bot) {
                echo "<li><label><input type='checkbox' name='bots_to_block[]' value='" . esc_attr($bot) . "'> " . esc_html($bot) . "</label></li>";
            }
            echo "</ul>";
        }
        echo '<button type="submit" name="block_bots">Block Selected Bots</button>';
        echo '</form>';
    }

    echo '<h1>Upload Log File</h1>';
    echo '<form action="" method="post" enctype="multipart/form-data">';
    echo '<input type="file" name="logfile" accept=".log">';
    echo '<button type="submit">Upload and Analyze</button>';
    echo '</form>';

    echo '<h2>Previously Uploaded Files</h2>';
    $uploaded_files = scandir($import_dir);
    if ($uploaded_files) {
        echo '<ul>';
        foreach ($uploaded_files as $file) {
            if ($file !== '.' && $file !== '..') {
                $file_url = add_query_arg('file', urlencode($file), menu_page_url('wdm-log-viewer', false));
                echo "<li><a href='{$file_url}'>{$file}</a></li>";
            }
        }
        echo '</ul>';
    } else {
        echo '<p>No previously uploaded files.</p>';
    }

    echo '</div>';
}

// Function to add bot-blocking rules to the public_html .htaccess file in the specified format
function add_bot_blocking_rules($bots_to_block = []) {
    $htaccess_path = $_SERVER['DOCUMENT_ROOT'] . '/.htaccess';
    
    if (empty($bots_to_block) || !is_array($bots_to_block)) {
        return "No bots specified for blocking.";
    }

    $bots_pattern = implode('|', array_map('preg_quote', $bots_to_block));
    $bot_block_rule = "\n# BEGIN Bot Blocking\n";
    $bot_block_rule .= "RewriteCond %{HTTP_USER_AGENT} ($bots_pattern) [NC]\n";
    $bot_block_rule .= "RewriteRule .* - [F,L]\n";
    $bot_block_rule .= "# END Bot Blocking\n";

    $htaccess_content = file_exists($htaccess_path) ? file_get_contents($htaccess_path) : '';
    $htaccess_content = preg_replace('/# BEGIN Bot Blocking.*# END Bot Blocking\n?/s', '', $htaccess_content);
    $htaccess_content .= $bot_block_rule;

    if (file_put_contents($htaccess_path, $htaccess_content)) {
        return "Bot blocking rules successfully added to .htaccess.";
    } else {
        return "Failed to write to .htaccess. Please check file permissions.";
    }
}
