<?php
/**
 * ZC Data Manager - Settings Admin Page
 * General plugin settings and configuration
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
$cron_manager = ZC_Cron_Manager::get_instance();

// Handle form submission
if (isset($_POST['submit']) && wp_verify_nonce($_POST['zc_nonce'], 'zc_settings')) {
    $updated_settings = array();
    
    // Auto Update Settings
    $auto_update = isset($_POST['auto_update']) ? 1 : 0;
    update_option('zc_dm_auto_update', $auto_update);
    $updated_settings[] = __('Auto Updates', 'zc-data-manager');
    
    // Error Email Notifications
    $error_emails = isset($_POST['error_emails']) ? 1 : 0;
    update_option('zc_dm_error_emails', $error_emails);
    $updated_settings[] = __('Error Notifications', 'zc-data-manager');
    
    // Admin Email
    if (isset($_POST['admin_email'])) {
        $admin_email = sanitize_email($_POST['admin_email']);
        if (is_email($admin_email)) {
            update_option('zc_dm_admin_email', $admin_email);
            $updated_settings[] = __('Admin Email', 'zc-data-manager');
        }
    }
    
    // Log Retention Days
    if (isset($_POST['log_retention_days'])) {
        $retention_days = intval($_POST['log_retention_days']);
        if ($retention_days >= 1 && $retention_days <= 365) {
            update_option('zc_dm_log_retention_days', $retention_days);
            $updated_settings[] = __('Log Retention', 'zc-data-manager');
        }
    }
    
    // Update/reschedule cron based on auto-update setting
    if ($auto_update) {
        $cron_manager->schedule_events();
    } else {
        $cron_manager->clear_scheduled_events();
    }
    
    if (!empty($updated_settings)) {
        echo '<div class="notice notice-success"><p>' . 
             sprintf(__('Settings updated: %s', 'zc-data-manager'), implode(', ', $updated_settings)) . 
             '</p></div>';
    }
}

// Handle reset settings
if (isset($_POST['reset']) && wp_verify_nonce($_POST['zc_nonce'], 'zc_settings')) {
    // Reset to default values
    update_option('zc_dm_auto_update', 1);
    update_option('zc_dm_error_emails', 1);
    update_option('zc_dm_admin_email', get_option('admin_email'));
    update_option('zc_dm_log_retention_days', 30);
    
    // Reschedule cron events
    $cron_manager->reschedule_all_events();
    
    echo '<div class="notice notice-success"><p>' . 
         __('Settings have been reset to defaults.', 'zc-data-manager') . 
         '</p></div>';
}

// Get current values
$auto_update = get_option('zc_dm_auto_update', 1);
$error_emails = get_option('zc_dm_error_emails', 1);
$admin_email = get_option('zc_dm_admin_email', get_option('admin_email'));
$log_retention_days = get_option('zc_dm_log_retention_days', 30);

// Get cron status
$cron_status = $cron_manager->get_cron_status();
?>

<div class="wrap">
    <h1><?php _e('ZC Data Manager Settings', 'zc-data-manager'); ?></h1>
    
    <div class="zc-settings-tabs">
        <nav class="nav-tab-wrapper">
            <a href="#general" class="nav-tab nav-tab-active" data-tab="general">
                <?php _e('General', 'zc-data-manager'); ?>
            </a>
            <a href="#automation" class="nav-tab" data-tab="automation">
                <?php _e('Automation', 'zc-data-manager'); ?>
            </a>
            <a href="#notifications" class="nav-tab" data-tab="notifications">
                <?php _e('Notifications', 'zc-data-manager'); ?>
            </a>
            <a href="#maintenance" class="nav-tab" data-tab="maintenance">
                <?php _e('Maintenance', 'zc-data-manager'); ?>
            </a>
            <a href="#system" class="nav-tab" data-tab="system">
                <?php _e('System Info', 'zc-data-manager'); ?>
            </a>
        </nav>
    </div>
    
    <form method="post" class="zc-form zc-settings-form">
        <?php wp_nonce_field('zc_settings', 'zc_nonce'); ?>
        
        <!-- General Settings Tab -->
        <div id="general-tab" class="zc-tab-content zc-tab-active">
            <div class="zc-settings-section">
                <h2><?php _e('General Settings', 'zc-data-manager'); ?></h2>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Plugin Status', 'zc-data-manager'); ?></th>
                        <td>
                            <p>
                                <strong><?php _e('Version:', 'zc-data-manager'); ?></strong> <?php echo ZC_DATA_MANAGER_VERSION; ?>
                            </p>
                            <p>
                                <strong><?php _e('Database:', 'zc-data-manager'); ?></strong>
                                <?php
                                $db_version = get_option('zc_dm_db_version', '');
                                if ($db_version) {
                                    echo '<span style="color: #00a32a;">✓ ' . sprintf(__('Installed (v%s)', 'zc-data-manager'), $db_version) . '</span>';
                                } else {
                                    echo '<span style="color: #d63638;">✗ ' . __('Not installed', 'zc-data-manager') . '</span>';
                                }
                                ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="admin_email"><?php _e('Admin Email', 'zc-data-manager'); ?></label>
                        </th>
                        <td>
                            <input type="email" 
                                   id="admin_email" 
                                   name="admin_email" 
                                   value="<?php echo esc_attr($admin_email); ?>" 
                                   class="regular-text">
                            <p class="description">
                                <?php _e('Email address for notifications and alerts', 'zc-data-manager'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        
        <!-- Automation Tab -->
        <div id="automation-tab" class="zc-tab-content">
            <div class="zc-settings-section">
                <h2><?php _e('Automation Settings', 'zc-data-manager'); ?></h2>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Automatic Updates', 'zc-data-manager'); ?></th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="checkbox" 
                                           name="auto_update" 
                                           value="1" 
                                           <?php checked($auto_update); ?>>
                                    <?php _e('Enable automatic data updates', 'zc-data-manager'); ?>
                                </label>
                                <p class="description">
                                    <?php _e('Automatically fetch new data from sources using WordPress cron', 'zc-data-manager'); ?>
                                </p>
                            </fieldset>
                        </td>
                    </tr>
                </table>
                
                <?php if ($auto_update): ?>
                    <div class="zc-cron-status">
                        <h3><?php _e('Scheduled Updates Status', 'zc-data-manager'); ?></h3>
                        
                        <table class="widefat">
                            <thead>
                                <tr>
                                    <th><?php _e('Update Type', 'zc-data-manager'); ?></th>
                                    <th><?php _e('Schedule', 'zc-data-manager'); ?></th>
                                    <th><?php _e('Next Run', 'zc-data-manager'); ?></th>
                                    <th><?php _e('Status', 'zc-data-manager'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($cron_status['scheduled_events'] as $event_type => $event_info): ?>
                                    <tr>
                                        <td><strong><?php echo ucfirst($event_type); ?></strong></td>
                                        <td><?php echo ucfirst($event_type) . 'ly'; ?></td>
                                        <td>
                                            <?php if ($event_info['scheduled']): ?>
                                                <?php echo $event_info['next_run']; ?>
                                                <br><small>(<?php echo sprintf(__('in %s', 'zc-data-manager'), $event_info['human_time']); ?>)</small>
                                            <?php else: ?>
                                                <span style="color: #d63638;"><?php _e('Not scheduled', 'zc-data-manager'); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($event_info['scheduled']): ?>
                                                <span style="color: #00a32a;">✓ <?php _e('Active', 'zc-data-manager'); ?></span>
                                            <?php else: ?>
                                                <span style="color: #d63638;">✗ <?php _e('Inactive', 'zc-data-manager'); ?></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        
                        <p class="description">
                            <strong><?php _e('WordPress Cron:', 'zc-data-manager'); ?></strong>
                            <?php if ($cron_status['wordpress_cron_enabled']): ?>
                                <span style="color: #00a32a;">✓ <?php _e('Working', 'zc-data-manager'); ?></span>
                            <?php else: ?>
                                <span style="color: #d63638;">✗ <?php _e('Disabled', 'zc-data-manager'); ?></span>
                                - <?php _e('Your server has disabled WordPress cron. Automatic updates will not work.', 'zc-data-manager'); ?>
                            <?php endif; ?>
                        </p>
                        
                        <p>
                            <button type="button" class="button zc-test-cron">
                                <?php _e('Test Cron System', 'zc-data-manager'); ?>
                            </button>
                            <button type="button" class="button zc-reschedule-cron">
                                <?php _e('Reschedule All Events', 'zc-data-manager'); ?>
                            </button>
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Notifications Tab -->
        <div id="notifications-tab" class="zc-tab-content">
            <div class="zc-settings-section">
                <h2><?php _e('Notification Settings', 'zc-data-manager'); ?></h2>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Error Notifications', 'zc-data-manager'); ?></th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="checkbox" 
                                           name="error_emails" 
                                           value="1" 
                                           <?php checked($error_emails); ?>>
                                    <?php _e('Send email alerts for data fetch errors', 'zc-data-manager'); ?>
                                </label>
                                <p class="description">
                                    <?php _e('Get notified by email when data fetching fails', 'zc-data-manager'); ?>
                                </p>
                            </fieldset>
                        </td>
                    </tr>
                </table>
                
                <h3><?php _e('Email Templates', 'zc-data-manager'); ?></h3>
                <p class="description">
                    <?php _e('Error notifications will include the following information:', 'zc-data-manager'); ?>
                </p>
                
                <div class="zc-email-preview">
                    <h4><?php _e('Sample Error Email:', 'zc-data-manager'); ?></h4>
                    <div class="zc-email-content">
                        <strong><?php _e('Subject:', 'zc-data-manager'); ?></strong> 
                        [<?php echo get_bloginfo('name'); ?>] <?php _e('ZC Data Manager Error', 'zc-data-manager'); ?><br>
                        
                        <strong><?php _e('Content:', 'zc-data-manager'); ?></strong><br>
                        <?php _e('An error occurred in ZC Data Manager:', 'zc-data-manager'); ?><br><br>
                        
                        <?php _e('Series: GDP-US', 'zc-data-manager'); ?><br>
                        <?php _e('Source: FRED', 'zc-data-manager'); ?><br>
                        <?php _e('Action: Data fetch', 'zc-data-manager'); ?><br>
                        <?php _e('Message: API rate limit exceeded', 'zc-data-manager'); ?><br>
                        <?php _e('Time: 2024-03-15 10:30:00', 'zc-data-manager'); ?><br><br>
                        
                        <?php _e('Please check the logs for more details.', 'zc-data-manager'); ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Maintenance Tab -->
        <div id="maintenance-tab" class="zc-tab-content">
            <div class="zc-settings-section">
                <h2><?php _e('Maintenance Settings', 'zc-data-manager'); ?></h2>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="log_retention_days"><?php _e('Log Retention', 'zc-data-manager'); ?></label>
                        </th>
                        <td>
                            <input type="number" 
                                   id="log_retention_days" 
                                   name="log_retention_days" 
                                   value="<?php echo esc_attr($log_retention_days); ?>" 
                                   min="1" 
                                   max="365" 
                                   class="small-text"> 
                            <?php _e('days', 'zc-data-manager'); ?>
                            <p class="description">
                                <?php _e('Automatically delete logs older than this many days (1-365)', 'zc-data-manager'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <h3><?php _e('Manual Maintenance', 'zc-data-manager'); ?></h3>
                <p class="description">
                    <?php _e('Perform maintenance tasks manually:', 'zc-data-manager'); ?>
                </p>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Clean Up Logs', 'zc-data-manager'); ?></th>
                        <td>
                            <button type="button" class="button zc-cleanup-logs">
                                <?php _e('Delete Old Logs Now', 'zc-data-manager'); ?>
                            </button>
                            <p class="description">
                                <?php _e('Remove all logs older than the retention period', 'zc-data-manager'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row"><?php _e('Reset Plugin', 'zc-data-manager'); ?></th>
                        <td>
                            <button type="submit" name="reset" class="button button-secondary" 
                                    onclick="return confirm('<?php _e('Are you sure you want to reset all settings to defaults?', 'zc-data-manager'); ?>')">
                                <?php _e('Reset to Defaults', 'zc-data-manager'); ?>
                            </button>
                            <p class="description">
                                <?php _e('Reset all plugin settings to their default values', 'zc-data-manager'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        
        <!-- System Info Tab -->
        <div id="system-tab" class="zc-tab-content">
            <div class="zc-settings-section">
                <h2><?php _e('System Information', 'zc-data-manager'); ?></h2>
                
                <?php
                // Get system info
                global $wpdb;
                $php_version = PHP_VERSION;
                $wp_version = get_bloginfo('version');
                $mysql_version = $wpdb->db_version();
                $server_info = $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown';
                $memory_limit = ini_get('memory_limit');
                $time_limit = ini_get('max_execution_time');
                $upload_max = ini_get('upload_max_filesize');
                ?>
                
                <div class="zc-system-info">
                    <h3><?php _e('WordPress Environment', 'zc-data-manager'); ?></h3>
                    <table class="widefat">
                        <tr>
                            <td><strong><?php _e('WordPress Version:', 'zc-data-manager'); ?></strong></td>
                            <td><?php echo $wp_version; ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php _e('PHP Version:', 'zc-data-manager'); ?></strong></td>
                            <td>
                                <?php echo $php_version; ?>
                                <?php if (version_compare($php_version, '7.4', '<')): ?>
                                    <span style="color: #d63638;"><?php _e('(Update recommended)', 'zc-data-manager'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><strong><?php _e('MySQL Version:', 'zc-data-manager'); ?></strong></td>
                            <td><?php echo $mysql_version; ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php _e('Server:', 'zc-data-manager'); ?></strong></td>
                            <td><?php echo $server_info; ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php _e('Memory Limit:', 'zc-data-manager'); ?></strong></td>
                            <td><?php echo $memory_limit; ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php _e('Execution Time Limit:', 'zc-data-manager'); ?></strong></td>
                            <td><?php echo $time_limit; ?> <?php _e('seconds', 'zc-data-manager'); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php _e('Upload Max Size:', 'zc-data-manager'); ?></strong></td>
                            <td><?php echo $upload_max; ?></td>
                        </tr>
                    </table>
                    
                    <h3><?php _e('Plugin Status', 'zc-data-manager'); ?></h3>
                    <?php
                    $database = ZC_Database::get_instance();
                    $stats = $database->get_dashboard_stats();
                    ?>
                    <table class="widefat">
                        <tr>
                            <td><strong><?php _e('Total Series:', 'zc-data-manager'); ?></strong></td>
                            <td><?php echo number_format_i18n($stats['total_series']); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php _e('Active Series:', 'zc-data-manager'); ?></strong></td>
                            <td><?php echo number_format_i18n($stats['active_series']); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php _e('Total Data Points:', 'zc-data-manager'); ?></strong></td>
                            <td><?php echo number_format_i18n($stats['total_observations']); ?></td>
                        </tr>
                        <tr>
                            <td><strong><?php _e('Recent Errors (7 days):', 'zc-data-manager'); ?></strong></td>
                            <td>
                                <?php echo number_format_i18n($stats['recent_errors']); ?>
                                <?php if ($stats['recent_errors'] > 0): ?>
                                    <a href="<?php echo admin_url('admin.php?page=zc-data-logs'); ?>">
                                        <?php _e('View Logs', 'zc-data-manager'); ?>
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                    
                    <h3><?php _e('Required PHP Extensions', 'zc-data-manager'); ?></h3>
                    <table class="widefat">
                        <?php
                        $extensions = array(
                            'curl' => 'cURL (for API requests)',
                            'json' => 'JSON (for data parsing)',
                            'mysqli' => 'MySQLi (for database access)',
                            'date' => 'Date/Time (for date handling)'
                        );
                        
                        foreach ($extensions as $ext => $description):
                            $loaded = extension_loaded($ext);
                        ?>
                            <tr>
                                <td><strong><?php echo $description; ?>:</strong></td>
                                <td>
                                    <?php if ($loaded): ?>
                                        <span style="color: #00a32a;">✓ <?php _e('Loaded', 'zc-data-manager'); ?></span>
                                    <?php else: ?>
                                        <span style="color: #d63638;">✗ <?php _e('Missing', 'zc-data-manager'); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </div>
        </div>
        
        <p class="submit">
            <input type="submit" name="submit" class="button button-primary" value="<?php _e('Save Settings', 'zc-data-manager'); ?>">
        </p>
    </form>
</div>

<style>
/* Settings Page Styles */
.zc-settings-tabs {
    margin: 20px 0;
}

