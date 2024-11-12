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
define( 'WDM_CPU_THRESHOLD', 99 ); // CPU usage threshold in percentage
define( 'WDM_MEMORY_THRESHOLD', 99 ); // Memory usage threshold in percentage
define( 'WDM_IO_THRESHOLD', 99 ); // Disk I/O threshold in percentage

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
        // Check conditions for downtime or fault notifications
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

// Function to display the log viewer page content with upload, parsing capabilities, resource usage stats, and bot detection
function wdm_display_log_viewer_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    echo '<div class="wrap">';
    echo '<h1>Downtime Log Viewer - HTTP Errors and Resource Spikes</h1>';
    echo '<p>Thresholds - CPU: ' . WDM_CPU_THRESHOLD . '%, Memory: ' . WDM_MEMORY_THRESHOLD . '%, I/O: ' . WDM_IO_THRESHOLD . '%</p>';

    // Define the import directory
    $import_dir = plugin_dir_path(__FILE__) . 'imports/';
    if (!file_exists($import_dir)) {
        mkdir($import_dir, 0755, true); // Create the directory if it doesn't exist
    }

    // Check if a file has been uploaded or selected from the previous uploads
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

    // Check if bots are selected for blocking
    if (isset($_POST['block_bots']) && !empty($_POST['bots_to_block'])) {
        $bots_to_block = $_POST['bots_to_block'];
        $result = add_bot_blocking_rules($bots_to_block);
        echo "<p>$result</p>";
    }

    // If there’s a valid target file, analyze it
    if ($target_file && file_exists($target_file)) {
        // Read the file for parsing
        $data = file($target_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        // Initialize counters for HTTP status groups and errors
        $errorCounts = ['3XX' => 0, '4XX' => 0, '5XX' => 0];
        $detailedErrors = ['3XX' => [], '4XX' => [], '5XX' => []];
        $errorCauses = [
            '301' => 'Moved Permanently: The resource has been moved to a new URL.',
            '302' => 'Found: Temporary redirection to a different URL.',
            '404' => 'Not Found: The requested resource does not exist on the server.',
            '403' => 'Forbidden: Access to the requested resource is denied.',
            '500' => 'Internal Server Error: Generic server error, often from misconfigurations.',
            '502' => 'Bad Gateway: Server received an invalid response from an inbound server.',
            '503' => 'Service Unavailable: Server is currently unavailable (overloaded or down).',
            '504' => 'Gateway Timeout: Server didn’t receive a timely response from an upstream server.',
        ];

        $userAgentPatterns = [
            'bot' => 'Bot: Common bot, potentially causing high traffic or errors',
            'crawl' => 'Crawler: May overload server resources if too frequent',
            'spider' => 'Spider: Typically search engines',
        ];

        $ipHits = [];
        $userAgentsDetected = [];

        // Loop through each line in the log file
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

            // Extract bot name from user agent string (after "compatible;" and before the first "/")
            if (preg_match('/compatible;\s*([a-zA-Z0-9]+)(?:\/|;|\s)/i', $userAgent, $matches)) {
                $botName = $matches[1];
                $userAgentsDetected[] = $botName;
            }
        }

        // Display HTTP error summary including 3XX, 4XX, and 5XX
        echo "<h2>HTTP Error Summary</h2>";
        foreach ($errorCounts as $group => $count) {
            echo "<h3>$group Errors: $count</h3>";
            echo "<ul>";
            foreach ($detailedErrors[$group] as $code => $details) {
                echo "<li>$code: {$details['count']} occurrences - Cause: {$details['cause']}</li>";
            }
            echo "</ul>";
        }

        // Display IP address analysis, limited to the top 25 IPs
        echo "<h2>IP Address Analysis (Top 25)</h2>";
        arsort($ipHits); // Sort IPs by hit count, descending
        $topIpHits = array_slice($ipHits, 0, 25, true);
        echo "<ul>";
        foreach ($topIpHits as $ip => $hitCount) {
            echo "<li>$ip: $hitCount requests</li>";
        }
        echo "</ul>";

        // Display detected bots with checkboxes to block them
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

    // Display the upload form
    echo '<h1>Upload Log File</h1>';
    echo '<form action="" method="post" enctype="multipart/form-data">';
    echo '<input type="file" name="logfile" accept=".log">';
    echo '<button type="submit">Upload and Analyze</button>';
    echo '</form>';

    // Additional stats section for I/O, Memory, and CPU usage
    echo '<h2>Resource Usage Stats</h2>';
    echo '<table class="widefat fixed" cellspacing="0">';
    echo '<thead><tr><th>Timestamp</th><th>Status</th><th>Details</th><th>HTTP Error Code</th><th>CPU Usage (%)</th><th>Memory Usage (MB)</th><th>I/O Usage (%)</th></tr></thead>';
    echo '<tbody>';

    // Fetch log entries for CPU, Memory, and I/O stats
    $log_file_path = WDM_LOG_FILE;
    $log_entries = [];

    if (file_exists($log_file_path)) {
        $file_content = file_get_contents($log_file_path);
        $lines = explode("\n", $file_content);

        foreach ($lines as $line) {
            if (strpos($line, '3XX') !== false || strpos($line, '4XX') !== false || strpos($line, '5XX') !== false || strpos($line, 'RESOURCE SPIKE') !== false || strpos($line, 'DOWN') !== false) {
                $log_entries[] = $line;
            }
        }
    }

    foreach ($log_entries as $entry) {
        preg_match('/\[(.*?)\] (.*?)\n/', $entry, $matches);
        $timestamp = $matches[1] ?? 'Unknown';
        $status = $matches[2] ?? 'Unknown';

        // Extract specific stats if they are available in the log entry
        preg_match('/Status Code: (\d{3})/', $entry, $http_code_match);
        $http_code = $http_code_match[1] ?? 'N/A';

        preg_match('/CPU Load: ([\d\.]+)%/', $entry, $cpu_match);
        $cpu_usage = $cpu_match[1] ?? 'N/A';

        preg_match('/Memory Usage: ([\d\.]+) MB/', $entry, $memory_match);
        $memory_usage = $memory_match[1] ?? 'N/A';

        preg_match('/Disk I/O: ([\d\.]+)%/', $entry, $io_match);
        $io_usage = $io_match[1] ?? 'N/A';

        echo '<tr>';
        echo "<td>{$timestamp}</td>";
        echo "<td>{$status}</td>";
        echo "<td>" . substr($entry, strpos($entry, ']') + 2) . "</td>";
        echo "<td>{$http_code}</td>";
        echo "<td>{$cpu_usage}</td>";
        echo "<td>{$memory_usage}</td>";
        echo "<td>{$io_usage}</td>";
        echo '</tr>';
    }

    echo '</tbody></table>';

    // List previously uploaded files in the 'imports' directory
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
    // Path to the .htaccess file in public_html
    $htaccess_path = $_SERVER['DOCUMENT_ROOT'] . '/.htaccess';
    
    // Check to ensure we're adding a non-empty array of bots
    if (empty($bots_to_block) || !is_array($bots_to_block)) {
        return "No bots specified for blocking.";
    }

    // Clean up bot names for use in the RewriteCond line
    $bots_pattern = implode('|', array_map('preg_quote', $bots_to_block));

    // Prepare the bot-blocking rule
    $bot_block_rule = "\n# BEGIN Bot Blocking\n";
    $bot_block_rule .= "RewriteCond %{HTTP_USER_AGENT} ($bots_pattern) [NC]\n";
    $bot_block_rule .= "RewriteRule .* - [F,L]\n";
    $bot_block_rule .= "# END Bot Blocking\n";

    // Read the existing .htaccess content
    $htaccess_content = file_exists($htaccess_path) ? file_get_contents($htaccess_path) : '';

    // Remove any existing bot-blocking section to avoid duplicates
    $htaccess_content = preg_replace('/# BEGIN Bot Blocking.*# END Bot Blocking\n?/s', '', $htaccess_content);

    // Append the new bot-blocking rules to the .htaccess content
    $htaccess_content .= $bot_block_rule;

    // Write the updated content back to the .htaccess file
    if (file_put_contents($htaccess_path, $htaccess_content)) {
        return "Bot blocking rules successfully added to .htaccess.";
    } else {
        return "Failed to write to .htaccess. Please check file permissions.";
    }
}


?>
