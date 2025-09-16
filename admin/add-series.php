<?php
/**
 * ZC Data Manager - Add/Edit Series Admin Page
 * Form for adding new data series or editing existing ones
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
$database = ZC_Database::get_instance();
$collector = ZC_Data_Collector::get_instance();

// Check if we're editing an existing series
$edit_mode = false;
$series_data = null;
$series_slug = '';

if (isset($_GET['edit']) && !empty($_GET['edit'])) {
    $series_slug = sanitize_text_field($_GET['edit']);
    $series_data = $database->get_series_by_slug($series_slug);
    
    if ($series_data) {
        $edit_mode = true;
    } else {
        echo '<div class="notice notice-error"><p>' . __('Series not found.', 'zc-data-manager') . '</p></div>';
    }
}

// Handle form submission
if (isset($_POST['submit']) && wp_verify_nonce($_POST['zc_nonce'], 'zc_add_series')) {
    $form_data = array(
        'slug' => sanitize_title($_POST['series_slug']),
        'name' => sanitize_text_field($_POST['series_name']),
        'source_type' => sanitize_text_field($_POST['source_type']),
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
        'source_config' => array()
    );
    
    // Build source configuration
    $source_type = $form_data['source_type'];
    $config_fields = $collector->get_source_config_fields($source_type);
    
    foreach ($config_fields as $field_key => $field_info) {
        $form_key = $source_type . '_' . $field_key;
        if (isset($_POST[$form_key])) {
            $form_data['source_config'][$field_key] = sanitize_text_field($_POST[$form_key]);
        }
    }
    
    // Validate required fields
    $validation_errors = array();
    
    if (empty($form_data['slug'])) {
        $validation_errors[] = __('Series slug is required.', 'zc-data-manager');
    }
    
    if (empty($form_data['name'])) {
        $validation_errors[] = __('Series name is required.', 'zc-data-manager');
    }
    
    if (empty($form_data['source_type'])) {
        $validation_errors[] = __('Data source is required.', 'zc-data-manager');
    }
    
    // Validate source-specific requirements
    foreach ($config_fields as $field_key => $field_info) {
        if (!empty($field_info['required']) && empty($form_data['source_config'][$field_key])) {
            $validation_errors[] = sprintf(__('%s is required.', 'zc-data-manager'), $field_info['label']);
        }
    }
    
    // If no validation errors, save the series
    if (empty($validation_errors)) {
        $save_result = $database->save_series($form_data);
        
        if ($save_result['success']) {
            // Try to fetch initial data
            if (!$edit_mode || isset($_POST['refresh_data'])) {
                $fetch_result = $collector->fetch_series_data($form_data['slug']);
                
                if ($fetch_result['success']) {
                    $success_message = sprintf(
                        __('Series saved successfully with %d data points!', 'zc-data-manager'),
                        $fetch_result['data_count']
                    );
                } else {
                    $success_message = __('Series saved successfully, but initial data fetch failed: ', 'zc-data-manager') . $fetch_result['message'];
                }
            } else {
                $success_message = __('Series updated successfully!', 'zc-data-manager');
            }
            
            echo '<div class="notice notice-success"><p>' . $success_message . '</p></div>';
            
            // Update series data for display
            $series_data = $database->get_series_by_slug($form_data['slug']);
            $edit_mode = true;
            $series_slug = $form_data['slug'];
            
        } else {
            echo '<div class="notice notice-error"><p>' . $save_result['message'] . '</p></div>';
        }
    } else {
        echo '<div class="notice notice-error"><p>' . implode('<br>', $validation_errors) . '</p></div>';
    }
}

// Get available data sources
$available_sources = $collector->get_available_sources();
?>

<div class="wrap">
    <h1>
        <?php echo $edit_mode ? __('Edit Series', 'zc-data-manager') : __('Add New Series', 'zc-data-manager'); ?>
        <?php if ($edit_mode): ?>
            <span class="title-count"><?php echo esc_html($series_data['name']); ?></span>
        <?php endif; ?>
    </h1>
    
    <?php if ($edit_mode): ?>
        <p class="description">
            <?php _e('Editing existing data series. Changes will be saved immediately.', 'zc-data-manager'); ?>
        </p>
    <?php else: ?>
        <p class="description">
            <?php _e('Create a new data series by selecting a source and configuring the parameters below.', 'zc-data-manager'); ?>
        </p>
    <?php endif; ?>
    
    <form method="post" class="zc-form zc-add-series-form">
        <?php wp_nonce_field('zc_add_series', 'zc_nonce'); ?>
        
        <div class="zc-form-section">
            <h2><?php _e('Basic Information', 'zc-data-manager'); ?></h2>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="series_name"><?php _e('Series Name', 'zc-data-manager'); ?> <span class="required">*</span></label>
                    </th>
                    <td>
                        <input type="text" 
                               id="series_name" 
                               name="series_name" 
                               value="<?php echo $edit_mode ? esc_attr($series_data['name']) : ''; ?>" 
                               class="regular-text" 
                               required>
                        <p class="description"><?php _e('Descriptive name for this data series', 'zc-data-manager'); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="series_slug"><?php _e('Series Slug', 'zc-data-manager'); ?> <span class="required">*</span></label>
                    </th>
                    <td>
                        <input type="text" 
                               id="series_slug" 
                               name="series_slug" 
                               value="<?php echo $edit_mode ? esc_attr($series_data['slug']) : ''; ?>" 
                               class="regular-text" 
                               required
                               <?php echo $edit_mode ? 'readonly' : ''; ?>>
                        <p class="description">
                            <?php if ($edit_mode): ?>
                                <?php _e('Series slug cannot be changed after creation', 'zc-data-manager'); ?>
                            <?php else: ?>
                                <?php _e('Unique identifier for this series (lowercase, hyphens only)', 'zc-data-manager'); ?>
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="source_type"><?php _e('Data Source', 'zc-data-manager'); ?> <span class="required">*</span></label>
                    </th>
                    <td>
                        <select id="source_type" 
                                name="source_type" 
                                class="zc-source-type-select" 
                                required
                                <?php echo $edit_mode ? 'disabled' : ''; ?>>
                            <option value=""><?php _e('Select a data source...', 'zc-data-manager'); ?></option>
                            <?php foreach ($available_sources as $source_key => $source_info): ?>
                                <option value="<?php echo esc_attr($source_key); ?>" 
                                        <?php selected($edit_mode ? $series_data['source_type'] : '', $source_key); ?>>
                                    <?php echo esc_html($source_info['name']); ?>
                                    <?php if ($source_info['requires_api_key']): ?>
                                        <?php _e('(Requires API Key)', 'zc-data-manager'); ?>
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <?php if ($edit_mode): ?>
                            <input type="hidden" name="source_type" value="<?php echo esc_attr($series_data['source_type']); ?>">
                            <p class="description"><?php _e('Data source cannot be changed after creation', 'zc-data-manager'); ?></p>
                        <?php else: ?>
                            <p class="description zc-source-help"><?php _e('Choose the data source for this series', 'zc-data-manager'); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><?php _e('Status', 'zc-data-manager'); ?></th>
                    <td>
                        <fieldset>
                            <label>
                                <input type="checkbox" 
                                       name="is_active" 
                                       value="1" 
                                       <?php checked($edit_mode ? $series_data['is_active'] : 1); ?>>
                                <?php _e('Active (include in automatic updates)', 'zc-data-manager'); ?>
                            </label>
                        </fieldset>
                    </td>
                </tr>
            </table>
        </div>
        
        <!-- Dynamic Source Configuration Sections -->
        <?php foreach ($available_sources as $source_key => $source_info): ?>
            <?php
            $config_fields = $collector->get_source_config_fields($source_key);
            $show_section = $edit_mode && $series_data['source_type'] === $source_key;
            ?>
            
            <div class="zc-form-section zc-source-config zc-source-config-<?php echo esc_attr($source_key); ?>" 
                 <?php echo !$show_section ? 'style="display: none;"' : ''; ?>>
                
                <h2>
                    <?php echo esc_html($source_info['name']); ?> <?php _e('Configuration', 'zc-data-manager'); ?>
                    <button type="button" class="button button-secondary zc-test-connection">
                        <?php _e('Test Connection', 'zc-data-manager'); ?>
                    </button>
                </h2>
                
                <p class="description"><?php echo esc_html($source_info['description']); ?></p>
                
                <?php if (!empty($config_fields)): ?>
                    <table class="form-table">
                        <?php foreach ($config_fields as $field_key => $field_config): ?>
                            <?php
                            $field_name = $source_key . '_' . $field_key;
                            $field_value = '';
                            
                            if ($edit_mode && $series_data['source_type'] === $source_key && isset($series_data['source_config'][$field_key])) {
                                $field_value = $series_data['source_config'][$field_key];
                            }
                            ?>
                            
                            <tr>
                                <th scope="row">
                                    <label for="<?php echo esc_attr($field_name); ?>">
                                        <?php echo esc_html($field_config['label']); ?>
                                        <?php if (!empty($field_config['required'])): ?>
                                            <span class="required">*</span>
                                        <?php endif; ?>
                                    </label>
                                </th>
                                <td>
                                    <?php if ($field_config['type'] === 'select'): ?>
                                        <select id="<?php echo esc_attr($field_name); ?>" 
                                                name="<?php echo esc_attr($field_name); ?>"
                                                <?php echo !empty($field_config['required']) ? 'required' : ''; ?>>
                                            <?php foreach ($field_config['options'] as $option_value => $option_label): ?>
                                                <option value="<?php echo esc_attr($option_value); ?>" 
                                                        <?php selected($field_value, $option_value); ?>>
                                                    <?php echo esc_html($option_label); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php elseif ($field_config['type'] === 'textarea'): ?>
                                        <textarea id="<?php echo esc_attr($field_name); ?>" 
                                                  name="<?php echo esc_attr($field_name); ?>"
                                                  rows="4" 
                                                  cols="50"
                                                  placeholder="<?php echo esc_attr($field_config['placeholder'] ?? ''); ?>"
                                                  <?php echo !empty($field_config['required']) ? 'required' : ''; ?>><?php echo esc_textarea($field_value); ?></textarea>
                                    <?php else: ?>
                                        <input type="<?php echo esc_attr($field_config['type']); ?>" 
                                               id="<?php echo esc_attr($field_name); ?>" 
                                               name="<?php echo esc_attr($field_name); ?>" 
                                               value="<?php echo esc_attr($field_value); ?>"
                                               placeholder="<?php echo esc_attr($field_config['placeholder'] ?? ''); ?>"
                                               class="regular-text"
                                               <?php if (isset($field_config['min'])): ?>min="<?php echo esc_attr($field_config['min']); ?>"<?php endif; ?>
                                               <?php if (isset($field_config['max'])): ?>max="<?php echo esc_attr($field_config['max']); ?>"<?php endif; ?>
                                               <?php echo !empty($field_config['required']) ? 'required' : ''; ?>>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($field_config['description'])): ?>
                                        <p class="description"><?php echo esc_html($field_config['description']); ?></p>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                    
                    <p class="submit-section">
                        <button type="button" class="button zc-preview-data">
                            <?php _e('Preview Data', 'zc-data-manager'); ?>
                        </button>
                    </p>
                <?php else: ?>
                    <p><?php _e('This data source requires no additional configuration.', 'zc-data-manager'); ?></p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        
        <!-- Submit Section -->
        <div class="zc-form-section">
            <h2><?php _e('Save Options', 'zc-data-manager'); ?></h2>
            
            <?php if ($edit_mode): ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Data Refresh', 'zc-data-manager'); ?></th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="checkbox" name="refresh_data" value="1" checked>
                                    <?php _e('Refresh data after saving changes', 'zc-data-manager'); ?>
                                </label>
                                <p class="description"><?php _e('Fetch latest data from the source after updating configuration', 'zc-data-manager'); ?></p>
                            </fieldset>
                        </td>
                    </tr>
                </table>
            <?php endif; ?>
            
            <p class="submit">
                <input type="submit" 
                       name="submit" 
                       class="button button-primary" 
                       value="<?php echo $edit_mode ? __('Update Series', 'zc-data-manager') : __('Create Series', 'zc-data-manager'); ?>">
                
                <a href="<?php echo admin_url('admin.php?page=zc-data-series'); ?>" class="button button-secondary">
                    <?php _e('Cancel', 'zc-data-manager'); ?>
                </a>
            </p>
        </div>
    </form>
    
    <?php if ($edit_mode && $series_data): ?>
        <!-- Series Information -->
        <div class="zc-series-info-section">
            <h2><?php _e('Series Information', 'zc-data-manager'); ?></h2>
            
            <div class="zc-info-cards">
                <div class="zc-info-card">
                    <h3><?php _e('Data Points', 'zc-data-manager'); ?></h3>
                    <p class="zc-info-value">
                        <?php
                        global $wpdb;
                        $tables = $database->get_table_names();
                        $data_count = $wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(*) FROM {$tables['observations']} WHERE series_slug = %s",
                            $series_slug
                        ));
                        echo number_format_i18n($data_count);
                        ?>
                    </p>
                </div>
                
                <div class="zc-info-card">
                    <h3><?php _e('Last Updated', 'zc-data-manager'); ?></h3>
                    <p class="zc-info-value">
                        <?php 
                        if ($series_data['last_updated']) {
                            echo human_time_diff(strtotime($series_data['last_updated']), current_time('timestamp')) . ' ' . __('ago', 'zc-data-manager');
                        } else {
                            _e('Never', 'zc-data-manager');
                        }
                        ?>
                    </p>
                </div>
                
                <div class="zc-info-card">
                    <h3><?php _e('Date Range', 'zc-data-manager'); ?></h3>
                    <p class="zc-info-value">
                        <?php
                        $date_range = $wpdb->get_row($wpdb->prepare(
                            "SELECT MIN(obs_date) as start_date, MAX(obs_date) as end_date 
                             FROM {$tables['observations']} WHERE series_slug = %s",
                            $series_slug
                        ), ARRAY_A);
                        
                        if ($date_range && $date_range['start_date']) {
                            echo date_i18n('M j, Y', strtotime($date_range['start_date'])) . ' - ' . 
                                 date_i18n('M j, Y', strtotime($date_range['end_date']));
                        } else {
                            _e('No data', 'zc-data-manager');
                        }
                        ?>
                    </p>
                </div>
                
                <div class="zc-info-card">
                    <h3><?php _e('Status', 'zc-data-manager'); ?></h3>
                    <p class="zc-info-value">
                        <span class="zc-status-badge zc-status-<?php echo $series_data['is_active'] ? 'active' : 'inactive'; ?>">
                            <?php echo $series_data['is_active'] ? __('Active', 'zc-data-manager') : __('Inactive', 'zc-data-manager'); ?>
                        </span>
                    </p>
                </div>
            </div>
            
            <!-- Quick Actions -->
            <div class="zc-quick-actions">
                <h3><?php _e('Quick Actions', 'zc-data-manager'); ?></h3>
                <p>
                    <button type="button" class="button zc-refresh-series" data-slug="<?php echo esc_attr($series_slug); ?>">
                        <?php _e('Refresh Data Now', 'zc-data-manager'); ?>
                    </button>
                    
                    <button type="button" class="button zc-copy-shortcode" data-text="[zc_chart series=&quot;<?php echo esc_attr($series_slug); ?>&quot;]">
                        <?php _e('Copy Chart Shortcode', 'zc-data-manager'); ?>
                    </button>
                </p>
                
                <p class="description">
                    <?php _e('Use the shortcode above to display this data series in posts, pages, or widgets with the ZC Charts plugin.', 'zc-data-manager'); ?>
                </p>
            </div>
        </div>
    <?php endif; ?>
</div>

<style>
/* Add Series Form Styles */
.zc-add-series-form {
    max-width: 800px;
}

