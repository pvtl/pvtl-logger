<?php
/*
Plugin Name: PVTL Server Logger
Plugin URI: https://pivotalagency.com.au
Description: Monitors website uptime and sends email notifications for downtime.
Version: 1.3
Author: Pivotal Agency Pty Ltd
Author URI: https://pivotalagency.com.au
License: GPLv2 or later
*/

// Exit if accessed directly
if ( !defined( 'ABSPATH' ) ) exit;

// Dynamic site URL retrieval
define( 'WDM_URL_TO_MONITOR', get_option('siteurl') );
define( 'WDM_LOG_FILE', plugin_dir_path( __FILE__ ) . 'logs/downtime-log.txt' );
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

// Function to monitor I/O usage as a rate in MB/s, using cumulative disk operations from getrusage()
function wdm_get_io_usage() {
    // Define path to store previous I/O data
    $io_data_file = plugin_dir_path( __FILE__ ) . 'logs/previous_io_data.txt';
    
    // Get current I/O data using getrusage()
    $current_usage = getrusage();
    $current_io_reads = $current_usage['ru_inblock'];
    $current_io_writes = $current_usage['ru_oublock'];
    $current_time = time();
    
    // Retrieve previous I/O data if it exists
    if (file_exists($io_data_file)) {
        $previous_data = json_decode(file_get_contents($io_data_file), true);
        $previous_io_reads = $previous_data['io_reads'];
        $previous_io_writes = $previous_data['io_writes'];
        $previous_time = $previous_data['time'];
    } else {
        // If no previous data, initialize values and return 0% I/O usage
        $previous_io_reads = $current_io_reads;
        $previous_io_writes = $current_io_writes;
        $previous_time = $current_time;
    }

    // Calculate elapsed time in seconds since last check
    $time_difference = max(1, $current_time - $previous_time);

    // Calculate the change in I/O operations
    $io_read_delta = max(0, $current_io_reads - $previous_io_reads);
    $io_write_delta = max(0, $current_io_writes - $previous_io_writes);

    // Estimate total I/O bytes based on block size (4 KB per block on most systems)
    $total_io_kb = ($io_read_delta + $io_write_delta) * 4;
    $io_mb_per_sec = ($total_io_kb / 1024) / $time_difference;

    // Calculate I/O usage as a percentage of the 25 MB/s limit
    $io_usage_percent = min(100, ($io_mb_per_sec / 25) * 100);

    // Save current I/O data for the next calculation
    $io_data = json_encode([
        'io_reads' => $current_io_reads,
        'io_writes' => $current_io_writes,
        'time' => $current_time
    ]);
    file_put_contents($io_data_file, $io_data);

    return round($io_usage_percent, 2); // Return I/O usage percentage
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

// Function to add a submenu page under Tools for the log viewer
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

    // Fetch log entries
    $log_file_path = WDM_LOG_FILE;
    $log_entries = [];

    if (file_exists($log_file_path)) {
        $file_content = file_get_contents($log_file_path);
        $lines = explode("\n", $file_content);

        foreach ($lines as $line) {
            if (strpos($line, '5') === 1 || strpos($line, 'RESOURCE SPIKE') !== false || strpos($line, 'DOWN') !== false) {
                $log_entries[] = $line;
            }
        }
    }

    // Display the logs
    echo '<div class="wrap">';
    echo '<h1>Downtime Log Viewer - 5XX Errors and Resource Spikes</h1>';
    echo '<p>Thresholds - CPU: ' . WDM_CPU_THRESHOLD . '%, Memory: ' . WDM_MEMORY_THRESHOLD . '%, I/O: ' . WDM_IO_THRESHOLD . '%</p>';
    
    if (empty($log_entries)) {
        echo '<p>No error or spike logs found.</p>';
    } else {
        echo '<table class="widefat fixed" cellspacing="0">';
        echo '<thead><tr><th>Timestamp</th><th>Status</th><th>Details</th><th>HTTP Error Code</th><th>CPU Usage (%)</th><th>Memory Usage (MB)</th><th>I/O Usage (%)</th></tr></thead>';
        echo '<tbody>';

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
    }
    
    echo '</div>';
}
