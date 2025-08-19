/**
 * WP Genius Admin JavaScript
 * Handles all admin interactions with WordPress-native patterns
 */

(function($) {
    'use strict';

    // Main admin object
    window.WPGenius = {
        init: function() {
            this.bindEvents();
            this.initTooltips();
            this.initFormValidation();
        },

        bindEvents: function() {
            // Check site status
            $(document).on('click', '.wp-genius-check-site', this.checkSite);
            
            // Delete site
            $(document).on('click', '.wp-genius-delete-site', this.deleteSite);
            
            // Check all sites
            $(document).on('click', '#wp-genius-check-all-sites', this.checkAllSites);
            
            // Add site form
            $(document).on('submit', '#wp-genius-add-site-form', this.addSite);
            
            // Copy connection key
            $(document).on('click', '#copy-connection-key', this.copyConnectionKey);
            
            // Generate report
            $(document).on('click', '#wp-genius-generate-new-report', this.generateReport);
            
            // Download client plugin
            $(document).on('click', '#download-client-plugin', this.downloadClientPlugin);
        },

        initTooltips: function() {
            // Initialize WordPress-style tooltips
            $('.wp-genius-tooltip').tooltip();
        },

        initFormValidation: function() {
            // Real-time form validation
            $('input[required]').on('blur', function() {
                WPGenius.validateField($(this));
            });
        },

        validateField: function($field) {
            const value = $field.val().trim();
            const fieldType = $field.attr('type');
            let isValid = true;
            let message = '';

            if ($field.prop('required') && !value) {
                isValid = false;
                message = wpGenius.strings.field_required || 'This field is required.';
            } else if (fieldType === 'url' && value && !this.isValidUrl(value)) {
                isValid = false;
                message = wpGenius.strings.invalid_url || 'Please enter a valid URL.';
            }

            this.showFieldValidation($field, isValid, message);
            return isValid;
        },

        showFieldValidation: function($field, isValid, message) {
            const $wrapper = $field.closest('td');
            $wrapper.find('.wp-genius-field-error').remove();

            if (!isValid) {
                $field.addClass('wp-genius-error');
                $wrapper.append('<div class="wp-genius-field-error">' + message + '</div>');
            } else {
                $field.removeClass('wp-genius-error');
            }
        },

        isValidUrl: function(url) {
            try {
                new URL(url);
                return true;
            } catch (e) {
                return false;
            }
        },

        checkSite: function(e) {
            e.preventDefault();
            
            const $button = $(this);
            const siteId = $button.data('site-id');
            
            if (!siteId) {
                WPGenius.showNotice('error', 'Invalid site ID.');
                return;
            }

            WPGenius.setButtonLoading($button, true);

            $.ajax({
                url: wpGenius.ajaxurl,
                type: 'POST',
                data: {
                    action: 'wp_genius_check_site',
                    site_id: siteId,
                    nonce: wpGenius.nonce
                },
                success: function(response) {
                    if (response.success) {
                        WPGenius.showNotice('success', response.data.message);
                        // Update status badge if on sites page
                        WPGenius.updateSiteStatus(siteId, response.data.status);
                    } else {
                        WPGenius.showNotice('error', response.data || 'Failed to check site.');
                    }
                },
                error: function() {
                    WPGenius.showNotice('error', 'Network error. Please try again.');
                },
                complete: function() {
                    WPGenius.setButtonLoading($button, false);
                }
            });
        },

        deleteSite: function(e) {
            e.preventDefault();
            
            const $link = $(this);
            const siteId = $link.data('site-id');
            
            if (!confirm(wpGenius.strings.confirm_delete)) {
                return;
            }

            $.ajax({
                url: wpGenius.ajaxurl,
                type: 'POST',
                data: {
                    action: 'wp_genius_delete_site',
                    site_id: siteId,
                    nonce: wpGenius.nonce
                },
                success: function(response) {
                    if (response.success) {
                        WPGenius.showNotice('success', response.data.message);
                        // Remove row from table
                        $link.closest('tr').fadeOut(300, function() {
                            $(this).remove();
                            WPGenius.checkEmptyTable();
                        });
                    } else {
                        WPGenius.showNotice('error', response.data || 'Failed to delete site.');
                    }
                },
                error: function() {
                    WPGenius.showNotice('error', 'Network error. Please try again.');
                }
            });
        },

        checkAllSites: function(e) {
            e.preventDefault();
            
            const $button = $(this);
            WPGenius.setButtonLoading($button, true);

            $.ajax({
                url: wpGenius.ajaxurl,
                type: 'POST',
                data: {
                    action: 'wp_genius_check_all_sites',
                    nonce: wpGenius.nonce
                },
                success: function(response) {
                    if (response.success) {
                        WPGenius.showNotice('success', response.data.message);
                        // Refresh page to show updated statuses
                        setTimeout(function() {
                            window.location.reload();
                        }, 1000);
                    } else {
                        WPGenius.showNotice('error', response.data || 'Failed to check sites.');
                    }
                },
                error: function() {
                    WPGenius.showNotice('error', 'Network error. Please try again.');
                },
                complete: function() {
                    WPGenius.setButtonLoading($button, false);
                }
            });
        },

        addSite: function(e) {
            e.preventDefault();
            
            const $form = $(this);
            const $submitButton = $form.find('button[type="submit"]');
            
            // Validate all required fields
            let isValid = true;
            $form.find('input[required]').each(function() {
                if (!WPGenius.validateField($(this))) {
                    isValid = false;
                }
            });

            if (!isValid) {
                WPGenius.showNotice('error', 'Please fix the errors above.');
                return;
            }

            WPGenius.setButtonLoading($submitButton, true);

            const formData = {
                action: 'wp_genius_add_site',
                site_name: $form.find('#site_name').val(),
                site_url: $form.find('#site_url').val(),
                nonce: wpGenius.nonce
            };

            $.ajax({
                url: wpGenius.ajaxurl,
                type: 'POST',
                data: formData,
                success: function(response) {
                    if (response.success) {
                        WPGenius.showNotice('success', response.data.message);
                        if (response.data.redirect) {
                            setTimeout(function() {
                                window.location.href = response.data.redirect;
                            }, 1000);
                        }
                    } else {
                        WPGenius.showNotice('error', response.data || 'Failed to add site.');
                    }
                },
                error: function() {
                    WPGenius.showNotice('error', 'Network error. Please try again.');
                },
                complete: function() {
                    WPGenius.setButtonLoading($submitButton, false);
                }
            });
        },

        copyConnectionKey: function(e) {
            e.preventDefault();
            
            const $button = $(this);
            const $key = $('#connection-key');
            const key = $key.text();

            if (navigator.clipboard) {
                navigator.clipboard.writeText(key).then(function() {
                    WPGenius.showTemporarySuccess($button, 'Copied!');
                });
            } else {
                // Fallback for older browsers
                $key.select();
                document.execCommand('copy');
                WPGenius.showTemporarySuccess($button, 'Copied!');
            }
        },

        generateReport: function(e) {
            e.preventDefault();
            
            // This will open a modal or navigate to report generation
            WPGenius.openReportModal();
        },

        downloadClientPlugin: function(e) {
            e.preventDefault();
            
            // Trigger download of client plugin
            const downloadUrl = wpGenius.plugin_url + 'dist/wp-genius-client.zip';
            const link = document.createElement('a');
            link.href = downloadUrl;
            link.download = 'wp-genius-client.zip';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        },

        openReportModal: function() {
            // Open WordPress-style modal for report generation
            const modalHtml = `
                <div id="wp-genius-report-modal" class="wp-genius-modal">
                    <div class="wp-genius-modal-content">
                        <div class="wp-genius-modal-header">
                            <h2>Generate Report</h2>
                            <button class="wp-genius-modal-close">&times;</button>
                        </div>
                        <div class="wp-genius-modal-body">
                            <form id="wp-genius-report-form">
                                <table class="form-table">
                                    <tr>
                                        <th><label for="report-site">Site</label></th>
                                        <td>
                                            <select id="report-site" name="site_id" required>
                                                <option value="">Select a site...</option>
                                            </select>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th><label for="report-period-start">Period Start</label></th>
                                        <td><input type="date" id="report-period-start" name="period_start" required /></td>
                                    </tr>
                                    <tr>
                                        <th><label for="report-period-end">Period End</label></th>
                                        <td><input type="date" id="report-period-end" name="period_end" required /></td>
                                    </tr>
                                    <tr>
                                        <th><label for="report-ai-enabled">AI Summary</label></th>
                                        <td>
                                            <label>
                                                <input type="checkbox" id="report-ai-enabled" name="ai_enabled" value="1" checked />
                                                Generate AI-powered client summary
                                            </label>
                                        </td>
                                    </tr>
                                </table>
                                
                                <p class="submit">
                                    <button type="submit" class="button button-primary">Generate Report</button>
                                    <button type="button" class="button button-secondary wp-genius-modal-close">Cancel</button>
                                </p>
                            </form>
                        </div>
                    </div>
                </div>
            `;

            $('body').append(modalHtml);
            this.loadSitesForReport();
            this.bindModalEvents();
        },

        loadSitesForReport: function() {
            $.ajax({
                url: wpGenius.ajaxurl,
                type: 'POST',
                data: {
                    action: 'wp_genius_get_sites',
                    nonce: wpGenius.nonce
                },
                success: function(response) {
                    if (response.success && response.data.sites) {
                        const $select = $('#report-site');
                        response.data.sites.forEach(function(site) {
                            $select.append(`<option value="${site.id}">${site.name}</option>`);
                        });
                    }
                }
            });
        },

        bindModalEvents: function() {
            // Close modal
            $(document).on('click', '.wp-genius-modal-close, .wp-genius-modal', function(e) {
                if (e.target === this) {
                    $('#wp-genius-report-modal').remove();
                }
            });

            // Submit report form
            $(document).on('submit', '#wp-genius-report-form', this.submitReportForm);
        },

        submitReportForm: function(e) {
            e.preventDefault();
            
            const $form = $(this);
            const $submitButton = $form.find('button[type="submit"]');
            
            WPGenius.setButtonLoading($submitButton, true);

            const formData = {
                action: 'wp_genius_generate_report',
                site_id: $form.find('#report-site').val(),
                period_start: $form.find('#report-period-start').val(),
                period_end: $form.find('#report-period-end').val(),
                ai_enabled: $form.find('#report-ai-enabled').is(':checked') ? 1 : 0,
                nonce: wpGenius.nonce
            };

            $.ajax({
                url: wpGenius.ajaxurl,
                type: 'POST',
                data: formData,
                success: function(response) {
                    if (response.success) {
                        WPGenius.showNotice('success', response.data.message);
                        $('#wp-genius-report-modal').remove();
                        
                        if (response.data.download_url) {
                            // Auto-download the report
                            window.open(response.data.download_url, '_blank');
                        }
                    } else {
                        WPGenius.showNotice('error', response.data || 'Failed to generate report.');
                    }
                },
                error: function() {
                    WPGenius.showNotice('error', 'Network error. Please try again.');
                },
                complete: function() {
                    WPGenius.setButtonLoading($submitButton, false);
                }
            });
        },

        setButtonLoading: function($button, loading) {
            if (loading) {
                $button.prop('disabled', true);
                $button.addClass('wp-genius-loading');
                
                const originalText = $button.text();
                $button.data('original-text', originalText);
                $button.text(wpGenius.strings.loading || 'Loading...');
            } else {
                $button.prop('disabled', false);
                $button.removeClass('wp-genius-loading');
                
                const originalText = $button.data('original-text');
                if (originalText) {
                    $button.text(originalText);
                }
            }
        },

        showNotice: function(type, message) {
            const noticeClass = type === 'success' ? 'notice-success' : 'notice-error';
            const notice = `
                <div class="notice ${noticeClass} is-dismissible wp-genius-notice">
                    <p>${message}</p>
                    <button type="button" class="notice-dismiss">
                        <span class="screen-reader-text">Dismiss this notice.</span>
                    </button>
                </div>
            `;

            $('.wrap h1').after(notice);
            
            // Auto-dismiss after 5 seconds
            setTimeout(function() {
                $('.wp-genius-notice').fadeOut(300, function() {
                    $(this).remove();
                });
            }, 5000);

            // Handle manual dismiss
            $('.notice-dismiss').on('click', function() {
                $(this).closest('.notice').fadeOut(300, function() {
                    $(this).remove();
                });
            });
        },

        showTemporarySuccess: function($element, message) {
            const originalText = $element.text();
            $element.text(message);
            $element.addClass('wp-genius-success');
            
            setTimeout(function() {
                $element.text(originalText);
                $element.removeClass('wp-genius-success');
            }, 2000);
        },

        updateSiteStatus: function(siteId, status) {
            const $row = $(`tr[data-site-id="${siteId}"]`);
            if ($row.length) {
                const $statusBadge = $row.find('.wp-genius-badge');
                $statusBadge.removeClass('wp-genius-badge-success wp-genius-badge-warning wp-genius-badge-error');
                
                switch (status) {
                    case 'active':
                        $statusBadge.addClass('wp-genius-badge-success').text('Active');
                        break;
                    case 'inactive':
                        $statusBadge.addClass('wp-genius-badge-warning').text('Inactive');
                        break;
                    case 'error':
                        $statusBadge.addClass('wp-genius-badge-error').text('Error');
                        break;
                }
            }
        },

        checkEmptyTable: function() {
            const $table = $('.wp-list-table tbody');
            if ($table.find('tr').length === 0) {
                // Show empty state
                const emptyState = `
                    <div class="wp-genius-empty-state">
                        <div class="wp-genius-empty-icon">
                            <span class="dashicons dashicons-admin-site-alt3"></span>
                        </div>
                        <h2>No sites found</h2>
                        <p>All sites have been removed. Add a new site to get started.</p>
                        <a href="${wpGenius.add_site_url}" class="button button-primary">Add Your First Site</a>
                    </div>
                `;
                $table.closest('.wp-list-table').replaceWith(emptyState);
            }
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        WPGenius.init();
    });

})(jQuery);
