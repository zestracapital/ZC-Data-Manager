<?php
/**
 * ZC Data Manager - Public API Functions
 * Functions for the Charts plugin to access data
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get data for a specific series
 * 
 * @param string $slug Series slug
 * @param string $start_date Optional start date (Y-m-d format)
 * @param string $end_date Optional end date (Y-m-d format)
 * @return array|false Array of data points or false on error
 */
function zc_get_series_data($slug, $start_date = null, $end_date = null) {
    if (empty($slug)) {
        return false;
    }
    
    // Check if plugin is properly initialized
    if (!class_exists('ZC_Database')) {
        return false;
    }
    
    $database = ZC_Database::get_instance();
    
    // Validate series exists and is active
    $series = $database->get_series_by_slug($slug);
    if (!$series || !$series['is_active']) {
        return false;
    }
    
    // Get observations
    $observations = $database->get_observations($slug, $start_date, $end_date);
    
    // Format data for consumption
    $formatted_data = array();
    foreach ($observations as $obs) {
        $formatted_data[] = array(
            'date' => $obs['date'],
            'value' => floatval($obs['value'])
        );
    }
    
    return $formatted_data;
}

/**
 * Get series information (metadata)
 * 
 * @param string $slug Series slug
 * @return array|false Series information or false on error
 */
function zc_get_series_info($slug) {
    if (empty($slug)) {
        return false;
    }
    
    if (!class_exists('ZC_Database')) {
        return false;
    }
    
    $database = ZC_Database::get_instance();
    $series = $database->get_series_by_slug($slug);
    
    if (!$series) {
        return false;
    }
    
    // Return formatted series info
    return array(
        'slug' => $series['slug'],
        'name' => $series['name'],
        'source_type' => $series['source_type'],
        'is_active' => (bool) $series['is_active'],
        'last_updated' => $series['last_updated'],
        'created_at' => $series['created_at']
    );
}

/**
 * Get all available series
 * 
 * @param bool $active_only Whether to return only active series
 * @return array Array of series information
 */
function zc_get_all_series($active_only = true) {
    if (!class_exists('ZC_Database')) {
        return array();
    }
    
    $database = ZC_Database::get_instance();
    $all_series = $database->get_all_series($active_only);
    
    // Format for public consumption
    $formatted_series = array();
    foreach ($all_series as $series) {
        $formatted_series[] = array(
            'slug' => $series['slug'],
            'name' => $series['name'],
            'source_type' => $series['source_type'],
            'is_active' => (bool) $series['is_active'],
            'last_updated' => $series['last_updated']
        );
    }
    
    return $formatted_series;
}

/**
 * Search series by name or slug
 * 
 * @param string $query Search query
 * @param int $limit Maximum results to return
 * @return array Array of matching series
 */
function zc_search_series($query, $limit = 20) {
    if (empty($query)) {
        return array();
    }
    
    if (!class_exists('ZC_Database')) {
        return array();
    }
    
    $database = ZC_Database::get_instance();
    $results = $database->search_series($query);
    
    // Apply limit and format results
    $limited_results = array_slice($results, 0, $limit);
    
    $formatted_results = array();
    foreach ($limited_results as $series) {
        $formatted_results[] = array(
            'slug' => $series['slug'],
            'name' => $series['name'],
            'source_type' => $series['source_type'],
            'last_updated' => $series['last_updated']
        );
    }
    
    return $formatted_results;
}

/**
 * Get data for date range (helper function)
 * 
 * @param string $slug Series slug
 * @param int $months_back Number of months to go back
 * @return array|false Array of data points or false on error
 */
function zc_get_data_range($slug, $months_back = 12) {
    $end_date = date('Y-m-d');
    $start_date = date('Y-m-d', strtotime("-{$months_back} months"));
    
    return zc_get_series_data($slug, $start_date, $end_date);
}

/**
 * Get latest data point for a series
 * 
 * @param string $slug Series slug
 * @return array|false Latest data point or false on error
 */