.zc-form-section {
    background: #fff;
    border: 1px solid #ccd0d4;
    margin: 20px 0;
    padding: 20px;
}

.zc-form-section h2 {
    margin-top: 0;
    border-bottom: 1px solid #e0e0e0;
    padding-bottom: 10px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.zc-form-section .form-table {
    margin-bottom: 0;
}

.required {
    color: #d63638;
}

.submit-section {
    text-align: center;
    margin: 15px 0;
    padding-top: 15px;
    border-top: 1px solid #e0e0e0;
}

.zc-series-info-section {
    background: #f0f6fc;
    border: 1px solid #c3d4e6;
    margin: 20px 0;
    padding: 20px;
}

.zc-info-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin: 20px 0;
}

.zc-info-card {
    background: #fff;
    border: 1px solid #c3d4e6;
    padding: 15px;
    border-radius: 4px;
    text-align: center;
}

.zc-info-card h3 {
    margin: 0 0 10px 0;
    font-size: 14px;
    color: #50575e;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.zc-info-value {
    font-size: 24px;
    font-weight: 600;
    margin: 0;
    color: #1d2327;
}

.zc-status-badge {
    padding: 4px 8px;
    border-radius: 3px;
    font-size: 12px;
    font-weight: 500;
    text-transform: uppercase;
}

