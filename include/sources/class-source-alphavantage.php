<?php
/**
 * ZC Data Manager - Alpha Vantage Data Source
 * Alpha Vantage API integration for financial market data
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class ZC_Source_AlphaVantage {
    
    private $api_key;
    private $base_url = 'https://www.alphavantage.co/query';
    
    public function __construct() {
        $this->api_key = get_option('zc_dm_alphavantage_api_key', '');
    }
    
    /**
     * Get configuration form fields
     */
    public function get_config_fields() {
        return array(
            'function' => array(
                'type' => 'select',
                'label' => __('Data Function', 'zc-data-manager'),
                'description' => __('Type of data to retrieve', 'zc-data-manager'),
                'required' => true,
                'options' => array(
                    'TIME_SERIES_DAILY' => 'Daily Stock Prices',
                    'TIME_SERIES_WEEKLY' => 'Weekly Stock Prices',
                    'TIME_SERIES_MONTHLY' => 'Monthly Stock Prices',
                    'GLOBAL_QUOTE' => 'Real-time Quote',
                    'FX_DAILY' => 'Daily FX Rates',
                    'FX_WEEKLY' => 'Weekly FX Rates',
                    'FX_MONTHLY' => 'Monthly FX Rates',
                    'DIGITAL_CURRENCY_DAILY' => 'Daily Crypto Prices',
                    'REAL_GDP' => 'Real GDP',
                    'INFLATION' => 'Inflation Rate',
                    'UNEMPLOYMENT' => 'Unemployment Rate'
                ),
                'default' => 'TIME_SERIES_DAILY'
            ),
            'symbol' => array(
                'type' => 'text',
                'label' => __('Symbol', 'zc-data-manager'),
                'description' => __('Stock symbol, currency pair, or crypto symbol', 'zc-data-manager'),
                'required' => true,
                'placeholder' => 'AAPL'
            ),
            'from_symbol' => array(
                'type' => 'text',
                'label' => __('From Symbol (FX/Crypto)', 'zc-data-manager'),
                'description' => __('Base currency for FX/crypto pairs (e.g., USD)', 'zc-data-manager'),
                'required' => false,
                'placeholder' => 'USD'
            ),
            'to_symbol' => array(
                'type' => 'text',
                'label' => __('To Symbol (FX/Crypto)', 'zc-data-manager'),
                'description' => __('Quote currency for FX/crypto pairs (e.g., EUR)', 'zc-data-manager'),
                'required' => false,
                'placeholder' => 'EUR'
            ),
            'market' => array(
                'type' => 'text',
                'label' => __('Market (Crypto)', 'zc-data-manager'),
                'description' => __('Market for crypto data (e.g., CNY)', 'zc-data-manager'),
                'required' => false,
                'placeholder' => 'USD'
            ),
            'outputsize' => array(
                'type' => 'select',
                'label' => __('Output Size', 'zc-data-manager'),
                'description' => __('Amount of data to retrieve', 'zc-data-manager'),
                'required' => false,
                'options' => array(
                    'compact' => 'Compact (last 100 data points)',
                    'full' => 'Full (20+ years of data)'
                ),
                'default' => 'compact'
            )
        );
    }
    
    /**
     * Test connection to Alpha Vantage API
     */
    public function test_connection($config) {
        if (empty($this->api_key)) {
            return array(
                'success' => false,
                'message' => __('Alpha Vantage API key not configured. Please add your API key in Data Sources settings.', 'zc-data-manager')
            );
        }
        
        if (empty($config['function'])) {
            return array(
                'success' => false,
                'message' => __('Data function is required for Alpha Vantage connection test', 'zc-data-manager')
            );
        }
        
        if (empty($config['symbol']) && !in_array($config['function'], ['FX_DAILY', 'FX_WEEKLY', 'FX_MONTHLY', 'DIGITAL_CURRENCY_DAILY'])) {
            return array(
                'success' => false,
                'message' => __('Symbol is required for this data function', 'zc-data-manager')
            );
        }
        
        // Build test parameters
        $test_params = $this->build_api_params($config);
        $test_params['outputsize'] = 'compact'; // Use compact for testing
        
        $test_url = $this->base_url . '?' . http_build_query($test_params);
        
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
        
        if (!$data) {
            return array(
                'success' => false,
                'message' => __('Invalid response format from Alpha Vantage API', 'zc-data-manager')
            );
        }
        
        // Check for API errors
        if (isset($data['Error Message'])) {
            return array(
                'success' => false,
                'message' => __('Alpha Vantage Error: ', 'zc-data-manager') . $data['Error Message']
            );
        }
        
        if (isset($data['Note']) && strpos($data['Note'], 'call frequency') !== false) {
            return array(
                'success' => false,
                'message' => __('API rate limit reached. Please wait and try again.', 'zc-data-manager')
            );
        }
        
        // Check if we got valid data
        $sample_data = $this->extract_data_from_response($data, $config['function']);
        
        if (empty($sample_data)) {
            return array(
                'success' => false,
                'message' => __('No data found for the specified parameters', 'zc-data-manager')
            );
        }
        
        return array(
            'success' => true,
            'message' => sprintf(
                __('Connection successful! Found %d sample data points', 'zc-data-manager'),
                count($sample_data)
            ),
            'sample_count' => count($sample_data)
        );
    }
    
    /**
     * Fetch data from Alpha Vantage API
     */
    public function fetch_data($config) {
        if (empty($this->api_key)) {
            throw new Exception(__('Alpha Vantage API key not configured', 'zc-data-manager'));
        }
        
        if (empty($config['function'])) {
            throw new Exception(__('Data function is required', 'zc-data-manager'));
        }
        
        // Build API parameters
        $api_params = $this->build_api_params($config);
        
        $api_url = $this->base_url . '?' . http_build_query($api_params);
        
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
            throw new Exception(sprintf(__('HTTP Error %d', 'zc-data-manager'), $response_code));
        }
        
        $data = json_decode($body, true);
        
        if (!$data) {
            throw new Exception(__('Invalid response format from Alpha Vantage API', 'zc-data-manager'));
        }
        
        // Check for API errors
        if (isset($data['Error Message'])) {
            throw new Exception(__('Alpha Vantage Error: ', 'zc-data-manager') . $data['Error Message']);
        }
        
        if (isset($data['Note']) && strpos($data['Note'], 'call frequency') !== false) {
            throw new Exception(__('API rate limit reached. Please wait before making more requests.', 'zc-data-manager'));
        }
        
        // Extract and parse data
        return $this->parse_alpha_vantage_data($data, $config['function']);
    }
    
    /**
     * Build API parameters based on function and config
     */
    private function build_api_params($config) {
        $params = array(
            'function' => $config['function'],
            'apikey' => $this->api_key
        );
        
        $function = $config['function'];
        
        // Add parameters based on function type
        if (in_array($function, ['TIME_SERIES_DAILY', 'TIME_SERIES_WEEKLY', 'TIME_SERIES_MONTHLY', 'GLOBAL_QUOTE'])) {
            // Stock functions
            $params['symbol'] = $config['symbol'];
            
            if (isset($config['outputsize'])) {
                $params['outputsize'] = $config['outputsize'];
            }
            
        } elseif (in_array($function, ['FX_DAILY', 'FX_WEEKLY', 'FX_MONTHLY'])) {
            // Forex functions
            $params['from_symbol'] = $config['from_symbol'] ?? $config['symbol'];
            $params['to_symbol'] = $config['to_symbol'];
            
            if (isset($config['outputsize'])) {
                $params['outputsize'] = $config['outputsize'];
            }
            
        } elseif ($function === 'DIGITAL_CURRENCY_DAILY') {
            // Crypto function
            $params['symbol'] = $config['symbol'];
            $params['market'] = $config['market'] ?? 'USD';
            
        } elseif (in_array($function, ['REAL_GDP', 'INFLATION', 'UNEMPLOYMENT'])) {
            // Economic indicators
            // These typically don't need additional parameters
        }
        
        return $params;
    }
    
    /**
     * Extract data from API response
     */
    private function extract_data_from_response($data, $function) {
        // Map functions to their data keys
        $data_keys = array(
            'TIME_SERIES_DAILY' => 'Time Series (Daily)',
            'TIME_SERIES_WEEKLY' => 'Weekly Time Series',
            'TIME_SERIES_MONTHLY' => 'Monthly Time Series',
            'FX_DAILY' => 'Time Series FX (Daily)',
            'FX_WEEKLY' => 'Time Series FX (Weekly)', 
            'FX_MONTHLY' => 'Time Series FX (Monthly)',
            'DIGITAL_CURRENCY_DAILY' => 'Time Series (Digital Currency Daily)',
            'REAL_GDP' => 'data',
            'INFLATION' => 'data',
            'UNEMPLOYMENT' => 'data'
        );
        
        $key = $data_keys[$function] ?? null;
        
        if (!$key || !isset($data[$key])) {
            // Try to find any time series data
            foreach ($data as $response_key => $response_data) {
                if (is_array($response_data) && !empty($response_data)) {
                    return array_slice($response_data, 0, 5); // Return first 5 for testing
                }
            }
            return array();
        }
        
        return array_slice($data[$key], 0, 5); // Return first 5 for testing
    }
    
    /**
     * Parse Alpha Vantage data
     */
    private function parse_alpha_vantage_data($data, $function) {
        $parsed_data = array();
        
        // Get the time series data
        $time_series = $this->extract_time_series_data($data, $function);
        
        if (empty($time_series)) {
            throw new Exception(__('No time series data found in API response', 'zc-data-manager'));
        }
        
        foreach ($time_series as $date => $values) {
            if (!is_array($values)) {
                continue;
            }
            
            // Determine which value to use based on function
            $value = $this->extract_value_from_entry($values, $function);
            
            if ($value !== null) {
                $parsed_data[] = array(
                    'date' => $date,
                    'value' => floatval($value)
                );
            }
        }
        
        // Sort by date (Alpha Vantage usually returns newest first)
        usort($parsed_data, function($a, $b) {
            return strtotime($a['date']) - strtotime($b['date']);
        });
        
        return $parsed_data;
    }
    
    /**
     * Extract time series data from response
     */
    private function extract_time_series_data($data, $function) {
        $data_keys = array(
            'TIME_SERIES_DAILY' => 'Time Series (Daily)',
            'TIME_SERIES_WEEKLY' => 'Weekly Time Series',
            'TIME_SERIES_MONTHLY' => 'Monthly Time Series',
            'FX_DAILY' => 'Time Series FX (Daily)',
            'FX_WEEKLY' => 'Time Series FX (Weekly)',
            'FX_MONTHLY' => 'Time Series FX (Monthly)',
            'DIGITAL_CURRENCY_DAILY' => 'Time Series (Digital Currency Daily)',
            'REAL_GDP' => 'data',
            'INFLATION' => 'data',
            'UNEMPLOYMENT' => 'data'
        );
        
        $key = $data_keys[$function] ?? null;
        
        if ($key && isset($data[$key])) {
            return $data[$key];
        }
        
        // Fallback: look for any time series-like data
        foreach ($data as $response_key => $response_data) {
            if (is_array($response_data) && !empty($response_data)) {
                $first_key = array_keys($response_data)[0];
                if (is_array($response_data[$first_key])) {
                    return $response_data;
                }
            }
        }
        
        return array();
    }
    
    /**
     * Extract value from data entry
     */
    private function extract_value_from_entry($entry, $function) {
        // Define value keys for different functions
        $value_keys = array(
            'TIME_SERIES_DAILY' => ['4. close', 'Close'],
            'TIME_SERIES_WEEKLY' => ['4. close', 'Close'],
            'TIME_SERIES_MONTHLY' => ['4. close', 'Close'],
            'FX_DAILY' => ['4. close', 'Close'],
            'FX_WEEKLY' => ['4. close', 'Close'],
            'FX_MONTHLY' => ['4. close', 'Close'],
            'DIGITAL_CURRENCY_DAILY' => ['4a. close (USD)', '4b. close (USD)', 'Close'],
            'REAL_GDP' => ['value', 'Value'],
            'INFLATION' => ['value', 'Value'],
            'UNEMPLOYMENT' => ['value', 'Value']
        );
        
        $keys = $value_keys[$function] ?? ['4. close', 'close', 'value', 'Close', 'Value'];
        
        foreach ($keys as $key) {
            if (isset($entry[$key]) && is_numeric($entry[$key])) {
                return $entry[$key];
            }
        }
        
        // Fallback: find any numeric value
        foreach ($entry as $value) {
            if (is_numeric($value)) {
                return $value;
            }
        }
        
        return null;
    }
    
    /**
     * Get popular Alpha Vantage symbols
     */
    public static function get_popular_symbols() {
        return array(
            // Stocks
            'AAPL' => 'Apple Inc.',
            'GOOGL' => 'Alphabet Inc.',
            'MSFT' => 'Microsoft Corporation',
            'AMZN' => 'Amazon.com Inc.',
            'TSLA' => 'Tesla Inc.',
            'META' => 'Meta Platforms Inc.',
            'NVDA' => 'NVIDIA Corporation',
            'JPM' => 'JPMorgan Chase & Co.',
            
            // Crypto (for DIGITAL_CURRENCY_DAILY)
            'BTC' => 'Bitcoin',
            'ETH' => 'Ethereum',
            'ADA' => 'Cardano',
            'DOT' => 'Polkadot'
        );
    }
    
    /**
     * Get popular FX pairs
     */
    public static function get_popular_fx_pairs() {
        return array(
            'USD-EUR' => 'US Dollar to Euro',
            'USD-GBP' => 'US Dollar to British Pound',
            'USD-JPY' => 'US Dollar to Japanese Yen',
            'EUR-GBP' => 'Euro to British Pound',
            'GBP-JPY' => 'British Pound to Japanese Yen'
        );
    }
    
    /**
     * Validate symbol format
     */
    public function validate_symbol($symbol) {
        if (empty($symbol)) {
            return array(
                'valid' => false,
                'message' => __('Symbol cannot be empty', 'zc-data-manager')
            );
        }
        
        if (!preg_match('/^[A-Z0-9.-]+$/i', $symbol)) {
            return array(
                'valid' => false,
                'message' => __('Invalid symbol format. Use letters, numbers, dots, and hyphens only', 'zc-data-manager')
            );
        }
        
        if (strlen($symbol) > 20) {
            return array(
                'valid' => false,
                'message' => __('Symbol is too long (max 20 characters)', 'zc-data-manager')
            );
        }
        
        return array(
            'valid' => true,
            'message' => __('Symbol format is valid', 'zc-data-manager')
        );
    }
    
    /**
     * Get rate limit information
     */
    public function get_rate_limit_info() {
        return array(
            'requests_per_minute' => 75, // Standard plan
            'requests_per_day' => 25, // Free plan
            'burst_limit' => 5,
            'time_window' => 60,
            'note' => 'Free plan: 25 requests/day. Premium plans allow up to 75 requests/minute.'
        );
    }
    
    /**
     * Check if data source is configured
     */
    public function is_configured() {
        return !empty($this->api_key);
    }
    
    /**
     * Get data source documentation URL
     */
    public function get_documentation_url() {
        return 'https://www.alphavantage.co/documentation/';
    }
    
    /**
     * Get API registration URL
     */
    public function get_registration_url() {
        return 'https://www.alphavantage.co/support/#api-key';
    }
}