function zc_get_latest_value($slug) {
    if (empty($slug)) {
        return false;
    }
    
    if (!class_exists('ZC_Database')) {
        return false;
    }
    
    $database = ZC_Database::get_instance();
    
    // Get last observation
    $latest = $database->get_observations($slug, null, null, 1);
    
    if (empty($latest)) {
        return false;
    }
    
    // Return the latest data point
    $observation = $latest[0];
    return array(
        'date' => $observation['date'],
        'value' => floatval($observation['value'])
    );
}

/**
 * Check if series exists and is active
 * 
 * @param string $slug Series slug
 * @return bool True if series exists and is active
 */
function zc_series_exists($slug) {
    if (empty($slug)) {
        return false;
    }
    
    if (!class_exists('ZC_Database')) {
        return false;
    }
    
    $database = ZC_Database::get_instance();
    $series = $database->get_series_by_slug($slug);
    
    return $series && $series['is_active'];
}

/**
 * Get data points count for a series
 * 
 * @param string $slug Series slug
 * @return int Number of data points
 */
function zc_get_data_count($slug) {
    if (!zc_series_exists($slug)) {
        return 0;
    }
    
    global $wpdb;
    
    if (!class_exists('ZC_Database')) {
        return 0;
    }
    
    $database = ZC_Database::get_instance();
    $tables = $database->get_table_names();
    
    $count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$tables['observations']} WHERE series_slug = %s",
        $slug
    ));
    
    return intval($count);
}

/**
 * Get date range for a series (first and last dates)
 * 
 * @param string $slug Series slug
 * @return array|false Array with start_date and end_date or false
 */
function zc_get_series_date_range($slug) {
    if (!zc_series_exists($slug)) {
        return false;
    }
    
    global $wpdb;
    
    if (!class_exists('ZC_Database')) {
        return false;
    }
    
    $database = ZC_Database::get_instance();
    $tables = $database->get_table_names();
    
    $range = $wpdb->get_row($wpdb->prepare(
        "SELECT MIN(obs_date) as start_date, MAX(obs_date) as end_date 
         FROM {$tables['observations']} 
         WHERE series_slug = %s",
        $slug
    ), ARRAY_A);
    
    if (!$range || !$range['start_date']) {
        return false;
    }
    
    return array(
        'start_date' => $range['start_date'],
        'end_date' => $range['end_date']
    );
}

/**
 * Get statistics for a series (min, max, average)
 * 
 * @param string $slug Series slug
 * @return array|false Array with statistics or false
 */
function zc_get_series_stats($slug) {
    if (!zc_series_exists($slug)) {
        return false;
    }
    
    global $wpdb;
    
    if (!class_exists('ZC_Database')) {
        return false;
    }
    
    $database = ZC_Database::get_instance();
    $tables = $database->get_table_names();
    
    $stats = $wpdb->get_row($wpdb->prepare(
        "SELECT 
            MIN(obs_value) as min_value,
            MAX(obs_value) as max_value,
            AVG(obs_value) as avg_value,
            COUNT(*) as data_points
         FROM {$tables['observations']} 
         WHERE series_slug = %s AND obs_value IS NOT NULL",
        $slug
    ), ARRAY_A);
    
    if (!$stats) {
        return false;
    }
    
    return array(
        'min_value' => floatval($stats['min_value']),
        'max_value' => floatval($stats['max_value']),
        'avg_value' => floatval($stats['avg_value']),
        'data_points' => intval($stats['data_points'])
    );
}

/**
 * Get data formatted for Chart.js
 * 
 * @param string $slug Series slug
 * @param string $start_date Optional start date
 * @param string $end_date Optional end date
 * @return array|false Chart.js formatted data or false
 */
function zc_get_chartjs_data($slug, $start_date = null, $end_date = null) {
    $data = zc_get_series_data($slug, $start_date, $end_date);
    
    if (!$data) {
        return false;
    }
    
    $chartjs_data = array();
    foreach ($data as $point) {
        $chartjs_data[] = array(
            'x' => $point['date'],
            'y' => $point['value']
        );
    }
    
    return $chartjs_data;
}

