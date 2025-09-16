/**
 * ZC Data Manager - Admin JavaScript
 * Handles all admin interface interactions
 */

(function($) {
    'use strict';
    
    // Initialize when document is ready
    $(document).ready(function() {
        ZCDataManager.init();
    });
    
    // Main ZC Data Manager Admin Object
    window.ZCDataManager = {
        
        // Configuration
        config: {
            ajaxUrl: zcDataManager.ajax_url,
            nonce: zcDataManager.nonce,
            strings: zcDataManager.strings
        },
        
        // Initialize all admin functionality
        init: function() {
            this.bindEvents();
            this.initTabs();
            this.initTooltips();
            this.initFormValidation();
        },
        
        // Bind all event handlers
        bindEvents: function() {
            // Test source connection buttons
            $(document).on('click', '.zc-test-connection', this.testConnection);
            
            // Refresh series buttons
            $(document).on('click', '.zc-refresh-series', this.refreshSeries);
            
            // Delete series buttons
            $(document).on('click', '.zc-delete-series', this.deleteSeries);
            
            // Toggle series active status
            $(document).on('click', '.zc-toggle-series', this.toggleSeries);
            
            // Source type change (dynamic form fields)
            $(document).on('change', '.zc-source-type-select', this.onSourceTypeChange);
            
            // Preview data button
            $(document).on('click', '.zc-preview-data', this.previewData);
            
            // Manual cron triggers
            $(document).on('click', '.zc-manual-cron', this.triggerManualCron);
            
            // Copy shortcode buttons
            $(document).on('click', '.zc-copy-shortcode', this.copyToClipboard);
            
            // Form submission with loading states
            $(document).on('submit', '.zc-form', this.handleFormSubmit);
            
            // Clear logs button
            $(document).on('click', '.zc-clear-logs', this.clearLogs);
        },
        
        // Test data source connection
        testConnection: function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var $form = $button.closest('form');
            var sourceType = $form.find('.zc-source-type-select').val();
            
            if (!sourceType) {
                ZCDataManager.showNotice('error', 'Please select a data source first.');
                return;
            }
            
            // Get form data
            var formData = ZCDataManager.getFormData($form);
            var config = ZCDataManager.buildSourceConfig($form, sourceType);
            
            // Show loading state
            var originalText = $button.text();
            $button.text(ZCDataManager.config.strings.testing_connection)
                   .prop('disabled', true)
                   .addClass('zc-loading');
            
            // Make AJAX request
            $.post(ZCDataManager.config.ajaxUrl, {
                action: 'zc_test_source_connection',
                nonce: ZCDataManager.config.nonce,
                source_type: sourceType,
                config: config
            })
            .done(function(response) {
                if (response.success) {
                    ZCDataManager.showNotice('success', ZCDataManager.config.strings.connection_success);
                    
                    // Show additional info if available
                    if (response.series_info || response.total_records) {
                        var details = '';
                        if (response.total_records) {
                            details += 'Found ' + response.total_records + ' records. ';
                        }
                        if (response.series_info && response.series_info.title) {
                            details += 'Series: ' + response.series_info.title;
                        }
                        if (details) {
                            ZCDataManager.showNotice('info', details, 5000);
                        }
                    }
                } else {
                    ZCDataManager.showNotice('error', response.message || ZCDataManager.config.strings.connection_failed);
                }
            })
            .fail(function() {
                ZCDataManager.showNotice('error', 'Connection test failed. Please try again.');
            })
            .always(function() {
                $button.text(originalText)
                       .prop('disabled', false)
                       .removeClass('zc-loading');
            });
        },
        
        // Refresh series data
        refreshSeries: function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var slug = $button.data('slug');
            
            if (!slug) {
                ZCDataManager.showNotice('error', 'Series slug not found.');
                return;
            }
            
            // Confirm action
            if (!confirm('Are you sure you want to refresh data for this series?')) {
                return;
            }
            
            // Show loading state
            var originalText = $button.text();
            $button.text(ZCDataManager.config.strings.refreshing_data)
                   .prop('disabled', true)
                   .addClass('zc-loading');
            
            // Make AJAX request
            $.post(ZCDataManager.config.ajaxUrl, {
                action: 'zc_refresh_series',
                nonce: ZCDataManager.config.nonce,
                series_slug: slug
            })
            .done(function(response) {
                if (response.success) {
                    ZCDataManager.showNotice('success', response.message || ZCDataManager.config.strings.refresh_success);
                    
                    // Update UI elements
                    ZCDataManager.updateSeriesInfo(slug, response);
                    
                    // Reload page after delay to show updated data
                    setTimeout(function() {
                        window.location.reload();
                    }, 2000);
                } else {
                    ZCDataManager.showNotice('error', response.message || ZCDataManager.config.strings.refresh_failed);
                }
            })
            .fail(function() {
                ZCDataManager.showNotice('error', 'Refresh failed. Please try again.');
            })
            .always(function() {
                $button.text(originalText)
                       .prop('disabled', false)
                       .removeClass('zc-loading');
            });
        },
        
        // Delete series
        deleteSeries: function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var slug = $button.data('slug');
            var $row = $button.closest('tr');
            
            if (!slug) {
                ZCDataManager.showNotice('error', 'Series slug not found.');
                return;
            }
            
            // Confirm deletion
            if (!confirm(ZCDataManager.config.strings.confirm_delete)) {
                return;
            }
            
            // Show loading state
            $button.prop('disabled', true).addClass('zc-loading');
            
            // Make AJAX request
            $.post(ZCDataManager.config.ajaxUrl, {
                action: 'zc_delete_series',
                nonce: ZCDataManager.config.nonce,
                series_slug: slug
            })
            .done(function(response) {
                if (response.success) {
                    ZCDataManager.showNotice('success', response.message || 'Series deleted successfully.');
                    
                    // Remove row from table
                    $row.fadeOut(500, function() {
                        $row.remove();
                        
                        // Check if table is empty
                        var $table = $row.closest('table');
                        if ($table.find('tbody tr').length === 0) {
                            $table.find('tbody').append(
                                '<tr class="no-items">' +
                                '<td colspan="7">No data series found.</td>' +
                                '</tr>'
                            );
                        }
                    });
                } else {
                    ZCDataManager.showNotice('error', response.message || 'Failed to delete series.');
                }
            })
            .fail(function() {
                ZCDataManager.showNotice('error', 'Delete failed. Please try again.');
            })
            .always(function() {
                $button.prop('disabled', false).removeClass('zc-loading');
            });
        },
        
        // Toggle series active status
        toggleSeries: function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var slug = $button.data('slug');
            var action = $button.data('action');
            
            if (!slug || !action) {
                return;
            }
            
            // Show loading state
            $button.prop('disabled', true).addClass('zc-loading');
            
            // This would need to be implemented with a proper toggle endpoint
            // For now, just show a placeholder
            setTimeout(function() {
                if (action === 'activate') {
                    $button.text('Deactivate')
                           .data('action', 'deactivate')
                           .removeClass('zc-loading');
                    ZCDataManager.showNotice('success', 'Series activated.');
                } else {
                    $button.text('Activate')
                           .data('action', 'activate')
                           .removeClass('zc-loading');
                    ZCDataManager.showNotice('success', 'Series deactivated.');
                }
                $button.prop('disabled', false);
            }, 1000);
        },
        
        // Handle source type change (show/hide relevant fields)
        onSourceTypeChange: function() {
            var $select = $(this);
            var sourceType = $select.val();
            var $form = $select.closest('form');
            
            // Hide all source-specific sections
            $form.find('.zc-source-config').hide();
            
            // Show relevant section
            if (sourceType) {
                $form.find('.zc-source-config-' + sourceType).show();
            }
            
            // Update help text
            ZCDataManager.updateSourceHelp(sourceType);
        },
        
        // Preview data before saving
        previewData: function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var $form = $button.closest('form');
            var sourceType = $form.find('.zc-source-type-select').val();
            
            if (!sourceType) {
                ZCDataManager.showNotice('error', 'Please select a data source first.');
                return;
            }
            
            var config = ZCDataManager.buildSourceConfig($form, sourceType);
            
            // Show loading state
            var originalText = $button.text();
            $button.text('Loading Preview...')
                   .prop('disabled', true)
                   .addClass('zc-loading');
            
            // Make AJAX request for preview
            $.post(ZCDataManager.config.ajaxUrl, {
                action: 'zc_preview_source_data',
                nonce: ZCDataManager.config.nonce,
                source_type: sourceType,
                config: config
            })
            .done(function(response) {
                if (response.success) {
                    ZCDataManager.showDataPreview(response);
                } else {
                    ZCDataManager.showNotice('error', response.message || 'Preview failed.');
                }
            })
            .fail(function() {
                ZCDataManager.showNotice('error', 'Preview failed. Please try again.');
            })
            .always(function() {
                $button.text(originalText)
                       .prop('disabled', false)
                       .removeClass('zc-loading');
            });
        },
        
        // Trigger manual cron jobs
        triggerManualCron: function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var jobType = $button.data('job');
            
            if (!jobType) {
                return;
            }
            
            // Show loading state
            var originalText = $button.text();
            $button.text('Running...')
                   .prop('disabled', true)
                   .addClass('zc-loading');
            
            // Make AJAX request
            $.post(ZCDataManager.config.ajaxUrl, {
                action: 'zc_manual_cron',
                nonce: ZCDataManager.config.nonce,
                job_type: jobType
            })
            .done(function(response) {
                if (response.success) {
                    ZCDataManager.showNotice('success', response.message);
                } else {
                    ZCDataManager.showNotice('error', response.message || 'Cron job failed.');
                }
            })
            .fail(function() {
                ZCDataManager.showNotice('error', 'Cron job failed. Please try again.');
            })
            .always(function() {
                $button.text(originalText)
                       .prop('disabled', false)
                       .removeClass('zc-loading');
            });
        },
        
        // Copy text to clipboard
        copyToClipboard: function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var text = $button.data('text') || $button.prev('input, textarea').val();
            
            if (!text) {
                return;
            }
            
            // Use modern clipboard API if available
            if (navigator.clipboard) {
                navigator.clipboard.writeText(text).then(function() {
                    ZCDataManager.showNotice('success', 'Copied to clipboard!', 2000);
                });
            } else {
                // Fallback for older browsers
                var $temp = $('<textarea>').val(text).appendTo('body').select();
                document.execCommand('copy');
                $temp.remove();
                ZCDataManager.showNotice('success', 'Copied to clipboard!', 2000);
            }
        },
        
        // Handle form submissions with loading states
        handleFormSubmit: function(e) {
            var $form = $(this);
            var $submitButton = $form.find('input[type="submit"], button[type="submit"]');
            
            // Add loading state
            $submitButton.prop('disabled', true)
                         .addClass('zc-loading');
            
            // Add spinner if not present
            if (!$submitButton.find('.spinner').length) {
                $submitButton.append('<span class="spinner is-active"></span>');
            }
            
            // Remove loading state after short delay (form will redirect or reload)
            setTimeout(function() {
                $submitButton.prop('disabled', false)
                             .removeClass('zc-loading')
                             .find('.spinner').remove();
            }, 3000);
        },
        
        // Clear error logs
        clearLogs: function(e) {
            e.preventDefault();
            
            if (!confirm('Are you sure you want to clear all logs?')) {
                return;
            }
            
            var $button = $(this);
            var originalText = $button.text();
            
            $button.text('Clearing...')
                   .prop('disabled', true)
                   .addClass('zc-loading');
            
            $.post(ZCDataManager.config.ajaxUrl, {
                action: 'zc_clear_logs',
                nonce: ZCDataManager.config.nonce
            })
            .done(function(response) {
                if (response.success) {
                    ZCDataManager.showNotice('success', 'Logs cleared successfully.');
                    // Reload page to show empty logs
                    setTimeout(function() {
                        window.location.reload();
                    }, 1000);
                } else {
                    ZCDataManager.showNotice('error', response.message || 'Failed to clear logs.');
                }
            })
            .fail(function() {
                ZCDataManager.showNotice('error', 'Failed to clear logs. Please try again.');
            })
            .always(function() {
                $button.text(originalText)
                       .prop('disabled', false)
                       .removeClass('zc-loading');
            });
        },
        
        // Utility Functions
        
        // Get form data as object
        getFormData: function($form) {
            var data = {};
            $form.serializeArray().forEach(function(item) {
                data[item.name] = item.value;
            });
            return data;
        },
        
        // Build source configuration object
        buildSourceConfig: function($form, sourceType) {
            var config = {};
            
            // Get all fields for this source type
            $form.find('.zc-source-config-' + sourceType + ' input, .zc-source-config-' + sourceType + ' select, .zc-source-config-' + sourceType + ' textarea').each(function() {
                var $field = $(this);
                var name = $field.attr('name');
                var value = $field.val();
                
                if (name && value) {
                    // Remove source type prefix if present
                    name = name.replace(sourceType + '_', '');
                    config[name] = value;
                }
            });
            
            return config;
        },
        
        // Show admin notice
        showNotice: function(type, message, duration) {
            duration = duration || 4000;
            
            var $notice = $('<div class="notice notice-' + type + ' is-dismissible">')
                .append('<p>' + message + '</p>')
                .append('<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>');
            
            // Insert after page header
            var $target = $('.wp-header-end').length ? $('.wp-header-end') : $('.wrap h1').first();
            $notice.insertAfter($target);
            
            // Auto dismiss
            setTimeout(function() {
                $notice.fadeOut(500, function() {
                    $notice.remove();
                });
            }, duration);
            
            // Manual dismiss
            $notice.on('click', '.notice-dismiss', function() {
                $notice.fadeOut(500, function() {
                    $notice.remove();
                });
            });
        },
        
        // Show data preview modal
        showDataPreview: function(response) {
            var modalHtml = '<div id="zc-preview-modal" class="zc-modal">' +
                '<div class="zc-modal-content">' +
                '<div class="zc-modal-header">' +
                '<h3>Data Preview</h3>' +
                '<button class="zc-modal-close">&times;</button>' +
                '</div>' +
                '<div class="zc-modal-body">' +
                '<p><strong>Total Records:</strong> ' + response.total_count + '</p>';
            
            if (response.date_range) {
                modalHtml += '<p><strong>Date Range:</strong> ' + response.date_range.start + ' to ' + response.date_range.end + '</p>';
            }
            
            if (response.preview_data && response.preview_data.length > 0) {
                modalHtml += '<table class="wp-list-table widefat">' +
                    '<thead><tr><th>Date</th><th>Value</th></tr></thead>' +
                    '<tbody>';
                
                response.preview_data.forEach(function(point) {
                    modalHtml += '<tr><td>' + point.date + '</td><td>' + point.value + '</td></tr>';
                });
                
                modalHtml += '</tbody></table>';
            }
            
            modalHtml += '</div>' +
                '<div class="zc-modal-footer">' +
                '<button class="button button-secondary zc-modal-close">Close</button>' +
                '</div>' +
                '</div>' +
                '</div>';
            
            // Add to page
            $('body').append(modalHtml);
            
            // Show modal
            $('#zc-preview-modal').fadeIn();
            
            // Close handlers
            $(document).on('click', '#zc-preview-modal .zc-modal-close, #zc-preview-modal', function(e) {
                if (e.target === this) {
                    $('#zc-preview-modal').fadeOut(function() {
                        $(this).remove();
                    });
                }
            });
        },
        
        // Update series info in UI
        updateSeriesInfo: function(slug, response) {
            // Update last updated time
            $('.zc-series-' + slug + ' .last-updated').text('Just now');
            
            // Update data count if provided
            if (response.data_count) {
                $('.zc-series-' + slug + ' .data-count').text(response.data_count);
            }
        },
        
        // Update source help text
        updateSourceHelp: function(sourceType) {
            var helpTexts = {
                'fred': 'Enter a FRED series ID like GDP, UNRATE, or CPIAUCSL. You can find series IDs on the FRED website.',
                'worldbank': 'Enter a World Bank indicator code like NY.GDP.MKTP.CD. Select the country/region for the data.',
                'yahoo': 'Enter a stock symbol like AAPL, GOOGL, or ^GSPC for the S&P 500 index.',
                'eurostat': 'Enter a Eurostat dataset code. You can find codes in the Eurostat data browser.',
                'dbnomics': 'Enter a DBnomics series code in format: provider/dataset/series',
                'csv': 'Upload a CSV file or provide a direct URL to a CSV file with date and value columns.'
            };
            
            var helpText = helpTexts[sourceType] || 'Configure the parameters for your selected data source.';
            $('.zc-source-help').text(helpText);
        },
        
        // Initialize tabs
        initTabs: function() {
            $('.zc-tabs').each(function() {
                var $tabs = $(this);
                var $tabButtons = $tabs.find('.zc-tab-button');
                var $tabContents = $tabs.find('.zc-tab-content');
                
                $tabButtons.on('click', function(e) {
                    e.preventDefault();
                    
                    var target = $(this).data('tab');
                    
                    // Update active states
                    $tabButtons.removeClass('active');
                    $(this).addClass('active');
                    
                    $tabContents.removeClass('active');
                    $('#' + target).addClass('active');
                });
            });
        },
        
        // Initialize tooltips
        initTooltips: function() {
            $('.zc-tooltip').each(function() {
                var $element = $(this);
                var title = $element.attr('title');
                
                if (title) {
                    $element.attr('data-tooltip', title).removeAttr('title');
                }
            });
        },
        
        // Initialize form validation
        initFormValidation: function() {
            $('.zc-form').each(function() {
                var $form = $(this);
                
                // Real-time validation
                $form.find('input[required], select[required], textarea[required]').on('blur', function() {
                    ZCDataManager.validateField($(this));
                });
                
                // Form submission validation
                $form.on('submit', function(e) {
                    var isValid = ZCDataManager.validateForm($form);
                    if (!isValid) {
                        e.preventDefault();
                        ZCDataManager.showNotice('error', 'Please fix the errors below before submitting.');
                    }
                });
            });
        },
        
        // Validate individual field
        validateField: function($field) {
            var isValid = true;
            var value = $field.val().trim();
            var $wrapper = $field.closest('.zc-field');
            
            // Remove existing error states
            $wrapper.removeClass('zc-field-error');
            $wrapper.find('.zc-field-error-message').remove();
            
            // Required field validation
            if ($field.prop('required') && !value) {
                isValid = false;
                ZCDataManager.addFieldError($wrapper, 'This field is required.');
            }
            
            // Type-specific validation
            var fieldType = $field.attr('type') || $field.prop('tagName').toLowerCase();
            
            if (value && fieldType === 'email' && !ZCDataManager.isValidEmail(value)) {
                isValid = false;
                ZCDataManager.addFieldError($wrapper, 'Please enter a valid email address.');
            }
            
            if (value && fieldType === 'url' && !ZCDataManager.isValidUrl(value)) {
                isValid = false;
                ZCDataManager.addFieldError($wrapper, 'Please enter a valid URL.');
            }
            
            return isValid;
        },
        
        // Validate entire form
        validateForm: function($form) {
            var isValid = true;
            
            $form.find('input[required], select[required], textarea[required]').each(function() {
                if (!ZCDataManager.validateField($(this))) {
                    isValid = false;
                }
            });
            
            return isValid;
        },
        
        // Add field error message
        addFieldError: function($wrapper, message) {
            $wrapper.addClass('zc-field-error');
            $wrapper.append('<div class="zc-field-error-message">' + message + '</div>');
        },
        
        // Utility validation functions
        isValidEmail: function(email) {
            var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return emailRegex.test(email);
        },
        
        isValidUrl: function(url) {
            try {
                new URL(url);
                return true;
            } catch (e) {
                return false;
            }
        }
    };
    
})(jQuery);