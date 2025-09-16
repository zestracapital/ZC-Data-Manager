<?php
/**
 * ZC Data Manager - Updated Main Plugin File with Final Fixes
 * Version: 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('ZC_DATA_MANAGER_VERSION', '1.0.0');
define('ZC_DATA_MANAGER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ZC_DATA_MANAGER_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ZC_DATA_MANAGER_PLUGIN_FILE', __FILE__);

/**
 * Main ZC Data Manager Class
 */
class ZC_Data_Manager {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->init_hooks();
        $this->load_dependencies();
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        add_action('plugins_loaded', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
        
        // AJAX handlers
        add_action('wp_ajax_zc_test_source_connection', array($this, 'ajax_test_source_connection'));
        add_action('wp_ajax_zc_refresh_series', array($this, 'ajax_refresh_series'));
        add_action('wp_ajax_zc_delete_series', array($this, 'ajax_delete_series'));
        add_action('wp_ajax_zc_preview_source_data', array($this, 'ajax_preview_source_data'));
        add_action('wp_ajax_zc_manual_cron', array($this, 'ajax_manual_cron'));
        add_action('wp_ajax_zc_reschedule_cron', array($this, 'ajax_reschedule_cron'));
        add_action('wp_ajax_zc_cleanup_logs', array($this, 'ajax_cleanup_logs'));
        add_action('wp_ajax_zc_export_logs', array($this, 'ajax_export_logs'));
    }
    
    /**
     * Load plugin dependencies
     */
    private function load_dependencies() {
        // Core classes
        require_once ZC_DATA_MANAGER_PLUGIN_DIR . 'includes/class-database.php';
        require_once ZC_DATA_MANAGER_PLUGIN_DIR . 'includes/class-data-collector.php';
        require_once ZC_DATA_MANAGER_PLUGIN_DIR . 'includes/class-cron-manager.php';
        
        // Data source classes
        require_once ZC_DATA_MANAGER_PLUGIN_DIR . 'includes/sources/class-source-fred.php';
        require_once ZC_DATA_MANAGER_PLUGIN_DIR . 'includes/sources/class-source-worldbank.php';
        require_once ZC_DATA_MANAGER_PLUGIN_DIR . 'includes/sources/class-source-yahoo.php';
        require_once ZC_DATA_MANAGER_PLUGIN_DIR . 'includes/sources/class-source-dbnomics.php';
        require_once ZC_DATA_MANAGER_PLUGIN_DIR . 'includes/sources/class-source-eurostat.php';
        require_once ZC_DATA_MANAGER_PLUGIN_DIR . 'includes/sources/class-source-alphavantage.php';
        require_once ZC_DATA_MANAGER_PLUGIN_DIR . 'includes/sources/class-source-csv.php';
        
        // API functions for Charts plugin
        require_once ZC_DATA_MANAGER_PLUGIN_DIR . 'api/data-functions.php';
    }
    
