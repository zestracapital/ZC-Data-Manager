<?php
/**
 * ZC Data Manager - Yahoo Finance Data Source
 * Yahoo Finance CSV download integration (unofficial)
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class ZC_Source_Yahoo {
    
    private $base_url = 'https://query1.finance.yahoo.com/v7/finance/download/';
    
    public function __construct() {
        // Yahoo Finance doesn't require API key
    }
    
    /**
     * Get configuration form fields
     */
    public function get_config_fields() {
        return array(
            'symbol' => array(
                'type' => 'text',
                'label' => __('Stock Symbol', 'zc-data-manager'),
                'description' => __('Yahoo Finance symbol (e.g., AAPL, GOOGL, ^GSPC for S&P 500)', 'zc-data-manager'),
                'required' => true,
                'placeholder' => 'AAPL'
            ),
            'period' => array(
                'type' => 'select',
                'label' => __('Time Period', 'zc-data-manager'),
                'description' => __('How much historical data to fetch', 'zc-data-manager'),
                'required' => true,
                'options' => array(
                    '1y' => '1 Year',
                    '2y' => '2 Years',
                    '5y' => '5 Years',
                    '10y' => '10 Years',
                    'max' => 'Maximum Available'
                ),
                'default' => '2y'
            ),
            'data_type' => array(
                'type' => 'select',
                'label' => __('Price Type', 'zc-data-manager'),
                'description' => __('Which price to use for the time series', 'zc-data-manager'),
                'required' => true,
                'options' => array(
                    'close' => 'Closing Price',
                    'adj_close' => 'Adjusted Closing Price',
                    'open' => 'Opening Price',
                    'high' => 'High Price',
                    'low' => 'Low Price',
                    'volume' => 'Volume'
                ),
                'default' => 'adj_close'
            )
        );
    }
    
    /**
     * Test connection to Yahoo Finance
     */
    public function test_connection($config) {
        if (empty($config['symbol'])) {
            return array(
                'success' => false,
                'message' => __('Stock symbol is required for Yahoo Finance connection test', 'zc-data-manager')
            );
        }
        
        // Test with last 5 days of data
        $end_time = time();
        $start_time = strtotime('-5 days');
        
        $test_url = $this->build_download_url($config['symbol'], $start_time, $end_time);
        
        $response = wp_remote_get($test_url, array(
            'timeout' => 30,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
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
                    'message' => sprintf(__('Symbol "%s" not found on Yahoo Finance', 'zc-data-manager'), $config['symbol'])
                );
            }
            
            return array(
                'success' => false,
                'message' => sprintf(__('HTTP Error %d: Unable to fetch data', 'zc-data-manager'), $response_code)
            );
        }
        
        // Check if response contains CSV data
        if (strpos($body, 'Date,Open,High,Low,Close') === false) {
            return array(
                'success' => false,
                'message' => __('Invalid response format - expected CSV data not found', 'zc-data-manager')
            );
        }
        
        // Parse a few lines to verify data quality
        $lines = explode("\n", trim($body));
        $data_lines = array_slice($lines, 1, 5); // Skip header, get first 5 data lines
        
        $valid_lines = 0;
        foreach ($data_lines as $line) {
            if (!empty($line) && $this->is_valid_csv_line($line)) {
                $valid_lines++;
            }
        }
        
        if ($valid_lines === 0) {
            return array(
                'success' => false,
                'message' => __('No valid price data found in response', 'zc-data-manager')
            );
        }
        
        return array(
            'success' => true,
            'message' => sprintf(
                __('Connection successful! Found %d recent data points for %s', 'zc-data-manager'),
                $valid_lines,
                strtoupper($config['symbol'])
            ),
            'sample_lines' => $valid_lines,
            'symbol' => strtoupper($config['symbol'])
        );
    }
    
    /**
     * Fetch data from Yahoo Finance
     */
    public function fetch_data($config) {
        if (empty($config['symbol'])) {
            throw new Exception(__('Stock symbol is required', 'zc-data-manager'));
        }
        
        $symbol = strtoupper($config['symbol']);
        $period = !empty($config['period']) ? $config['period'] : '2y';
        $data_type = !empty($config['data_type']) ? $config['data_type'] : 'adj_close';
        
        // Calculate time range
        $end_time = time();
        $start_time = $this->calculate_start_time($period);
        
        // Build download URL
        $download_url = $this->build_download_url($symbol, $start_time, $end_time);
        
        // Make request with appropriate headers
        $response = wp_remote_get($download_url, array(
            'timeout' => 60,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
        ));
        
        if (is_wp_error($response)) {
            throw new Exception(sprintf(__('Request failed: %s', 'zc-data-manager'), $response->get_error_message()));
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($response_code !== 200) {
            if ($response_code === 404) {
                throw new Exception(sprintf(__('Symbol "%s" not found', 'zc-data-manager'), $symbol));
            }
            throw new Exception(sprintf(__('HTTP Error %d', 'zc-data-manager'), $response_code));
        }
        
        if (empty($body)) {
            throw new Exception(__('Empty response from Yahoo Finance', 'zc-data-manager'));
        }
        
        return $this->parse_csv_data($body, $data_type);
    }
    
    /**
     * Calculate start time based on period
     */
    private function calculate_start_time($period) {
        $current_time = time();
        
        switch ($period) {
            case '1y':
                return strtotime('-1 year', $current_time);
            case '2y':
                return strtotime('-2 years', $current_time);
            case '5y':
                return strtotime('-5 years', $current_time);
            case '10y':
                return strtotime('-10 years', $current_time);
            case 'max':
                return strtotime('1970-01-01'); // Yahoo's earliest date
            default:
                return strtotime('-2 years', $current_time);
        }
    }
    
    /**
     * Build Yahoo Finance download URL
     */
    private function build_download_url($symbol, $start_time, $end_time) {
        return $this->base_url . $symbol . '?' . http_build_query(array(
            'period1' => $start_time,
            'period2' => $end_time,
            'interval' => '1d',
            'events' => 'history',
            'includeAdjustedClose' => 'true'
        ));
    }
    
    /**
     * Parse CSV data from Yahoo Finance
     */
    private function parse_csv_data($csv_content, $data_type) {
        $lines = explode("\n", trim($csv_content));
        
        if (empty($lines) || count($lines) < 2) {
            throw new Exception(__('Invalid CSV data received', 'zc-data-manager'));
        }
        
        // Parse header to get column indexes
        $header = str_getcsv($lines[0]);
        $column_index = $this->get_column_index($header, $data_type);
        
        if ($column_index === false) {
            throw new Exception(sprintf(__('Column for %s not found in CSV', 'zc-data-manager'), $data_type));
        }
        
        $parsed_data = array();
        
        // Process data lines (skip header)
        for ($i = 1; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            
            if (empty($line)) {
                continue;
            }
            
            $data = str_getcsv($line);
            
            if (count($data) < 6) { // Minimum columns: Date,Open,High,Low,Close,Volume
                continue;
            }
            
            // Validate date
            if (!isset($data[0]) || empty($data[0])) {
                continue;
            }
            
            // Validate value
            if (!isset($data[$column_index]) || $data[$column_index] === 'null' || !is_numeric($data[$column_index])) {
                continue;
            }
            
            $parsed_data[] = array(
                'date' => $data[0], // Yahoo returns YYYY-MM-DD format
                'value' => floatval($data[$column_index])
            );
        }
        
        if (empty($parsed_data)) {
            throw new Exception(__('No valid data points found in CSV', 'zc-data-manager'));
        }
        
        // Sort by date (Yahoo usually returns chronological order)
        usort($parsed_data, function($a, $b) {
            return strtotime($a['date']) - strtotime($b['date']);
        });
        
        return $parsed_data;
    }
    
    /**
     * Get column index for data type
     */
    private function get_column_index($header, $data_type) {
        $column_map = array(
            'open' => 'Open',
            'high' => 'High',
            'low' => 'Low',
            'close' => 'Close',
            'adj_close' => 'Adj Close',
            'volume' => 'Volume'
        );
        
        if (!isset($column_map[$data_type])) {
            return false;
        }
        
        $target_column = $column_map[$data_type];
        
        foreach ($header as $index => $column_name) {
            if (trim($column_name) === $target_column) {
                return $index;
            }
        }
        
        return false;
    }
    
    /**
     * Validate CSV line format
     */
    private function is_valid_csv_line($line) {
        $data = str_getcsv($line);
        
        // Should have at least 6 columns: Date,Open,High,Low,Close,Volume
        if (count($data) < 6) {
            return false;
        }
        
        // Check if date is valid
        if (empty($data[0]) || strtotime($data[0]) === false) {
            return false;
        }
        
        // Check if at least one price field is numeric
        for ($i = 1; $i <= 5; $i++) {
            if (isset($data[$i]) && is_numeric($data[$i]) && $data[$i] > 0) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Search Yahoo Finance symbols (basic validation)
     */
    public function validate_symbol($symbol) {
        $symbol = strtoupper(trim($symbol));
        
        // Basic symbol validation
        if (empty($symbol)) {
            return array(
                'valid' => false,
                'message' => __('Symbol cannot be empty', 'zc-data-manager')
            );
        }
        
        if (strlen($symbol) > 10) {
            return array(
                'valid' => false,
                'message' => __('Symbol is too long (max 10 characters)', 'zc-data-manager')
            );
        }
        
        if (!preg_match('/^[A-Z0-9\.\^-]+$/', $symbol)) {
            return array(
                'valid' => false,
                'message' => __('Invalid symbol format. Use letters, numbers, dots, hyphens, or ^ only', 'zc-data-manager')
            );
        }
        
        return array(
            'valid' => true,
            'message' => __('Symbol format is valid', 'zc-data-manager'),
            'formatted_symbol' => $symbol
        );
    }
    
    /**
     * Get popular stock symbols
     */
    public static function get_popular_symbols() {
        return array(
            // Individual stocks
            'AAPL' => 'Apple Inc.',
            'GOOGL' => 'Alphabet Inc.',
            'MSFT' => 'Microsoft Corporation',
            'AMZN' => 'Amazon.com Inc.',
            'TSLA' => 'Tesla Inc.',
            'META' => 'Meta Platforms Inc.',
            'NVDA' => 'NVIDIA Corporation',
            'JPM' => 'JPMorgan Chase & Co.',
            'JNJ' => 'Johnson & Johnson',
            'V' => 'Visa Inc.',
            
            // Indices
            '^GSPC' => 'S&P 500 Index',
            '^DJI' => 'Dow Jones Industrial Average',
            '^IXIC' => 'NASDAQ Composite',
            '^RUT' => 'Russell 2000 Index',
            '^VIX' => 'CBOE Volatility Index',
            
            // ETFs
            'SPY' => 'SPDR S&P 500 ETF',
            'QQQ' => 'Invesco QQQ Trust ETF',
            'IWM' => 'iShares Russell 2000 ETF',
            'GLD' => 'SPDR Gold Shares',
            'TLT' => 'iShares 20+ Year Treasury Bond ETF',
            
            // Currencies
            'EURUSD=X' => 'EUR/USD Exchange Rate',
            'GBPUSD=X' => 'GBP/USD Exchange Rate',
            'USDJPY=X' => 'USD/JPY Exchange Rate',
            
            // Commodities
            'CL=F' => 'Crude Oil Futures',
            'GC=F' => 'Gold Futures',
            'SI=F' => 'Silver Futures'
        );
    }
    
    /**
     * Get symbol suggestions based on category
     */
    public function get_symbol_suggestions($category = 'stocks') {
        $suggestions = array(
            'stocks' => array('AAPL', 'GOOGL', 'MSFT', 'AMZN', 'TSLA', 'META', 'NVDA'),
            'indices' => array('^GSPC', '^DJI', '^IXIC', '^RUT', '^VIX'),
            'etfs' => array('SPY', 'QQQ', 'IWM', 'GLD', 'TLT'),
            'forex' => array('EURUSD=X', 'GBPUSD=X', 'USDJPY=X'),
            'commodities' => array('CL=F', 'GC=F', 'SI=F')
        );
        
        return isset($suggestions[$category]) ? $suggestions[$category] : array();
    }
    
    /**
     * Get rate limit information
     */
    public function get_rate_limit_info() {
        return array(
            'requests_per_hour' => 2000, // Conservative estimate
            'requests_per_day' => 48000, // Conservative estimate
            'burst_limit' => 100,
            'time_window' => 60,
            'note' => 'Yahoo Finance has no official API. Use responsibly to avoid being blocked.'
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
        return 'https://finance.yahoo.com/';
    }
    
    /**
     * Get important notice about Yahoo Finance
     */
    public function get_usage_notice() {
        return __('Yahoo Finance API is unofficial and may change without notice. Use for educational/personal purposes only.', 'zc-data-manager');
    }
}