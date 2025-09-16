<?php
/**
 * ZC Data Manager - DBnomics Data Source
 * DBnomics API integration (80+ statistical agencies)
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class ZC_Source_DBnomics {
    
    private $base_url = 'https://api.db.nomics.world/v22/';
    
    public function __construct() {
        // DBnomics API doesn't require API key
    }
    
    /**
     * Get configuration form fields
     */
    public function get_config_fields() {
        return array(
            'series_code' => array(
                'type' => 'text',
                'label' => __('Series Code', 'zc-data-manager'),
                'description' => __('DBnomics series code in format: provider/dataset/series (e.g., ECB/BSI/M.U2.Y.V.M10.X.I.U2.2300.Z01.E)', 'zc-data-manager'),
                'required' => true,
                'placeholder' => 'ECB/BSI/M.U2.Y.V.M10.X.I.U2.2300.Z01.E'
            ),
            'provider' => array(
                'type' => 'select',
                'label' => __('Provider (Optional)', 'zc-data-manager'),
                'description' => __('Select a provider or leave auto-detect', 'zc-data-manager'),
                'required' => false,
                'options' => $this->get_common_providers(),
                'default' => 'auto'
            ),
            'start_date' => array(
                'type' => 'date',
                'label' => __('Start Date (Optional)', 'zc-data-manager'),
                'description' => __('Leave empty to get all available data', 'zc-data-manager'),
                'required' => false
            ),
            'end_date' => array(
                'type' => 'date',
                'label' => __('End Date (Optional)', 'zc-data-manager'),
                'description' => __('Leave empty to get data up to latest available', 'zc-data-manager'),
                'required' => false
            )
        );
    }
    
    /**
     * Get common DBnomics providers
     */
    private function get_common_providers() {
        return array(
            'auto' => 'Auto-detect from series code',
            'ECB' => 'European Central Bank',
            'EUROSTAT' => 'Eurostat',
            'IMF' => 'International Monetary Fund',
            'OECD' => 'OECD',
            'FED' => 'Federal Reserve System',
            'INSEE' => 'INSEE France',
            'DESTATIS' => 'Destatis Germany',
            'ONS' => 'ONS United Kingdom',
            'BIS' => 'Bank for International Settlements',
            'AMECO' => 'AMECO',
            'WB' => 'World Bank',
            'UN' => 'United Nations',
            'IEA' => 'International Energy Agency',
            'BOJ' => 'Bank of Japan',
            'ESRB' => 'European Systemic Risk Board'
        );
    }
    
    /**
     * Test connection to DBnomics API
     */
    public function test_connection($config) {
        if (empty($config['series_code'])) {
            return array(
                'success' => false,
                'message' => __('Series code is required for DBnomics connection test', 'zc-data-manager')
            );
        }
        
        // Parse series code
        $series_parts = $this->parse_series_code($config['series_code']);
        if (!$series_parts) {
            return array(
                'success' => false,
                'message' => __('Invalid series code format. Use: provider/dataset/series', 'zc-data-manager')
            );
        }
        
        // Test with series info endpoint
        $test_url = $this->base_url . 'series/' . urlencode($config['series_code']);
        
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
            if ($response_code === 404) {
                return array(
                    'success' => false,
                    'message' => sprintf(__('Series "%s" not found in DBnomics', 'zc-data-manager'), $config['series_code'])
                );
            }
            
            return array(
                'success' => false,
                'message' => sprintf(__('HTTP Error %d: %s', 'zc-data-manager'), $response_code, $body)
            );
        }
        
        $data = json_decode($body, true);
        
        if (!$data || !isset($data['series']) || !isset($data['series']['docs'])) {
            return array(
                'success' => false,
                'message' => __('Invalid response format from DBnomics API', 'zc-data-manager')
            );
        }
        
        $series_docs = $data['series']['docs'];
        
        if (empty($series_docs)) {
            return array(
                'success' => false,
                'message' => sprintf(__('Series "%s" not found', 'zc-data-manager'), $config['series_code'])
            );
        }
        
        $series_info = $series_docs[0];
        
        return array(
            'success' => true,
            'message' => sprintf(
                __('Connection successful! Found series: %s from %s', 'zc-data-manager'),
                $series_info['name'] ?? $series_info['code'],
                $series_info['provider_code']
            ),
            'series_info' => $series_info
        );
    }
    
    /**
     * Fetch data from DBnomics API
     */
    public function fetch_data($config) {
        if (empty($config['series_code'])) {
            throw new Exception(__('Series code is required', 'zc-data-manager'));
        }
        
        // Parse series code
        $series_parts = $this->parse_series_code($config['series_code']);
        if (!$series_parts) {
            throw new Exception(__('Invalid series code format. Use: provider/dataset/series', 'zc-data-manager'));
        }
        
        // Build API URL for observations
        $url_params = array(
            'observations' => '1',
            'format' => 'json'
        );
        
        // Add date filters if provided
        if (!empty($config['start_date'])) {
            $url_params['observations_attributes.period[gte]'] = $config['start_date'];
        }
        
        if (!empty($config['end_date'])) {
            $url_params['observations_attributes.period[lte]'] = $config['end_date'];
        }
        
        $api_url = $this->base_url . 'series/' . urlencode($config['series_code']) . '?' . http_build_query($url_params);
        
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
            $error_message = $this->parse_dbnomics_error($response_code, $body);
            throw new Exception($error_message);
        }
        
        $data = json_decode($body, true);
        
        if (!$data || !isset($data['series']) || !isset($data['series']['docs'])) {
            throw new Exception(__('Invalid response format from DBnomics API', 'zc-data-manager'));
        }
        
        $series_docs = $data['series']['docs'];
        
        if (empty($series_docs)) {
            throw new Exception(__('No series found', 'zc-data-manager'));
        }
        
        $series_data = $series_docs[0];
        
        if (!isset($series_data['observations']) || empty($series_data['observations'])) {
            throw new Exception(__('No observations found for this series', 'zc-data-manager'));
        }
        
        return $this->parse_dbnomics_observations($series_data['observations']);
    }
    
    /**
     * Parse series code into components
     */
    private function parse_series_code($series_code) {
        $parts = explode('/', $series_code);
        
        if (count($parts) < 3) {
            return false;
        }
        
        return array(
            'provider' => $parts[0],
            'dataset' => $parts[1],
            'series' => implode('/', array_slice($parts, 2))
        );
    }
    
    /**
     * Parse DBnomics API error responses
     */
    private function parse_dbnomics_error($response_code, $body) {
        $error_messages = array(
            400 => __('Bad request - check your series code format', 'zc-data-manager'),
            404 => __('Series not found in DBnomics database', 'zc-data-manager'),
            429 => __('Rate limit exceeded', 'zc-data-manager'),
            500 => __('DBnomics server error', 'zc-data-manager')
        );
        
        $base_message = isset($error_messages[$response_code]) 
            ? $error_messages[$response_code] 
            : sprintf(__('HTTP Error %d', 'zc-data-manager'), $response_code);
        
        // Try to parse JSON error details
        $json_data = json_decode($body, true);
        if ($json_data && isset($json_data['message'])) {
            $base_message .= ': ' . $json_data['message'];
        }
        
        return $base_message;
    }
    
    /**
     * Parse DBnomics observations data
     */
    private function parse_dbnomics_observations($observations) {
        $parsed_data = array();
        
        foreach ($observations as $obs) {
            // Skip missing values
            if (!isset($obs['value']) || $obs['value'] === null || $obs['value'] === '') {
                continue;
            }
            
            // Skip if period is missing
            if (!isset($obs['period']) || empty($obs['period'])) {
                continue;
            }
            
            // Parse period (can be in various formats: YYYY, YYYY-MM, YYYY-MM-DD, YYYY-QX, etc.)
            $date = $this->parse_period($obs['period']);
            if (!$date) {
                continue;
            }
            
            $parsed_data[] = array(
                'date' => $date,
                'value' => floatval($obs['value'])
            );
        }
        
        // Sort by date (DBnomics usually returns sorted data)
        usort($parsed_data, function($a, $b) {
            return strtotime($a['date']) - strtotime($b['date']);
        });
        
        return $parsed_data;
    }
    
    /**
     * Parse period string into standard date format
     */
    private function parse_period($period) {
        // Handle different period formats
        
        // YYYY-MM-DD (daily)
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $period)) {
            return $period;
        }
        
        // YYYY-MM (monthly)
        if (preg_match('/^\d{4}-\d{2}$/', $period)) {
            return $period . '-01';
        }
        
        // YYYY (yearly)
        if (preg_match('/^\d{4}$/', $period)) {
            return $period . '-01-01';
        }
        
        // YYYY-Q1, YYYY-Q2, etc. (quarterly)
        if (preg_match('/^(\d{4})-Q([1-4])$/', $period, $matches)) {
            $year = $matches[1];
            $quarter = intval($matches[2]);
            $month = ($quarter - 1) * 3 + 1;
            return sprintf('%s-%02d-01', $year, $month);
        }
        
        // YYYY-M01, YYYY-M02, etc. (monthly alternative format)
        if (preg_match('/^(\d{4})-M(\d{2})$/', $period, $matches)) {
            return $matches[1] . '-' . $matches[2] . '-01';
        }
        
        // Try to parse with strtotime as fallback
        $timestamp = strtotime($period);
        if ($timestamp !== false) {
            return date('Y-m-d', $timestamp);
        }
        
        return false;
    }
    
    /**
     * Search DBnomics series
     */
    public function search_series($search_text, $provider = '', $limit = 20) {
        $search_params = array(
            'q' => $search_text,
            'limit' => $limit,
            'format' => 'json'
        );
        
        if (!empty($provider) && $provider !== 'auto') {
            $search_params['provider_code'] = $provider;
        }
        
        $search_url = $this->base_url . 'series?' . http_build_query($search_params);
        
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
        
        if (!$data || !isset($data['series']) || !isset($data['series']['docs'])) {
            return array(
                'success' => false,
                'message' => __('No search results found', 'zc-data-manager')
            );
        }
        
        $results = array();
        foreach ($data['series']['docs'] as $series) {
            $results[] = array(
                'code' => $series['code'],
                'name' => $series['name'] ?? $series['code'],
                'provider' => $series['provider_code'],
                'dataset' => $series['dataset_code'],
                'frequency' => $series['@frequency'] ?? 'Unknown',
                'last_update' => $series['indexed_at'] ?? null
            );
        }
        
        return array(
            'success' => true,
            'results' => $results
        );
    }
    
    /**
     * Get providers list
     */
    public function get_providers() {
        $providers_url = $this->base_url . 'providers?format=json&limit=100';
        
        $response = wp_remote_get($providers_url, array(
            'timeout' => 30,
            'user-agent' => 'ZC Data Manager WordPress Plugin'
        ));
        
        if (is_wp_error($response)) {
            return $this->get_common_providers();
        }
        
        $data = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!$data || !isset($data['providers']) || !isset($data['providers']['docs'])) {
            return $this->get_common_providers();
        }
        
        $providers = array('auto' => 'Auto-detect from series code');
        foreach ($data['providers']['docs'] as $provider) {
            $providers[$provider['code']] = $provider['name'];
        }
        
        return $providers;
    }
    
    /**
     * Get popular DBnomics series
     */
    public static function get_popular_series() {
        return array(
            'ECB/BSI/M.U2.Y.V.M10.X.I.U2.2300.Z01.E' => 'Euro Area Money Supply M1',
            'ECB/IRS/M.U2.L.L40.CI.0000.EUR.N.Z' => 'Euro Area Interest Rates',
            'EUROSTAT/prc_hicp_manr/M.CP00.ANR.EA19' => 'Euro Area HICP Inflation',
            'IMF/IFS/M.US.PCPI_IX' => 'US Consumer Price Index',
            'OECD/KEI/LOLITOAA_OECD' => 'OECD Unemployment Rate',
            'FED/FRED/GDP' => 'US Gross Domestic Product',
            'INSEE/BDM/001565404' => 'France GDP',
            'DESTATIS/DESTATIS/12411-0001' => 'Germany CPI',
            'ONS/QNA/ABMI' => 'UK GDP',
            'BIS/CBPOL/M.XM.EUR.EUR.BB.AC.A' => 'ECB Policy Rate'
        );
    }
    
    /**
     * Validate series code format
     */
    public function validate_series_code($series_code) {
        $parts = explode('/', $series_code);
        
        if (count($parts) < 3) {
            return array(
                'valid' => false,
                'message' => __('Series code must have at least 3 parts: provider/dataset/series', 'zc-data-manager')
            );
        }
        
        // Check for valid characters
        foreach ($parts as $part) {
            if (empty($part) || !preg_match('/^[A-Za-z0-9._-]+$/', $part)) {
                return array(
                    'valid' => false,
                    'message' => __('Invalid characters in series code. Use only letters, numbers, dots, hyphens, and underscores', 'zc-data-manager')
                );
            }
        }
        
        if (strlen($series_code) > 255) {
            return array(
                'valid' => false,
                'message' => __('Series code is too long (max 255 characters)', 'zc-data-manager')
            );
        }
        
        return array(
            'valid' => true,
            'message' => __('Series code format is valid', 'zc-data-manager'),
            'parsed' => array(
                'provider' => $parts[0],
                'dataset' => $parts[1],
                'series' => implode('/', array_slice($parts, 2))
            )
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
            'note' => 'DBnomics has no official rate limits, but please use responsibly'
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
        return 'https://db.nomics.world/docs/';
    }
    
    /**
     * Get DBnomics website URL
     */
    public function get_website_url() {
        return 'https://db.nomics.world/';
    }
}