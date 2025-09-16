<?php
/**
 * ZC Data Manager - Cron Manager Class
 * Handles scheduled data updates via WordPress cron
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class ZC_Cron_Manager {
    
    private static $instance = null;
    private $database;
    private $collector;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->database = ZC_Database::get_instance();
        $this->collector = ZC_Data_Collector::get_instance();
        $this->init_hooks();
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        // Register cron hooks
        add_action('zc_hourly_update', array($this, 'hourly_update'));
        add_action('zc_daily_update', array($this, 'daily_update'));
        add_action('zc_weekly_update', array($this, 'weekly_update'));
        add_action('zc_cleanup_logs', array($this, 'cleanup_logs'));
        
        // Schedule events on WordPress init
        add_action('wp', array($this, 'schedule_events'));
        
        // Add custom cron intervals
        add_filter('cron_schedules', array($this, 'add_custom_intervals'));
    }
    
    /**
     * Add custom cron intervals
     */
    public function add_custom_intervals($schedules) {
        // 15 minutes interval
        $schedules['fifteen_minutes'] = array(
            'interval' => 15 * 60,
            'display' => __('Every 15 Minutes', 'zc-data-manager')
        );
        
        // 30 minutes interval
        $schedules['thirty_minutes'] = array(
            'interval' => 30 * 60,
            'display' => __('Every 30 Minutes', 'zc-data-manager')
        );
        
        // 6 hours interval
        $schedules['six_hours'] = array(
            'interval' => 6 * 60 * 60,
            'display' => __('Every 6 Hours', 'zc-data-manager')
        );
        
        return $schedules;
    }
    
    /**
     * Schedule all cron events
     */
    public function schedule_events() {
        // Only schedule if auto-update is enabled
        if (!get_option('zc_dm_auto_update', 1)) {
            return;
        }
        
        // Schedule hourly updates (high frequency data like stocks)
        if (!wp_next_scheduled('zc_hourly_update')) {
            wp_schedule_event(time(), 'hourly', 'zc_hourly_update');
        }
        
        // Schedule daily updates (most economic data)
        if (!wp_next_scheduled('zc_daily_update')) {
            // Schedule for 3 AM local time
            $daily_time = strtotime('tomorrow 3:00 AM');
            wp_schedule_event($daily_time, 'daily', 'zc_daily_update');
        }
        
        // Schedule weekly updates (low frequency data)
        if (!wp_next_scheduled('zc_weekly_update')) {
            // Schedule for Sunday 2 AM
            $weekly_time = strtotime('next Sunday 2:00 AM');
            wp_schedule_event($weekly_time, 'weekly', 'zc_weekly_update');
        }
        
        // Schedule log cleanup (daily)
        if (!wp_next_scheduled('zc_cleanup_logs')) {
            $cleanup_time = strtotime('tomorrow 1:00 AM');
            wp_schedule_event($cleanup_time, 'daily', 'zc_cleanup_logs');
        }
    }
    
    /**
     * Clear all scheduled events
     */
    public function clear_scheduled_events() {
        wp_clear_scheduled_hook('zc_hourly_update');
        wp_clear_scheduled_hook('zc_daily_update');
        wp_clear_scheduled_hook('zc_weekly_update');
        wp_clear_scheduled_hook('zc_cleanup_logs');
    }
    
    /**
     * Hourly update - High frequency data
     */
    public function hourly_update() {
        // Only update financial/stock data hourly
        $this->update_by_source_types(array('yahoo', 'alphavantage', 'quandl'));
        
        $this->database->log_action(
            '',
            '',
            'Hourly cron update',
            'success',
            'Hourly cron job executed'
        );
    }
    
    /**
     * Daily update - Most economic data
     */
    public function daily_update() {
        // Update economic data sources
        $this->update_by_source_types(array('fred', 'worldbank', 'eurostat', 'oecd'));
        
        $this->database->log_action(
            '',
            '',
            'Daily cron update',
            'success',
            'Daily cron job executed'
        );
    }
    
    /**
     * Weekly update - Low frequency data
     */
    public function weekly_update() {
        // Update any remaining sources and do full refresh
        $result = $this->collector->refresh_all_series();
        
        $status = $result['failed'] > 0 ? 'warning' : 'success';
        $message = sprintf(
            'Weekly full refresh: %d successful, %d failed',
            $result['success'],
            $result['failed']
        );
        
        $this->database->log_action(
            '',
            '',
            'Weekly cron update',
            $status,
            $message
        );
        
        // Send summary email if there were failures
        if ($result['failed'] > 0 && get_option('zc_dm_error_emails', 1)) {
            $this->send_weekly_summary($result);
        }
    }
    
    /**
     * Clean up old logs
     */
    public function cleanup_logs() {
        $deleted = $this->database->cleanup_old_logs();
        
        $this->database->log_action(
            '',
            '',
            'Log cleanup',
            'success',
            sprintf('Cleaned up %d old log entries', $deleted)
        );
    }
    
    /**
     * Update series by source types
     */
    private function update_by_source_types($source_types) {
        global $wpdb;
        $tables = $this->database->get_table_names();
        
        // Get active series for specified source types
        $source_types_sql = "'" . implode("','", array_map('esc_sql', $source_types)) . "'";
        
        $series_to_update = $wpdb->get_results(
            "SELECT slug, source_type FROM {$tables['series']} 
             WHERE is_active = 1 AND source_type IN ({$source_types_sql})",
            ARRAY_A
        );
        
        $results = array(
            'total' => count($series_to_update),
            'success' => 0,
            'failed' => 0,
            'source_types' => $source_types
        );
        
        foreach ($series_to_update as $series) {
            $result = $this->collector->fetch_series_data($series['slug']);
            
            if ($result['success']) {
                $results['success']++;
            } else {
                $results['failed']++;
            }
            
            // Add delay between requests to avoid rate limiting
            $this->add_rate_limit_delay($series['source_type']);
        }
        
        // Log summary
        $status = $results['failed'] > 0 ? 'warning' : 'success';
        $message = sprintf(
            'Updated %s sources: %d successful, %d failed out of %d total',
            implode(', ', $source_types),
            $results['success'],
            $results['failed'],
            $results['total']
        );
        
        $this->database->log_action(
            '',
            implode(',', $source_types),
            'Cron source update',
            $status,
            $message
        );
        
        return $results;
    }
    
    /**
     * Add appropriate delay based on source type rate limits
     */
    private function add_rate_limit_delay($source_type) {
        $delays = array(
            'fred' => 1000000,      // 1 second (120/minute limit)
            'alphavantage' => 15000000, // 15 seconds (25/day limit)
            'yahoo' => 500000,      // 0.5 seconds
            'worldbank' => 250000,  // 0.25 seconds
            'eurostat' => 500000,   // 0.5 seconds
            'oecd' => 500000,       // 0.5 seconds
            'dbnomics' => 250000,   // 0.25 seconds
            'quandl' => 2000000     // 2 seconds
        );
        
        $delay = isset($delays[$source_type]) ? $delays[$source_type] : 500000;
        usleep($delay);
    }
    
    /**
     * Manual trigger for cron jobs (for testing/admin)
     */
    public function manual_trigger($job_type) {
        if (!current_user_can('manage_options')) {
            return array(
                'success' => false,
                'message' => __('Insufficient permissions', 'zc-data-manager')
            );
        }
        
        switch ($job_type) {
            case 'hourly':
                $this->hourly_update();
                $message = __('Hourly update completed manually', 'zc-data-manager');
                break;
                
            case 'daily':
                $this->daily_update();
                $message = __('Daily update completed manually', 'zc-data-manager');
                break;
                
            case 'weekly':
                $this->weekly_update();
                $message = __('Weekly update completed manually', 'zc-data-manager');
                break;
                
            case 'cleanup':
                $this->cleanup_logs();
                $message = __('Log cleanup completed manually', 'zc-data-manager');
                break;
                
            default:
                return array(
                    'success' => false,
                    'message' => __('Invalid job type', 'zc-data-manager')
                );
        }
        
        return array(
            'success' => true,
            'message' => $message
        );
    }
    
    /**
     * Get next scheduled times for all cron jobs
     */
    public function get_scheduled_times() {
        return array(
            'hourly' => wp_next_scheduled('zc_hourly_update'),
            'daily' => wp_next_scheduled('zc_daily_update'),
            'weekly' => wp_next_scheduled('zc_weekly_update'),
            'cleanup' => wp_next_scheduled('zc_cleanup_logs')
        );
    }
    
    /**
     * Check if cron is working properly
     */
    public function test_cron() {
        // Test by scheduling a test event
        $test_hook = 'zc_test_cron_' . time();
        $test_time = time() + 60; // 1 minute from now
        
        wp_schedule_single_event($test_time, $test_hook);
        
        // Check if event was scheduled
        $scheduled = wp_next_scheduled($test_hook);
        
        if ($scheduled) {
            // Clean up test event
            wp_clear_scheduled_hook($test_hook);
            
            return array(
                'success' => true,
                'message' => __('Cron system is working correctly', 'zc-data-manager')
            );
        } else {
            return array(
                'success' => false,
                'message' => __('Cron system may not be working properly', 'zc-data-manager')
            );
        }
    }
    
    /**
     * Get cron status and information
     */
    public function get_cron_status() {
        $scheduled_times = $this->get_scheduled_times();
        $auto_update_enabled = get_option('zc_dm_auto_update', 1);
        
        $status = array(
            'auto_update_enabled' => $auto_update_enabled,
            'wordpress_cron_enabled' => !defined('DISABLE_WP_CRON') || !DISABLE_WP_CRON,
            'scheduled_events' => array()
        );
        
        foreach ($scheduled_times as $event => $timestamp) {
            $status['scheduled_events'][$event] = array(
                'scheduled' => $timestamp !== false,
                'next_run' => $timestamp ? date('Y-m-d H:i:s', $timestamp) : null,
                'human_time' => $timestamp ? human_time_diff($timestamp, current_time('timestamp')) : null
            );
        }
        
        return $status;
    }
    
    /**
     * Enable/disable automatic updates
     */
    public function toggle_auto_updates($enable = true) {
        update_option('zc_dm_auto_update', $enable ? 1 : 0);
        
        if ($enable) {
            $this->schedule_events();
            $message = __('Automatic updates enabled', 'zc-data-manager');
        } else {
            $this->clear_scheduled_events();
            $message = __('Automatic updates disabled', 'zc-data-manager');
        }
        
        $this->database->log_action(
            '',
            '',
            'Auto-update toggle',
            'success',
            $message
        );
        
        return array(
            'success' => true,
            'message' => $message,
            'enabled' => $enable
        );
    }
    
    /**
     * Send weekly summary email
     */
    private function send_weekly_summary($results) {
        $admin_email = get_option('zc_dm_admin_email', get_option('admin_email'));
        
        if (empty($admin_email)) {
            return;
        }
        
        $subject = sprintf(
            __('[%s] ZC Data Manager Weekly Summary', 'zc-data-manager'),
            get_bloginfo('name')
        );
        
        $message = sprintf(
            __("ZC Data Manager Weekly Summary\n\nTotal Series: %d\nSuccessful Updates: %d\nFailed Updates: %d\n\nFailed Series:\n", 'zc-data-manager'),
            $results['total'],
            $results['success'],
            $results['failed']
        );
        
        // Add details about failed series
        foreach ($results['details'] as $slug => $result) {
            if (!$result['success']) {
                $message .= "- {$slug}: {$result['message']}\n";
            }
        }
        
        $message .= sprintf(
            __("\n\nPlease check the logs in your WordPress admin for more details.\n\nTime: %s", 'zc-data-manager'),
            current_time('mysql')
        );
        
        wp_mail($admin_email, $subject, $message);
    }
    
    /**
     * Force reschedule all events (useful for troubleshooting)
     */
    public function reschedule_all_events() {
        $this->clear_scheduled_events();
        $this->schedule_events();
        
        $this->database->log_action(
            '',
            '',
            'Cron reschedule',
            'success',
            'All cron events rescheduled'
        );
        
        return array(
            'success' => true,
            'message' => __('All cron events have been rescheduled', 'zc-data-manager')
        );
    }
}