.zc-tab-content {
    display: none;
}

.zc-tab-content.zc-tab-active {
    display: block;
}

.zc-settings-section {
    background: #fff;
    border: 1px solid #ccd0d4;
    margin: 20px 0;
    padding: 20px;
}

.zc-settings-section h2 {
    margin-top: 0;
    border-bottom: 1px solid #e0e0e0;
    padding-bottom: 10px;
}

.zc-cron-status {
    background: #f0f6fc;
    border: 1px solid #c3d4e6;
    padding: 15px;
    margin-top: 20px;
    border-radius: 4px;
}

.zc-cron-status h3 {
    margin-top: 0;
    color: #0073aa;
}

.zc-email-preview {
    background: #f6f7f7;
    border: 1px solid #ddd;
    padding: 15px;
    margin-top: 15px;
    border-radius: 4px;
}

.zc-email-content {
    background: #fff;
    border: 1px solid #ddd;
    padding: 15px;
    margin-top: 10px;
    font-family: monospace;
    white-space: pre-line;
    font-size: 12px;
}

.zc-system-info table {
    margin-bottom: 20px;
}

.zc-system-info td {
    padding: 8px 12px;
}

.zc-system-info tr:nth-child(even) {
    background: #f9f9f9;
}

/* Responsive */
@media (max-width: 768px) {
    .nav-tab-wrapper {
        border-bottom: 1px solid #ccc;
    }
    
    .nav-tab {
        display: block;
        width: 100%;
        margin: 0;
        border-radius: 0;
        border-right: none;
        border-left: none;
    }
}
</style>

