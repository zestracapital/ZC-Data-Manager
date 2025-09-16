<?php
/**
 * ZC Data Manager - Data Sources Configuration Page
 * Configure API keys and settings for data sources
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Check user permissions
if (!current_user_can('manage_options')) {
    wp_die(__('You do not have sufficient permissions to access this page.'));
}

// Initialize classes
$collector = ZC_Data_Collector::get_instance();

// Handle form submission
if (isset($_POST['submit']) && wp_verify_nonce($_POST['zc_nonce'], 'zc_data_sources')) {
    $updated_options = array();
    
    // FRED API Key
    if (isset($_POST['fred_api_key'])) {
        $fred_key = sanitize_text_field($_POST['fred_api_key']);
        update_option('zc_dm_fred_api_key', $fred_key);
        $updated_options[] = 'FRED API Key';
    }
    
    // Alpha Vantage API Key
    if (isset($_POST['alphavantage_api_key'])) {
        $av_key = sanitize_text_field($_POST['alphavantage_api_key']);
        update_option('zc_dm_alphavantage_api_key', $av_key);
        $updated_options[] = 'Alpha Vantage API Key';
    }
    
    // Quandl API Key
    if (isset($_POST['quandl_api_key'])) {
        $quandl_key = sanitize_text_field($_POST['quandl_api_key']);
        update_option('zc_dm_quandl_api_key', $quandl_key);
        $updated_options[] = 'Quandl API Key';
    }
    
    if (!empty($updated_options)) {
        echo '<div class="notice notice-success"><p>' . 
             sprintf(__('Updated: %s', 'zc-data-manager'), implode(', ', $updated_options)) . 
             '</p></div>';
    }
}

// Get current values
$fred_api_key = get_option('zc_dm_fred_api_key', '');
$alphavantage_api_key = get_option('zc_dm_alphavantage_api_key', '');
$quandl_api_key = get_option('zc_dm_quandl_api_key', '');

// Get available sources
$available_sources = $collector->get_available_sources();
?>

<div class="wrap">
    <h1><?php _e('Data Sources Configuration', 'zc-data-manager'); ?></h1>
    
    <p class="description">
        <?php _e('Configure API keys and connection settings for various data sources. API keys are required for some sources to access their data.', 'zc-data-manager'); ?>
    </p>
    
    <div class="zc-sources-overview">
        <h2><?php _e('Available Data Sources', 'zc-data-manager'); ?></h2>
        
        <div class="zc-sources-grid">
            <?php foreach ($available_sources as $source_key => $source_info): ?>
                <?php
                $is_configured = false;
                $config_status = __('Not Configured', 'zc-data-manager');
                $status_class = 'zc-status-error';
                
                // Check configuration status
                if (class_exists($source_info['class'])) {
                    $source_instance = new $source_info['class']();
                    if (method_exists($source_instance, 'is_configured')) {
                        $is_configured = $source_instance->is_configured();
                        if ($is_configured) {
                            $config_status = __('Configured', 'zc-data-manager');
                            $status_class = 'zc-status-success';
                        }
                    }
                }
                ?>
                
                <div class="zc-source-overview-card">
                    <div class="zc-source-header">
                        <h3><?php echo esc_html($source_info['name']); ?></h3>
                        <span class="zc-status-badge <?php echo $status_class; ?>">
                            <?php echo $config_status; ?>
                        </span>
                    </div>
                    
                    <p class="zc-source-description">
                        <?php echo esc_html($source_info['description']); ?>
                    </p>
                    
                    <div class="zc-source-details">
                        <p>
                            <strong><?php _e('API Key Required:', 'zc-data-manager'); ?></strong>
                            <?php echo $source_info['requires_api_key'] ? __('Yes', 'zc-data-manager') : __('No', 'zc-data-manager'); ?>
                        </p>
                        
                        <?php if ($source_info['requires_api_key']): ?>
                            <p>
                                <a href="#zc-config-<?php echo esc_attr($source_key); ?>" class="button button-secondary button-small">
                                    <?php _e('Configure', 'zc-data-manager'); ?>
                                </a>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <form method="post" class="zc-form">
        <?php wp_nonce_field('zc_data_sources', 'zc_nonce'); ?>
        
        <!-- FRED Configuration -->
        <div class="zc-config-section" id="zc-config-fred">
            <h2>
                <?php _e('FRED (Federal Reserve Economic Data)', 'zc-data-manager'); ?>
                <button type="button" class="button button-secondary zc-test-source" data-source="fred">
                    <?php _e('Test Connection', 'zc-data-manager'); ?>
                </button>
            </h2>
            
            <p class="description">
                <?php _e('FRED provides US economic data from the Federal Reserve Bank of St. Louis. An API key is required to access their data.', 'zc-data-manager'); ?>
            </p>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="fred_api_key"><?php _e('API Key', 'zc-data-manager'); ?></label>
                    </th>
                    <td>
                        <input type="text" 
                               id="fred_api_key" 
                               name="fred_api_key" 
                               value="<?php echo esc_attr($fred_api_key); ?>" 
                               class="regular-text"
                               placeholder="<?php _e('Enter your FRED API key', 'zc-data-manager'); ?>">
                        
                        <p class="description">
                            <?php _e('Get your free API key from:', 'zc-data-manager'); ?> 
                            <a href="https://research.stlouisfed.org/docs/api/api_key.html" target="_blank">
                                <?php _e('FRED API Registration', 'zc-data-manager'); ?>
                            </a>
                        </p>
                    </td>
                </tr>
            </table>
            
            <?php if (!empty($fred_api_key)): ?>
                <div class="zc-api-status">
                    <h4><?php _e('Popular FRED Series', 'zc-data-manager'); ?></h4>
                    <p class="description"><?php _e('Try these popular economic indicators:', 'zc-data-manager'); ?></p>
                    
                    <div class="zc-popular-series">
                        <?php 
                        $popular_fred = array(
                            'GDP' => 'Gross Domestic Product',
                            'UNRATE' => 'Unemployment Rate',
                            'CPIAUCSL' => 'Consumer Price Index',
                            'FEDFUNDS' => 'Federal Funds Rate',
                            'PAYEMS' => 'Nonfarm Payrolls'
                        );
                        foreach ($popular_fred as $code => $name): 
                        ?>
                            <span class="zc-series-tag">
                                <code><?php echo $code; ?></code> - <?php echo $name; ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Alpha Vantage Configuration -->
        <div class="zc-config-section" id="zc-config-alphavantage">
            <h2>
                <?php _e('Alpha Vantage', 'zc-data-manager'); ?>
                <button type="button" class="button button-secondary zc-test-source" data-source="alphavantage">
                    <?php _e('Test Connection', 'zc-data-manager'); ?>
                </button>
            </h2>
            
            <p class="description">
                <?php _e('Alpha Vantage provides stock market data, forex rates, and cryptocurrency prices. Free tier allows 25 requests per day.', 'zc-data-manager'); ?>
            </p>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="alphavantage_api_key"><?php _e('API Key', 'zc-data-manager'); ?></label>
                    </th>
                    <td>
                        <input type="text" 
                               id="alphavantage_api_key" 
                               name="alphavantage_api_key" 
                               value="<?php echo esc_attr($alphavantage_api_key); ?>" 
                               class="regular-text"
                               placeholder="<?php _e('Enter your Alpha Vantage API key', 'zc-data-manager'); ?>">
                        
                        <p class="description">
                            <?php _e('Get your free API key from:', 'zc-data-manager'); ?> 
                            <a href="https://www.alphavantage.co/support/#api-key" target="_blank">
                                <?php _e('Alpha Vantage API Key', 'zc-data-manager'); ?>
                            </a>
                        </p>
                    </td>
                </tr>
            </table>
            
            <div class="zc-rate-limit-warning">
                <p><strong><?php _e('Rate Limits:', 'zc-data-manager'); ?></strong></p>
                <ul>
                    <li><?php _e('Free tier: 25 requests per day', 'zc-data-manager'); ?></li>
                    <li><?php _e('Premium: Up to 1,200 requests per minute', 'zc-data-manager'); ?></li>
                </ul>
            </div>
        </div>
        
        <!-- Quandl Configuration -->
        <div class="zc-config-section" id="zc-config-quandl">
            <h2>
                <?php _e('Quandl (Nasdaq Data Link)', 'zc-data-manager'); ?>
                <button type="button" class="button button-secondary zc-test-source" data-source="quandl">
                    <?php _e('Test Connection', 'zc-data-manager'); ?>
                </button>
            </h2>
            
            <p class="description">
                <?php _e('Quandl provides financial and economic data. Free accounts get 50,000 API calls per day.', 'zc-data-manager'); ?>
            </p>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="quandl_api_key"><?php _e('API Key', 'zc-data-manager'); ?></label>
                    </th>
                    <td>
                        <input type="text" 
                               id="quandl_api_key" 
                               name="quandl_api_key" 
                               value="<?php echo esc_attr($quandl_api_key); ?>" 
                               class="regular-text"
                               placeholder="<?php _e('Enter your Quandl API key', 'zc-data-manager'); ?>">
                        
                        <p class="description">
                            <?php _e('Get your free API key from:', 'zc-data-manager'); ?> 
                            <a href="https://data.nasdaq.com/sign-up" target="_blank">
                                <?php _e('Nasdaq Data Link', 'zc-data-manager'); ?>
                            </a>
                        </p>
                    </td>
                </tr>
            </table>
        </div>
        
        <!-- No API Key Required Sources -->
        <div class="zc-config-section">
            <h2><?php _e('No Configuration Required', 'zc-data-manager'); ?></h2>
            
            <p class="description">
                <?php _e('The following data sources work without API keys:', 'zc-data-manager'); ?>
            </p>
            
            <div class="zc-no-config-sources">
                <div class="zc-source-item">
                    <h4><?php _e('World Bank Open Data', 'zc-data-manager'); ?></h4>
                    <p><?php _e('Global development data for all countries. No API key required.', 'zc-data-manager'); ?></p>
                </div>
                
                <div class="zc-source-item">
                    <h4><?php _e('Yahoo Finance', 'zc-data-manager'); ?></h4>
                    <p><?php _e('Stock prices, indices, and financial data. Unofficial API - use responsibly.', 'zc-data-manager'); ?></p>
                </div>
                
                <div class="zc-source-item">
                    <h4><?php _e('DBnomics', 'zc-data-manager'); ?></h4>
                    <p><?php _e('Aggregate data from 80+ statistical agencies worldwide. No API key required.', 'zc-data-manager'); ?></p>
                </div>
                
                <div class="zc-source-item">
                    <h4><?php _e('Eurostat', 'zc-data-manager'); ?></h4>
                    <p><?php _e('European Union statistics. No API key required.', 'zc-data-manager'); ?></p>
                </div>
                
                <div class="zc-source-item">
                    <h4><?php _e('CSV Files/URLs', 'zc-data-manager'); ?></h4>
                    <p><?php _e('Import data from CSV files or direct URLs. No configuration needed.', 'zc-data-manager'); ?></p>
                </div>
            </div>
        </div>
        
        <p class="submit">
            <input type="submit" name="submit" class="button button-primary" value="<?php _e('Save Configuration', 'zc-data-manager'); ?>">
        </p>
    </form>
    
    <!-- Connection Test Results -->
    <div id="zc-test-results" class="zc-test-results" style="display: none;">
        <h3><?php _e('Connection Test Results', 'zc-data-manager'); ?></h3>
        <div class="zc-test-output"></div>
    </div>
</div>

<style>
/* Data Sources Page Styles */
.zc-sources-overview {
    background: #fff;
    border: 1px solid #ccd0d4;
    margin: 20px 0;
    padding: 20px;
}

