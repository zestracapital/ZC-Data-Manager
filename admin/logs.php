<?php
/**
 * ZC Data Manager - Logs Admin Page
 * View and manage system logs
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

// Handle actions
if (isset($_POST['action']) && wp_verify_nonce($_POST['zc_nonce'], 'zc_logs_action')) {
    $action = sanitize_text_field($_POST['action']);
    
    switch ($action) {
        case 'clear_all':
            // Clear all logs
            global $wpdb;
            $tables = $database->get_table_names();
            $deleted = $wpdb->query("DELETE FROM {$tables['logs']}");
            
            if ($deleted !== false) {
                echo '<div class="notice notice-success"><p>' . 
                     sprintf(__('Cleared %d log entries.', 'zc-data-manager'), $deleted) . 
                     '</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>' . __('Failed to clear logs.', 'zc-data-manager') . '</p></div>';
            }
            break;
            
        case 'clear_old':
            $deleted = $database->cleanup_old_logs();
            echo '<div class="notice notice-success"><p>' . 
                 sprintf(__('Cleaned up %d old log entries.', 'zc-data-manager'), $deleted) . 
                 '</p></div>';
            break;
    }
}

// Get filter parameters
$status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
$series_filter = isset($_GET['series']) ? sanitize_text_field($_GET['series']) : '';
$days_filter = isset($_GET['days']) ? intval($_GET['days']) : 30;
$search_query = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';

// Pagination
$per_page = 50;
$current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;

// Get logs
$logs = $database->get_logs($per_page * $current_page, $series_filter, $status_filter, $days_filter);

// Filter by search if needed
if (!empty($search_query)) {
    $logs = array_filter($logs, function($log) use ($search_query) {
        $searchable = strtolower($log['series_slug'] . ' ' . $log['action'] . ' ' . $log['message'] . ' ' . $log['source_type']);
        return strpos($searchable, strtolower($search_query)) !== false;
    });
}

// Apply pagination
$total_logs = count($logs);
$total_pages = ceil($total_logs / $per_page);
$offset = ($current_page - 1) * $per_page;
$logs_page = array_slice($logs, $offset, $per_page);

// Get all series for filter
$all_series = $database->get_all_series(false);
?>

<div class="wrap">
    <h1 class="wp-heading-inline"><?php _e('System Logs', 'zc-data-manager'); ?></h1>
    
    <hr class="wp-header-end">
    
    <!-- Filter Controls -->
    <div class="zc-logs-filters">
        <form method="get" class="zc-filter-form">
            <input type="hidden" name="page" value="zc-data-logs">
            
            <div class="zc-filter-row">
                <select name="status">
                    <option value=""><?php _e('All Status Types', 'zc-data-manager'); ?></option>
                    <option value="success" <?php selected($status_filter, 'success'); ?>><?php _e('Success', 'zc-data-manager'); ?></option>
                    <option value="warning" <?php selected($status_filter, 'warning'); ?>><?php _e('Warning', 'zc-data-manager'); ?></option>
                    <option value="error" <?php selected($status_filter, 'error'); ?>><?php _e('Error', 'zc-data-manager'); ?></option>
                </select>
                
                <select name="series">
                    <option value=""><?php _e('All Series', 'zc-data-manager'); ?></option>
                    <?php foreach ($all_series as $series): ?>
                        <option value="<?php echo esc_attr($series['slug']); ?>" <?php selected($series_filter, $series['slug']); ?>>
                            <?php echo esc_html($series['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                
                <select name="days">
                    <option value="1" <?php selected($days_filter, 1); ?>><?php _e('Last 24 hours', 'zc-data-manager'); ?></option>
                    <option value="7" <?php selected($days_filter, 7); ?>><?php _e('Last 7 days', 'zc-data-manager'); ?></option>
                    <option value="30" <?php selected($days_filter, 30); ?>><?php _e('Last 30 days', 'zc-data-manager'); ?></option>
                    <option value="90" <?php selected($days_filter, 90); ?>><?php _e('Last 90 days', 'zc-data-manager'); ?></option>
                    <option value="365" <?php selected($days_filter, 365); ?>><?php _e('Last year', 'zc-data-manager'); ?></option>
                </select>
                
                <input type="search" 
                       name="s" 
                       value="<?php echo esc_attr($search_query); ?>" 
                       placeholder="<?php _e('Search logs...', 'zc-data-manager'); ?>">
                
                <input type="submit" class="button" value="<?php _e('Filter', 'zc-data-manager'); ?>">
                
                <?php if ($status_filter || $series_filter || $search_query || $days_filter != 30): ?>
                    <a href="<?php echo admin_url('admin.php?page=zc-data-logs'); ?>" class="button">
                        <?php _e('Clear Filters', 'zc-data-manager'); ?>
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
    
    <!-- Log Statistics -->
    <div class="zc-log-stats">
        <?php
        $error_count = count(array_filter($logs, function($l) { return $l['status'] === 'error'; }));
        $warning_count = count(array_filter($logs, function($l) { return $l['status'] === 'warning'; }));
        $success_count = count(array_filter($logs, function($l) { return $l['status'] === 'success'; }));
        ?>
        
        <div class="zc-stat-item zc-stat-error">
            <strong><?php echo number_format_i18n($error_count); ?></strong>
            <span><?php _e('Errors', 'zc-data-manager'); ?></span>
        </div>
        
        <div class="zc-stat-item zc-stat-warning">
            <strong><?php echo number_format_i18n($warning_count); ?></strong>
            <span><?php _e('Warnings', 'zc-data-manager'); ?></span>
        </div>
        
        <div class="zc-stat-item zc-stat-success">
            <strong><?php echo number_format_i18n($success_count); ?></strong>
            <span><?php _e('Success', 'zc-data-manager'); ?></span>
        </div>
        
        <div class="zc-stat-item">
            <strong><?php echo number_format_i18n($total_logs); ?></strong>
            <span><?php _e('Total', 'zc-data-manager'); ?></span>
        </div>
    </div>
    
    <!-- Log Actions -->
    <div class="zc-log-actions">
        <form method="post" style="display: inline-block;">
            <?php wp_nonce_field('zc_logs_action', 'zc_nonce'); ?>
            <input type="hidden" name="action" value="clear_old">
            <button type="submit" class="button" onclick="return confirm('<?php _e('Delete old logs based on retention settings?', 'zc-data-manager'); ?>')">
                <?php _e('Clean Old Logs', 'zc-data-manager'); ?>
            </button>
        </form>
        
        <form method="post" style="display: inline-block;">
            <?php wp_nonce_field('zc_logs_action', 'zc_nonce'); ?>
            <input type="hidden" name="action" value="clear_all">
            <button type="submit" class="button button-secondary" onclick="return confirm('<?php _e('Delete ALL logs? This cannot be undone!', 'zc-data-manager'); ?>')">
                <?php _e('Clear All Logs', 'zc-data-manager'); ?>
            </button>
        </form>
        
        <button type="button" class="button zc-export-logs">
            <?php _e('Export Logs', 'zc-data-manager'); ?>
        </button>
    </div>
    
    <!-- Pagination Top -->
    <?php if ($total_pages > 1): ?>
        <div class="tablenav top">
            <div class="tablenav-pages">
                <span class="displaying-num">
                    <?php printf(_n('%s item', '%s items', $total_logs, 'zc-data-manager'), number_format_i18n($total_logs)); ?>
                </span>
                <?php
                $page_links = paginate_links(array(
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'prev_text' => __('&laquo;'),
                    'next_text' => __('&raquo;'),
                    'total' => $total_pages,
                    'current' => $current_page
                ));
                echo $page_links;
                ?>
            </div>
        </div>
    <?php endif; ?>
    
    <!-- Logs Table -->
    <table class="wp-list-table widefat fixed striped zc-logs-table">
        <thead>
            <tr>
                <th scope="col" class="column-time"><?php _e('Time', 'zc-data-manager'); ?></th>
                <th scope="col" class="column-status"><?php _e('Status', 'zc-data-manager'); ?></th>
                <th scope="col" class="column-series"><?php _e('Series', 'zc-data-manager'); ?></th>
                <th scope="col" class="column-source"><?php _e('Source', 'zc-data-manager'); ?></th>
                <th scope="col" class="column-action"><?php _e('Action', 'zc-data-manager'); ?></th>
                <th scope="col" class="column-message"><?php _e('Message', 'zc-data-manager'); ?></th>
            </tr>
        </thead>
        
        <tbody>
            <?php if (!empty($logs_page)): ?>
                <?php foreach ($logs_page as $log): ?>
                    <tr class="zc-log-row zc-log-<?php echo esc_attr($log['status']); ?>">
                        <td class="column-time">
                            <abbr title="<?php echo esc_attr($log['created_at']); ?>">
                                <?php echo human_time_diff(strtotime($log['created_at']), current_time('timestamp')) . ' ' . __('ago', 'zc-data-manager'); ?>
                            </abbr>
                        </td>
                        
                        <td class="column-status">
                            <span class="zc-status-badge zc-status-<?php echo esc_attr($log['status']); ?>">
                                <?php echo ucfirst($log['status']); ?>
                            </span>
                        </td>
                        
                        <td class="column-series">
                            <?php if (!empty($log['series_slug'])): ?>
                                <code><?php echo esc_html($log['series_slug']); ?></code>
                            <?php else: ?>
                                <span class="zc-empty">—</span>
                            <?php endif; ?>
                        </td>
                        
                        <td class="column-source">
                            <?php if (!empty($log['source_type'])): ?>
                                <span class="zc-source-tag">
                                    <?php echo esc_html(ucfirst($log['source_type'])); ?>
                                </span>
                            <?php else: ?>
                                <span class="zc-empty">—</span>
                            <?php endif; ?>
                        </td>
                        
                        <td class="column-action">
                            <strong><?php echo esc_html($log['action']); ?></strong>
                        </td>
                        
                        <td class="column-message">
                            <?php if (!empty($log['message'])): ?>
                                <div class="zc-log-message">
                                    <?php echo esc_html(wp_trim_words($log['message'], 20)); ?>
                                    <?php if (str_word_count($log['message']) > 20): ?>
                                        <button type="button" class="zc-show-full-message" data-full="<?php echo esc_attr($log['message']); ?>">
                                            <?php _e('Show more', 'zc-data-manager'); ?>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <span class="zc-empty">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr class="no-items">
                    <td colspan="6" class="colspanchange">
                        <?php if (!empty($search_query) || $status_filter || $series_filter): ?>
                            <?php _e('No logs found matching your criteria.', 'zc-data-manager'); ?>
                        <?php else: ?>
                            <?php _e('No log entries found.', 'zc-data-manager'); ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
    
    <!-- Pagination Bottom -->
    <?php if ($total_pages > 1): ?>
        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <span class="displaying-num">
                    <?php printf(_n('%s item', '%s items', $total_logs, 'zc-data-manager'), number_format_i18n($total_logs)); ?>
                </span>
                <?php echo $page_links; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Modal for full log messages -->
<div id="zc-log-message-modal" class="zc-modal" style="display: none;">
    <div class="zc-modal-content">
        <div class="zc-modal-header">
            <h3><?php _e('Full Log Message', 'zc-data-manager'); ?></h3>
            <button class="zc-modal-close">&times;</button>
        </div>
        <div class="zc-modal-body">
            <pre class="zc-log-message-full"></pre>
        </div>
        <div class="zc-modal-footer">
            <button class="button zc-modal-close"><?php _e('Close', 'zc-data-manager'); ?></button>
        </div>
    </div>
</div>

<style>
/* Logs Page Styles */
.zc-logs-filters {
    background: #fff;
    border: 1px solid #ccd0d4;
    padding: 15px;
    margin: 15px 0;
}