    /**
     * Plugin initialization
     */
    public function init() {
        // Initialize database
        ZC_Database::get_instance();
        
        // Initialize cron manager
        ZC_Cron_Manager::get_instance();
        
        // Load text domain for translations
        load_plugin_textdomain('zc-data-manager', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Create database tables
        ZC_Database::get_instance()->create_tables();
        
        // Schedule cron events
        ZC_Cron_Manager::get_instance()->schedule_events();
        
        // Set default options
        $default_options = array(
            'zc_dm_version' => ZC_DATA_MANAGER_VERSION,
            'zc_dm_auto_update' => 1,
            'zc_dm_error_emails' => 1,
            'zc_dm_admin_email' => get_option('admin_email'),
            'zc_dm_log_retention_days' => 30
        );
        
        foreach ($default_options as $key => $value) {
            if (!get_option($key)) {
                add_option($key, $value);
            }
        }
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear scheduled cron events
        wp_clear_scheduled_hook('zc_hourly_update');
        wp_clear_scheduled_hook('zc_daily_update');
        wp_clear_scheduled_hook('zc_weekly_update');
        wp_clear_scheduled_hook('zc_cleanup_logs');
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Add admin menu pages
     */
    public function add_admin_menu() {
        // Main menu
        add_menu_page(
            __('ZC Data Manager', 'zc-data-manager'),
            __('Data Manager', 'zc-data-manager'),
            'manage_options',
            'zc-data-manager',
            array($this, 'dashboard_page'),
            'dashicons-chart-line',
            30
        );
        
        // Dashboard
        add_submenu_page(
            'zc-data-manager',
            __('Dashboard', 'zc-data-manager'),
            __('Dashboard', 'zc-data-manager'),
            'manage_options',
            'zc-data-manager',
            array($this, 'dashboard_page')
        );
        
        // Data Series
        add_submenu_page(
            'zc-data-manager',
            __('Data Series', 'zc-data-manager'),
            __('Data Series', 'zc-data-manager'),
            'manage_options',
            'zc-data-series',
            array($this, 'series_list_page')
        );
        
        // Add Series
        add_submenu_page(
            'zc-data-manager',
            __('Add Series', 'zc-data-manager'),
            __('Add Series', 'zc-data-manager'),
            'manage_options',
            'zc-add-series',
            array($this, 'add_series_page')
        );
        
        // Data Sources
        add_submenu_page(
            'zc-data-manager',
            __('Data Sources', 'zc-data-manager'),
            __('Data Sources', 'zc-data-manager'),
            'manage_options',
            'zc-data-sources',
            array($this, 'data_sources_page')
        );
        
        // Logs
        add_submenu_page(
            'zc-data-manager',
            __('Logs', 'zc-data-manager'),
            __('Logs', 'zc-data-manager'),
            'manage_options',
            'zc-data-logs',
            array($this, 'logs_page')
        );
        
        // Settings
        add_submenu_page(
            'zc-data-manager',
            __('Settings', 'zc-data-manager'),
            __('Settings', 'zc-data-manager'),
            'manage_options',
            'zc-data-settings',
            array($this, 'settings_page')
        );
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function admin_scripts($hook) {
        // Only load on our plugin pages
        if (strpos($hook, 'zc-data') === false && strpos($hook, 'data-manager') === false) {
            return;
        }
        
        wp_enqueue_style(
            'zc-data-manager-admin',
            ZC_DATA_MANAGER_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            ZC_DATA_MANAGER_VERSION
        );
        
        wp_enqueue_script(
            'zc-data-manager-admin',
            ZC_DATA_MANAGER_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            ZC_DATA_MANAGER_VERSION,
            true
        );
        
        // Localize script for AJAX
        wp_localize_script('zc-data-manager-admin', 'zcDataManager', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('zc_data_manager_nonce'),
            'strings' => array(
                'confirm_delete' => __('Are you sure you want to delete this series?', 'zc-data-manager'),
                'testing_connection' => __('Testing connection...', 'zc-data-manager'),
                'connection_success' => __('Connection successful!', 'zc-data-manager'),
                'connection_failed' => __('Connection failed. Please check your settings.', 'zc-data-manager'),
                'refreshing_data' => __('Refreshing data...', 'zc-data-manager'),
                'refresh_success' => __('Data refreshed successfully!', 'zc-data-manager'),
                'refresh_failed' => __('Failed to refresh data.', 'zc-data-manager')
            )
        ));
    }
    
    /**
     * Dashboard page
     */
    public function dashboard_page() {
        include ZC_DATA_MANAGER_PLUGIN_DIR . 'admin/dashboard.php';
    }
    
    /**
     * Series list page
     */
    public function series_list_page() {
        include ZC_DATA_MANAGER_PLUGIN_DIR . 'admin/series-list.php';
    }
    
    /**
     * Add series page
     */
    public function add_series_page() {
        include ZC_DATA_MANAGER_PLUGIN_DIR . 'admin/add-series.php';
    }
    
    /**
     * Data sources page
     */
    public function data_sources_page() {
        include ZC_DATA_MANAGER_PLUGIN_DIR . 'admin/data-sources.php';
    }
    
    /**
     * Logs page
     */
    public function logs_page() {
        include ZC_DATA_MANAGER_PLUGIN_DIR . 'admin/logs.php';
    }
    
    /**
     * Settings page
     */
    public function settings_page() {
        include ZC_DATA_MANAGER_PLUGIN_DIR . 'admin/settings.php';
    }
    
    /**
     * AJAX: Test source connection
     */
    public function ajax_test_source_connection() {
        check_ajax_referer('zc_data_manager_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'zc-data-manager'));
        }
        
        $source_type = sanitize_text_field($_POST['source_type']);
        $config = $_POST['config'];
        
        $collector = ZC_Data_Collector::get_instance();
        $result = $collector->test_source_connection($source_type, $config);
        
        wp_send_json($result);
    }
    
    /**
     * AJAX: Refresh series data
     */
    public function ajax_refresh_series() {
        check_ajax_referer('zc_data_manager_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'zc-data-manager'));
        }
        
        $series_slug = sanitize_text_field($_POST['series_slug']);
        
        $collector = ZC_Data_Collector::get_instance();
        $result = $collector->refresh_series($series_slug);
        
        wp_send_json($result);
    }
    
    /**
     * AJAX: Delete series
     */
    public function ajax_delete_series() {
        check_ajax_referer('zc_data_manager_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'zc-data-manager'));
        }
        
        $series_slug = sanitize_text_field($_POST['series_slug']);
        
        $db = ZC_Database::get_instance();
        $result = $db->delete_series($series_slug);
        
        wp_send_json($result);
    }
    
    /**
     * AJAX: Preview source data
     */
    public function ajax_preview_source_data() {
        check_ajax_referer('zc_data_manager_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'zc-data-manager'));
        }
        
        $source_type = sanitize_text_field($_POST['source_type']);
        $config = $_POST['config'];
        
        $collector = ZC_Data_Collector::get_instance();
        $result = $collector->preview_source_data($source_type, $config);
        
        wp_send_json($result);
    }
    
    /**
     * AJAX: Manual cron trigger
     */
    public function ajax_manual_cron() {
        check_ajax_referer('zc_data_manager_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'zc-data-manager'));
        }
        
        $job_type = sanitize_text_field($_POST['job_type']);
        
        $cron_manager = ZC_Cron_Manager::get_instance();
        $result = $cron_manager->manual_trigger($job_type);
        
        wp_send_json($result);
    }
    
    /**
     * AJAX: Reschedule cron events
     */
    public function ajax_reschedule_cron() {
        check_ajax_referer('zc_data_manager_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'zc-data-manager'));
        }
        
        $cron_manager = ZC_Cron_Manager::get_instance();
        $result = $cron_manager->reschedule_all_events();
        
        wp_send_json($result);
    }
    
    /**
     * AJAX: Cleanup logs
     */
    public function ajax_cleanup_logs() {
        check_ajax_referer('zc_data_manager_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'zc-data-manager'));
        }
        
        $database = ZC_Database::get_instance();
        $deleted = $database->cleanup_old_logs();
        
        wp_send_json(array(
            'success' => true,
            'message' => sprintf(__('Cleaned up %d old log entries', 'zc-data-manager'), $deleted)
        ));
    }
    
    /**
     * AJAX: Export logs
     */
    public function ajax_export_logs() {
        check_ajax_referer('zc_data_manager_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'zc-data-manager'));
        }
        
        $database = ZC_Database::get_instance();
        
        // Get filter parameters
        $status = sanitize_text_field($_GET['status'] ?? '');
        $series = sanitize_text_field($_GET['series'] ?? '');
        $days = intval($_GET['days'] ?? 30);
        $search = sanitize_text_field($_GET['search'] ?? '');
        
        // Get logs
        $logs = $database->get_logs(1000, $series, $status, $days);
        
        // Filter by search if provided
        if (!empty($search)) {
            $logs = array_filter($logs, function($log) use ($search) {
                $searchable = strtolower($log['series_slug'] . ' ' . $log['action'] . ' ' . $log['message']);
                return strpos($searchable, strtolower($search)) !== false;
            });
        }
        
        // Generate CSV
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="zc-data-manager-logs-' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // CSV header
        fputcsv($output, array('Date', 'Series', 'Source', 'Action', 'Status', 'Message'));
        
        // CSV data
        foreach ($logs as $log) {
            fputcsv($output, array(
                $log['created_at'],
                $log['series_slug'],
                $log['source_type'],
                $log['action'],
                $log['status'],
                $log['message']
            ));
        }
        
        fclose($output);
        exit;
    }
}

// Initialize the plugin
ZC_Data_Manager::get_instance();