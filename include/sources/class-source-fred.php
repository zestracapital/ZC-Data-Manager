<?php
/**
 * ZC Data Manager - FRED Data Source
 * Federal Reserve Economic Data (FRED) API integration
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class ZC_Source_FRED {
    
    private $api_key;
    private $base_url = 'https://api.stlouisfed.org/fred/';
    private $rate_limit = 120; // requests per 60 seconds
    
    public function __construct() {
        $this->api_key = get_option('zc_dm_fred_api_key', '');
    }
    
    /**
     * Get configuration form fields
     */
    public function get_config_fields() {
        return array(
            'series_id' => array(
                'type' => 'text',
                'label' => __('Series ID', 'zc-data-manager'),
                'description' => __('FRED series identifier (e.g., GDP, UNRATE, CPIAUCSL)', 'zc-data-manager'),
                'required' => true,
                'placeholder' => 'GDP'
            ),
            'start_date' => array(
                'type' => 'date',
                'label' => __('Start Date (Optional)', 'zc-data-manager'),
                'description' => __('Leave empty to get all available historical data', 'zc-data-manager'),
                'required' => false
            ),
            'end_date' => array(
                'type' => 'date',
                'label' => __('End Date (Optional)', 'zc-data-manager'),
                'description' => __('Leave empty to get data up to the latest available', 'zc-data-manager'),
                'required' => false
            )
        );
    }
    
    /**
     * Test connection to FRED API
     */
    public function test_connection($config) {
        if (empty($this->api_key)) {
            return array(
                'success' => false,
                'message' => __('FRED API key not configured. Please add your API key in Data Sources settings.', 'zc-data-manager')
            );
        }
        
        if (empty($config['series_id'])) {
            return array(
                'success' => false,
                'message' => __('Series ID is required for FRED connection test', 'zc-data-manager')
            );
        }
        
        // Test with series info endpoint (lighter than full data)
        $test_url = $this->base_url . 'series?series_id=' . urlencode($config['series_id']) . '&api_key=' . $this->api_key . '&file_type=json';
        
        $response = wp_remote_get($test_url, array(
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
            return array(
                'success' => false,
                'message' => sprintf(__('HTTP Error %d: %s', 'zc-data-manager'), $response_code, $body)
            );
        }
        
        $data = json_decode($body, true);
        
        if (!$data || !isset($data['seriess'])) {
            return array(
                'success' => false,
                'message' => __('Invalid response format from FRED API', 'zc-data-manager')
            );
        }
        
        if (empty($data['seriess'])) {
            return array(
                'success' => false,
                'message' => sprintf(__('Series "%s" not found in FRED database', 'zc-data-manager'), $config['series_id'])
            );
        }
        
        $series_info = $data['seriess'][0];
        
        return array(
            'success' => true,
            'message' => sprintf(
                __('Connection successful! Found series: %s (%s)', 'zc-data-manager'),
                $series_info['title'],
                $series_info['id']
            ),
            'series_info' => $series_info
        );
    }
    
    /**
     * Fetch data from FRED API
     */
    public function fetch_data($config) {
        if (empty($this->api_key)) {
            throw new Exception(__('FRED API key not configured', 'zc-data-manager'));
        }
        
        if (empty($config['series_id'])) {
            throw new Exception(__('Series ID is required', 'zc-data-manager'));
        }
        
        // Build API URL
        $url_params = array(
            'series_id' => $config['series_id'],
            'api_key' => $this->api_key,
            'file_type' => 'json',
            'limit' => 100000 // Get maximum data
        );
        
        // Add date filters if provided
        if (!empty($config['start_date'])) {
            $url_params['observation_start'] = $config['start_date'];
        }
        
        if (!empty($config['end_date'])) {
            $url_params['observation_end'] = $config['end_date'];
        }
        
        $api_url = $this->base_url . 'series/observations?' . http_build_query($url_params);
        
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
            // Handle specific FRED error codes
            $error_message = $this->parse_fred_error($response_code, $body);
            throw new Exception($error_message);
        }
        
        $data = json_decode($body, true);
        
        if (!$data || !isset($data['observations'])) {
            throw new Exception(__('Invalid response format from FRED API', 'zc-data-manager'));
        }
        
        return $this->parse_fred_observations($data['observations']);
    }
    
    /**
     * Parse FRED API error responses
     */
    private function parse_fred_error($response_code, $body) {
        $error_messages = array(
            400 => __('Bad request - check your series ID and parameters', 'zc-data-manager'),
            401 => __('Invalid API key', 'zc-data-manager'),
            403 => __('Access forbidden - check API key permissions', 'zc-data-manager'),
            404 => __('Series not found', 'zc-data-manager'),
            429 => __('Rate limit exceeded - too many requests', 'zc-data-manager'),
            500 => __('FRED server error', 'zc-data-manager')
        );
        
        $base_message = isset($error_messages[$response_code]) 
            ? $error_messages[$response_code] 
            : sprintf(__('HTTP Error %d', 'zc-data-manager'), $response_code);
        
        // Try to parse JSON error details
        $json_data = json_decode($body, true);
        if ($json_data && isset($json_data['error_message'])) {
            $base_message .= ': ' . $json_data['error_message'];
        }
        
        return $base_message;
    }
    
    /**
     * Parse FRED observations data
     */
    private function parse_fred_observations($observations) {
        $parsed_data = array();
        
        foreach ($observations as $obs) {
            // Skip missing values (marked as '.' in FRED)
            if (!isset($obs['value']) || $obs['value'] === '.' || $obs['value'] === null) {
                continue;
            }
            
            // Skip if date is missing
            if (!isset($obs['date']) || empty($obs['date'])) {
                continue;
            }
            
            $parsed_data[] = array(
                'date' => $obs['date'],
                'value' => floatval($obs['value'])
            );
        }
        
        // Sort by date (FRED usually returns sorted data, but just to be sure)
        usort($parsed_data, function($a, $b) {
            return strtotime($a['date']) - strtotime($b['date']);
        });
        
        return $parsed_data;
    }
    
    /**
     * Search FRED series
     */
    public function search_series($search_text, $limit = 20) {
        if (empty($this->api_key)) {
            return array(
                'success' => false,
                'message' => __('FRED API key not configured', 'zc-data-manager')
            );
        }
        
        $search_url = $this->base_url . 'series/search?' . http_build_query(array(
            'search_text' => $search_text,
            'api_key' => $this->api_key,
            'file_type' => 'json',
            'limit' => $limit
        ));
        
        $response = wp_remote_get($search_url, array(
            'timeout' => 30,
            'user-agent' => 'ZC Data Manager WordPress Plugin'
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => $response->get_error_message()
            );
        }
        
        $data = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!$data || !isset($data['seriess'])) {
            return array(
                'success' => false,
                'message' => __('No search results found', 'zc-data-manager')
            );
        }
        
        $results = array();
        foreach ($data['seriess'] as $series) {
            $results[] = array(
                'id' => $series['id'],
                'title' => $series['title'],
                'frequency' => $series['frequency_short'],
                'units' => $series['units_short'],
                'last_updated' => $series['last_updated']
            );
        }
        
        return array(
            'success' => true,
            'results' => $results
        );
    }
    
    /**
     * Get series information
     */
    public function get_series_info($series_id) {
        if (empty($this->api_key)) {
            throw new Exception(__('FRED API key not configured', 'zc-data-manager'));
        }
        
        $info_url = $this->base_url . 'series?' . http_build_query(array(
            'series_id' => $series_id,
            'api_key' => $this->api_key,
            'file_type' => 'json'
        ));
        
        $response = wp_remote_get($info_url, array(
            'timeout' => 30,
            'user-agent' => 'ZC Data Manager WordPress Plugin'
        ));
        
        if (is_wp_error($response)) {
            throw new Exception($response->get_error_message());
        }
        
        $data = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!$data || !isset($data['seriess']) || empty($data['seriess'])) {
            throw new Exception(__('Series not found', 'zc-data-manager'));
        }
        
        return $data['seriess'][0];
    }
    
    /**
     * Get popular FRED series (for suggestions)
     */
    public static function get_popular_series() {
        return array(
            'GDP' => 'Gross Domestic Product',
            'UNRATE' => 'Unemployment Rate',
            'CPIAUCSL' => 'Consumer Price Index',
            'FEDFUNDS' => 'Federal Funds Rate',
            'DEXUSEU' => 'USD/EUR Exchange Rate',
            'PAYEMS' => 'Nonfarm Payrolls',
            'HOUST' => 'Housing Starts',
            'INDPRO' => 'Industrial Production Index',
            'CPILFESL' => 'Core CPI (Less Food & Energy)',
            'UMCSENT' => 'Consumer Sentiment Index',
            'DGS10' => '10-Year Treasury Rate',
            'DGS2' => '2-Year Treasury Rate',
            'MTSDS133FMS' => 'Median Sales Price of Houses',
            'CSUSHPISA' => 'Case-Shiller Home Price Index',
            'ICSA' => 'Initial Claims for Unemployment'
        );
    }
    
    /**
     * Validate FRED series ID format
     */
    public function validate_series_id($series_id) {
        // FRED series IDs are typically uppercase alphanumeric with some special characters
        if (!preg_match('/^[A-Z0-9_#\-\.]+$/i', $series_id)) {
            return array(
                'valid' => false,
                'message' => __('Invalid series ID format. FRED series IDs contain letters, numbers, and limited special characters.', 'zc-data-manager')
            );
        }
        
        if (strlen($series_id) > 255) {
            return array(
                'valid' => false,
                'message' => __('Series ID is too long', 'zc-data-manager')
            );
        }
        
        return array(
            'valid' => true,
            'message' => __('Series ID format is valid', 'zc-data-manager')
        );
    }
    
    /**
     * Get rate limit information
     */
    public function get_rate_limit_info() {
        return array(
            'requests_per_hour' => 7200, // 120 per minute * 60
            'requests_per_day' => 172800, // 120 per minute * 60 * 24
            'burst_limit' => 120,
            'time_window' => 60
        );
    }
    
    /**
     * Check if API key is configured and valid
     */
    public function is_configured() {
        return !empty($this->api_key);
    }
    
    /**
     * Get data source documentation URL
     */
    public function get_documentation_url() {
        return 'https://research.stlouisfed.org/docs/api/fred/';
    }
    
    /**
     * Get API registration URL
     */
    public function get_registration_url() {
        return 'https://research.stlouisfed.org/docs/api/api_key.html';
    }
}