.zc-status-active {
    background: #00a32a;
    color: #fff;
}

.zc-status-inactive {
    background: #8c8f94;
    color: #fff;
}

.zc-quick-actions {
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid #c3d4e6;
}

.zc-quick-actions h3 {
    margin-bottom: 10px;
}

/* Auto-generate slug from name */
.zc-auto-slug {
    color: #8c8f94;
    font-style: italic;
}

/* Loading states */
.zc-loading {
    position: relative;
    color: transparent !important;
}

.zc-loading::after {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    margin: -8px 0 0 -8px;
    width: 16px;
    height: 16px;
    border: 2px solid #f3f3f3;
    border-top: 2px solid #0073aa;
    border-radius: 50%;
    animation: zc-spin 1s linear infinite;
}

@keyframes zc-spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* Responsive */
@media (max-width: 768px) {
    .zc-info-cards {
        grid-template-columns: 1fr;
    }
    
    .zc-form-section h2 {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }
}
</style>

<script>
jQuery(document).ready(function($) {
    // Auto-generate slug from name (only for new series)
    <?php if (!$edit_mode): ?>
    $('#series_name').on('input', function() {
        var name = $(this).val();
        var slug = name.toLowerCase()
                      .replace(/[^a-z0-9\s-]/g, '')
                      .replace(/\s+/g, '-')
                      .replace(/-+/g, '-')
                      .replace(/^-|-$/g, '');
        $('#series_slug').val(slug);
    });
    <?php endif; ?>
    
    // Show/hide source configuration sections
    $('.zc-source-type-select').on('change', function() {
        var selectedSource = $(this).val();
        
        // Hide all source config sections
        $('.zc-source-config').hide();
        
        // Show selected source section
        if (selectedSource) {
            $('.zc-source-config-' + selectedSource).show();
        }
    });
    
    // Initialize with current selection
    $('.zc-source-type-select').trigger('change');
});
</script>