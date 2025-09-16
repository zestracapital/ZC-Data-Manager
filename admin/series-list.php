<?php
/**
 * ZC Data Manager - Series List Admin Page
 * Display and manage all data series
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

// Handle bulk actions
if (isset($_POST['action']) && $_POST['action'] !== '-1' && !empty($_POST['series'])) {
    check_admin_referer('zc_series_bulk_action');
    
    $action = sanitize_text_field($_POST['action']);
    $selected_series = array_map('sanitize_text_field', $_POST['series']);
    
    $success_count = 0;
    $error_count = 0;
    
    foreach ($selected_series as $series_slug) {
        switch ($action) {
            case 'delete':
                $result = $database->delete_series($series_slug);
                if ($result['success']) {
                    $success_count++;
                } else {
                    $error_count++;
                }
                break;
                
            case 'activate':
                $result = $database->save_series(array(
                    'slug' => $series_slug,
                    'is_active' => 1
                ));
                if ($result['success']) {
                    $success_count++;
                } else {
                    $error_count++;
                }
                break;
                
            case 'deactivate':
                $result = $database->save_series(array(
                    'slug' => $series_slug,
                    'is_active' => 0
                ));
                if ($result['success']) {
                    $success_count++;
                } else {
                    $error_count++;
                }
                break;
                
            case 'refresh':
                $result = $collector->refresh_series($series_slug);
                if ($result['success']) {
                    $success_count++;
                } else {
                    $error_count++;
                }
                break;
        }
    }
    
    // Show results message
    if ($success_count > 0) {
        echo '<div class="notice notice-success"><p>' . 
             sprintf(_n('%d series processed successfully.', '%d series processed successfully.', $success_count, 'zc-data-manager'), $success_count) . 
             '</p></div>';
    }
    
    if ($error_count > 0) {
        echo '<div class="notice notice-error"><p>' . 
             sprintf(_n('%d series failed to process.', '%d series failed to process.', $error_count, 'zc-data-manager'), $error_count) . 
             '</p></div>';
    }
}

// Get all series
$all_series = $database->get_all_series();

// Filter by status if requested
$status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'all';
if ($status_filter === 'active') {
    $series_list = array_filter($all_series, function($series) {
        return $series['is_active'] == 1;
    });
} elseif ($status_filter === 'inactive') {
    $series_list = array_filter($all_series, function($series) {
        return $series['is_active'] == 0;
    });
} else {
    $series_list = $all_series;
}

// Search functionality
$search_query = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
if (!empty($search_query)) {
    $series_list = array_filter($series_list, function($series) use ($search_query) {
        $searchable = strtolower($series['name'] . ' ' . $series['slug'] . ' ' . $series['source_type']);
        return strpos($searchable, strtolower($search_query)) !== false;
    });
}

// Pagination
$per_page = 20;
$current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$total_items = count($series_list);
$total_pages = ceil($total_items / $per_page);
$offset = ($current_page - 1) * $per_page;
$series_page = array_slice($series_list, $offset, $per_page);

// Get source information
$available_sources = $collector->get_available_sources();
?>

<div class="wrap">
    <h1 class="wp-heading-inline">
        <?php _e('Data Series', 'zc-data-manager'); ?>
    </h1>
    
    <a href="<?php echo admin_url('admin.php?page=zc-add-series'); ?>" class="page-title-action">
        <?php _e('Add New Series', 'zc-data-manager'); ?>
    </a>
    
    <hr class="wp-header-end">
    
    <!-- Status Filter Tabs -->
    <ul class="subsubsub">
        <li class="all">
            <a href="<?php echo admin_url('admin.php?page=zc-data-series'); ?>" <?php echo $status_filter === 'all' ? 'class="current"' : ''; ?>>
                <?php _e('All', 'zc-data-manager'); ?> <span class="count">(<?php echo count($all_series); ?>)</span>
            </a> |
        </li>
        <li class="active">
            <a href="<?php echo admin_url('admin.php?page=zc-data-series&status=active'); ?>" <?php echo $status_filter === 'active' ? 'class="current"' : ''; ?>>
                <?php _e('Active', 'zc-data-manager'); ?> 
                <span class="count">(<?php echo count(array_filter($all_series, function($s) { return $s['is_active']; })); ?>)</span>
            </a> |
        </li>
        <li class="inactive">
            <a href="<?php echo admin_url('admin.php?page=zc-data-series&status=inactive'); ?>" <?php echo $status_filter === 'inactive' ? 'class="current"' : ''; ?>>
                <?php _e('Inactive', 'zc-data-manager'); ?> 
                <span class="count">(<?php echo count(array_filter($all_series, function($s) { return !$s['is_active']; })); ?>)</span>
            </a>
        </li>
    </ul>
    
    <!-- Search Form -->
    <div class="zc-series-controls">
        <form method="get" class="zc-search-form">
            <input type="hidden" name="page" value="zc-data-series">
            <?php if ($status_filter !== 'all'): ?>
                <input type="hidden" name="status" value="<?php echo esc_attr($status_filter); ?>">
            <?php endif; ?>
            <p class="search-box">
                <input type="search" name="s" value="<?php echo esc_attr($search_query); ?>" placeholder="<?php _e('Search series...', 'zc-data-manager'); ?>">
                <input type="submit" class="button" value="<?php _e('Search', 'zc-data-manager'); ?>">
            </p>
        </form>
    </div>
    
    <!-- Series Table -->
    <form method="post" id="zc-series-filter">
        <?php wp_nonce_field('zc_series_bulk_action'); ?>
        
        <div class="tablenav top">
            <div class="alignleft actions bulkactions">
                <label for="bulk-action-selector-top" class="screen-reader-text"><?php _e('Select bulk action', 'zc-data-manager'); ?></label>
                <select name="action" id="bulk-action-selector-top">
                    <option value="-1"><?php _e('Bulk Actions', 'zc-data-manager'); ?></option>
                    <option value="refresh"><?php _e('Refresh Data', 'zc-data-manager'); ?></option>
                    <option value="activate"><?php _e('Activate', 'zc-data-manager'); ?></option>
                    <option value="deactivate"><?php _e('Deactivate', 'zc-data-manager'); ?></option>
                    <option value="delete"><?php _e('Delete', 'zc-data-manager'); ?></option>
                </select>
                <input type="submit" class="button action" value="<?php _e('Apply', 'zc-data-manager'); ?>">
            </div>
            
            <?php if ($total_pages > 1): ?>
                <div class="tablenav-pages">
                    <span class="displaying-num">
                        <?php printf(_n('%s item', '%s items', $total_items, 'zc-data-manager'), number_format_i18n($total_items)); ?>
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
            <?php endif; ?>
        </div>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <td class="manage-column column-cb check-column">
                        <label class="screen-reader-text" for="cb-select-all-1"><?php _e('Select All', 'zc-data-manager'); ?></label>
                        <input id="cb-select-all-1" type="checkbox">
                    </td>
                    <th scope="col" class="manage-column column-name column-primary">
                        <?php _e('Name', 'zc-data-manager'); ?>
                    </th>
                    <th scope="col" class="manage-column column-slug">
                        <?php _e('Slug', 'zc-data-manager'); ?>
                    </th>
                    <th scope="col" class="manage-column column-source">
                        <?php _e('Source', 'zc-data-manager'); ?>
                    </th>
                    <th scope="col" class="manage-column column-status">
                        <?php _e('Status', 'zc-data-manager'); ?>
                    </th>
                    <th scope="col" class="manage-column column-updated">
                        <?php _e('Last Updated', 'zc-data-manager'); ?>
                    </th>
                    <th scope="col" class="manage-column column-data">
                        <?php _e('Data Points', 'zc-data-manager'); ?>
                    </th>
                </tr>
            </thead>
            
            <tbody>
                <?php if (!empty($series_page)): ?>
                    <?php foreach ($series_page as $series): ?>
                        <?php
                        // Get data point count for this series
                        global $wpdb;
                        $tables = $database->get_table_names();
                        $data_count = $wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(*) FROM {$tables['observations']} WHERE series_slug = %s",
                            $series['slug']
                        ));
                        
                        $source_name = isset($available_sources[$series['source_type']]) 
                            ? $available_sources[$series['source_type']]['name'] 
                            : ucfirst($series['source_type']);
                        ?>
                        <tr>
                            <th scope="row" class="check-column">
                                <input type="checkbox" name="series[]" value="<?php echo esc_attr($series['slug']); ?>">
                            </th>
                            
                            <td class="column-name column-primary">
                                <strong>
                                    <a href="<?php echo admin_url('admin.php?page=zc-add-series&edit=' . urlencode($series['slug'])); ?>">
                                        <?php echo esc_html($series['name']); ?>
                                    </a>
                                </strong>
                                
                                <div class="row-actions">
                                    <span class="edit">
                                        <a href="<?php echo admin_url('admin.php?page=zc-add-series&edit=' . urlencode($series['slug'])); ?>">
                                            <?php _e('Edit', 'zc-data-manager'); ?>
                                        </a> |
                                    </span>
                                    
                                    <span class="refresh">
                                        <a href="#" class="zc-refresh-series" data-slug="<?php echo esc_attr($series['slug']); ?>">
                                            <?php _e('Refresh', 'zc-data-manager'); ?>
                                        </a> |
                                    </span>
                                    
                                    <?php if ($series['is_active']): ?>
                                        <span class="deactivate">
                                            <a href="#" class="zc-toggle-series" data-slug="<?php echo esc_attr($series['slug']); ?>" data-action="deactivate">
                                                <?php _e('Deactivate', 'zc-data-manager'); ?>
                                            </a> |
                                        </span>
                                    <?php else: ?>
                                        <span class="activate">
                                            <a href="#" class="zc-toggle-series" data-slug="<?php echo esc_attr($series['slug']); ?>" data-action="activate">
                                                <?php _e('Activate', 'zc-data-manager'); ?>
                                            </a> |
                                        </span>
                                    <?php endif; ?>
                                    
                                    <span class="delete">
                                        <a href="#" class="zc-delete-series" data-slug="<?php echo esc_attr($series['slug']); ?>">
                                            <?php _e('Delete', 'zc-data-manager'); ?>
                                        </a>
                                    </span>
                                </div>
                                
                                <button type="button" class="toggle-row">
                                    <span class="screen-reader-text"><?php _e('Show more details', 'zc-data-manager'); ?></span>
                                </button>
                            </td>
                            
                            <td class="column-slug" data-colname="<?php _e('Slug', 'zc-data-manager'); ?>">
                                <code><?php echo esc_html($series['slug']); ?></code>
                            </td>
                            
                            <td class="column-source" data-colname="<?php _e('Source', 'zc-data-manager'); ?>">
                                <span class="zc-source-badge zc-source-<?php echo esc_attr($series['source_type']); ?>">
                                    <?php echo esc_html($source_name); ?>
                                </span>
                            </td>
                            
                            <td class="column-status" data-colname="<?php _e('Status', 'zc-data-manager'); ?>">
                                <?php if ($series['is_active']): ?>
                                    <span class="zc-status-active"><?php _e('Active', 'zc-data-manager'); ?></span>
                                <?php else: ?>
                                    <span class="zc-status-inactive"><?php _e('Inactive', 'zc-data-manager'); ?></span>
                                <?php endif; ?>
                            </td>
                            
                            <td class="column-updated" data-colname="<?php _e('Last Updated', 'zc-data-manager'); ?>">
                                <?php if ($series['last_updated']): ?>
                                    <abbr title="<?php echo esc_attr($series['last_updated']); ?>">
                                        <?php echo human_time_diff(strtotime($series['last_updated']), current_time('timestamp')) . ' ' . __('ago', 'zc-data-manager'); ?>
                                    </abbr>
                                <?php else: ?>
                                    <span class="zc-never-updated"><?php _e('Never', 'zc-data-manager'); ?></span>
                                <?php endif; ?>
                            </td>
                            
                            <td class="column-data" data-colname="<?php _e('Data Points', 'zc-data-manager'); ?>">
                                <span class="zc-data-count">
                                    <?php echo number_format_i18n($data_count); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr class="no-items">
                        <td class="colspanchange" colspan="7">
                            <?php if (!empty($search_query)): ?>
                                <?php _e('No series found matching your search.', 'zc-data-manager'); ?>
                            <?php else: ?>
                                <?php _e('No data series found.', 'zc-data-manager'); ?>
                                <a href="<?php echo admin_url('admin.php?page=zc-add-series'); ?>">
                                    <?php _e('Add your first series', 'zc-data-manager'); ?>
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <?php if ($total_pages > 1): ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <span class="displaying-num">
                        <?php printf(_n('%s item', '%s items', $total_items, 'zc-data-manager'), number_format_i18n($total_items)); ?>
                    </span>
                    <?php echo $page_links; ?>
                </div>
            </div>
        <?php endif; ?>
    </form>
</div>

<style>
/* Series list specific styles */
.zc-series-controls {
    margin: 15px 0;
}

