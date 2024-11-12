<?php
/*
Plugin Name: PVTL Server Logger
Plugin URI: https://pivotalagency.com.au
Description: Monitors website uptime and sends email notifications for downtime.
Version: 1.0
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

// Option name for storing last notification time
define( 'WDM_LAST_NOTIFICATION_OPTION', 'wdm_last_notification_time' );

// Function to check website status
function wdm_check_website_status() {
    $response = wp_remote_get( WDM_URL_TO_MONITOR );

    $timestamp = current_time( 'mysql' );
    $status_code = wp_remote_retrieve_response_code( $response );
    $cpu_load = sys_getloadavg();
    $memory_usage = memory_get_usage(true) / (1024 * 1024);

    // Get the last notification time from the database
    $last_notification = get_option(WDM_LAST_NOTIFICATION_OPTION, 0);
    $time_since_last_notification = time() - $last_notification;

    if ( $status_code != 200 ) {
        // If the site is down and we haven't sent a recent notification
        if ($time_since_last_notification > WDM_NOTIFICATION_INTERVAL) {
            $log_entry = "[{$timestamp}] DOWN\nURL: " . WDM_URL_TO_MONITOR . "\nCPU Load: " . implode(', ', $cpu_load) . "\nMemory Usage: " . round($memory_usage, 2) . " MB\n";
            file_put_contents( WDM_LOG_FILE, $log_entry, FILE_APPEND );

            // Send email notification
            $subject = "Alert: Website Down at {$timestamp}";
            $message = "The website " . WDM_URL_TO_MONITOR . " is down as of {$timestamp}.\n\n"
                . "HTTP Response Code: {$status_code}\n"
                . "CPU Load: " . implode(', ', $cpu_load) . "\n"
                . "Memory Usage: " . round($memory_usage, 2) . " MB\n\n"
                . "Check the server status and logs for more information.";
            wp_mail( WDM_EMAIL, $subject, $message );

            // Update the last notification time
            update_option(WDM_LAST_NOTIFICATION_OPTION, time());
        }
    } else {
        // If the site is back up and there was a previous downtime
        if ( strpos(file_get_contents(WDM_LOG_FILE), 'DOWN') !== false ) {
            $last_down_time = preg_match('/\[(.*?)\] DOWN/', file_get_contents(WDM_LOG_FILE), $matches) ? strtotime($matches[1]) : false;
            $downtime_duration = $last_down_time ? (time() - $last_down_time) : 0;

            $log_entry = "[{$timestamp}] UP - Downtime Duration: {$downtime_duration} seconds\n";
            file_put_contents( WDM_LOG_FILE, $log_entry, FILE_APPEND );

            // Send recovery email if we haven't sent one recently
            if ($time_since_last_notification > WDM_NOTIFICATION_INTERVAL) {
                $subject = "Recovery: Website Up at {$timestamp}";
                $message = "The website " . WDM_URL_TO_MONITOR . " is back online as of {$timestamp}.\n\n"
                    . "Downtime Duration: {$downtime_duration} seconds\n\n"
                    . "Review logs for additional details.";
                wp_mail( WDM_EMAIL, $subject, $message );

                // Update the last notification time
                update_option(WDM_LAST_NOTIFICATION_OPTION, time());
            }
        }
    }
}

// Schedule the monitor to run every minute
function wdm_schedule_monitor() {
    if ( ! wp_next_scheduled( 'wdm_monitor_event' ) ) {
        wp_schedule_event( time(), 'every_minute', 'wdm_monitor_event' );
    }
}
add_action( 'wp', 'wdm_schedule_monitor' );

// Hook the monitor function to the scheduled event
add_action( 'wdm_monitor_event', 'wdm_check_website_status' );

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
    // Remove last notification option on deactivation
    delete_option(WDM_LAST_NOTIFICATION_OPTION);
}
register_deactivation_hook( __FILE__, 'wdm_clear_schedule' );



// Existing code above this point...

// Function to create an admin menu page for the log viewer
function wdm_add_log_viewer_page() {
    add_menu_page(
        'Downtime Log Viewer',         // Page title
        'Downtime Logs',               // Menu title
        'manage_options',              // Capability
        'wdm-log-viewer',              // Menu slug
        'wdm_display_log_viewer_page', // Function to display page content
        'dashicons-visibility',        // Icon
        80                             // Position
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
            if (strpos($line, '5') === 1) { // Filters lines with 5XX status codes
                $log_entries[] = $line;
            }
        }
    }

    // Display the logs
    echo '<div class="wrap">';
    echo '<h1>Downtime Log Viewer - 5XX Errors</h1>';
    
    if (empty($log_entries)) {
        echo '<p>No 5XX error logs found.</p>';
    } else {
        echo '<table class="widefat fixed" cellspacing="0">';
        echo '<thead><tr><th>Timestamp</th><th>Status</th><th>Details</th></tr></thead>';
        echo '<tbody>';

        foreach ($log_entries as $entry) {
            // Parsing entry parts
            preg_match('/\[(.*?)\] (.*?)\n/', $entry, $matches);
            $timestamp = $matches[1] ?? 'Unknown';
            $status = $matches[2] ?? 'Unknown';
            $details = substr($entry, strpos($entry, ']') + 2) ?? 'Unknown';

            echo '<tr>';
            echo "<td>{$timestamp}</td>";
            echo "<td>{$status}</td>";
            echo "<td>{$details}</td>";
            echo '</tr>';
        }

        echo '</tbody></table>';
    }
    
    echo '</div>';
}
