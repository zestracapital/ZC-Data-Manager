<?php
/**
 * ZC Data Manager - Eurostat Data Source
 * Eurostat API integration for European Union statistics
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class ZC_Source_Eurostat {
    
    private $base_url = 'https://ec.europa.eu/eurostat/api/dissemination/statistics/1.0/data/';
    
    public function __construct() {
        // Eurostat API doesn't require API key
    }
    
    /**
     * Get configuration form fields
     */
    public function get_config_fields() {
        return array(
            'dataset_code' => array(
                'type' => 'text',
                'label' => __('Dataset Code', 'zc-data-manager'),
                'description' => __('Eurostat dataset code (e.g., prc_hicp_manr for HICP inflation)', 'zc-data-manager'),
                'required' => true,
                'placeholder' => 'prc_hicp_manr'
            ),
            'filters' => array(
                'type' => 'textarea',
                'label' => __('Filters (Optional)', 'zc-data-manager'),
                'description' => __('Additional filters in key=value format, one per line (e.g., geo=EU27_2020)', 'zc-data-manager'),
                'required' => false,
                'placeholder' => 'geo=EU27_2020' . "\n" . 'coicop=CP00'
            ),
            'time_format' => array(
                'type' => 'select',
                'label' => __('Time Format', 'zc-data-manager'),
                'description' => __('How to interpret time periods', 'zc-data-manager'),
                'required' => true,
                'options' => array(
                    'auto' => 'Auto-detect',
                    'monthly' => 'Monthly (YYYY-MM)',
                    'quarterly' => 'Quarterly (YYYY-QX)',
                    'yearly' => 'Yearly (YYYY)'
                ),
                'default' => 'auto'
            ),
            'start_period' => array(
                'type' => 'text',
                'label' => __('Start Period (Optional)', 'zc-data-manager'),
                'description' => __('Starting time period (e.g., 2010, 2010-01, 2010-Q1)', 'zc-data-manager'),
                'required' => false,
                'placeholder' => '2010'
            ),
            'end_period' => array(
                'type' => 'text',
                'label' => __('End Period (Optional)', 'zc-data-manager'),
                'description' => __('Ending time period (leave empty for latest)', 'zc-data-manager'),
                'required' => false,
                'placeholder' => '2024'
            )
        );
    }
    
    /**
     * Test connection to Eurostat API
     */
    public function test_connection($config) {
        if (empty($config['dataset_code'])) {
            return array(
                'success' => false,
                'message' => __('Dataset code is required for Eurostat connection test', 'zc-data-manager')
            );
        }
        
        // Test with metadata endpoint first (lighter request)
        $metadata_url = $this->base_url . urlencode($config['dataset_code']) . '?format=JSON&compressed=false&lang=en';
        
        $response = wp_remote_get($metadata_url, array(
            'timeout' => 30,
            'user-agent' => 'ZC Data Manager WordPress Plugin'
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => sprintf(__('Connection error: %s', 'zc-data-manager'), $response->get_error_message())
            );
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($response_code !== 200) {
            if ($response_code === 404) {
                return array(
                    'success' => false,
                    'message' => sprintf(__('Dataset "%s" not found in Eurostat', 'zc-data-manager'), $config['dataset_code'])
                );
            }
            
            return array(
                'success' => false,
                'message' => sprintf(__('HTTP Error %d: %s', 'zc-data-manager'), $response_code, $body)
            );
        }
        
        $data = json_decode($body, true);
        
        if (!$data) {
            return array(
                'success' => false,
                'message' => __('Invalid response format from Eurostat API', 'zc-data-manager')
            );
        }
        
        // Extract dataset info
        $dataset_info = array(
            'code' => $config['dataset_code'],
            'title' => 'Eurostat Dataset',
            'last_update' => null
        );
        
        if (isset($data['label'])) {
            $dataset_info['title'] = $data['label'];
        }
        
        if (isset($data['updated'])) {
            $dataset_info['last_update'] = $data['updated'];
        }
        
        // Test with a small data request
        $test_data_url = $this->build_data_url($config['dataset_code'], $config, 'json', array('lastTimePeriod' => '1'));
        
        $data_response = wp_remote_get($test_data_url, array(
            'timeout' => 30,
            'user-agent' => 'ZC Data Manager WordPress Plugin'
        ));
        
        $data_count = 0;
        if (!is_wp_error($data_response) && wp_remote_retrieve_response_code($data_response) === 200) {
            $test_data = json_decode(wp_remote_retrieve_body($data_response), true);
            if (isset($test_data['value']) && is_array($test_data['value'])) {
                $data_count = count($test_data['value']);
            }
        }
        
        return array(
            'success' => true,
            'message' => sprintf(
                __('Connection successful! Found dataset: %s (sample: %d observations)', 'zc-data-manager'),
                $dataset_info['title'],
                $data_count
            ),
            'dataset_info' => $dataset_info
        );
    }
    
    /**
     * Fetch data from Eurostat API
     */
    public function fetch_data($config) {
        if (empty($config['dataset_code'])) {
            throw new Exception(__('Dataset code is required', 'zc-data-manager'));
        }
        
        // Build API URL
        $api_url = $this->build_data_url($config['dataset_code'], $config);
        
        // Make API request
        $response = wp_remote_get($api_url, array(
            'timeout' => 60,
            'user-agent' => 'ZC Data Manager WordPress Plugin'
        ));
        
        if (is_wp_error($response)) {
            throw new Exception(sprintf(__('API request failed: %s', 'zc-data-manager'), $response->get_error_message()));
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($response_code !== 200) {
            $error_message = $this->parse_eurostat_error($response_code, $body);
            throw new Exception($error_message);
        }
        
        $data = json_decode($body, true);
        
        if (!$data) {
            throw new Exception(__('Invalid response format from Eurostat API', 'zc-data-manager'));
        }
        
        // Parse Eurostat JSON-stat format
        return $this->parse_eurostat_data($data, $config);
    }
    
    /**
     * Build Eurostat API URL
     */
    private function build_data_url($dataset_code, $config, $format = 'JSON', $additional_params = array()) {
        $params = array_merge(array(
            'format' => $format,
            'compressed' => 'false',
            'lang' => 'en'
        ), $additional_params);
        
        // Add time period filters if specified
        if (!empty($config['start_period']) && !empty($config['end_period'])) {
            $params['time'] = $config['start_period'] . ':' . $config['end_period'];
        } elseif (!empty($config['start_period'])) {
            $params['sinceTimePeriod'] = $config['start_period'];
        } elseif (!empty($config['end_period'])) {
            $params['untilTimePeriod'] = $config['end_period'];
        }
        
        // Parse additional filters from config
        if (!empty($config['filters'])) {
            $filter_lines = explode("\n", $config['filters']);
            foreach ($filter_lines as $line) {
                $line = trim($line);
                if (empty($line) || strpos($line, '=') === false) {
                    continue;
                }
                
                list($key, $value) = explode('=', $line, 2);
                $params[trim($key)] = trim($value);
            }
        }
        
        return $this->base_url . urlencode($dataset_code) . '?' . http_build_query($params);
    }
    
    /**
     * Parse Eurostat API error responses
     */
    private function parse_eurostat_error($response_code, $body) {
        $error_messages = array(
            400 => __('Bad request - check your dataset code and filters', 'zc-data-manager'),
            404 => __('Dataset not found in Eurostat database', 'zc-data-manager'),
            413 => __('Request too large - try adding more filters to reduce data size', 'zc-data-manager'),
            429 => __('Rate limit exceeded', 'zc-data-manager'),
            500 => __('Eurostat server error', 'zc-data-manager')
        );
        
        $base_message = isset($error_messages[$response_code]) 
            ? $error_messages[$response_code] 
            : sprintf(__('HTTP Error %d', 'zc-data-manager'), $response_code);
        
        // Try to parse JSON error details
        $json_data = json_decode($body, true);
        if ($json_data && isset($json_data['error'])) {
            $base_message .= ': ' . $json_data['error'];
        }
        
        return $base_message;
    }
    
    /**
     * Parse Eurostat JSON-stat format data
     */
    private function parse_eurostat_data($data, $config) {
        $parsed_data = array();
        
        // Eurostat uses JSON-stat format
        if (!isset($data['value']) || !isset($data['dimension'])) {
            throw new Exception(__('Invalid Eurostat data format', 'zc-data-manager'));
        }
        
        $values = $data['value'];
        $dimensions = $data['dimension'];
        
        if (!isset($dimensions['time']['category']['index'])) {
            throw new Exception(__('No time dimension found in dataset', 'zc-data-manager'));
        }
        
        $time_periods = $dimensions['time']['category']['index'];
        $time_size = $dimensions['time']['category']['index'];
        
        // Get time period labels
        $time_labels = array();
        if (isset($dimensions['time']['category']['label'])) {
            $time_labels = $dimensions['time']['category']['label'];
        } else {
            // Use index keys as labels
            $time_labels = array_keys($time_periods);
        }
        
        // Calculate dimension sizes for index calculation
        $dimension_sizes = array();
        $total_size = 1;
        
        foreach ($dimensions as $dim_name => $dim_data) {
            if (isset($dim_data['category']['index'])) {
                $size = count($dim_data['category']['index']);
                $dimension_sizes[$dim_name] = $size;
                $total_size *= $size;
            }
        }
        
        // Find time dimension position (usually last)
        $dim_names = array_keys($dimension_sizes);
        $time_pos = array_search('time', $dim_names);
        
        if ($time_pos === false) {
            throw new Exception(__('Time dimension not found', 'zc-data-manager'));
        }
        
        // Extract values for each time period
        foreach ($time_labels as $time_key => $time_label) {
            $time_index = $time_periods[$time_key];
            
            // Calculate the base index for this time period
            $base_index = 0;
            $multiplier = 1;
            
            // Calculate index based on dimension structure
            for ($i = count($dim_names) - 1; $i >= 0; $i--) {
                if ($i === $time_pos) {
                    $base_index += $time_index * $multiplier;
                }
                $multiplier *= $dimension_sizes[$dim_names[$i]];
            }
            
            // Get value (there might be multiple values per time period, take first non-null)
            $value = null;
            $search_range = $dimension_sizes['time'] ?? 1;
            
            for ($offset = 0; $offset < $search_range && $value === null; $offset++) {
                $index = $base_index + $offset;
                if (isset($values[$index]) && $values[$index] !== null) {
                    $value = $values[$index];
                    break;
                }
            }
            
            if ($value === null) {
                continue; // Skip missing values
            }
            
            // Convert time period to date
            $date = $this->parse_time_period($time_label, $config['time_format'] ?? 'auto');
            
            if ($date) {
                $parsed_data[] = array(
                    'date' => $date,
                    'value' => floatval($value)
                );
            }
        }
        
        // Sort by date
        usort($parsed_data, function($a, $b) {
            return strtotime($a['date']) - strtotime($b['date']);
        });
        
        if (empty($parsed_data)) {
            throw new Exception(__('No valid data points found in dataset', 'zc-data-manager'));
        }
        
        return $parsed_data;
    }
    
    /**
     * Parse Eurostat time period to standard date format
     */
    private function parse_time_period($period, $time_format = 'auto') {
        // Clean the period string
        $period = trim($period);
        
        // Auto-detect format if not specified
        if ($time_format === 'auto') {
            if (preg_match('/^\d{4}-\d{2}$/', $period)) {
                $time_format = 'monthly';
            } elseif (preg_match('/^\d{4}-Q[1-4]$/', $period)) {
                $time_format = 'quarterly';
            } elseif (preg_match('/^\d{4}$/', $period)) {
                $time_format = 'yearly';
            }
        }
        
        switch ($time_format) {
            case 'monthly':
                // YYYY-MM format
                if (preg_match('/^(\d{4})-(\d{2})$/', $period, $matches)) {
                    return $matches[1] . '-' . $matches[2] . '-01';
                }
                break;
                
            case 'quarterly':
                // YYYY-QX format
                if (preg_match('/^(\d{4})-Q([1-4])$/', $period, $matches)) {
                    $year = $matches[1];
                    $quarter = intval($matches[2]);
                    $month = ($quarter - 1) * 3 + 1;
                    return sprintf('%s-%02d-01', $year, $month);
                }
                break;
                
            case 'yearly':
                // YYYY format
                if (preg_match('/^\d{4}$/', $period)) {
                    return $period . '-01-01';
                }
                break;
        }
        
        // Fallback: try to parse with strtotime
        $timestamp = strtotime($period);
        if ($timestamp !== false) {
            return date('Y-m-d', $timestamp);
        }
        
        return false;
    }
    
    /**
     * Search Eurostat datasets
     */
    public function search_datasets($search_text, $limit = 20) {
        // Eurostat doesn't have a direct search API, so we'll return common datasets
        $popular_datasets = $this->get_popular_datasets();
        
        if (empty($search_text)) {
            return array(
                'success' => true,
                'results' => array_slice($popular_datasets, 0, $limit)
            );
        }
        
        // Filter by search text
        $filtered = array();
        $search_lower = strtolower($search_text);
        
        foreach ($popular_datasets as $dataset) {
            $searchable = strtolower($dataset['code'] . ' ' . $dataset['title'] . ' ' . $dataset['description']);
            if (strpos($searchable, $search_lower) !== false) {
                $filtered[] = $dataset;
            }
        }
        
        return array(
            'success' => true,
            'results' => array_slice($filtered, 0, $limit)
        );
    }
    
    /**
     * Get popular Eurostat datasets
     */
    public static function get_popular_datasets() {
        return array(
            array(
                'code' => 'prc_hicp_manr',
                'title' => 'HICP - Monthly inflation rate',
                'description' => 'Harmonised Index of Consumer Prices - monthly inflation rates',
                'frequency' => 'Monthly'
            ),
            array(
                'code' => 'une_rt_m',
                'title' => 'Unemployment rates by sex and age - monthly data',
                'description' => 'Unemployment rates by gender and age groups',
                'frequency' => 'Monthly'
            ),
            array(
                'code' => 'nama_10_gdp',
                'title' => 'Gross domestic product at market prices',
                'description' => 'GDP at current and constant prices',
                'frequency' => 'Quarterly'
            ),
            array(
                'code' => 'ei_bsfs_m',
                'title' => 'Business and consumer surveys',
                'description' => 'Economic sentiment and confidence indicators',
                'frequency' => 'Monthly'
            ),
            array(
                'code' => 'sts_inpr_m',
                'title' => 'Production in industry - monthly data',
                'description' => 'Industrial production index',
                'frequency' => 'Monthly'
            ),
            array(
                'code' => 'ext_lt_maineu',
                'title' => 'EU trade since 1988 by HS2-4-6 and CN8',
                'description' => 'European Union international trade data',
                'frequency' => 'Monthly'
            ),
            array(
                'code' => 'demo_pjan',
                'title' => 'Population on 1 January by age and sex',
                'description' => 'Population statistics by demographics',
                'frequency' => 'Yearly'
            ),
            array(
                'code' => 'ilc_peps01',
                'title' => 'At-risk-of-poverty rate by poverty threshold',
                'description' => 'Social inclusion and living conditions',
                'frequency' => 'Yearly'
            ),
            array(
                'code' => 'env_ac_aigg_q',
                'title' => 'Air emissions accounts by NACE Rev. 2 activity',
                'description' => 'Environmental accounts - air emissions',
                'frequency' => 'Quarterly'
            ),
            array(
                'code' => 'tec00001',
                'title' => 'Real GDP growth rate',
                'description' => 'Economic growth indicators',
                'frequency' => 'Quarterly'
            )
        );
    }
    
    /**
     * Validate dataset code format
     */
    public function validate_dataset_code($dataset_code) {
        // Eurostat dataset codes are typically lowercase with underscores
        if (!preg_match('/^[a-z0-9_]+$/', $dataset_code)) {
            return array(
                'valid' => false,
                'message' => __('Invalid dataset code format. Use lowercase letters, numbers, and underscores only.', 'zc-data-manager')
            );
        }
        
        if (strlen($dataset_code) < 3 || strlen($dataset_code) > 50) {
            return array(
                'valid' => false,
                'message' => __('Dataset code must be between 3 and 50 characters.', 'zc-data-manager')
            );
        }
        
        return array(
            'valid' => true,
            'message' => __('Dataset code format is valid', 'zc-data-manager')
        );
    }
    
    /**
     * Get rate limit information
     */
    public function get_rate_limit_info() {
        return array(
            'requests_per_hour' => 3600, // Conservative estimate
            'requests_per_day' => 86400, // No official limit documented
            'burst_limit' => 60,
            'time_window' => 60,
            'note' => 'Eurostat has no official rate limits, but large datasets may timeout'
        );
    }
    
    /**
     * Check if data source is configured
     */
    public function is_configured() {
        return true; // No API key required
    }
    
    /**
     * Get data source documentation URL
     */
    public function get_documentation_url() {
        return 'https://ec.europa.eu/eurostat/web/json-and-unicode-web-services';
    }
    
    /**
     * Get Eurostat data browser URL
     */
    public function get_data_browser_url() {
        return 'https://ec.europa.eu/eurostat/databrowser/';
    }
}