.zc-search-form {
    display: inline-block;
}

.zc-source-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: 500;
    text-transform: uppercase;
    color: #fff;
}

.zc-source-fred { background-color: #1e73be; }
.zc-source-worldbank { background-color: #0073aa; }
.zc-source-yahoo { background-color: #720e9e; }
.zc-source-eurostat { background-color: #003d82; }
.zc-source-oecd { background-color: #c8102e; }
.zc-source-alphavantage { background-color: #ff6900; }
.zc-source-dbnomics { background-color: #00a085; }
.zc-source-quandl { background-color: #3fb34f; }
.zc-source-csv { background-color: #8c8f94; }
.zc-source-googlesheets { background-color: #0f9d58; }

.zc-status-active {
    color: #00a32a;
    font-weight: 500;
}

.zc-status-inactive {
    color: #d63638;
    font-weight: 500;
}

.zc-never-updated {
    color: #8c8f94;
    font-style: italic;
}

.zc-data-count {
    font-weight: 500;
    color: #2271b1;
}

.column-slug code {
    background: #f1f1f1;
    padding: 2px 5px;
    border-radius: 3px;
    font-size: 12px;
}

/* Responsive adjustments */
@media screen and (max-width: 782px) {
    .wp-list-table td.column-source,
    .wp-list-table td.column-updated,
    .wp-list-table td.column-data {
        display: block;
        width: auto;
    }
    
    .wp-list-table td.column-source:before { content: "Source: "; }
    .wp-list-table td.column-updated:before { content: "Last Updated: "; }
    .wp-list-table td.column-data:before { content: "Data Points: "; }
}
</style>

<script>
jQuery(document).ready(function($) {
    // Handle refresh series
    $('.zc-refresh-series').on('click', function(e) {
        e.preventDefault();
        
        var $link = $(this);
        var slug = $link.data('slug');
        var originalText = $link.text();
        
        $link.text('<?php _e('Refreshing...', 'zc-data-manager'); ?>');
        
        $.post(ajaxurl, {
            action: 'zc_refresh_series',
            series_slug: slug,
            nonce: zcDataManager.nonce
        }, function(response) {
            if (response.success) {
                // Show success message
                $('<div class="notice notice-success is-dismissible"><p>' + response.message + '</p></div>')
                    .insertAfter('.wp-header-end').delay(3000).fadeOut();
                
                // Reload page to show updated data
                setTimeout(function() {
                    window.location.reload();
                }, 1000);
            } else {
                alert(response.message || '<?php _e('Failed to refresh series', 'zc-data-manager'); ?>');
            }
        }).always(function() {
            $link.text(originalText);
        });
    });
    
    // Handle delete series
    $('.zc-delete-series').on('click', function(e) {
        e.preventDefault();
        
        if (!confirm(zcDataManager.strings.confirm_delete)) {
            return;
        }
        
        var $link = $(this);
        var slug = $link.data('slug');
        var $row = $link.closest('tr');
        
        $.post(ajaxurl, {
            action: 'zc_delete_series',
            series_slug: slug,
            nonce: zcDataManager.nonce
        }, function(response) {
            if (response.success) {
                $row.fadeOut(function() {
                    $row.remove();
                });
                
                $('<div class="notice notice-success is-dismissible"><p>' + response.message + '</p></div>')
                    .insertAfter('.wp-header-end').delay(3000).fadeOut();
            } else {
                alert(response.message || '<?php _e('Failed to delete series', 'zc-data-manager'); ?>');
            }
        });
    });
    
    // Handle activate/deactivate toggle
    $('.zc-toggle-series').on('click', function(e) {
        e.preventDefault();
        
        var $link = $(this);
        var slug = $link.data('slug');
        var action = $link.data('action');
        
        // This would need additional AJAX handler - simplified for now
        alert('Toggle functionality would be implemented here');
    });
    
    // Handle bulk actions
    $('#doaction, #doaction2').on('click', function(e) {
        var action = $(this).siblings('select').val();
        if (action === 'delete') {
            return confirm(zcDataManager.strings.confirm_delete);
        }
        return true;
    });
    
    // Select all checkbox functionality
    $('#cb-select-all-1').on('change', function() {
        $('input[name="series[]"]').prop('checked', this.checked);
    });
});
</script>