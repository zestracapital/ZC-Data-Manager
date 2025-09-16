<?php
/**
 * ZC Data Manager - CSV Data Source
 * Import data from CSV files or URLs
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class ZC_Source_CSV {
    
    public function __construct() {
        // CSV source doesn't require API key
    }
    
    /**
     * Get configuration form fields
     */
    public function get_config_fields() {
        return array(
            'source_type' => array(
                'type' => 'select',
                'label' => __('CSV Source', 'zc-data-manager'),
                'description' => __('Choose how to provide the CSV data', 'zc-data-manager'),
                'required' => true,
                'options' => array(
                    'url' => 'Remote URL',
                    'file' => 'Upload File'
                ),
                'default' => 'url'
            ),
            'csv_url' => array(
                'type' => 'url',
                'label' => __('CSV URL', 'zc-data-manager'),
                'description' => __('Direct URL to CSV file (if Source Type is URL)', 'zc-data-manager'),
                'required' => false,
                'placeholder' => 'https://example.com/data.csv'
            ),
            'csv_file' => array(
                'type' => 'file',
                'label' => __('Upload CSV File', 'zc-data-manager'),
                'description' => __('Upload a CSV file (if Source Type is File)', 'zc-data-manager'),
                'required' => false,
                'accept' => '.csv,.txt'
            ),
            'date_column' => array(
                'type' => 'text',
                'label' => __('Date Column', 'zc-data-manager'),
                'description' => __('Name or index (0-based) of the date column', 'zc-data-manager'),
                'required' => true,
                'placeholder' => 'Date',
                'default' => '0'
            ),
            'value_column' => array(
                'type' => 'text',
                'label' => __('Value Column', 'zc-data-manager'),
                'description' => __('Name or index (0-based) of the value column', 'zc-data-manager'),
                'required' => true,
                'placeholder' => 'Value',
                'default' => '1'
            ),
            'has_header' => array(
                'type' => 'checkbox',
                'label' => __('Has Header Row', 'zc-data-manager'),
                'description' => __('Check if the CSV file has a header row', 'zc-data-manager'),
                'required' => false,
                'default' => true
            ),
            'delimiter' => array(
                'type' => 'select',
                'label' => __('CSV Delimiter', 'zc-data-manager'),
                'description' => __('Character used to separate CSV fields', 'zc-data-manager'),
                'required' => true,
                'options' => array(
                    ',' => 'Comma (,)',
                    ';' => 'Semicolon (;)',
                    '\t' => 'Tab',
                    '|' => 'Pipe (|)'
                ),
                'default' => ','
            ),
            'date_format' => array(
                'type' => 'select',
                'label' => __('Date Format', 'zc-data-manager'),
                'description' => __('Format of dates in the CSV file', 'zc-data-manager'),
                'required' => true,
                'options' => array(
                    'auto' => 'Auto-detect',
                    'Y-m-d' => 'YYYY-MM-DD (2024-03-15)',
                    'm/d/Y' => 'MM/DD/YYYY (03/15/2024)',
                    'd/m/Y' => 'DD/MM/YYYY (15/03/2024)', 
                    'Y-m' => 'YYYY-MM (2024-03)',
                    'Y' => 'YYYY (2024)'
                ),
                'default' => 'auto'
            )
        );
    }
    
    /**
     * Test connection to CSV source
     */
    public function test_connection($config) {
        if (empty($config['source_type'])) {
            return array(
                'success' => false,
                'message' => __('CSV source type is required', 'zc-data-manager')
            );
        }
        
        if ($config['source_type'] === 'url' && empty($config['csv_url'])) {
            return array(
                'success' => false,
                'message' => __('CSV URL is required when source type is URL', 'zc-data-manager')
            );
        }
        
        if ($config['source_type'] === 'file' && empty($config['csv_file'])) {
            return array(
                'success' => false,
                'message' => __('CSV file is required when source type is file', 'zc-data-manager')
            );
        }
        
        try {
            // Get CSV content
            $csv_content = $this->get_csv_content($config);
            
            if (empty($csv_content)) {
                throw new Exception(__('CSV file is empty or could not be read', 'zc-data-manager'));
            }
            
            // Parse a few lines for testing
            $test_data = $this->parse_csv_content($csv_content, $config, 5);
            
            if (empty($test_data)) {
                throw new Exception(__('No valid data found in CSV file', 'zc-data-manager'));
            }
            
            return array(
                'success' => true,
                'message' => sprintf(
                    __('Connection successful! Found %d sample data points', 'zc-data-manager'),
                    count($test_data)
                ),
                'sample_data' => $test_data
            );
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => $e->getMessage()
            );
        }
    }
    
    /**
     * Fetch data from CSV source
     */
    public function fetch_data($config) {
        if (empty($config['source_type'])) {
            throw new Exception(__('CSV source type is required', 'zc-data-manager'));
        }
        
        // Get CSV content
        $csv_content = $this->get_csv_content($config);
        
        if (empty($csv_content)) {
            throw new Exception(__('CSV file is empty or could not be read', 'zc-data-manager'));
        }
        
        // Parse CSV content
        $parsed_data = $this->parse_csv_content($csv_content, $config);
        
        if (empty($parsed_data)) {
            throw new Exception(__('No valid data found in CSV file', 'zc-data-manager'));
        }
        
        return $parsed_data;
    }
    
    /**
     * Get CSV content based on source type
     */
    private function get_csv_content($config) {
        if ($config['source_type'] === 'url') {
            return $this->get_csv_from_url($config['csv_url']);
        } elseif ($config['source_type'] === 'file') {
            return $this->get_csv_from_file($config['csv_file']);
        }
        
        throw new Exception(__('Invalid CSV source type', 'zc-data-manager'));
    }
    
    /**
     * Get CSV content from URL
     */
    private function get_csv_from_url($url) {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new Exception(__('Invalid CSV URL format', 'zc-data-manager'));
        }
        
        $response = wp_remote_get($url, array(
            'timeout' => 60,
            'user-agent' => 'ZC Data Manager WordPress Plugin'
        ));
        
        if (is_wp_error($response)) {
            throw new Exception(sprintf(__('Error fetching CSV: %s', 'zc-data-manager'), $response->get_error_message()));
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            throw new Exception(sprintf(__('HTTP Error %d when fetching CSV', 'zc-data-manager'), $response_code));
        }
        
        $content = wp_remote_retrieve_body($response);
        
        // Check if content looks like CSV
        if (!$this->is_likely_csv($content)) {
            throw new Exception(__('URL does not appear to contain CSV data', 'zc-data-manager'));
        }
        
        return $content;
    }
    
    /**
     * Get CSV content from uploaded file
     */
    private function get_csv_from_file($file_path) {
        // Handle WordPress upload
        if (isset($_FILES['csv_file']) && !empty($_FILES['csv_file']['tmp_name'])) {
            $uploaded_file = $_FILES['csv_file'];
            
            // Validate file
            if ($uploaded_file['error'] !== UPLOAD_ERR_OK) {
                throw new Exception(__('File upload error', 'zc-data-manager'));
            }
            
            // Check file size (max 10MB)
            if ($uploaded_file['size'] > 10 * 1024 * 1024) {
                throw new Exception(__('File too large (max 10MB)', 'zc-data-manager'));
            }
            
            // Check MIME type
            $allowed_types = array('text/csv', 'text/plain', 'application/csv');
            $file_type = wp_check_filetype($uploaded_file['name']);
            
            if (!in_array($uploaded_file['type'], $allowed_types) && !in_array($file_type['type'], $allowed_types)) {
                throw new Exception(__('Invalid file type. Please upload a CSV file.', 'zc-data-manager'));
            }
            
            $content = file_get_contents($uploaded_file['tmp_name']);
            
            if ($content === false) {
                throw new Exception(__('Could not read uploaded file', 'zc-data-manager'));
            }
            
            return $content;
        }
        
        // Handle stored file path
        if (!empty($file_path) && file_exists($file_path)) {
            $content = file_get_contents($file_path);
            
            if ($content === false) {
                throw new Exception(__('Could not read CSV file', 'zc-data-manager'));
            }
            
            return $content;
        }
        
        throw new Exception(__('CSV file not found', 'zc-data-manager'));
    }
    
    /**
     * Check if content looks like CSV
     */
    private function is_likely_csv($content) {
        // Check for common CSV indicators
        $lines = explode("\n", substr($content, 0, 1000)); // First 1000 chars
        
        if (count($lines) < 2) {
            return false;
        }
        
        // Check if lines have consistent comma/semicolon patterns
        $delimiters = array(',', ';', '\t');
        
        foreach ($delimiters as $delimiter) {
            $delimiter = $delimiter === '\t' ? "\t" : $delimiter;
            $first_count = substr_count($lines[0], $delimiter);
            
            if ($first_count > 0) {
                $consistent = true;
                for ($i = 1; $i < min(5, count($lines)); $i++) {
                    if (substr_count($lines[$i], $delimiter) !== $first_count) {
                        $consistent = false;
                        break;
                    }
                }
                
                if ($consistent) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Parse CSV content
     */
    private function parse_csv_content($content, $config, $limit = null) {
        $delimiter = $config['delimiter'] === '\t' ? "\t" : $config['delimiter'];
        $has_header = !empty($config['has_header']);
        $date_column = $config['date_column'];
        $value_column = $config['value_column'];
        $date_format = $config['date_format'] ?? 'auto';
        
        // Split into lines
        $lines = explode("\n", $content);
        $lines = array_map('trim', $lines);
        $lines = array_filter($lines); // Remove empty lines
        
        if (empty($lines)) {
            throw new Exception(__('No data lines found in CSV', 'zc-data-manager'));
        }
        
        // Parse header if present
        $header = null;
        $data_start_index = 0;
        
        if ($has_header) {
            $header = str_getcsv($lines[0], $delimiter);
            $data_start_index = 1;
        }
        
        // Determine column indices
        $date_col_index = $this->get_column_index($date_column, $header);
        $value_col_index = $this->get_column_index($value_column, $header);
        
        if ($date_col_index === false) {
            throw new Exception(sprintf(__('Date column "%s" not found', 'zc-data-manager'), $date_column));
        }
        
        if ($value_col_index === false) {
            throw new Exception(sprintf(__('Value column "%s" not found', 'zc-data-manager'), $value_column));
        }
        
        // Parse data lines
        $parsed_data = array();
        $line_count = 0;
        
        for ($i = $data_start_index; $i < count($lines); $i++) {
            if ($limit && $line_count >= $limit) {
                break;
            }
            
            $line = $lines[$i];
            if (empty($line)) {
                continue;
            }
            
            $data = str_getcsv($line, $delimiter);
            
            // Check if we have enough columns
            if (count($data) <= max($date_col_index, $value_col_index)) {
                continue;
            }
            
            $raw_date = trim($data[$date_col_index]);
            $raw_value = trim($data[$value_col_index]);
            
            // Skip empty values
            if (empty($raw_date) || empty($raw_value)) {
                continue;
            }
            
            // Parse date
            $date = $this->parse_date($raw_date, $date_format);
            if (!$date) {
                continue;
            }
            
            // Parse value
            $value = $this->parse_value($raw_value);
            if ($value === null) {
                continue;
            }
            
            $parsed_data[] = array(
                'date' => $date,
                'value' => $value
            );
            
            $line_count++;
        }
        
        // Sort by date
        usort($parsed_data, function($a, $b) {
            return strtotime($a['date']) - strtotime($b['date']);
        });
        
        return $parsed_data;
    }
    
    /**
     * Get column index from name or number
     */
    private function get_column_index($column, $header) {
        // If it's a number, use as index
        if (is_numeric($column)) {
            return intval($column);
        }
        
        // If we have a header, search by name
        if ($header && is_array($header)) {
            $index = array_search($column, $header);
            if ($index !== false) {
                return $index;
            }
            
            // Try case-insensitive search
            $header_lower = array_map('strtolower', $header);
            $index = array_search(strtolower($column), $header_lower);
            if ($index !== false) {
                return $index;
            }
        }
        
        return false;
    }
    
    /**
     * Parse date string
     */
    private function parse_date($date_string, $format) {
        if ($format === 'auto') {
            // Try common formats
            $formats = array('Y-m-d', 'm/d/Y', 'd/m/Y', 'Y-m', 'Y');
        } else {
            $formats = array($format);
        }
        
        foreach ($formats as $fmt) {
            $parsed = DateTime::createFromFormat($fmt, $date_string);
            if ($parsed !== false) {
                return $parsed->format('Y-m-d');
            }
        }
        
        // Try strtotime as fallback
        $timestamp = strtotime($date_string);
        if ($timestamp !== false) {
            return date('Y-m-d', $timestamp);
        }
        
        return false;
    }
    
    /**
     * Parse value string
     */
    private function parse_value($value_string) {
        // Remove common formatting
        $cleaned = str_replace(array(',', ' ', '$', '%'), '', $value_string);
        
        if (is_numeric($cleaned)) {
            return floatval($cleaned);
        }
        
        return null;
    }
    
    /**
     * Validate CSV configuration
     */
    public function validate_config($config) {
        $errors = array();
        
        if (empty($config['source_type'])) {
            $errors[] = __('Source type is required', 'zc-data-manager');
        }
        
        if ($config['source_type'] === 'url' && empty($config['csv_url'])) {
            $errors[] = __('CSV URL is required when source type is URL', 'zc-data-manager');
        }
        
        if (!empty($config['csv_url']) && !filter_var($config['csv_url'], FILTER_VALIDATE_URL)) {
            $errors[] = __('Invalid CSV URL format', 'zc-data-manager');
        }
        
        if (empty($config['date_column'])) {
            $errors[] = __('Date column is required', 'zc-data-manager');
        }
        
        if (empty($config['value_column'])) {
            $errors[] = __('Value column is required', 'zc-data-manager');
        }
        
        return empty($errors) ? array('valid' => true) : array('valid' => false, 'errors' => $errors);
    }
    
    /**
     * Get rate limit information
     */
    public function get_rate_limit_info() {
        return array(
            'requests_per_hour' => 'N/A',
            'requests_per_day' => 'N/A',
            'burst_limit' => 'N/A',
            'time_window' => 'N/A',
            'note' => 'CSV source has no rate limits, but large files may take time to process'
        );
    }
    
    /**
     * Check if data source is configured
     */
    public function is_configured() {
        return true; // CSV doesn't require external configuration
    }
    
    /**
     * Get data source documentation
     */
    public function get_documentation_url() {
        return 'https://en.wikipedia.org/wiki/Comma-separated_values';
    }
    
    /**
     * Get sample CSV format
     */
    public function get_sample_csv() {
        return "Date,Value\n2024-01-01,100.5\n2024-01-02,101.2\n2024-01-03,99.8";
    }
}