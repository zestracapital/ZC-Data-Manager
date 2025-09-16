<?php
/**
 * ZC Data Manager - Data Collector Class
 * Handles data collection from various sources
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class ZC_Data_Collector {
    
    private static $instance = null;
    private $database;
    private $sources = array();
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->database = ZC_Database::get_instance();
        $this->register_sources();
    }
    
    /**
     * Register all available data sources
     */
    private function register_sources() {
        $this->sources = array(
            'fred' => array(
                'name' => 'FRED (Federal Reserve)',
                'class' => 'ZC_Source_FRED',
                'requires_api_key' => true,
                'description' => 'US Federal Reserve Economic Data'
            ),
            'worldbank' => array(
                'name' => 'World Bank Open Data',
                'class' => 'ZC_Source_WorldBank',
                'requires_api_key' => false,
                'description' => 'Global development data from World Bank'
            ),
            'dbnomics' => array(
                'name' => 'DBnomics',
                'class' => 'ZC_Source_DBnomics',
                'requires_api_key' => false,
                'description' => 'Aggregate data from 80+ statistical agencies'
            ),
            'eurostat' => array(
                'name' => 'Eurostat',
                'class' => 'ZC_Source_Eurostat',
                'requires_api_key' => false,
                'description' => 'European Union statistics'
            ),
            'oecd' => array(
                'name' => 'OECD Data',
                'class' => 'ZC_Source_OECD',
                'requires_api_key' => false,
                'description' => 'OECD member countries economic data'
            ),
            'alphavantage' => array(
                'name' => 'Alpha Vantage',
                'class' => 'ZC_Source_AlphaVantage',
                'requires_api_key' => true,
                'description' => 'Stock market and financial data'
            ),
            'yahoo' => array(
                'name' => 'Yahoo Finance',
                'class' => 'ZC_Source_Yahoo',
                'requires_api_key' => false,
                'description' => 'Stock prices and financial data'
            ),
            'quandl' => array(
                'name' => 'Quandl',
                'class' => 'ZC_Source_Quandl',
                'requires_api_key' => true,
                'description' => 'Financial and economic data'
            ),
            'csv' => array(
                'name' => 'CSV File/URL',
                'class' => 'ZC_Source_CSV',
                'requires_api_key' => false,
                'description' => 'Import from CSV files or URLs'
            ),
            'googlesheets' => array(
                'name' => 'Google Sheets',
                'class' => 'ZC_Source_GoogleSheets',
                'requires_api_key' => false,
                'description' => 'Import from public Google Sheets'
            )
        );
        
        // Allow plugins to register additional sources
        $this->sources = apply_filters('zc_data_sources', $this->sources);
    }
    
    /**
     * Get all available data sources
     */
    public function get_available_sources() {
        return $this->sources;
    }
    
    /**
     * Get specific data source info
     */
    public function get_source_info($source_type) {
        return isset($this->sources[$source_type]) ? $this->sources[$source_type] : null;
    }
    
    /**
     * Test connection to a data source
     */
    public function test_source_connection($source_type, $config) {
        if (!isset($this->sources[$source_type])) {
            return array(
                'success' => false,
                'message' => __('Unknown data source type', 'zc-data-manager')
            );
        }
        
        $source_class = $this->sources[$source_type]['class'];
        
        if (!class_exists($source_class)) {
            return array(
                'success' => false,
                'message' => sprintf(__('Source class %s not found', 'zc-data-manager'), $source_class)
            );
        }
        
        try {
            $source = new $source_class();
            
            if (method_exists($source, 'test_connection')) {
                $result = $source->test_connection($config);
            } else {
                // If no test method, try a simple fetch
                $result = $source->fetch_data($config);
                
                if ($result && !empty($result)) {
                    $result = array(
                        'success' => true,
                        'message' => __('Connection successful', 'zc-data-manager'),
                        'sample_count' => count($result)
                    );
                } else {
                    $result = array(
                        'success' => false,
                        'message' => __('No data returned from source', 'zc-data-manager')
                    );
                }
            }
            
            // Log the test
            $status = $result['success'] ? 'success' : 'error';
            $this->database->log_action(
                '',
                $source_type,
                'Connection test',
                $status,
                $result['message']
            );
            
            return $result;
            
        } catch (Exception $e) {
            $error_message = sprintf(__('Connection test failed: %s', 'zc-data-manager'), $e->getMessage());
            
            // Log the error
            $this->database->log_action(
                '',
                $source_type,
                'Connection test',
                'error',
                $error_message
            );
            
            return array(
                'success' => false,
                'message' => $error_message
            );
        }
    }
    
    /**
     * Fetch data for a specific series
     */
    public function fetch_series_data($series_slug) {
        $series = $this->database->get_series_by_slug($series_slug);
        
        if (!$series) {
            return array(
                'success' => false,
                'message' => __('Series not found', 'zc-data-manager')
            );
        }
        
        if (!$series['is_active']) {
            return array(
                'success' => false,
                'message' => __('Series is not active', 'zc-data-manager')
            );
        }
        
        $source_type = $series['source_type'];
        $source_config = $series['source_config'];
        
        if (!isset($this->sources[$source_type])) {
            $this->database->log_action(
                $series_slug,
                $source_type,
                'Data fetch',
                'error',
                'Unknown source type'
            );
            
            return array(
                'success' => false,
                'message' => __('Unknown source type', 'zc-data-manager')
            );
        }
        
        $source_class = $this->sources[$source_type]['class'];
        
        if (!class_exists($source_class)) {
            $this->database->log_action(
                $series_slug,
                $source_type,
                'Data fetch',
                'error',
                "Source class {$source_class} not found"
            );
            
            return array(
                'success' => false,
                'message' => sprintf(__('Source class %s not found', 'zc-data-manager'), $source_class)
            );
        }
        
        try {
            $source = new $source_class();
            $data = $source->fetch_data($source_config);
            
            if (!$data || !is_array($data)) {
                throw new Exception(__('No data returned from source', 'zc-data-manager'));
            }
            
            // Validate data format
            $validated_data = $this->validate_data($data);
            
            if (empty($validated_data)) {
                throw new Exception(__('No valid observations found in source data', 'zc-data-manager'));
            }
            
            // Save observations to database
            $save_result = $this->database->save_observations($series_slug, $validated_data);
            
            if ($save_result['success']) {
                $message = sprintf(
                    __('Successfully fetched %d observations (%d new, %d updated)', 'zc-data-manager'),
                    count($validated_data),
                    $save_result['inserted'],
                    $save_result['updated']
                );
                
                $this->database->log_action(
                    $series_slug,
                    $source_type,
                    'Data fetch',
                    'success',
                    $message
                );
                
                return array(
                    'success' => true,
                    'message' => $message,
                    'data_count' => count($validated_data),
                    'inserted' => $save_result['inserted'],
                    'updated' => $save_result['updated']
                );
            } else {
                throw new Exception($save_result['message']);
            }
            
        } catch (Exception $e) {
            $error_message = sprintf(__('Data fetch failed: %s', 'zc-data-manager'), $e->getMessage());
            
            $this->database->log_action(
                $series_slug,
                $source_type,
                'Data fetch',
                'error',
                $error_message
            );
            
            return array(
                'success' => false,
                'message' => $error_message
            );
        }
    }
    
    /**
     * Refresh data for a specific series
     */
    public function refresh_series($series_slug) {
        return $this->fetch_series_data($series_slug);
    }
    
    /**
     * Refresh all active series
     */
    public function refresh_all_series() {
        $series_list = $this->database->get_all_series(true); // Active only
        $results = array(
            'total' => count($series_list),
            'success' => 0,
            'failed' => 0,
            'details' => array()
        );
        
        foreach ($series_list as $series) {
            $result = $this->fetch_series_data($series['slug']);
            
            if ($result['success']) {
                $results['success']++;
            } else {
                $results['failed']++;
            }
            
            $results['details'][$series['slug']] = $result;
            
            // Add small delay to avoid rate limiting
            usleep(100000); // 0.1 second
        }
        
        // Log overall results
        $status = $results['failed'] > 0 ? 'warning' : 'success';
        $message = sprintf(
            __('Bulk refresh completed: %d successful, %d failed out of %d total', 'zc-data-manager'),
            $results['success'],
            $results['failed'],
            $results['total']
        );
        
        $this->database->log_action(
            '',
            '',
            'Bulk refresh',
            $status,
            $message
        );
        
        return $results;
    }
    
    /**
     * Refresh series by frequency
     */
    public function refresh_by_frequency($frequency) {
        $frequency_map = array(
            'hourly' => array('stock', 'crypto', 'forex'),
            'daily' => array('stock', 'economic', 'commodity'),
            'weekly' => array('economic'),
            'monthly' => array('economic'),
            'quarterly' => array('economic'),
            'yearly' => array('economic')
        );
        
        if (!isset($frequency_map[$frequency])) {
            return array(
                'success' => false,
                'message' => __('Invalid frequency specified', 'zc-data-manager')
            );
        }
        
        // For now, we'll refresh based on last update time
        $time_limits = array(
            'hourly' => '-1 hour',
            'daily' => '-1 day',
            'weekly' => '-1 week',
            'monthly' => '-1 month',
            'quarterly' => '-3 months',
            'yearly' => '-1 year'
        );
        
        $cutoff_time = date('Y-m-d H:i:s', strtotime($time_limits[$frequency]));
        
        // Get series that haven't been updated recently
        global $wpdb;
        $tables = $this->database->get_table_names();
        
        $series_to_update = $wpdb->get_results($wpdb->prepare(
            "SELECT slug FROM {$tables['series']} 
             WHERE is_active = 1 
             AND (last_updated IS NULL OR last_updated < %s)",
            $cutoff_time
        ), ARRAY_A);
        
        $results = array(
            'frequency' => $frequency,
            'total' => count($series_to_update),
            'success' => 0,
            'failed' => 0,
            'details' => array()
        );
        
        foreach ($series_to_update as $series) {
            $result = $this->fetch_series_data($series['slug']);
            
            if ($result['success']) {
                $results['success']++;
            } else {
                $results['failed']++;
            }
            
            $results['details'][$series['slug']] = $result;
            
            // Add delay to avoid rate limiting
            usleep(200000); // 0.2 second
        }
        
        return $results;
    }
    
    /**
     * Validate data format from sources
     */
    private function validate_data($data) {
        $validated = array();
        
        foreach ($data as $observation) {
            // Skip if not array or missing required fields
            if (!is_array($observation) || !isset($observation['date']) || !isset($observation['value'])) {
                continue;
            }
            
            // Validate date format
            $date = $this->validate_date($observation['date']);
            if (!$date) {
                continue;
            }
            
            // Validate value
            $value = $this->validate_value($observation['value']);
            if ($value === null) {
                continue;
            }
            
            $validated[] = array(
                'date' => $date,
                'value' => $value
            );
        }
        
        // Remove duplicates based on date
        $unique_dates = array();
        $final_data = array();
        
        foreach ($validated as $obs) {
            if (!in_array($obs['date'], $unique_dates)) {
                $unique_dates[] = $obs['date'];
                $final_data[] = $obs;
            }
        }
        
        return $final_data;
    }
    
    /**
     * Validate and format date
     */
    private function validate_date($date_input) {
        // Try different date formats
        $formats = array(
            'Y-m-d',
            'Y-m-d H:i:s',
            'm/d/Y',
            'd/m/Y',
            'Y-m',
            'Y'
        );
        
        foreach ($formats as $format) {
            $parsed_date = DateTime::createFromFormat($format, $date_input);
            if ($parsed_date !== false) {
                return $parsed_date->format('Y-m-d');
            }
        }
        
        // Try strtotime as fallback
        $timestamp = strtotime($date_input);
        if ($timestamp !== false) {
            return date('Y-m-d', $timestamp);
        }
        
        return false;
    }
    
    /**
     * Validate and format value
     */
    private function validate_value($value_input) {
        // Handle null, empty, or non-numeric values
        if ($value_input === null || $value_input === '' || $value_input === '.') {
            return null;
        }
        
        // Remove common formatting (commas, spaces)
        $cleaned = str_replace(array(',', ' '), '', $value_input);
        
        // Check if numeric
        if (is_numeric($cleaned)) {
            return floatval($cleaned);
        }
        
        return null;
    }
    
    /**
     * Get source configuration form fields
     */
    public function get_source_config_fields($source_type) {
        if (!isset($this->sources[$source_type])) {
            return array();
        }
        
        $source_class = $this->sources[$source_type]['class'];
        
        if (!class_exists($source_class)) {
            return array();
        }
        
        $source = new $source_class();
        
        if (method_exists($source, 'get_config_fields')) {
            return $source->get_config_fields();
        }
        
        return array();
    }
    
    /**
     * Preview data from source before saving
     */
    public function preview_source_data($source_type, $config, $limit = 10) {
        if (!isset($this->sources[$source_type])) {
            return array(
                'success' => false,
                'message' => __('Unknown data source type', 'zc-data-manager')
            );
        }
        
        $source_class = $this->sources[$source_type]['class'];
        
        if (!class_exists($source_class)) {
            return array(
                'success' => false,
                'message' => sprintf(__('Source class %s not found', 'zc-data-manager'), $source_class)
            );
        }
        
        try {
            $source = new $source_class();
            $data = $source->fetch_data($config);
            
            if (!$data || !is_array($data)) {
                throw new Exception(__('No data returned from source', 'zc-data-manager'));
            }
            
            // Validate and limit data for preview
            $validated_data = $this->validate_data($data);
            $preview_data = array_slice($validated_data, 0, $limit);
            
            return array(
                'success' => true,
                'message' => sprintf(__('Found %d total observations', 'zc-data-manager'), count($validated_data)),
                'total_count' => count($validated_data),
                'preview_data' => $preview_data,
                'date_range' => array(
                    'start' => !empty($validated_data) ? $validated_data[0]['date'] : null,
                    'end' => !empty($validated_data) ? end($validated_data)['date'] : null
                )
            );
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => sprintf(__('Preview failed: %s', 'zc-data-manager'), $e->getMessage())
            );
        }
    }
}