.zc-sources-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
    margin-top: 20px;
}

.zc-source-overview-card {
    border: 1px solid #e0e0e0;
    padding: 15px;
    border-radius: 4px;
    background: #fafafa;
}

.zc-source-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}

.zc-source-header h3 {
    margin: 0;
    font-size: 16px;
}

.zc-status-badge {
    padding: 3px 8px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: 500;
    text-transform: uppercase;
}

.zc-status-success {
    background: #00a32a;
    color: #fff;
}

.zc-status-error {
    background: #d63638;
    color: #fff;
}

.zc-source-description {
    color: #50575e;
    margin-bottom: 15px;
}

.zc-source-details p {
    margin: 5px 0;
    font-size: 13px;
}

.zc-config-section {
    background: #fff;
    border: 1px solid #ccd0d4;
    margin: 20px 0;
    padding: 20px;
}

.zc-config-section h2 {
    margin-top: 0;
    border-bottom: 1px solid #e0e0e0;
    padding-bottom: 10px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.zc-api-status {
    background: #f0f6fc;
    border: 1px solid #c3d4e6;
    padding: 15px;
    margin-top: 15px;
    border-radius: 4px;
}

.zc-api-status h4 {
    margin-top: 0;
    color: #0073aa;
}

.zc-popular-series {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 10px;
}

.zc-series-tag {
    background: #fff;
    border: 1px solid #c3d4e6;
    padding: 4px 8px;
    border-radius: 3px;
    font-size: 12px;
    display: inline-block;
}

.zc-series-tag code {
    background: none;
    padding: 0;
    color: #0073aa;
    font-weight: 500;
}

.zc-rate-limit-warning {
    background: #fff3cd;
    border: 1px solid #ffeaa7;
    padding: 10px 15px;
    border-radius: 4px;
    margin-top: 15px;
}

.zc-rate-limit-warning ul {
    margin: 5px 0 0 20px;
}

.zc-no-config-sources {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 15px;
    margin-top: 15px;
}

.zc-source-item {
    background: #f9f9f9;
    border: 1px solid #e0e0e0;
    padding: 15px;
    border-radius: 4px;
}

.zc-source-item h4 {
    margin: 0 0 8px 0;
    color: #0073aa;
}

.zc-source-item p {
    margin: 0;
    color: #50575e;
    font-size: 13px;
}

.zc-test-results {
    background: #fff;
    border: 1px solid #ccd0d4;
    margin: 20px 0;
    padding: 20px;
}

.zc-test-output {
    background: #f6f7f7;
    border: 1px solid #ddd;
    padding: 15px;
    border-radius: 4px;
    font-family: monospace;
    white-space: pre-wrap;
    max-height: 300px;
    overflow-y: auto;
}

/* Responsive */
@media (max-width: 768px) {
    .zc-sources-grid,
    .zc-no-config-sources {
        grid-template-columns: 1fr;
    }
    
    .zc-config-section h2,
    .zc-source-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }
}
</style>

