(function($){
    if (!window.sapmData) {
        return;
    }

    $(document).ready(function() {
        // Toggle groups
        $('.sapm-group-header').on('click', function() {
            $(this).toggleClass('collapsed');
            $(this).next('.sapm-group-content').toggleClass('hidden');
        });

        // Click on plugin tag - cycle: default -> enabled -> disabled -> defer -> default
        $('.sapm-plugin-tag').on('click', function() {
            var tag = $(this);
            var currentState = tag.data('state');
            var inheritedState = tag.data('inheritedState');
            var newState;

            if (inheritedState) {
                currentState = inheritedState;
                tag.removeClass('inherited');
                tag.removeAttr('data-inherited-state');
                tag.removeData('inheritedState');
            }

            if (currentState === 'default') {
                newState = 'enabled';
            } else if (currentState === 'enabled') {
                newState = 'disabled';
            } else if (currentState === 'disabled') {
                newState = 'defer';
            } else {
                newState = 'default';
            }

            tag.removeClass('enabled disabled defer default').addClass(newState);
            tag.data('state', newState);

            // Update icon
            var icon = tag.find('.dashicons');
            icon.removeClass('dashicons-yes dashicons-no dashicons-clock dashicons-minus');
            if (newState === 'enabled') {
                icon.addClass('dashicons-yes');
            } else if (newState === 'disabled') {
                icon.addClass('dashicons-no');
            } else if (newState === 'defer') {
                icon.addClass('dashicons-clock');
            } else {
                icon.addClass('dashicons-minus');
            }

            // Auto-save debounce
            clearTimeout(window.sapmSaveTimeout);
            window.sapmSaveTimeout = setTimeout(saveRules, 1000);
        });

        // Save rules
        function saveRules() {
            var rules = {};

            $('.sapm-screen-row').each(function() {
                var screenId = $(this).data('screen-id');
                var screenRules = {};
                var hasRules = false;

                $(this).find('.sapm-plugin-tag').each(function() {
                    var plugin = $(this).data('plugin');
                    var state = $(this).data('state');

                    if (state !== 'default') {
                        screenRules[plugin] = state;
                        hasRules = true;
                    }
                });

                if (hasRules) {
                    rules[screenId] = screenRules;
                }
            });

            $('#sapm-save-status').text(sapmData.strings.saving);

            $.post(sapmData.ajaxUrl, {
                action: 'sapm_save_rules',
                nonce: sapmData.nonce,
                rules: JSON.stringify(rules)
            }, function(response) {
                if (response.success) {
                    $('#sapm-save-status').text(sapmData.strings.saved).fadeOut(2000, function() {
                        $(this).text('').show();
                    });
                } else {
                    $('#sapm-save-status').text(sapmData.strings.error);
                }
            });
        }

        // Filter plugins
        $('#sapm-filter').on('input', function() {
            var filter = $(this).val().toLowerCase();

            $('.sapm-plugin-tag').each(function() {
                var name = $(this).text().toLowerCase();
                $(this).toggle(name.indexOf(filter) > -1);
            });
        });

        // Reset all
        $('#sapm-reset-all').on('click', function() {
            if (confirm('Are you sure you want to reset all rules?')) {
                $('.sapm-plugin-tag')
                    .removeClass('enabled disabled defer inherited')
                    .addClass('default')
                    .data('state', 'default')
                    .removeAttr('data-inherited-state')
                    .removeData('inheritedState');
                $('.sapm-plugin-tag .dashicons').removeClass('dashicons-yes dashicons-no dashicons-clock').addClass('dashicons-minus');
                saveRules();
            }
        });

        // ==========================================
        // REQUEST TYPE SETTINGS (AJAX/REST/Cron/CLI)
        // ==========================================

        // Toggle mode - show/hide configuration
        $('.sapm-rt-mode').on('change', function() {
            var type = $(this).data('type');
            var mode = $(this).val();
            var row = $('.sapm-request-type-row[data-request-type="' + type + '"]');

            // Hide both configurations
            row.find('.sapm-rt-blacklist-config').hide();
            row.find('.sapm-rt-whitelist-config').hide();

            // Show relevant configuration
            if (mode === 'blacklist') {
                row.find('.sapm-rt-blacklist-config').slideDown(200);
            } else if (mode === 'whitelist') {
                row.find('.sapm-rt-whitelist-config').slideDown(200);
            }
        });

        // Save request type settings
        $('#sapm-save-request-types').on('click', function() {
            var $btn = $(this);
            var $status = $('#sapm-rt-save-status');
            var rules = {};

            // Collect data for each type (ajax, rest, cron, cli)
            $('.sapm-request-type-row').each(function() {
                var type = $(this).data('request-type');
                var row = $(this);
                var mode = row.find('.sapm-rt-mode:checked').val() || 'passthrough';

                var typeRules = {
                    '_mode': mode,
                    'disabled_plugins': [],
                    'default_plugins': [],
                    '_detect_by_action': false,
                    '_detect_by_namespace': false
                };

                // Blacklist - collect disabled plugins
                if (mode === 'blacklist') {
                    row.find('.sapm-rt-disabled-plugin:checked').each(function() {
                        typeRules.disabled_plugins.push($(this).val());
                    });
                }

                // Whitelist - collect enabled plugins
                if (mode === 'whitelist') {
                    row.find('.sapm-rt-enabled-plugin:checked').each(function() {
                        typeRules.default_plugins.push($(this).val());
                    });

                    // Smart detection
                    var detection = row.find('.sapm-rt-detection').is(':checked');
                    if (type === 'ajax') {
                        typeRules._detect_by_action = detection;
                    } else if (type === 'rest') {
                        typeRules._detect_by_namespace = detection;
                    }
                }

                rules[type] = typeRules;
            });

            // Disable button during saving
            $btn.prop('disabled', true);
            $status.text(sapmData.strings.saving || 'Saving...').css('color', '#0073aa');

            // AJAX request
            $.post(sapmData.ajaxUrl, {
                action: 'sapm_save_request_type_rules',
                nonce: sapmData.nonce,
                rules: JSON.stringify(rules)
            }, function(response) {
                $btn.prop('disabled', false);

                if (response.success) {
                    $status.text(sapmData.strings.saved || 'Saved!').css('color', '#46b450');
                    setTimeout(function() {
                        $status.fadeOut(400, function() {
                            $(this).text('').show();
                        });
                    }, 2000);
                } else {
                    $status.text(response.data || 'Error saving').css('color', '#dc3232');
                }
            }).fail(function() {
                $btn.prop('disabled', false);
                $status.text('AJAX error').css('color', '#dc3232');
            });
        });

        // ==========================================
        // REQUEST TYPE PERFORMANCE SAMPLING
        // ==========================================

        // Load performance data
        $('#sapm-load-rt-performance').on('click', function() {
            var $btn = $(this);
            var $container = $('#sapm-rt-performance-container');

            $btn.prop('disabled', true).find('.dashicons').addClass('spin');

            $.post(sapmData.ajaxUrl, {
                action: 'sapm_get_request_type_performance',
                nonce: sapmData.nonce
            }, function(response) {
                $btn.prop('disabled', false).find('.dashicons').removeClass('spin');

                if (response.success && response.data) {
                    renderPerformanceData(response.data);
                    $container.slideDown(300);
                } else {
                    alert(response.data || 'Error loading data');
                }
            }).fail(function() {
                $btn.prop('disabled', false).find('.dashicons').removeClass('spin');
                alert('AJAX error');
            });
        });

        // Delete all performance data
        $('#sapm-clear-rt-performance').on('click', function() {
            if (!confirm('Are you sure you want to delete all measured performance data?')) {
                return;
            }

            var $btn = $(this);
            $btn.prop('disabled', true);

            $.post(sapmData.ajaxUrl, {
                action: 'sapm_clear_request_type_performance',
                nonce: sapmData.nonce,
                type: 'all'
            }, function(response) {
                $btn.prop('disabled', false);

                if (response.success) {
                    $('#sapm-rt-performance-container').html('<p style="color: #46b450;">Data have been deleted..</p>');
                } else {
                    alert(response.data || 'Error deleting');
                }
            }).fail(function() {
                $btn.prop('disabled', false);
                alert('AJAX error');
            });
        });

        // Render performance data
        function renderPerformanceData(data) {
            var $container = $('#sapm-rt-performance-container');
            var html = '';

            var typeLabels = {
                ajax: 'AJAX',
                rest: 'REST API',
                cron: 'WP-Cron',
                cli: 'WP-CLI'
            };

            var hasAnyData = false;

            for (var type in data) {
                var triggers = data[type];
                var triggerCount = Object.keys(triggers).length;

                if (triggerCount === 0) {
                    continue;
                }

                hasAnyData = true;

                html += '<div class="sapm-rt-perf-type" style="margin-bottom: 20px; padding: 15px; background: #f9f9f9; border-left: 4px solid #0073aa;">';
                html += '<h5 style="margin: 0 0 10px 0; cursor: pointer;" class="sapm-rt-perf-type-toggle">';
                html += '<span class="dashicons dashicons-arrow-down-alt2" style="font-size: 16px;"></span> ';
                html += typeLabels[type] || type.toUpperCase();
                html += ' <span style="color: #666; font-weight: normal;">(' + triggerCount + ' triggers)</span>';
                html += '</h5>';
                html += '<div class="sapm-rt-perf-type-content">';

                // Arrange triggers by total samples desc
                var sortedTriggers = Object.keys(triggers).sort(function(a, b) {
                    return triggers[b].samples - triggers[a].samples;
                });

                for (var i = 0; i < sortedTriggers.length; i++) {
                    var trigger = sortedTriggers[i];
                    var tdata = triggers[trigger];

                    html += '<div class="sapm-rt-perf-trigger" style="margin: 10px 0; padding: 10px; background: #fff; border: 1px solid #ddd;">';
                    html += '<strong style="color: #23282d;">' + escapeHtml(trigger) + '</strong>';
                    html += ' <span style="color: #888; font-size: 12px;">(samples: ' + tdata.samples + ', avg: ' + tdata.avg_ms.toFixed(1) + 'ms, ' + tdata.avg_queries.toFixed(0) + ' queries)</span>';

                    // Plugin breakdown
                    if (tdata.plugins && tdata.plugins.length > 0) {
                        html += '<table style="width: 100%; margin-top: 8px; font-size: 12px; border-collapse: collapse;">';
                        html += '<thead><tr style="background: #f1f1f1;">';
                        html += '<th style="text-align: left; padding: 5px; border: 1px solid #ddd;">Plugin</th>';
                        html += '<th style="text-align: right; padding: 5px; border: 1px solid #ddd; width: 80px;">Avg ms</th>';
                        html += '<th style="text-align: right; padding: 5px; border: 1px solid #ddd; width: 80px;">Avg queries</th>';
                        html += '<th style="text-align: right; padding: 5px; border: 1px solid #ddd; width: 70px;">Samples</th>';
                        html += '</tr></thead><tbody>';

                        // Sort plugins by avg_ms desc
                        tdata.plugins.sort(function(a, b) {
                            return b.avg_ms - a.avg_ms;
                        });

                        for (var j = 0; j < tdata.plugins.length; j++) {
                            var plugin = tdata.plugins[j];
                            var rowColor = plugin.avg_ms > 50 ? '#ffe0e0' : (plugin.avg_ms > 20 ? '#fff8e0' : '#fff');

                            html += '<tr style="background: ' + rowColor + ';">';
                            html += '<td style="padding: 4px 5px; border: 1px solid #ddd;">' + escapeHtml(plugin.name) + '</td>';
                            html += '<td style="text-align: right; padding: 4px 5px; border: 1px solid #ddd;">' + plugin.avg_ms.toFixed(1) + '</td>';
                            html += '<td style="text-align: right; padding: 4px 5px; border: 1px solid #ddd;">' + plugin.avg_queries.toFixed(1) + '</td>';
                            html += '<td style="text-align: right; padding: 4px 5px; border: 1px solid #ddd;">' + plugin.samples + '</td>';
                            html += '</tr>';
                        }

                        html += '</tbody></table>';
                    }

                    html += '</div>'; // trigger
                }

                html += '</div>'; // type-content
                html += '</div>'; // type
            }

            if (!hasAnyData) {
                html = '<p style="color: #888; font-style: italic;">No data collected yet. Sampling runs automatically for 5% of requests.</p>';
            }

            $container.html(html);

            // Toggle collapse for each type
            $container.find('.sapm-rt-perf-type-toggle').on('click', function() {
                var $content = $(this).next('.sapm-rt-perf-type-content');
                var $icon = $(this).find('.dashicons');

                $content.slideToggle(200);
                $icon.toggleClass('dashicons-arrow-down-alt2 dashicons-arrow-right-alt2');
            });
        }

        // Helper: escape HTML
        function escapeHtml(text) {
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // ==========================================
        // MODE SWITCHER (Manual / Auto)
        // ==========================================

        // Mode switcher
        $('input[name="sapm_mode"]').on('change', function() {
            var newMode = $(this).val();
            var $status = $('#sapm-mode-status');
            var $suggestionsBox = $('#sapm-auto-suggestions');

            $status.text(sapmData.strings.saving).css('color', '#0073aa');

            $.post(sapmData.ajaxUrl, {
                action: 'sapm_set_mode',
                nonce: sapmData.nonce,
                mode: newMode
            }, function(response) {
                if (response.success) {
                    $status.text(sapmData.strings.modeChanged).css('color', '#46b450');
                    setTimeout(function() {
                        $status.fadeOut(400, function() {
                            $(this).text('').show();
                        });
                    }, 2000);

                    // Show/hide auto suggestions box
                    if (newMode === 'auto') {
                        $suggestionsBox.slideDown(300);
                        loadAutoSuggestions();
                    } else {
                        $suggestionsBox.slideUp(300);
                    }
                } else {
                    $status.text(sapmData.strings.modeError).css('color', '#dc3232');
                }
            }).fail(function() {
                $status.text('AJAX error').css('color', '#dc3232');
            });
        });

        // Load auto suggestions
        function loadAutoSuggestions() {
            var $content = $('#sapm-suggestions-content');
            $content.html('<p style="color: #666;">' + sapmData.strings.loading + '</p>');

            $.post(sapmData.ajaxUrl, {
                action: 'sapm_get_auto_suggestions',
                nonce: sapmData.nonce
            }, function(response) {
                if (response.success && response.data && response.data.suggestions) {
                    renderAutoSuggestions(response.data.suggestions);
                } else {
                    $content.html('<p style="color: #dc3232;">' + (response.data?.message || sapmData.strings.error) + '</p>');
                }
            }).fail(function() {
                $content.html('<p style="color: #dc3232;">AJAX error</p>');
            });
        }

        // Render auto suggestions
        function renderAutoSuggestions(data) {
            var $content = $('#sapm-suggestions-content');
            var html = '';

            var typeLabels = {
                ajax: 'AJAX',
                rest: 'REST API',
                cron: 'WP-Cron',
                cli: 'WP-CLI'
            };

            var hasAnySuggestion = false;
            var hasScreenSuggestions = false;

            // Section 1: Request Type suggestions (AJAX/REST/Cron/CLI)
            html += '<div class="sapm-suggestion-section">';
            html += '<h4 style="margin: 0 0 10px 0; padding: 8px 12px; background: #0073aa; color: #fff; border-radius: 3px;">';
            html += '<span class="dashicons dashicons-rest-api" style="margin-right: 5px;"></span>';
            html += 'Suggestions for request types (AJAX/REST/Cron/CLI)';
            html += '</h4>';
            html += '<p style="margin: 0 0 15px 0; color: #666; font-size: 12px; padding: 0 5px;">'; 
            html += '<strong>Explanation:</strong> These rules affect plugin loading during AJAX requests, REST API calls, WP-Cron tasks, and WP-CLI commands. ';
            html += 'They apply <strong>globally</strong> to all requests of a given type.';
            html += '</p>';

            var requestTypeHtml = '';
            for (var type in data) {
                if (type === 'admin_screens') continue; // Handle separately
                
                var typeData = data[type];
                var blocks = typeData.suggested_blocks || [];
                var whitelist = typeData.suggested_whitelist || [];

                if (blocks.length === 0 && whitelist.length === 0) {
                    continue;
                }

                hasAnySuggestion = true;

                requestTypeHtml += '<div class="sapm-suggestion-type" style="margin: 10px 0; padding: 10px; background: #f9f9f9; border-left: 3px solid #0073aa;">';
                requestTypeHtml += '<strong>' + typeLabels[type] + '</strong>';

                if (blocks.length > 0) {
                    requestTypeHtml += '<div style="margin-top: 8px;"><span style="color: #dc3232; font-weight: bold;">' + sapmData.strings.suggestBlock + ':</span><ul style="margin: 5px 0 5px 20px;">';
                    for (var i = 0; i < blocks.length; i++) {
                        var b = blocks[i];
                        requestTypeHtml += '<li>';
                        requestTypeHtml += '<input type="checkbox" class="sapm-suggestion-item" data-type="' + type + '" data-action="block" data-plugin="' + escapeHtml(b.plugin) + '" checked> ';
                        requestTypeHtml += escapeHtml(b.plugin);
                        if (b.savings_ms) {
                            requestTypeHtml += ' <span style="color: #d54e21; font-size: 11px;">(savings: ' + b.savings_ms.toFixed(1) + 'ms)</span>';
                        }
                        if (b.confidence) {
                            requestTypeHtml += ' <span style="color: #666; font-size: 11px;">(' + sapmData.strings.confidence + ': ' + (b.confidence * 100).toFixed(0) + '%)</span>';
                        }
                        requestTypeHtml += '</li>';
                    }
                    requestTypeHtml += '</ul></div>';
                }

                if (whitelist.length > 0) {
                    requestTypeHtml += '<div style="margin-top: 8px;"><span style="color: #46b450; font-weight: bold;">' + sapmData.strings.suggestWhitelist + ':</span><ul style="margin: 5px 0 5px 20px;">';
                    for (var j = 0; j < whitelist.length; j++) {
                        var w = whitelist[j];
                        requestTypeHtml += '<li>';
                        requestTypeHtml += '<input type="checkbox" class="sapm-suggestion-item" data-type="' + type + '" data-action="whitelist" data-plugin="' + escapeHtml(w.plugin) + '" checked> ';
                        requestTypeHtml += escapeHtml(w.plugin);
                        if (w.confidence) {
                            requestTypeHtml += ' <span style="color: #666; font-size: 11px;">(' + sapmData.strings.confidence + ': ' + (w.confidence * 100).toFixed(0) + '%)</span>';
                        }
                        requestTypeHtml += '</li>';
                    }
                    requestTypeHtml += '</ul></div>';
                }

                requestTypeHtml += '</div>';
            }

            if (requestTypeHtml === '') {
                html += '<p style="color: #888; font-style: italic; padding: 10px;">No suggestions available yet. Collecting data from AJAX/REST/Cron requests.</p>';
            } else {
                html += requestTypeHtml;
            }
            html += '</div>';

            // Section 2: Per-Screen suggestions (Admin screens)
            html += '<div class="sapm-suggestion-section" style="margin-top: 20px;">';
            html += '<h4 style="margin: 0 0 10px 0; padding: 8px 12px; background: #46b450; color: #fff; border-radius: 3px;">';
            html += '<span class="dashicons dashicons-admin-generic" style="margin-right: 5px;"></span>';
            html += 'Suggestions for individual admin screens';
            html += '</h4>';
            html += '<p style="margin: 0 0 15px 0; color: #666; font-size: 12px; padding: 0 5px;">'; 
            html += '<strong>Explanation:</strong> These rules allow you to block or defer plugin loading on <strong>specific admin screens</strong> in WordPress. ';
            html += 'For example, you can block an SEO plugin on the media screen because it is not needed there.';
            html += '</p>';

            var adminScreens = data.admin_screens || [];
            if (adminScreens.length > 0) {
                hasAnySuggestion = true;
                hasScreenSuggestions = true;
                
                var totalSavings = 0;
                for (var s = 0; s < adminScreens.length; s++) {
                    var screen = adminScreens[s];
                    var screenBlocks = screen.suggested_blocks || [];
                    var screenDefer = screen.suggested_defer || [];
                    
                    for (var sb = 0; sb < screenBlocks.length; sb++) {
                        totalSavings += screenBlocks[sb].savings_ms || 0;
                    }
                    for (var sd = 0; sd < screenDefer.length; sd++) {
                        totalSavings += screenDefer[sd].savings_ms || 0;
                    }
                }
                
                if (totalSavings > 0) {
                    html += '<div style="background: #e7f7e7; padding: 10px; margin-bottom: 15px; border-radius: 3px;">';
                    html += '<strong style="color: #46b450;">💡 Total potential savings: ' + totalSavings.toFixed(1) + 'ms</strong>';
                    html += '</div>';
                }
                
                for (var k = 0; k < adminScreens.length; k++) {
                    var screenData = adminScreens[k];
                    var blocks = screenData.suggested_blocks || [];
                    var defer = screenData.suggested_defer || [];
                    
                    html += '<div class="sapm-suggestion-type" style="margin: 10px 0; padding: 10px; background: #f0f8f0; border-left: 3px solid #46b450;">';
                    html += '<strong style="font-size: 13px;">' + escapeHtml(screenData.screen_label || screenData.screen) + '</strong>';
                    html += ' <code style="font-size: 11px; background: #e0e0e0; padding: 2px 5px; border-radius: 2px;">' + escapeHtml(screenData.screen) + '</code>';
                    html += ' <span style="color: #888; font-size: 11px;">(total load: ' + screenData.total_load_ms.toFixed(1) + 'ms)</span>';
                    
                    if (blocks.length > 0) {
                        html += '<div style="margin-top: 8px;"><span style="color: #dc3232; font-weight: bold;">🚫 Block:</span><ul style="margin: 5px 0 5px 20px;">';
                        for (var m = 0; m < blocks.length; m++) {
                            var blk = blocks[m];
                            html += '<li>';
                            html += '<input type="checkbox" class="sapm-suggestion-item" data-type="screen" data-screen="' + escapeHtml(screenData.screen) + '" data-action="block" data-plugin="' + escapeHtml(blk.plugin) + '" checked> ';
                            html += escapeHtml(blk.plugin);
                            html += ' <span style="color: #d54e21; font-size: 11px;">(savings: ' + blk.savings_ms.toFixed(1) + 'ms)</span>';
                            html += ' <span style="color: #666; font-size: 11px;">(' + sapmData.strings.confidence + ': ' + (blk.confidence * 100).toFixed(0) + '%)</span>';
                            html += '</li>';
                        }
                        html += '</ul></div>';
                    }
                    
                    if (defer.length > 0) {
                        html += '<div style="margin-top: 8px;"><span style="color: #f0ad4e; font-weight: bold;">⏳ Defer:</span><ul style="margin: 5px 0 5px 20px;">';
                        for (var n = 0; n < defer.length; n++) {
                            var def = defer[n];
                            html += '<li>';
                            html += '<input type="checkbox" class="sapm-suggestion-item" data-type="screen" data-screen="' + escapeHtml(screenData.screen) + '" data-action="defer" data-plugin="' + escapeHtml(def.plugin) + '" checked> ';
                            html += escapeHtml(def.plugin);
                            html += ' <span style="color: #d54e21; font-size: 11px;">(savings: ' + def.savings_ms.toFixed(1) + 'ms)</span>';
                            html += ' <span style="color: #666; font-size: 11px;">(' + sapmData.strings.confidence + ': ' + (def.confidence * 100).toFixed(0) + '%)</span>';
                            html += '</li>';
                        }
                        html += '</ul></div>';
                    }
                    
                    html += '</div>';
                }
            } else {
                html += '<p style="color: #888; font-style: italic; padding: 10px;">';
                html += 'No suggestions available for individual screens yet. ';
                html += 'Browse different admin pages to collect data.';
                html += '</p>';
            }
            html += '</div>';

            if (!hasAnySuggestion) {
                html = '<div style="text-align: center; padding: 20px;">';
                html += '<span class="dashicons dashicons-chart-area" style="font-size: 48px; color: #ddd;"></span>';
                html += '<p style="color: #888; font-style: italic;">' + sapmData.strings.noSuggestions + '</p>';
                html += '<p style="color: #666; font-size: 12px;">Browse WordPress admin to collect data.</p>';
                html += '</div>';
            }

            $content.html(html);
        }

        // Refresh suggestions
        $('#sapm-refresh-suggestions').on('click', function() {
            loadAutoSuggestions();
        });

        // Reset Auto data (sampling + rules)
        $('#sapm-reset-auto-data').on('click', function() {
            if (!confirm('Are you sure you want to delete all collected data and reset Auto rules to default values?')) {
                return;
            }

            var $btn = $(this);
            $btn.prop('disabled', true);

            $.post(sapmData.ajaxUrl, {
                action: 'sapm_reset_auto_data',
                nonce: sapmData.nonce
            }, function(response) {
                $btn.prop('disabled', false);

                if (response.success) {
                    alert('Auto data has been reset. Page will reload.');
                    location.reload();
                } else {
                    alert('Error: ' + (response.data?.message || 'Unknown error'));
                }
            }).fail(function() {
                $btn.prop('disabled', false);
                alert('AJAX error');
            });
        });

        // Apply suggestions
        $('#sapm-apply-suggestions').on('click', function() {
            var $btn = $(this);
            var suggestions = [];

            $('.sapm-suggestion-item:checked').each(function() {
                var suggestion = {
                    type: $(this).data('type'),
                    action: $(this).data('action'),
                    plugin: $(this).data('plugin')
                };
                // Add screen ID for screen-based suggestions
                if ($(this).data('screen')) {
                    suggestion.screen = $(this).data('screen');
                }
                suggestions.push(suggestion);
            });

            if (suggestions.length === 0) {
                alert('No suggestions selected to apply.');
                return;
            }

            $btn.prop('disabled', true);

            $.post(sapmData.ajaxUrl, {
                action: 'sapm_apply_auto_rules',
                nonce: sapmData.nonce,
                suggestions: JSON.stringify(suggestions)
            }, function(response) {
                $btn.prop('disabled', false);

                if (response.success) {
                    alert('Suggestions have been applied! Page will reload.');
                    location.reload();
                } else {
                    alert('Error: ' + (response.data || 'Unknown error'));
                }
            }).fail(function() {
                $btn.prop('disabled', false);
                alert('AJAX error');
            });
        });

        // If mode is 'auto', show suggestions box on load
        if (sapmData.currentMode === 'auto') {
            $('#sapm-auto-suggestions').show();
            loadAutoSuggestions();
        }
    });
})(jQuery);
