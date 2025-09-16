<?php
/**
 * ZC Data Manager - World Bank Data Source
 * World Bank Open Data API integration
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class ZC_Source_WorldBank {
    
    private $base_url = 'https://api.worldbank.org/v2/';
    private $default_country = 'US';
    
    public function __construct() {
        // World Bank API doesn't require API key
    }
    
    /**
     * Get configuration form fields
     */
    public function get_config_fields() {
        return array(
            'indicator_code' => array(
                'type' => 'text',
                'label' => __('Indicator Code', 'zc-data-manager'),
                'description' => __('World Bank indicator code (e.g., NY.GDP.MKTP.CD for GDP)', 'zc-data-manager'),
                'required' => true,
                'placeholder' => 'NY.GDP.MKTP.CD'
            ),
            'country_code' => array(
                'type' => 'select',
                'label' => __('Country/Region', 'zc-data-manager'),
                'description' => __('Select country or region for the data', 'zc-data-manager'),
                'required' => true,
                'options' => $this->get_common_countries(),
                'default' => 'US'
            ),
            'start_year' => array(
                'type' => 'number',
                'label' => __('Start Year (Optional)', 'zc-data-manager'),
                'description' => __('Leave empty to get all available data', 'zc-data-manager'),
                'required' => false,
                'min' => 1960,
                'max' => date('Y'),
                'placeholder' => '2000'
            ),
            'end_year' => array(
                'type' => 'number',
                'label' => __('End Year (Optional)', 'zc-data-manager'),
                'description' => __('Leave empty to get data up to latest available', 'zc-data-manager'),
                'required' => false,
                'min' => 1960,
                'max' => date('Y') + 1,
                'placeholder' => date('Y')
            )
        );
    }
    
    /**
     * Get common countries/regions for dropdown
     */
    private function get_common_countries() {
        return array(
            'WLD' => 'World',
            'US' => 'United States',
            'CN' => 'China',
            'JP' => 'Japan',
            'DE' => 'Germany',
            'GB' => 'United Kingdom',
            'IN' => 'India',
            'FR' => 'France',
            'IT' => 'Italy',
            'BR' => 'Brazil',
            'CA' => 'Canada',
            'RU' => 'Russian Federation',
            'KR' => 'Korea, Rep.',
            'ES' => 'Spain',
            'AU' => 'Australia',
            'MX' => 'Mexico',
            'ID' => 'Indonesia',
            'NL' => 'Netherlands',
            'SA' => 'Saudi Arabia',
            'TR' => 'Turkey',
            'CH' => 'Switzerland',
            'EUU' => 'European Union',
            'OED' => 'OECD members',
            'HIC' => 'High income',
            'UMC' => 'Upper middle income',
            'LMC' => 'Lower middle income',
            'LIC' => 'Low income'
        );
    }
    
    /**
     * Test connection to World Bank API
     */
    public function test_connection($config) {
        if (empty($config['indicator_code'])) {
            return array(
                'success' => false,
                'message' => __('Indicator code is required for World Bank connection test', 'zc-data-manager')
            );
        }
        
        $country_code = !empty($config['country_code']) ? $config['country_code'] : $this->default_country;
        
        // Test with a simple request for last 5 years
        $test_url = $this->base_url . sprintf(
            'country/%s/indicator/%s?format=json&per_page=5&date=%d:%d',
            $country_code,
            $config['indicator_code'],
            date('Y') - 5,
            date('Y')
        );
        
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
        
        if (!$data || !is_array($data) || count($data) < 2) {
            return array(
                'success' => false,
                'message' => __('Invalid response format from World Bank API', 'zc-data-manager')
            );
        }
        
        $metadata = $data[0];
        $observations = $data[1];
        
        if ($metadata['total'] == 0) {
            return array(
                'success' => false,
                'message' => sprintf(
                    __('No data found for indicator "%s" and country "%s"', 'zc-data-manager'),
                    $config['indicator_code'],
                    $country_code
                )
            );
        }
        
        // Get indicator info from first observation
        $sample_obs = !empty($observations) ? $observations[0] : null;
        
        return array(
            'success' => true,
            'message' => sprintf(
                __('Connection successful! Found %d data points for %s', 'zc-data-manager'),
                $metadata['total'],
                $sample_obs ? $sample_obs['indicator']['value'] : $config['indicator_code']
            ),
            'total_records' => $metadata['total'],
            'indicator_name' => $sample_obs ? $sample_obs['indicator']['value'] : null
        );
    }
    
    /**
     * Fetch data from World Bank API
     */
    public function fetch_data($config) {
        if (empty($config['indicator_code'])) {
            throw new Exception(__('Indicator code is required', 'zc-data-manager'));
        }
        
        $country_code = !empty($config['country_code']) ? $config['country_code'] : $this->default_country;
        
        // Build date range
        $date_range = $this->build_date_range($config);
        
        // Build API URL with pagination support
        $base_params = array(
            'format' => 'json',
            'per_page' => 10000, // Maximum allowed
            'date' => $date_range
        );
        
        $api_url = $this->base_url . sprintf(
            'country/%s/indicator/%s?%s',
            $country_code,
            $config['indicator_code'],
            http_build_query($base_params)
        );
        
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
            $error_message = $this->parse_worldbank_error($response_code, $body);
            throw new Exception($error_message);
        }
        
        $data = json_decode($body, true);
        
        if (!$data || !is_array($data) || count($data) < 2) {
            throw new Exception(__('Invalid response format from World Bank API', 'zc-data-manager'));
        }
        
        $metadata = $data[0];
        $observations = $data[1];
        
        if (empty($observations)) {
            throw new Exception(__('No data found for the specified parameters', 'zc-data-manager'));
        }
        
        // Handle pagination if necessary
        if ($metadata['total'] > $metadata['per_page']) {
            $all_observations = $this->fetch_all_pages($country_code, $config['indicator_code'], $date_range, $metadata);
        } else {
            $all_observations = $observations;
        }
        
        return $this->parse_worldbank_observations($all_observations);
    }
    
    /**
     * Fetch all pages if data is paginated
     */
    private function fetch_all_pages($country_code, $indicator_code, $date_range, $metadata) {
        $all_observations = array();
        $total_pages = ceil($metadata['total'] / $metadata['per_page']);
        
        for ($page = 1; $page <= $total_pages; $page++) {
            $params = array(
                'format' => 'json',
                'per_page' => 10000,
                'page' => $page,
                'date' => $date_range
            );
            
            $url = $this->base_url . sprintf(
                'country/%s/indicator/%s?%s',
                $country_code,
                $indicator_code,
                http_build_query($params)
            );
            
            $response = wp_remote_get($url, array(
                'timeout' => 60,
                'user-agent' => 'ZC Data Manager WordPress Plugin'
            ));
            
            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                $page_data = json_decode(wp_remote_retrieve_body($response), true);
                if (isset($page_data[1]) && is_array($page_data[1])) {
                    $all_observations = array_merge($all_observations, $page_data[1]);
                }
            }
            
            // Small delay between requests
            usleep(250000); // 0.25 seconds
        }
        
        return $all_observations;
    }
    
    /**
     * Build date range string for API
     */
    private function build_date_range($config) {
        $start_year = !empty($config['start_year']) ? intval($config['start_year']) : 1960;
        $end_year = !empty($config['end_year']) ? intval($config['end_year']) : date('Y');
        
        return $start_year . ':' . $end_year;
    }
    
    /**
     * Parse World Bank API error responses
     */
    private function parse_worldbank_error($response_code, $body) {
        $error_messages = array(
            400 => __('Bad request - check your indicator code and country code', 'zc-data-manager'),
            404 => __('Indicator or country not found', 'zc-data-manager'),
            429 => __('Rate limit exceeded', 'zc-data-manager'),
            500 => __('World Bank server error', 'zc-data-manager')
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
     * Parse World Bank observations data
     */
    private function parse_worldbank_observations($observations) {
        $parsed_data = array();
        
        foreach ($observations as $obs) {
            // Skip missing values
            if (!isset($obs['value']) || $obs['value'] === null) {
                continue;
            }
            
            // Skip if date is missing
            if (!isset($obs['date']) || empty($obs['date'])) {
                continue;
            }
            
            // World Bank returns year data, convert to date format
            $date = $obs['date'] . '-01-01'; // Use January 1st for yearly data
            
            $parsed_data[] = array(
                'date' => $date,
                'value' => floatval($obs['value'])
            );
        }
        
        // Sort by date (World Bank returns newest first, we want oldest first)
        usort($parsed_data, function($a, $b) {
            return strtotime($a['date']) - strtotime($b['date']);
        });
        
        return $parsed_data;
    }
    
    /**
     * Search World Bank indicators
     */
    public function search_indicators($search_text, $limit = 20) {
        $search_url = $this->base_url . 'indicator?' . http_build_query(array(
            'format' => 'json',
            'per_page' => $limit,
            'source' => 2 // World Development Indicators
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
        
        if (!$data || !isset($data[1]) || !is_array($data[1])) {
            return array(
                'success' => false,
                'message' => __('No search results found', 'zc-data-manager')
            );
        }
        
        $results = array();
        foreach ($data[1] as $indicator) {
            // Filter by search text if provided
            if (!empty($search_text)) {
                $searchable = strtolower($indicator['name'] . ' ' . $indicator['id']);
                if (strpos($searchable, strtolower($search_text)) === false) {
                    continue;
                }
            }
            
            $results[] = array(
                'id' => $indicator['id'],
                'name' => $indicator['name'],
                'source' => $indicator['source']['value'] ?? 'World Bank',
                'topics' => isset($indicator['topics']) ? $indicator['topics'] : array()
            );
        }
        
        return array(
            'success' => true,
            'results' => array_slice($results, 0, $limit)
        );
    }
    
    /**
     * Get popular World Bank indicators
     */
    public static function get_popular_indicators() {
        return array(
            'NY.GDP.MKTP.CD' => 'GDP (current US$)',
            'NY.GDP.PCAP.CD' => 'GDP per capita (current US$)',
            'SP.POP.TOTL' => 'Population, total',
            'SL.UEM.TOTL.ZS' => 'Unemployment, total (% of total labor force)',
            'FP.CPI.TOTL.ZG' => 'Inflation, consumer prices (annual %)',
            'BX.KLT.DINV.WD.GD.ZS' => 'Foreign direct investment, net inflows (% of GDP)',
            'NE.EXP.GNFS.ZS' => 'Exports of goods and services (% of GDP)',
            'NE.IMP.GNFS.ZS' => 'Imports of goods and services (% of GDP)',
            'GC.BAL.CASH.GD.ZS' => 'Cash surplus/deficit (% of GDP)',
            'SE.ADT.LITR.ZS' => 'Literacy rate, adult total (% of people ages 15 and above)',
            'SH.DYN.MORT' => 'Mortality rate, under-5 (per 1,000 live births)',
            'EG.USE.ELEC.KH.PC' => 'Electric power consumption (kWh per capita)',
            'AG.LND.ARBL.ZS' => 'Arable land (% of land area)',
            'EN.ATM.CO2E.PC' => 'CO2 emissions (metric tons per capita)',
            'IT.NET.USER.ZS' => 'Internet users (% of population)'
        );
    }
    
    /**
     * Get all available countries
     */
    public function get_all_countries() {
        $countries_url = $this->base_url . 'country?format=json&per_page=500';
        
        $response = wp_remote_get($countries_url, array(
            'timeout' => 30,
            'user-agent' => 'ZC Data Manager WordPress Plugin'
        ));
        
        if (is_wp_error($response)) {
            return $this->get_common_countries(); // Fallback to common countries
        }
        
        $data = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!$data || !isset($data[1]) || !is_array($data[1])) {
            return $this->get_common_countries(); // Fallback to common countries
        }
        
        $countries = array();
        foreach ($data[1] as $country) {
            $countries[$country['id']] = $country['name'];
        }
        
        return $countries;
    }
    
    /**
     * Validate World Bank indicator code format
     */
    public function validate_indicator_code($indicator_code) {
        // World Bank indicator codes typically follow pattern like NY.GDP.MKTP.CD
        if (!preg_match('/^[A-Z]{2,3}\.[A-Z]{2,4}\.[A-Z]{2,6}\.[A-Z]{2,3}$/i', $indicator_code)) {
            return array(
                'valid' => false,
                'message' => __('Invalid indicator code format. World Bank codes typically follow pattern like NY.GDP.MKTP.CD', 'zc-data-manager')
            );
        }
        
        return array(
            'valid' => true,
            'message' => __('Indicator code format is valid', 'zc-data-manager')
        );
    }
    
    /**
     * Get rate limit information
     */
    public function get_rate_limit_info() {
        return array(
            'requests_per_hour' => 3600, // Reasonable estimate
            'requests_per_day' => 86400, // No official limit, but be reasonable
            'burst_limit' => 60,
            'time_window' => 60,
            'note' => 'World Bank API has no official rate limits, but please use responsibly'
        );
    }
    
    /**
     * Check if data source is configured
     */
    public function is_configured() {
        return true; // World Bank API doesn't require API key
    }
    
    /**
     * Get data source documentation URL
     */
    public function get_documentation_url() {
        return 'https://datahelpdesk.worldbank.org/knowledgebase/articles/889392';
    }
    
    /**
     * Get data catalog URL
     */
    public function get_catalog_url() {
        return 'https://data.worldbank.org/';
    }
}