<script>
jQuery(document).ready(function($) {
    // Tab switching
    $('.nav-tab').on('click', function(e) {
        e.preventDefault();
        
        var targetTab = $(this).data('tab');
        
        // Update tab states
        $('.nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        
        // Show/hide content
        $('.zc-tab-content').removeClass('zc-tab-active');
        $('#' + targetTab + '-tab').addClass('zc-tab-active');
    });
    
    // Test cron system
    $('.zc-test-cron').on('click', function() {
        var $button = $(this);
        var originalText = $button.text();
        
        $button.text('<?php _e("Testing...", "zc-data-manager"); ?>').prop('disabled', true);
        
        $.post(ajaxurl, {
            action: 'zc_manual_cron',
            nonce: zcDataManager.nonce,
            job_type: 'test'
        })
        .done(function(response) {
            alert(response.message || '<?php _e("Cron test completed", "zc-data-manager"); ?>');
        })
        .always(function() {
            $button.text(originalText).prop('disabled', false);
        });
    });
    
    // Reschedule cron events
    $('.zc-reschedule-cron').on('click', function() {
        if (!confirm('<?php _e("Reschedule all cron events?", "zc-data-manager"); ?>')) {
            return;
        }
        
        var $button = $(this);
        var originalText = $button.text();
        
        $button.text('<?php _e("Rescheduling...", "zc-data-manager"); ?>').prop('disabled', true);
        
        $.post(ajaxurl, {
            action: 'zc_reschedule_cron',
            nonce: zcDataManager.nonce
        })
        .done(function(response) {
            if (response.success) {
                location.reload();
            } else {
                alert(response.message || '<?php _e("Failed to reschedule", "zc-data-manager"); ?>');
            }
        })
        .always(function() {
            $button.text(originalText).prop('disabled', false);
        });
    });
    
    // Cleanup logs
    $('.zc-cleanup-logs').on('click', function() {
        if (!confirm('<?php _e("Delete all old logs?", "zc-data-manager"); ?>')) {
            return;
        }
        
        var $button = $(this);
        var originalText = $button.text();
        
        $button.text('<?php _e("Cleaning...", "zc-data-manager"); ?>').prop('disabled', true);
        
        $.post(ajaxurl, {
            action: 'zc_cleanup_logs',
            nonce: zcDataManager.nonce
        })
        .done(function(response) {
            alert(response.message || '<?php _e("Logs cleaned", "zc-data-manager"); ?>');
        })
        .always(function() {
            $button.text(originalText).prop('disabled', false);
        });
    });
});
</script>