<script>
jQuery(document).ready(function($) {
    // Test source connection
    $('.zc-test-source').on('click', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var source = $button.data('source');
        var $section = $button.closest('.zc-config-section');
        var $results = $('#zc-test-results');
        var $output = $('.zc-test-output');
        
        // Get API key for this source
        var apiKey = '';
        if (source === 'fred') {
            apiKey = $('#fred_api_key').val();
        } else if (source === 'alphavantage') {
            apiKey = $('#alphavantage_api_key').val();
        } else if (source === 'quandl') {
            apiKey = $('#quandl_api_key').val();
        }
        
        if (!apiKey) {
            alert('<?php _e("Please enter an API key first", "zc-data-manager"); ?>');
            return;
        }
        
        // Show loading state
        var originalText = $button.text();
        $button.text('<?php _e("Testing...", "zc-data-manager"); ?>')
               .prop('disabled', true);
        
        // Show results area
        $results.show();
        $output.text('<?php _e("Testing connection...", "zc-data-manager"); ?>');
        
        // Build test config based on source
        var config = {};
        if (source === 'fred') {
            config.series_id = 'GDP';
        } else if (source === 'alphavantage') {
            config.symbol = 'MSFT';
            config.function = 'TIME_SERIES_DAILY';
        } else if (source === 'quandl') {
            config.dataset = 'WIKI/AAPL';
        }
        
        // Make AJAX request
        $.post(ajaxurl, {
            action: 'zc_test_source_connection',
            nonce: zcDataManager.nonce,
            source_type: source,
            config: config
        })
        .done(function(response) {
            if (response.success) {
                $output.html('<span style="color: #00a32a;">✓ ' + response.message + '</span>');
                
                if (response.series_info) {
                    $output.append('\n\n<?php _e("Additional Info:", "zc-data-manager"); ?>\n' + JSON.stringify(response.series_info, null, 2));
                }
            } else {
                $output.html('<span style="color: #d63638;">✗ ' + (response.message || '<?php _e("Connection failed", "zc-data-manager"); ?>') + '</span>');
            }
        })
        .fail(function() {
            $output.html('<span style="color: #d63638;">✗ <?php _e("Request failed. Please try again.", "zc-data-manager"); ?></span>');
        })
        .always(function() {
            $button.text(originalText).prop('disabled', false);
        });
    });
    
    // Smooth scroll to configuration sections
    $('a[href^="#zc-config-"]').on('click', function(e) {
        e.preventDefault();
        var target = $(this.getAttribute('href'));
        if (target.length) {
            $('html, body').animate({
                scrollTop: target.offset().top - 50
            }, 500);
        }
    });
});
</script>