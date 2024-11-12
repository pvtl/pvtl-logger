<?php
/*
Plugin Name: PVTL Downtime Monitor
Plugin URI: https://pivotalagency.com.au
Description: Monitors website uptime and sends email notifications for downtime.
Version: 1.0
Author: Erin Welch
Author URI: https://pivotalagency.com.au
License: GPLv2 or later
*/

// Exit if accessed directly
if ( !defined( 'ABSPATH' ) ) exit;

// Configuration
define( 'WDM_URL_TO_MONITOR', get_option('siteurl') ); // Automatically retrieves the site URL
define( 'WDM_LOG_FILE', plugin_dir_path( __FILE__ ) . 'logs/downtime-log.txt' );
define( 'WDM_EMAIL', 'erin.w@pivotalagency.com.au' );

// Function to check website status
function wdm_check_website_status() {
    $response = wp_remote_get( WDM_URL_TO_MONITOR );

    $timestamp = current_time( 'mysql' );
    $status_code = wp_remote_retrieve_response_code( $response );
    $cpu_load = sys_getloadavg(); // Get server load
    $memory_usage = memory_get_usage(true) / (1024 * 1024); // Memory in MB

    if ( $status_code != 200 ) {
        // Log downtime
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
    } else {
        // Check if the site was previously down
        $log_contents = file_exists(WDM_LOG_FILE) ? file_get_contents(WDM_LOG_FILE) : '';
        if ( strpos($log_contents, 'DOWN') !== false ) {
            $last_down_time = preg_match('/\[(.*?)\] DOWN/', $log_contents, $matches) ? strtotime($matches[1]) : false;
            $downtime_duration = $last_down_time ? (time() - $last_down_time) : 0;

            // Log uptime
            $log_entry = "[{$timestamp}] UP - Downtime Duration: {$downtime_duration} seconds\n";
            file_put_contents( WDM_LOG_FILE, $log_entry, FILE_APPEND );

            // Send recovery email
            $subject = "Recovery: Website Up at {$timestamp}";
            $message = "The website " . WDM_URL_TO_MONITOR . " is back online as of {$timestamp}.\n\n"
                . "Downtime Duration: {$downtime_duration} seconds\n\n"
                . "Review logs for additional details.";
            wp_mail( WDM_EMAIL, $subject, $message );
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
}
register_deactivation_hook( __FILE__, 'wdm_clear_schedule' );