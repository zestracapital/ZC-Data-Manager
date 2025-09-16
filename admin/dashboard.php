<?php
/**
 * ZC Data Manager - Dashboard Admin Page
 * Main dashboard showing overview and statistics
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Check user permissions
if (!current_user_can('manage_options')) {
    wp_die(__('You do not have sufficient permissions to access this page.'));
}

// Get dashboard statistics
$database = ZC_Database::get_instance();
$collector = ZC_Data_Collector::get_instance();
$cron_manager = ZC_Cron_Manager::get_instance();

$stats = $database->get_dashboard_stats();
$cron_status = $cron_manager->get_cron_status();
$available_sources = $collector->get_available_sources();

// Get recent logs
$recent_logs = $database->get_logs(10, '', '', 7); // Last 10 logs from past 7 days

// Handle manual actions
if (isset($_POST['action']) && wp_verify_nonce($_POST['zc_nonce'], 'zc_dashboard_action')) {
    $action = sanitize_text_field($_POST['action']);
    
    switch ($action) {
        case 'refresh_all':
            $refresh_result = $collector->refresh_all_series();
            if ($refresh_result['success'] > 0) {
                echo '<div class="notice notice-success"><p>' . 
                     sprintf(__('Refreshed %d series successfully (%d failed)', 'zc-data-manager'), 
                             $refresh_result['success'], $refresh_result['failed']) . 
                     '</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>' . __('No series were refreshed successfully', 'zc-data-manager') . '</p></div>';
            }
            // Refresh stats after update
            $stats = $database->get_dashboard_stats();
            break;
            
        case 'test_cron':
            $cron_test = $cron_manager->test_cron();
            $notice_class = $cron_test['success'] ? 'notice-success' : 'notice-error';
            echo '<div class="notice ' . $notice_class . '"><p>' . $cron_test['message'] . '</p></div>';
            break;
            
        case 'manual_hourly':
            $cron_result = $cron_manager->manual_trigger('hourly');
            $notice_class = $cron_result['success'] ? 'notice-success' : 'notice-error';
            echo '<div class="notice ' . $notice_class . '"><p>' . $cron_result['message'] . '</p></div>';
            break;
            
        case 'manual_daily':
            $cron_result = $cron_manager->manual_trigger('daily');
            $notice_class = $cron_result['success'] ? 'notice-success' : 'notice-error';
            echo '<div class="notice ' . $notice_class . '"><p>' . $cron_result['message'] . '</p></div>';
            break;
    }
}
?>

<div class="wrap zc-dashboard">
    <h1><?php _e('ZC Data Manager Dashboard', 'zc-data-manager'); ?></h1>
    
    <!-- Quick Stats Cards -->
    <div class="zc-stats-grid">
        <div class="zc-stat-card">
            <div class="zc-stat-icon">
                <span class="dashicons dashicons-chart-line"></span>
            </div>
            <div class="zc-stat-content">
                <h3><?php echo number_format($stats['total_series']); ?></h3>
                <p><?php _e('Total Series', 'zc-data-manager'); ?></p>
                <small><?php echo sprintf(__('%d active', 'zc-data-manager'), $stats['active_series']); ?></small>
            </div>
        </div>
        
        <div class="zc-stat-card">
            <div class="zc-stat-icon">
                <span class="dashicons dashicons-database-view"></span>
            </div>
            <div class="zc-stat-content">
                <h3><?php echo number_format($stats['total_observations']); ?></h3>
                <p><?php _e('Data Points', 'zc-data-manager'); ?></p>
                <small><?php echo $stats['latest_observation'] ? date_i18n('M j, Y', strtotime($stats['latest_observation'])) : __('No data', 'zc-data-manager'); ?></small>
            </div>
        </div>
        
        <div class="zc-stat-card <?php echo $stats['recent_errors'] > 0 ? 'zc-stat-warning' : 'zc-stat-success'; ?>">
            <div class="zc-stat-icon">
                <span class="dashicons dashicons-<?php echo $stats['recent_errors'] > 0 ? 'warning' : 'yes-alt'; ?>"></span>
            </div>
            <div class="zc-stat-content">
                <h3><?php echo number_format($stats['recent_errors']); ?></h3>
                <p><?php _e('Recent Errors', 'zc-data-manager'); ?></p>
                <small><?php _e('Last 7 days', 'zc-data-manager'); ?></small>
            </div>
        </div>
        
        <div class="zc-stat-card <?php echo $cron_status['auto_update_enabled'] ? 'zc-stat-success' : 'zc-stat-inactive'; ?>">
            <div class="zc-stat-icon">
                <span class="dashicons dashicons-<?php echo $cron_status['auto_update_enabled'] ? 'update' : 'controls-pause'; ?>"></span>
            </div>
            <div class="zc-stat-content">
                <h3><?php echo $cron_status['auto_update_enabled'] ? __('ON', 'zc-data-manager') : __('OFF', 'zc-data-manager'); ?></h3>
                <p><?php _e('Auto Updates', 'zc-data-manager'); ?></p>
                <small><?php echo $cron_status['wordpress_cron_enabled'] ? __('WP Cron OK', 'zc-data-manager') : __('WP Cron Disabled', 'zc-data-manager'); ?></small>
            </div>
        </div>
    </div>
    
    <!-- Quick Actions -->
    <div class="zc-dashboard-section">
        <h2><?php _e('Quick Actions', 'zc-data-manager'); ?></h2>
        <div class="zc-actions-grid">
            <div class="zc-action-card">
                <h3><?php _e('Add New Series', 'zc-data-manager'); ?></h3>
                <p><?php _e('Create a new data series from available sources', 'zc-data-manager'); ?></p>
                <a href="<?php echo admin_url('admin.php?page=zc-add-series'); ?>" class="button button-primary">
                    <?php _e('Add Series', 'zc-data-manager'); ?>
                </a>
            </div>
            
            <div class="zc-action-card">
                <h3><?php _e('Manage Series', 'zc-data-manager'); ?></h3>
                <p><?php _e('View, edit, or delete existing data series', 'zc-data-manager'); ?></p>
                <a href="<?php echo admin_url('admin.php?page=zc-data-series'); ?>" class="button">
                    <?php _e('Manage Series', 'zc-data-manager'); ?>
                </a>
            </div>
            
            <div class="zc-action-card">
                <h3><?php _e('Configure Sources', 'zc-data-manager'); ?></h3>
                <p><?php _e('Set up API keys and data source settings', 'zc-data-manager'); ?></p>
                <a href="<?php echo admin_url('admin.php?page=zc-data-sources'); ?>" class="button">
                    <?php _e('Configure Sources', 'zc-data-manager'); ?>
                </a>
            </div>
        </div>
    </div>
    
    <!-- Data Sources Status -->
    <div class="zc-dashboard-section">
        <h2><?php _e('Data Sources Status', 'zc-data-manager'); ?></h2>
        <div class="zc-sources-grid">
            <?php foreach ($available_sources as $source_key => $source_info): ?>
                <?php
                $source_class = $source_info['class'];
                $is_configured = false;
                $status_class = 'zc-source-unconfigured';
                $status_text = __('Not Configured', 'zc-data-manager');
                
                if (class_exists($source_class)) {
                    $source_instance = new $source_class();
                    if (method_exists($source_instance, 'is_configured')) {
                        $is_configured = $source_instance->is_configured();
                        if ($is_configured) {
                            $status_class = 'zc-source-configured';
                            $status_text = __('Configured', 'zc-data-manager');
                        }
                    }
                }
                ?>
                <div class="zc-source-card <?php echo $status_class; ?>">
                    <div class="zc-source-header">
                        <h4><?php echo esc_html($source_info['name']); ?></h4>
                        <span class="zc-source-status"><?php echo $status_text; ?></span>
                    </div>
                    <p><?php echo esc_html($source_info['description']); ?></p>
                    <?php if ($source_info['requires_api_key'] && !$is_configured): ?>
                        <small class="zc-source-note"><?php _e('Requires API key', 'zc-data-manager'); ?></small>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- Two Column Layout -->
    <div class="zc-dashboard-columns">
        
        <!-- Left Column: Recent Activity -->
        <div class="zc-dashboard-column">
            <div class="zc-dashboard-section">
                <h2><?php _e('Recent Activity', 'zc-data-manager'); ?></h2>
                <?php if (!empty($recent_logs)): ?>
                    <div class="zc-activity-list">
                        <?php foreach ($recent_logs as $log): ?>
                            <div class="zc-activity-item zc-activity-<?php echo esc_attr($log['status']); ?>">
                                <div class="zc-activity-icon">
                                    <span class="dashicons dashicons-<?php 
                                        echo $log['status'] === 'success' ? 'yes-alt' : 
                                            ($log['status'] === 'warning' ? 'warning' : 'dismiss'); 
                                    ?>"></span>
                                </div>
                                <div class="zc-activity-content">
                                    <div class="zc-activity-message">
                                        <?php if (!empty($log['series_slug'])): ?>
                                            <strong><?php echo esc_html($log['series_slug']); ?>:</strong>
                                        <?php endif; ?>
                                        <?php echo esc_html($log['action']); ?>
                                    </div>
                                    <div class="zc-activity-meta">
                                        <?php echo human_time_diff(strtotime($log['created_at']), current_time('timestamp')) . ' ' . __('ago', 'zc-data-manager'); ?>
                                        <?php if (!empty($log['source_type'])): ?>
                                            • <?php echo esc_html(ucfirst($log['source_type'])); ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($log['message']) && $log['status'] !== 'success'): ?>
                                        <div class="zc-activity-details">
                                            <?php echo esc_html($log['message']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="zc-activity-footer">
                        <a href="<?php echo admin_url('admin.php?page=zc-data-logs'); ?>">
                            <?php _e('View All Logs', 'zc-data-manager'); ?> →
                        </a>
                    </p>
                <?php else: ?>
                    <p class="zc-no-activity"><?php _e('No recent activity found.', 'zc-data-manager'); ?></p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Right Column: System Status & Manual Actions -->
        <div class="zc-dashboard-column">
            
            <!-- Cron Status -->
            <div class="zc-dashboard-section">
                <h2><?php _e('System Status', 'zc-data-manager'); ?></h2>
                
                <div class="zc-system-status">
                    <div class="zc-status-item">
                        <span class="zc-status-label"><?php _e('Auto Updates:', 'zc-data-manager'); ?></span>
                        <span class="zc-status-value <?php echo $cron_status['auto_update_enabled'] ? 'zc-status-on' : 'zc-status-off'; ?>">
                            <?php echo $cron_status['auto_update_enabled'] ? __('Enabled', 'zc-data-manager') : __('Disabled', 'zc-data-manager'); ?>
                        </span>
                    </div>
                    
                    <div class="zc-status-item">
                        <span class="zc-status-label"><?php _e('WordPress Cron:', 'zc-data-manager'); ?></span>
                        <span class="zc-status-value <?php echo $cron_status['wordpress_cron_enabled'] ? 'zc-status-on' : 'zc-status-off'; ?>">
                            <?php echo $cron_status['wordpress_cron_enabled'] ? __('Working', 'zc-data-manager') : __('Disabled', 'zc-data-manager'); ?>
                        </span>
                    </div>
                    
                    <?php if ($cron_status['auto_update_enabled']): ?>
                        <?php foreach ($cron_status['scheduled_events'] as $event => $info): ?>
                            <div class="zc-status-item">
                                <span class="zc-status-label"><?php echo ucfirst($event) . ' ' . __('Update:', 'zc-data-manager'); ?></span>
                                <span class="zc-status-value">
                                    <?php if ($info['scheduled']): ?>
                                        <?php echo sprintf(__('in %s', 'zc-data-manager'), $info['human_time']); ?>
                                    <?php else: ?>
                                        <span class="zc-status-error"><?php _e('Not scheduled', 'zc-data-manager'); ?></span>
                                    <?php endif; ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Manual Actions -->
            <div class="zc-dashboard-section">
                <h2><?php _e('Manual Actions', 'zc-data-manager'); ?></h2>
                
                <form method="post" class="zc-manual-actions">
                    <?php wp_nonce_field('zc_dashboard_action', 'zc_nonce'); ?>
                    
                    <p>
                        <button type="submit" name="action" value="refresh_all" class="button">
                            <span class="dashicons dashicons-update"></span>
                            <?php _e('Refresh All Series', 'zc-data-manager'); ?>
                        </button>
                        <small class="description"><?php _e('Manually fetch latest data for all active series', 'zc-data-manager'); ?></small>
                    </p>
                    
                    <p>
                        <button type="submit" name="action" value="test_cron" class="button">
                            <span class="dashicons dashicons-clock"></span>
                            <?php _e('Test Cron System', 'zc-data-manager'); ?>
                        </button>
                        <small class="description"><?php _e('Check if WordPress cron is working properly', 'zc-data-manager'); ?></small>
                    </p>
                    
                    <hr>
                    
                    <p>
                        <button type="submit" name="action" value="manual_hourly" class="button button-secondary">
                            <?php _e('Run Hourly Update', 'zc-data-manager'); ?>
                        </button>
                    </p>
                    
                    <p>
                        <button type="submit" name="action" value="manual_daily" class="button button-secondary">
                            <?php _e('Run Daily Update', 'zc-data-manager'); ?>
                        </button>
                    </p>
                </form>
            </div>
            
        </div>
        
    </div>
    
    <!-- Help Section -->
    <div class="zc-dashboard-section zc-help-section">
        <h2><?php _e('Getting Started', 'zc-data-manager'); ?></h2>
        <div class="zc-help-grid">
            <div class="zc-help-item">
                <h4><?php _e('1. Configure Data Sources', 'zc-data-manager'); ?></h4>
                <p><?php _e('Set up API keys for sources like FRED and Alpha Vantage in the Data Sources page.', 'zc-data-manager'); ?></p>
            </div>
            
            <div class="zc-help-item">
                <h4><?php _e('2. Add Data Series', 'zc-data-manager'); ?></h4>
                <p><?php _e('Create your first data series by selecting a source and configuring the parameters.', 'zc-data-manager'); ?></p>
            </div>
            
            <div class="zc-help-item">
                <h4><?php _e('3. Enable Auto Updates', 'zc-data-manager'); ?></h4>
                <p><?php _e('Configure automatic data updates in Settings to keep your data current.', 'zc-data-manager'); ?></p>
            </div>
            
            <div class="zc-help-item">
                <h4><?php _e('4. Install Charts Plugin', 'zc-data-manager'); ?></h4>
                <p><?php _e('Add the ZC Charts plugin to visualize your data with interactive charts.', 'zc-data-manager'); ?></p>
            </div>
        </div>
    </div>
    
</div>

<style>
/* Dashboard specific styles */
.zc-dashboard {
    max-width: 1200px;
}

