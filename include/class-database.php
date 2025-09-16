<?php
/**
 * ZC Data Manager - Database Class
 * Handles all database operations for the plugin
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class ZC_Database {
    
    private static $instance = null;
    private $wpdb;
    
    // Table names
    private $series_table;
    private $observations_table;
    private $logs_table;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        
        // Set table names
        $this->series_table = $wpdb->prefix . 'zc_data_series';
        $this->observations_table = $wpdb->prefix . 'zc_data_observations';
        $this->logs_table = $wpdb->prefix . 'zc_data_logs';
    }
    
    /**
     * Create database tables
     */
    public function create_tables() {
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        $charset_collate = $this->wpdb->get_charset_collate();
        
        // Create series table
        $series_sql = "CREATE TABLE {$this->series_table} (
            id int AUTO_INCREMENT PRIMARY KEY,
            slug varchar(128) UNIQUE NOT NULL,
            name varchar(255) NOT NULL,
            source_type varchar(64) NOT NULL,
            source_config longtext DEFAULT NULL,
            is_active tinyint(1) DEFAULT 1,
            last_updated datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_slug (slug),
            INDEX idx_active (is_active),
            INDEX idx_source (source_type)
        ) $charset_collate;";
        
        // Create observations table
        $observations_sql = "CREATE TABLE {$this->observations_table} (
            id int AUTO_INCREMENT PRIMARY KEY,
            series_slug varchar(128) NOT NULL,
            obs_date date NOT NULL,
            obs_value decimal(20,6) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_obs (series_slug, obs_date),
            INDEX idx_series_date (series_slug, obs_date),
            INDEX idx_date (obs_date)
        ) $charset_collate;";
        
        // Create logs table
        $logs_sql = "CREATE TABLE {$this->logs_table} (
            id int AUTO_INCREMENT PRIMARY KEY,
            series_slug varchar(128) DEFAULT NULL,
            source_type varchar(64) DEFAULT NULL,
            action varchar(128) NOT NULL,
            status enum('success','warning','error') DEFAULT 'success',
            message text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_series (series_slug),
            INDEX idx_status (status),
            INDEX idx_date (created_at)
        ) $charset_collate;";
        
        dbDelta($series_sql);
        dbDelta($observations_sql);
        dbDelta($logs_sql);
        
        // Update database version
        update_option('zc_dm_db_version', '1.0');
    }
    
    /**
     * Insert or update a data series
     */
    public function save_series($data) {
        $data = wp_parse_args($data, array(
            'slug' => '',
            'name' => '',
            'source_type' => '',
            'source_config' => '',
            'is_active' => 1
        ));
        
        // Validate required fields
        if (empty($data['slug']) || empty($data['name']) || empty($data['source_type'])) {
            return array(
                'success' => false,
                'message' => __('Required fields are missing', 'zc-data-manager')
            );
        }
        
        // Sanitize data
        $series_data = array(
            'slug' => sanitize_title($data['slug']),
            'name' => sanitize_text_field($data['name']),
            'source_type' => sanitize_text_field($data['source_type']),
            'source_config' => wp_json_encode($data['source_config']),
            'is_active' => intval($data['is_active'])
        );
        
        // Check if series exists
        $existing = $this->get_series_by_slug($series_data['slug']);
        
        if ($existing) {
            // Update existing series
            $result = $this->wpdb->update(
                $this->series_table,
                $series_data,
                array('slug' => $series_data['slug']),
                array('%s', '%s', '%s', '%s', '%d'),
                array('%s')
            );
            
            $action = 'updated';
        } else {
            // Insert new series
            $result = $this->wpdb->insert(
                $this->series_table,
                $series_data,
                array('%s', '%s', '%s', '%s', '%d')
            );
            
            $action = 'created';
        }
        
        if ($result !== false) {
            // Log the action
            $this->log_action(
                $series_data['slug'],
                $series_data['source_type'],
                "Series {$action}",
                'success',
                "Series '{$series_data['name']}' has been {$action} successfully"
            );
            
            return array(
                'success' => true,
                'message' => sprintf(__('Series %s successfully', 'zc-data-manager'), $action),
                'action' => $action,
                'slug' => $series_data['slug']
            );
        } else {
            return array(
                'success' => false,
                'message' => __('Failed to save series to database', 'zc-data-manager'),
                'error' => $this->wpdb->last_error
            );
        }
    }
    
    /**
     * Get series by slug
     */
    public function get_series_by_slug($slug) {
        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->series_table} WHERE slug = %s",
            $slug
        );
        
        $series = $this->wpdb->get_row($query, ARRAY_A);
        
        if ($series && !empty($series['source_config'])) {
            $series['source_config'] = json_decode($series['source_config'], true);
        }
        
        return $series;
    }
    
    /**
     * Get all series
     */
    public function get_all_series($active_only = false) {
        $where = $active_only ? 'WHERE is_active = 1' : '';
        
        $query = "SELECT * FROM {$this->series_table} {$where} ORDER BY created_at DESC";
        $results = $this->wpdb->get_results($query, ARRAY_A);
        
        // Decode source_config for each series
        foreach ($results as &$series) {
            if (!empty($series['source_config'])) {
                $series['source_config'] = json_decode($series['source_config'], true);
            }
        }
        
        return $results;
    }
    
    /**
     * Delete series and all its observations
     */
    public function delete_series($slug) {
        // Start transaction
        $this->wpdb->query('START TRANSACTION');
        
        try {
            // Delete observations first
            $obs_deleted = $this->wpdb->delete(
                $this->observations_table,
                array('series_slug' => $slug),
                array('%s')
            );
            
            // Delete series
            $series_deleted = $this->wpdb->delete(
                $this->series_table,
                array('slug' => $slug),
                array('%s')
            );
            
            if ($series_deleted !== false) {
                // Commit transaction
                $this->wpdb->query('COMMIT');
                
                // Log the action
                $this->log_action(
                    $slug,
                    '',
                    'Series deleted',
                    'success',
                    "Series '{$slug}' and {$obs_deleted} observations deleted"
                );
                
                return array(
                    'success' => true,
                    'message' => __('Series deleted successfully', 'zc-data-manager'),
                    'observations_deleted' => $obs_deleted
                );
            } else {
                throw new Exception($this->wpdb->last_error);
            }
        } catch (Exception $e) {
            // Rollback transaction
            $this->wpdb->query('ROLLBACK');
            
            return array(
                'success' => false,
                'message' => __('Failed to delete series', 'zc-data-manager'),
                'error' => $e->getMessage()
            );
        }
    }
    
    /**
     * Save observations for a series
     */
    public function save_observations($series_slug, $observations) {
        if (empty($observations) || !is_array($observations)) {
            return array(
                'success' => false,
                'message' => __('No observations provided', 'zc-data-manager')
            );
        }
        
        $inserted = 0;
        $updated = 0;
        $errors = 0;
        
        foreach ($observations as $obs) {
            if (!isset($obs['date']) || !isset($obs['value'])) {
                $errors++;
                continue;
            }
            
            // Sanitize data
            $obs_data = array(
                'series_slug' => sanitize_text_field($series_slug),
                'obs_date' => sanitize_text_field($obs['date']),
                'obs_value' => floatval($obs['value'])
            );
            
            // Check if observation exists
            $existing = $this->wpdb->get_var($this->wpdb->prepare(
                "SELECT id FROM {$this->observations_table} WHERE series_slug = %s AND obs_date = %s",
                $obs_data['series_slug'],
                $obs_data['obs_date']
            ));
            
            if ($existing) {
                // Update existing observation
                $result = $this->wpdb->update(
                    $this->observations_table,
                    array('obs_value' => $obs_data['obs_value']),
                    array('id' => $existing),
                    array('%f'),
                    array('%d')
                );
                
                if ($result !== false) {
                    $updated++;
                } else {
                    $errors++;
                }
            } else {
                // Insert new observation
                $result = $this->wpdb->insert(
                    $this->observations_table,
                    $obs_data,
                    array('%s', '%s', '%f')
                );
                
                if ($result !== false) {
                    $inserted++;
                } else {
                    $errors++;
                }
            }
        }
        
        // Update series last_updated timestamp
        $this->wpdb->update(
            $this->series_table,
            array('last_updated' => current_time('mysql')),
            array('slug' => $series_slug),
            array('%s'),
            array('%s')
        );
        
        $total_processed = $inserted + $updated;
        $message = sprintf(
            __('%d observations processed: %d new, %d updated, %d errors', 'zc-data-manager'),
            count($observations),
            $inserted,
            $updated,
            $errors
        );
        
        // Log the action
        $this->log_action(
            $series_slug,
            '',
            'Observations saved',
            $errors > 0 ? 'warning' : 'success',
            $message
        );
        
        return array(
            'success' => $total_processed > 0,
            'message' => $message,
            'inserted' => $inserted,
            'updated' => $updated,
            'errors' => $errors
        );
    }
    
    /**
     * Get observations for a series
     */
    public function get_observations($series_slug, $start_date = null, $end_date = null, $limit = null) {
        $where_conditions = array("series_slug = %s");
        $where_values = array($series_slug);
        
        if ($start_date) {
            $where_conditions[] = "obs_date >= %s";
            $where_values[] = $start_date;
        }
        
        if ($end_date) {
            $where_conditions[] = "obs_date <= %s";
            $where_values[] = $end_date;
        }
        
        $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
        $limit_clause = $limit ? "LIMIT " . intval($limit) : '';
        
        $query = "SELECT obs_date as date, obs_value as value 
                  FROM {$this->observations_table} 
                  {$where_clause} 
                  ORDER BY obs_date ASC 
                  {$limit_clause}";
        
        $prepared_query = $this->wpdb->prepare($query, $where_values);
        return $this->wpdb->get_results($prepared_query, ARRAY_A);
    }
    
    /**
     * Search series by name or slug
     */
    public function search_series($query) {
        $search_query = $this->wpdb->prepare(
            "SELECT slug, name, source_type, last_updated 
             FROM {$this->series_table} 
             WHERE (name LIKE %s OR slug LIKE %s) 
             AND is_active = 1 
             ORDER BY name ASC",
            '%' . $this->wpdb->esc_like($query) . '%',
            '%' . $this->wpdb->esc_like($query) . '%'
        );
        
        return $this->wpdb->get_results($search_query, ARRAY_A);
    }
    
    /**
     * Log an action
     */
    public function log_action($series_slug, $source_type, $action, $status = 'success', $message = '') {
        $log_data = array(
            'series_slug' => sanitize_text_field($series_slug),
            'source_type' => sanitize_text_field($source_type),
            'action' => sanitize_text_field($action),
            'status' => in_array($status, array('success', 'warning', 'error')) ? $status : 'success',
            'message' => sanitize_textarea_field($message)
        );
        
        $this->wpdb->insert(
            $this->logs_table,
            $log_data,
            array('%s', '%s', '%s', '%s', '%s')
        );
        
        // Send email alert for error status if enabled
        if ($status === 'error' && get_option('zc_dm_error_emails', 1)) {
            $this->send_error_email($log_data);
        }
    }
    
    /**
     * Get logs with optional filtering
     */
    public function get_logs($limit = 50, $series_slug = '', $status = '', $days = 30) {
        $where_conditions = array();
        $where_values = array();
        
        if (!empty($series_slug)) {
            $where_conditions[] = "series_slug = %s";
            $where_values[] = $series_slug;
        }
        
        if (!empty($status)) {
            $where_conditions[] = "status = %s";
            $where_values[] = $status;
        }
        
        if ($days > 0) {
            $where_conditions[] = "created_at >= %s";
            $where_values[] = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        }
        
        $where_clause = empty($where_conditions) ? '' : 'WHERE ' . implode(' AND ', $where_conditions);
        
        $query = "SELECT * FROM {$this->logs_table} 
                  {$where_clause} 
                  ORDER BY created_at DESC 
                  LIMIT %d";
        
        $where_values[] = intval($limit);
        
        $prepared_query = $this->wpdb->prepare($query, $where_values);
        return $this->wpdb->get_results($prepared_query, ARRAY_A);
    }
    
    /**
     * Get dashboard statistics
     */
    public function get_dashboard_stats() {
        // Count series
        $total_series = $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->series_table}");
        $active_series = $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->series_table} WHERE is_active = 1");
        
        // Count observations
        $total_observations = $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->observations_table}");
        
        // Latest observations date
        $latest_observation = $this->wpdb->get_var("SELECT MAX(obs_date) FROM {$this->observations_table}");
        
        // Recent errors count (last 7 days)
        $recent_errors = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->logs_table} 
             WHERE status = 'error' AND created_at >= %s",
            date('Y-m-d H:i:s', strtotime('-7 days'))
        ));
        
        // Last successful updates
        $last_updates = $this->wpdb->get_results(
            "SELECT series_slug, MAX(created_at) as last_update 
             FROM {$this->logs_table} 
             WHERE status = 'success' AND action LIKE '%observations%'
             GROUP BY series_slug 
             ORDER BY last_update DESC 
             LIMIT 5",
            ARRAY_A
        );
        
        return array(
            'total_series' => intval($total_series),
            'active_series' => intval($active_series),
            'total_observations' => intval($total_observations),
            'latest_observation' => $latest_observation,
            'recent_errors' => intval($recent_errors),
            'last_updates' => $last_updates
        );
    }
    
    /**
     * Clean up old logs
     */
    public function cleanup_old_logs() {
        $retention_days = get_option('zc_dm_log_retention_days', 30);
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$retention_days} days"));
        
        $deleted = $this->wpdb->query($this->wpdb->prepare(
            "DELETE FROM {$this->logs_table} WHERE created_at < %s",
            $cutoff_date
        ));
        
        return $deleted;
    }
    
    /**
     * Send error email notification
     */
    private function send_error_email($log_data) {
        $admin_email = get_option('zc_dm_admin_email', get_option('admin_email'));
        
        if (empty($admin_email)) {
            return;
        }
        
        $subject = sprintf(
            __('[%s] ZC Data Manager Error', 'zc-data-manager'),
            get_bloginfo('name')
        );
        
        $message = sprintf(
            __("An error occurred in ZC Data Manager:\n\nSeries: %s\nSource: %s\nAction: %s\nMessage: %s\nTime: %s\n\nPlease check the logs for more details.", 'zc-data-manager'),
            $log_data['series_slug'],
            $log_data['source_type'],
            $log_data['action'],
            $log_data['message'],
            current_time('mysql')
        );
        
        wp_mail($admin_email, $subject, $message);
    }
    
    /**
     * Get table names (for external access)
     */
    public function get_table_names() {
        return array(
            'series' => $this->series_table,
            'observations' => $this->observations_table,
            'logs' => $this->logs_table
        );
    }
}