.zc-filter-row {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
}

.zc-filter-row select,
.zc-filter-row input[type="search"] {
    margin: 0;
}

.zc-log-stats {
    display: flex;
    gap: 20px;
    margin: 15px 0;
    padding: 15px;
    background: #fff;
    border: 1px solid #ccd0d4;
}

.zc-stat-item {
    text-align: center;
    padding: 10px;
    border-radius: 4px;
    background: #f6f7f7;
    min-width: 80px;
}

.zc-stat-item strong {
    display: block;
    font-size: 24px;
    font-weight: 600;
    margin-bottom: 5px;
}

.zc-stat-error strong { color: #d63638; }
.zc-stat-warning strong { color: #dba617; }
.zc-stat-success strong { color: #00a32a; }

.zc-log-actions {
    margin: 15px 0;
    padding: 10px 0;
    border-bottom: 1px solid #ccd0d4;
}

.zc-log-actions form {
    margin-right: 10px;
}

.zc-logs-table .column-time { width: 120px; }
.zc-logs-table .column-status { width: 80px; }
.zc-logs-table .column-series { width: 120px; }
.zc-logs-table .column-source { width: 100px; }
.zc-logs-table .column-action { width: 150px; }
.zc-logs-table .column-message { width: auto; }

.zc-log-row.zc-log-error {
    background-color: #fef7f7;
}

.zc-log-row.zc-log-warning {
    background-color: #fffbf0;
}

.zc-status-badge {
    padding: 3px 8px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: 500;
    text-transform: uppercase;
    color: #fff;
}

.zc-status-success { background: #00a32a; }
.zc-status-warning { background: #dba617; }
.zc-status-error { background: #d63638; }

.zc-source-tag {
    background: #f0f0f1;
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 11px;
    color: #50575e;
}

.zc-empty {
    color: #8c8f94;
    font-style: italic;
}

.zc-log-message {
    position: relative;
}

.zc-show-full-message {
    background: none;
    border: none;
    color: #2271b1;
    text-decoration: underline;
    cursor: pointer;
    font-size: 12px;
    margin-left: 5px;
}

.zc-show-full-message:hover {
    color: #135e96;
}

/* Modal styles */
.zc-modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 100000;
    display: flex;
    align-items: center;
    justify-content: center;
}

.zc-modal-content {
    background: #fff;
    border-radius: 4px;
    max-width: 80%;
    max-height: 80%;
    display: flex;
    flex-direction: column;
}

.zc-modal-header {
    padding: 15px 20px;
    border-bottom: 1px solid #ddd;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.zc-modal-header h3 {
    margin: 0;
}

.zc-modal-close {
    background: none;
    border: none;
    font-size: 20px;
    cursor: pointer;
    color: #666;
}

.zc-modal-body {
    padding: 20px;
    flex: 1;
    overflow-y: auto;
}

.zc-modal-footer {
    padding: 15px 20px;
    border-top: 1px solid #ddd;
    text-align: right;
}

.zc-log-message-full {
    background: #f6f7f7;
    padding: 15px;
    border: 1px solid #ddd;
    border-radius: 4px;
    max-height: 400px;
    overflow-y: auto;
    white-space: pre-wrap;
    font-family: monospace;
    font-size: 12px;
}

/* Responsive */
@media (max-width: 768px) {
    .zc-filter-row {
        flex-direction: column;
        align-items: stretch;
    }
    
    .zc-filter-row select,
    .zc-filter-row input {
        width: 100%;
        margin-bottom: 10px;
    }
    
    .zc-log-stats {
        flex-wrap: wrap;
    }
    
    .zc-stat-item {
        flex: 1;
        min-width: auto;
    }
    
    .zc-modal-content {
        max-width: 95%;
        max-height: 95%;
    }
}
</style>

<script>
jQuery(document).ready(function($) {
    // Show full log message in modal
    $('.zc-show-full-message').on('click', function() {
        var fullMessage = $(this).data('full');
        $('.zc-log-message-full').text(fullMessage);
        $('#zc-log-message-modal').show();
    });
    
    // Close modal
    $('.zc-modal-close, .zc-modal').on('click', function(e) {
        if (e.target === this) {
            $('#zc-log-message-modal').hide();
        }
    });
    
    // Export logs
    $('.zc-export-logs').on('click', function() {
        var $button = $(this);
        var originalText = $button.text();
        
        $button.text('<?php _e("Exporting...", "zc-data-manager"); ?>').prop('disabled', true);
        
        // Create export URL with current filters
        var exportUrl = ajaxurl + '?' + $.param({
            action: 'zc_export_logs',
            nonce: zcDataManager.nonce,
            status: '<?php echo esc_js($status_filter); ?>',
            series: '<?php echo esc_js($series_filter); ?>',
            days: '<?php echo esc_js($days_filter); ?>',
            search: '<?php echo esc_js($search_query); ?>'
        });
        
        // Download file
        var link = document.createElement('a');
        link.href = exportUrl;
        link.download = 'zc-data-manager-logs-' + new Date().toISOString().split('T')[0] + '.csv';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        
        setTimeout(function() {
            $button.text(originalText).prop('disabled', false);
        }, 2000);
    });
    
    // Auto-refresh option
    var autoRefresh = false;
    $(document).on('keydown', function(e) {
        if (e.ctrlKey && e.key === 'r') {
            e.preventDefault();
            autoRefresh = !autoRefresh;
            
            if (autoRefresh) {
                $('.wrap h1').after('<div class="notice notice-info"><p><?php _e("Auto-refresh enabled. Press Ctrl+R again to disable.", "zc-data-manager"); ?></p></div>');
                
                var refreshInterval = setInterval(function() {
                    if (!autoRefresh) {
                        clearInterval(refreshInterval);
                        return;
                    }
                    window.location.reload();
                }, 30000); // Refresh every 30 seconds
            } else {
                $('.notice-info').remove();
            }
        }
    });
});
</script>