.zc-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin: 20px 0;
}

.zc-stat-card {
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    padding: 20px;
    display: flex;
    align-items: center;
    gap: 15px;
}

.zc-stat-card.zc-stat-success { border-left: 4px solid #00a32a; }
.zc-stat-card.zc-stat-warning { border-left: 4px solid #dba617; }
.zc-stat-card.zc-stat-inactive { border-left: 4px solid #8c8f94; }

.zc-stat-icon {
    font-size: 24px;
    color: #2271b1;
}

.zc-stat-content h3 {
    margin: 0;
    font-size: 28px;
    font-weight: 600;
    color: #1d2327;
}

.zc-stat-content p {
    margin: 5px 0 0 0;
    font-weight: 500;
    color: #50575e;
}

.zc-stat-content small {
    color: #8c8f94;
}

.zc-dashboard-section {
    background: #fff;
    border: 1px solid #ccd0d4;
    margin: 20px 0;
    padding: 20px;
}

.zc-dashboard-section h2 {
    margin-top: 0;
    border-bottom: 1px solid #e0e0e0;
    padding-bottom: 10px;
}

.zc-actions-grid, .zc-sources-grid, .zc-help-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 15px;
    margin-top: 15px;
}

.zc-action-card, .zc-source-card, .zc-help-item {
    border: 1px solid #e0e0e0;
    padding: 15px;
    border-radius: 4px;
}

.zc-action-card h3, .zc-help-item h4 {
    margin-top: 0;
    margin-bottom: 10px;
}

.zc-source-card.zc-source-configured {
    border-left: 4px solid #00a32a;
}

.zc-source-card.zc-source-unconfigured {
    border-left: 4px solid #dba617;
}

.zc-source-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}

.zc-source-header h4 {
    margin: 0;
}

.zc-source-status {
    font-size: 12px;
    padding: 2px 8px;
    border-radius: 3px;
    background: #f0f0f1;
    color: #50575e;
}

.zc-dashboard-columns {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin: 20px 0;
}

.zc-dashboard-column .zc-dashboard-section {
    margin: 0 0 20px 0;
}

.zc-activity-list {
    max-height: 400px;
    overflow-y: auto;
}

.zc-activity-item {
    display: flex;
    gap: 10px;
    padding: 10px 0;
    border-bottom: 1px solid #f0f0f1;
}

.zc-activity-item:last-child {
    border-bottom: none;
}

.zc-activity-icon {
    color: #8c8f94;
}

.zc-activity-item.zc-activity-success .zc-activity-icon { color: #00a32a; }
.zc-activity-item.zc-activity-warning .zc-activity-icon { color: #dba617; }
.zc-activity-item.zc-activity-error .zc-activity-icon { color: #d63638; }

.zc-activity-content {
    flex: 1;
}

.zc-activity-message {
    font-weight: 500;
    margin-bottom: 5px;
}

.zc-activity-meta {
    font-size: 12px;
    color: #8c8f94;
    margin-bottom: 5px;
}

.zc-activity-details {
    font-size: 12px;
    color: #50575e;
    background: #f6f7f7;
    padding: 5px 8px;
    border-radius: 3px;
}

.zc-system-status {
    background: #f6f7f7;
    padding: 15px;
    border-radius: 4px;
}

.zc-status-item {
    display: flex;
    justify-content: space-between;
    margin-bottom: 8px;
}

.zc-status-item:last-child {
    margin-bottom: 0;
}

.zc-status-label {
    font-weight: 500;
    color: #50575e;
}

.zc-status-value.zc-status-on { color: #00a32a; font-weight: 500; }
.zc-status-value.zc-status-off { color: #d63638; font-weight: 500; }
.zc-status-value.zc-status-error { color: #d63638; }

.zc-manual-actions p {
    margin-bottom: 15px;
}

.zc-manual-actions button {
    margin-bottom: 5px;
}

.zc-manual-actions .dashicons {
    margin-right: 5px;
}

.zc-help-section {
    background: #f0f6fc;
    border-color: #c3d4e6;
}

@media (max-width: 768px) {
    .zc-dashboard-columns {
        grid-template-columns: 1fr;
    }
    
    .zc-stats-grid {
        grid-template-columns: 1fr;
    }
    
    .zc-actions-grid, .zc-sources-grid {
        grid-template-columns: 1fr;
    }
}
</style>