/**
 * Get multiple series data for comparison charts
 * 
 * @param array $slugs Array of series slugs
 * @param string $start_date Optional start date
 * @param string $end_date Optional end date
 * @return array Array with data for each series
 */
function zc_get_multiple_series_data($slugs, $start_date = null, $end_date = null) {
    if (!is_array($slugs) || empty($slugs)) {
        return array();
    }
    
    $result = array();
    
    foreach ($slugs as $slug) {
        $data = zc_get_series_data($slug, $start_date, $end_date);
        $info = zc_get_series_info($slug);
        
        if ($data && $info) {
            $result[$slug] = array(
                'info' => $info,
                'data' => $data
            );
        }
    }
    
    return $result;
}

/**
 * Calculate percentage change for a series
 * 
 * @param string $slug Series slug
 * @param int $periods Number of periods to compare
 * @return float|false Percentage change or false
 */
function zc_calculate_change($slug, $periods = 1) {
    $data = zc_get_series_data($slug);
    
    if (!$data || count($data) <= $periods) {
        return false;
    }
    
    $latest = end($data);
    $previous = $data[count($data) - 1 - $periods];
    
    if ($previous['value'] == 0) {
        return false;
    }
    
    $change = (($latest['value'] - $previous['value']) / $previous['value']) * 100;
    
    return round($change, 2);
}

/**
 * Get available data sources information
 * 
 * @return array Array of available data sources
 */
function zc_get_available_sources() {
    if (!class_exists('ZC_Data_Collector')) {
        return array();
    }
    
    $collector = ZC_Data_Collector::get_instance();
    $sources = $collector->get_available_sources();
    
    // Format for public consumption (remove class names)
    $public_sources = array();
    foreach ($sources as $key => $source) {
        $public_sources[$key] = array(
            'name' => $source['name'],
            'description' => $source['description'],
            'requires_api_key' => $source['requires_api_key']
        );
    }
    
    return $public_sources;
}

/**
 * Refresh a specific series (for admin use)
 * 
 * @param string $slug Series slug
 * @return array Result array with success status and message
 */
function zc_refresh_series_data($slug) {
    if (!current_user_can('manage_options')) {
        return array(
            'success' => false,
            'message' => __('Insufficient permissions', 'zc-data-manager')
        );
    }
    
    if (!class_exists('ZC_Data_Collector')) {
        return array(
            'success' => false,
            'message' => __('Data collector not available', 'zc-data-manager')
        );
    }
    
    $collector = ZC_Data_Collector::get_instance();
    return $collector->refresh_series($slug);
}

/**
 * Check if ZC Data Manager is properly installed and configured
 * 
 * @return bool True if plugin is ready for use
 */
function zc_is_data_manager_ready() {
    // Check if classes exist
    if (!class_exists('ZC_Database') || !class_exists('ZC_Data_Collector')) {
        return false;
    }
    
    // Check if database tables exist
    global $wpdb;
    $database = ZC_Database::get_instance();
    $tables = $database->get_table_names();
    
    $series_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$tables['series']}'");
    $obs_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$tables['observations']}'");
    
    return $series_table_exists && $obs_table_exists;
}

/**
 * Get plugin version and status information
 * 
 * @return array Plugin information
 */
function zc_get_plugin_info() {
    return array(
        'version' => ZC_DATA_MANAGER_VERSION,
        'ready' => zc_is_data_manager_ready(),
        'total_series' => count(zc_get_all_series(false)),
        'active_series' => count(zc_get_all_series(true)),
        'auto_update_enabled' => get_option('zc_dm_auto_update', 1)
    );
}

// WordPress hooks for Charts plugin integration
add_action('init', function() {
    // Make functions available after WordPress init
    if (function_exists('add_action')) {
        do_action('zc_data_manager_loaded');
    }
});

// Filter for Charts plugin to check if Data Manager is available
add_filter('zc_data_manager_available', function() {
    return zc_is_data_manager_ready();
});