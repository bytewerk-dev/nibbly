<?php if (!defined('NIBBLY_DASHBOARD')) { http_response_code(404); exit; } ?>
    // ============================================================
    // SETTINGS MANAGEMENT
    // ============================================================

    // Costs are kept with four decimals of a cent; they are rounded here, when shown. Amounts under one cent keep their decimals.
    function formatAiCents(cents) {
        var euro = (parseFloat(cents) || 0) / 100;
        return (euro > 0 && euro < 0.01 ? euro.toFixed(4) : euro.toFixed(2)) + ' EUR';
    }

    function updateAiUsage(usage) {
        var el = document.getElementById('aiUsageSummary');
        if (!AI_FEATURES_ENABLED || !aiServiceIsUsable(currentAiSettings || {})) {
            if (el) el.hidden = true;
            renderAiUsagePanel(null);
            return;
        }
        renderAiLimitWarnings(usage ? usage.warnings : []);
        if (!el || !usage) return;
        el.hidden = false;
        var today = usage.today || {};
        var month = usage.month || {};
        el.textContent = t('ai.usage_summary', {
            today: today.requests || 0,
            cost: formatAiCents(month.estimatedCostCents || 0)
        });
        renderAiUsagePanel(usage);
    }

    // ---- Limit warnings ---------------------------------------------------------------------------------------------
    var AI_LIMIT_FIELDS = { month: 'aiMonthlyBudget', requests: 'aiDailyRequests', text: 'aiDailyTextRequests', images: 'aiDailyImageRequests' };
    var aiLimitWarnings = [];

    function aiLimitValue(kind, value) {
        return kind === 'month' ? formatAiCents(value) : String(value);
    }

    function aiLimitDismissKey(warning) {
        return 'nb.aiLimitDismissed:' + warning.kind + ':' + warning.level + ':' + new Date().toISOString().slice(0, warning.kind === 'month' ? 7 : 10);
    }

    function aiLimitDismissed(warning) {
        if (warning.level === 'reached') return false;
        try { return window.sessionStorage.getItem(aiLimitDismissKey(warning)) === '1'; } catch (error) { return false; }
    }

    function renderAiLimitWarnings(warnings) {
        aiLimitWarnings = Array.isArray(warnings) ? warnings : [];
        document.querySelectorAll('[data-ai-limit-banner]').forEach(function(box) {
            var shown = aiLimitWarnings.filter(function(warning) { return !aiLimitDismissed(warning); });
            box.replaceChildren();
            box.hidden = !shown.length || !AI_FEATURES_ENABLED;
            if (box.hidden) return;
            var isAdmin = USER_ROLE === 'admin';
            var inner = document.createElement('div');
            inner.className = 'info-banner__inner';
            shown.forEach(function(warning) {
                var line = document.createElement('p');
                line.className = 'ai-limit-banner__line ai-limit-banner__line--' + warning.level;
                var params = { kind: t('ai.limit_kind_' + warning.kind), used: aiLimitValue(warning.kind, warning.used), limit: aiLimitValue(warning.kind, warning.limit), percent: warning.percent };
                line.textContent = t(warning.level === 'reached' ? 'ai.limit_reached' : 'ai.limit_warning', params);
                if (warning.level !== 'reached') {
                    var dismiss = document.createElement('button');
                    dismiss.type = 'button';
                    dismiss.className = 'btn btn-link btn-sm';
                    dismiss.textContent = t('ai.limit_dismiss');
                    dismiss.addEventListener('click', function() {
                        try { window.sessionStorage.setItem(aiLimitDismissKey(warning), '1'); } catch (error) { /* only this view */ }
                        renderAiLimitWarnings(aiLimitWarnings);
                    });
                    line.append(' ', dismiss);
                }
                inner.appendChild(line);
            });
            var actions = document.createElement('div');
            actions.className = 'ai-limit-banner__actions';
            if (!isAdmin) {
                var note = document.createElement('span');
                note.textContent = t('ai.limit_ask_admin');
                actions.appendChild(note);
            } else if (box.getAttribute('data-ai-limit-banner') === 'settings') {
                var raise = document.createElement('button');
                raise.type = 'button';
                raise.className = 'btn btn-secondary btn-sm';
                raise.textContent = t('ai.limit_raise');
                raise.addEventListener('click', function() {
                    aiLimitWarnings.forEach(function(warning) {
                        var field = document.getElementById(AI_LIMIT_FIELDS[warning.kind]);
                        if (!field) return;
                        // Fields are in cents (month) or counts; raise by half, at least by one.
                        var current = parseInt(field.value || '0', 10) || 0;
                        field.value = Math.max(current + 1, Math.ceil(current * 1.5));
                    });
                    showToast(t('ai.limit_raised'), 'info');
                });
                actions.appendChild(raise);
            } else {
                var adjust = document.createElement('button');
                adjust.type = 'button';
                adjust.className = 'btn btn-secondary btn-sm';
                adjust.textContent = t('ai.limit_adjust');
                adjust.addEventListener('click', function() { switchTab('settings', { settingsTab: 'ai' }); });
                actions.appendChild(adjust);
            }
            inner.appendChild(actions);
            box.appendChild(inner);
        });
    }

    function aiUsageStatTile(value, label, sub) {
        var tile = document.createElement('div');
        tile.className = 'ai-usage-stat';
        var valueEl = document.createElement('span');
        valueEl.className = 'ai-usage-stat__value';
        valueEl.textContent = value;
        var labelEl = document.createElement('span');
        labelEl.className = 'ai-usage-stat__label';
        labelEl.textContent = label;
        tile.appendChild(valueEl);
        tile.appendChild(labelEl);
        if (sub) {
            var subEl = document.createElement('span');
            subEl.className = 'ai-usage-stat__sub';
            subEl.textContent = sub;
            tile.appendChild(subEl);
        }
        return tile;
    }

    function renderAiUsagePanel(usage) {
        var panel = document.getElementById('aiUsagePanel');
        var body = document.getElementById('aiUsagePanelBody');
        if (!panel || !body) return;
        if (!usage) {
            panel.hidden = true;
            return;
        }
        panel.hidden = false;
        var today = usage.today || {};
        var month = usage.month || {};
        var budgetCents = parseInt((currentAiSettings && currentAiSettings.limits && currentAiSettings.limits.monthlyBudgetCents) || 0, 10);

        body.innerHTML = '';
        var grid = document.createElement('div');
        grid.className = 'ai-usage-grid';
        grid.appendChild(aiUsageStatTile(
            String(today.requests || 0),
            t('ai.usage_requests_today'),
            t('ai.usage_text_image_split', { text: today.textRequests || 0, images: today.imageRequests || 0 })
        ));
        grid.appendChild(aiUsageStatTile(
            formatAiCents(month.estimatedCostCents || 0),
            t('ai.usage_cost_month'),
            t('ai.usage_requests_month', { requests: month.requests || 0 })
        ));
        grid.appendChild(aiUsageStatTile(
            ((month.inputTokens || 0) + (month.outputTokens || 0)).toLocaleString(),
            t('ai.usage_tokens_month'),
            t('ai.usage_tokens_split', {
                input: (month.inputTokens || 0).toLocaleString(),
                output: (month.outputTokens || 0).toLocaleString()
            })
        ));
        const reservations = usage.reservations || {};
        if (month.unpricedRequests > 0) {
            var unpriced = document.createElement('p');
            unpriced.className = 'form-hint ai-unpriced-note';
            unpriced.textContent = t('ai.usage_unpriced', { count: month.unpricedRequests });
            body.appendChild(unpriced);
        }
        grid.appendChild(aiUsageStatTile(formatAiCents(reservations.reservedCents || 0), t('ai.reserved'), t('ai.pending_requests', { count: reservations.pendingRequests || 0 })));
        body.appendChild(grid);
        const budgetHint = document.createElement('p');
        budgetHint.className = 'form-hint'; budgetHint.textContent = t('ai.budget_explanation');
        body.appendChild(budgetHint);

        if (budgetCents > 0) {
            var spent = (month.estimatedCostCents || 0) + (reservations.reservedCents || 0);
            var ratio = spent / budgetCents;
            var budget = document.createElement('div');
            budget.className = 'ai-usage-budget';
            var header = document.createElement('div');
            header.className = 'ai-usage-budget__header';
            var label = document.createElement('span');
            label.className = 'ai-usage-budget__label';
            label.textContent = t('ai.usage_budget');
            var value = document.createElement('span');
            value.className = 'ai-usage-budget__value';
            value.textContent = t('ai.usage_budget_value', {
                spent: formatAiCents(spent),
                budget: formatAiCents(budgetCents),
                percent: Math.min(999, Math.round(ratio * 100))
            });
            header.appendChild(label);
            header.appendChild(value);
            var bar = document.createElement('div');
            bar.className = 'ai-usage-budget__bar';
            var fill = document.createElement('div');
            fill.className = 'ai-usage-budget__fill'
                + (ratio >= 1 ? ' ai-usage-budget__fill--over' : (ratio >= 0.8 ? ' ai-usage-budget__fill--warn' : ''));
            fill.style.width = Math.min(100, Math.max(ratio > 0 ? 2 : 0, Math.round(ratio * 100))) + '%';
            bar.appendChild(fill);
            budget.appendChild(header);
            budget.appendChild(bar);
            body.appendChild(budget);
        }
    }

    function switchAiToolTab(tool) {
        var requestedTool = ['image', 'text', 'audit'].indexOf(tool) !== -1 ? tool : 'text';
        var availableTabs = Array.from(document.querySelectorAll('.ai-tool-tab')).filter(function(tab) {
            return !tab.hidden;
        });
        if (!availableTabs.length) return;
        var activeTool = availableTabs.some(function(tab) {
            return tab.dataset.aiToolTab === requestedTool;
        }) ? requestedTool : availableTabs[0].dataset.aiToolTab;
        document.querySelectorAll('.ai-tool-tab').forEach(function(tab) {
            var isActive = tab.dataset.aiToolTab === activeTool;
            tab.classList.toggle('active', isActive);
            tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });
        document.querySelectorAll('.ai-tool-panel').forEach(function(panel) {
            panel.hidden = panel.dataset.aiToolPanel !== activeTool;
            panel.classList.toggle('active', panel.dataset.aiToolPanel === activeTool);
        });
        // Opening the image tool under OpenRouter loads live image prices so
        // the estimated per-image cost can be shown.
        if (activeTool === 'image' && aiSavedProviderKey() === 'openrouter') {
            loadOpenRouterModels();
        }
    }

    function openAiImageGenerator(promptText, aspectRatio) {
        if (!dashboardAiImageUsable) {
            showToast(t('ai.image_generator_disabled'), 'warning');
            return;
        }
        switchTab('home');
        switchAiToolTab('image');
        var prompt = document.getElementById('aiImagePrompt');
        if (prompt && promptText) {
            prompt.value = promptText;
        }
        if (aspectRatio) {
            setAiImageRatio(aspectRatio);
        }
        if (prompt) {
            prompt.focus();
        }
    }

    function setAiImageRatio(aspectRatio) {
        var ratioInput = document.getElementById('aiImageRatio');
        if (ratioInput) ratioInput.value = aspectRatio || 'auto';
        if (typeof updateAiImageRatioIcon === 'function') {
            updateAiImageRatioIcon();
        }
    }

    function getNewsImagePrompt() {
        var title = document.getElementById('newsTitle')?.value.trim() || '';
        var excerpt = document.getElementById('newsExcerpt')?.value.trim() || '';
        var content = document.getElementById('newsContentWysiwyg')?.innerText.trim() || '';
        var language = document.getElementById('newsLang')?.value.trim() || '';
        var parts = [title, excerpt, content].filter(Boolean).join('\n\n').slice(0, 900);
        return [
            'Create a 16:9 editorial cover image for a website news post.',
            title ? 'Article title: ' + title : '',
            language ? 'Content language: ' + language : '',
            parts ? 'Context:\n' + parts : '',
            'Use the article context to choose a fitting subject, mood, and setting.',
            'No text, no logo, no UI, no watermark. Natural composition with a clear subject and enough negative space for cropping.'
        ].filter(Boolean).join('\n\n');
    }

    function getSeoOgImagePrompt() {
        var title = document.getElementById('seoOgTitle')?.value.trim()
            || document.getElementById('seoTitle')?.value.trim()
            || currentContent?.title
            || currentPage
            || '';
        var description = document.getElementById('seoOgDescription')?.value.trim()
            || document.getElementById('seoDescription')?.value.trim()
            || currentContent?.description
            || '';
        var pageText = extractContentText(currentContent || {}).slice(0, 700);
        return [
            'Create a 16:9 landscape Open Graph/social sharing image for this page. It should work well when cropped to a 1200x630 preview.',
            title ? 'Page title: ' + title : '',
            description ? 'Description: ' + description : '',
            pageText ? 'Page context: ' + pageText : '',
            'No embedded text, no logos unless explicitly part of the brand, no UI mockup. Clean social-sharing composition with a strong visual focal point.'
        ].filter(Boolean).join('\n\n');
    }

    function dashboardAnalyticsRangeLabel(period, count) {
        if (period === 'months') return t('dashboard_home.range_label_months', {count: count || 12});
        if (period === 'years') return t('dashboard_home.range_label_years');
        return t('dashboard_home.range_label_days', {count: count || 30});
    }

    function updateDashboardAnalyticsRangeUi(period, count) {
        var label = dashboardAnalyticsRangeLabel(period, count);
        var labelMap = {
            dashboardViewsPeriodLabel: t('dashboard_home.views_period', {period: label}),
            dashboardVisitorsPeriodLabel: t('dashboard_home.visitors_period', {period: label}),
            dashboardVisitsPeriodLabel: t('dashboard_home.visits_period', {period: label}),
            dashboardChartRangeLabel: t('dashboard_home.views_period', {period: label})
        };
        Object.keys(labelMap).forEach(function(id) {
            var el = document.getElementById(id);
            if (el) el.textContent = labelMap[id];
        });
        document.querySelectorAll('.dashboard-range-tab').forEach(function(tab) {
            var active = tab.dataset.analyticsPeriod === period && parseInt(tab.dataset.analyticsCount || '0', 10) === count;
            tab.classList.toggle('active', active);
        });
    }

    function setDashboardAnalyticsRange(period, count) {
        currentDashboardAnalyticsRange = {
            period: ['days', 'months', 'years'].includes(period) ? period : 'days',
            count: parseInt(count || '0', 10)
        };
        updateDashboardAnalyticsRangeUi(currentDashboardAnalyticsRange.period, currentDashboardAnalyticsRange.count);
        loadDashboardOverview();
    }

    function formatDashboardDate(value) {
        if (!value) return '—';
        var date = typeof value === 'number' ? new Date(value * 1000) : new Date(value);
        if (isNaN(date.getTime())) return '—';
        return date.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
    }

    async function openDashboardRecentEdit(item) {
        if (!item) return;
        if (item.type === 'news') {
            switchTab('news');
            if (!newsLoaded) {
                newsLoaded = true;
                await loadNews();
            }
            editPost(item.id);
            return;
        }
        if (item.type === 'page' && item.id) {
            window.location.href = '#page/' + item.id;
            applyDashboardRoute(true);
        }
    }

    function renderDashboardStatus(status) {
        var target = document.getElementById('dashboardStatusStrip');
        if (!target || !status) return;

        var recent = (status.recentEdits || [])[0] || null;
        window.dashboardRecentStatusItems = status.recentEdits || [];
        var backup = status.backup || {};
        var users = status.users || {};
        var currentUser = users.current || {};
        var chips = [];

        if (isDashboardModuleEnabled('mails')) {
            var unreadCount = status.messages?.unread || 0;
            chips.push('<button type="button" class="dashboard-status-chip' + (unreadCount > 0 ? ' dashboard-status-chip--accent' : '') + '" onclick="switchTab(&quot;mails&quot;)"><span class="dashboard-status-chip__label">' + escapeHtml(t('dashboard_home.unread_messages')) + '</span><strong class="dashboard-status-chip__value">' + unreadCount + '</strong></button>');
        }

        var backupText = backup.newest ? formatDashboardDate(backup.newest) : t('dashboard_home.no_backup');
        if (VALID_DASHBOARD_TABS.indexOf('backup') !== -1) {
            chips.push('<button type="button" class="dashboard-status-chip" onclick="switchTab(&quot;backup&quot;)"><span class="dashboard-status-chip__label">' + escapeHtml(t('dashboard_home.backup_status')) + '</span><strong class="dashboard-status-chip__value">' + escapeHtml(backupText) + '</strong></button>');

            chips.push('<button type="button" class="dashboard-status-chip" onclick="switchTab(&quot;backup&quot;)"><span class="dashboard-status-chip__label">' + escapeHtml(t('dashboard_home.auto_backup')) + '</span><strong class="dashboard-status-chip__value">' + escapeHtml(backup.enabled ? t('dashboard_home.active') : t('dashboard_home.inactive')) + '</strong></button>');
        }

        if (recent) {
            var recentTitle = recent.title || recent.id || '';
            chips.push('<button type="button" class="dashboard-status-chip dashboard-status-chip--wide" title="' + escapeHtml(recentTitle) + '" onclick="openDashboardRecentEdit(window.dashboardRecentStatusItems[0])"><span class="dashboard-status-chip__label">' + escapeHtml(t('dashboard_home.recent_edit')) + '</span><strong class="dashboard-status-chip__value">' + escapeHtml(recentTitle) + '</strong></button>');
        }

        if (currentUser.username) {
            chips.push('<button type="button" class="dashboard-status-chip" onclick="switchTab(&quot;settings&quot;, {settingsTab: &quot;users&quot;});"><span class="dashboard-status-chip__label">' + escapeHtml(t('dashboard_home.current_user')) + '</span><strong class="dashboard-status-chip__value">' + escapeHtml(currentUser.username) + '</strong></button>');
        }

        target.innerHTML = chips.join('');
    }

    async function loadDashboardOverview() {
        try {
            updateDashboardAnalyticsRangeUi(currentDashboardAnalyticsRange.period, currentDashboardAnalyticsRange.count);
            var params = new URLSearchParams({
                action: 'dashboard-overview',
                analytics_period: currentDashboardAnalyticsRange.period,
                analytics_count: String(currentDashboardAnalyticsRange.count),
                _: String(Date.now())
            });
            var response = await fetch('api.php?' + params.toString());
            var result = await response.json();
            if (!result.success) throw new Error(result.message || 'Could not load dashboard overview');
            var data = result.data || {};
            var analytics = data.analytics || {};

            var viewsToday = document.getElementById('dashboardViewsToday');
            var viewsPeriod = document.getElementById('dashboardViewsPeriod');
            var visitorsPeriod = document.getElementById('dashboardVisitorsPeriod');
            var visitsPeriod = document.getElementById('dashboardVisitsPeriod');
            var botCount = document.getElementById('dashboardBotCount');
            var pageCount = document.getElementById('dashboardPageCount');
            var newsCount = document.getElementById('dashboardNewsCount');
            if (viewsToday) viewsToday.textContent = analytics.todayViews || 0;
            if (viewsPeriod) viewsPeriod.textContent = analytics.periodViews || 0;
            if (visitorsPeriod) visitorsPeriod.textContent = analytics.periodVisitors || 0;
            if (visitsPeriod) visitsPeriod.textContent = analytics.periodVisits || 0;
            if (botCount) botCount.textContent = analytics.botRequests || 0;
            if (pageCount) pageCount.textContent = data.pages || 0;
            if (newsCount) newsCount.textContent = data.news || 0;
            renderDashboardStatus(data.status || {});

            renderDashboardTopPages(analytics.topPages || []);
            renderDashboardBreakdown('dashboardReferrers', analytics.referrers || []);
            renderDashboardBreakdown('dashboardDevices', analytics.devices || []);
            renderDashboardBreakdown('dashboardBrowsers', analytics.browsers || []);
            renderDashboardBreakdown('dashboardOs', analytics.os || []);
            const chart = document.getElementById('dashboardTrafficChart');
            let state = document.getElementById('dashboardAnalyticsState');
            if (!state && chart) { state = document.createElement('p'); state.id = 'dashboardAnalyticsState'; state.className = 'dashboard-empty'; state.setAttribute('role', 'status'); chart.before(state); }
            if (state) { state.hidden = analytics.state === 'ready'; state.textContent = t('analytics.' + (analytics.state || 'empty')); }
            if (chart) chart.hidden = analytics.state !== 'ready';
            renderDashboardTrafficChart(analytics.series || []);
            renderDashboardHourlyChart(analytics.hourlyToday || []);

            if (AI_FEATURES_ENABLED) {
                currentAiSettings = (data.ai && data.ai.settings) || {};
                populateAiSettings(currentAiSettings);
                updateAiUsage(data.ai ? data.ai.usage : null);
                updateDashboardAiPanel(currentAiSettings);
            } else {
                updateDashboardAiPanel({});
            }
        } catch (error) {
            var target = document.getElementById('dashboardTopPages');
            if (target) target.textContent = error.message;
        }
    }

    function renderDashboardTopPages(pages) {
        var target = document.getElementById('dashboardTopPages');
        if (!target) return;
        if (!pages.length) {
            target.innerHTML = '<p class="dashboard-empty">' + t('dashboard_home.no_views') + '</p>';
            return;
        }
        target.innerHTML = pages.map(function(page) {
            var visitors = page.visitors || 0;
            var visits = page.visits || 0;
            return '<div class="dashboard-top-page">' +
                '<span><strong>' + escapeHtml(page.title || page.key || '') + '</strong><small>' + t('dashboard_home.views_detail', { views: page.views || 0, visitors: visitors, visits: visits }) + '</small></span>' +
                '<strong>' + (page.views || 0) + '</strong>' +
                '</div>';
        }).join('');
    }

    function renderDashboardTrafficChart(series) {
        var target = document.getElementById('dashboardTrafficChart');
        if (!target) return;
        if (!series.length) {
            target.innerHTML = '<p class="dashboard-empty">' + t('dashboard_home.no_views') + '</p>';
            return;
        }

        var width = 720;
        var height = 220;
        var pad = { top: 16, right: 18, bottom: 34, left: 42 };
        var chartWidth = width - pad.left - pad.right;
        var chartHeight = height - pad.top - pad.bottom;
        var values = series.map(function(item) { return Math.max(0, parseInt(item.views || 0, 10)); });
        var maxValue = Math.max.apply(null, values.concat([1]));
        // Four integer intervals keep low traffic from repeating rounded ticks.
        var yMax = Math.max(4, Math.ceil(maxValue * 1.2 / 4) * 4);
        var stepX = series.length > 1 ? chartWidth / (series.length - 1) : chartWidth;

        function x(index) {
            return pad.left + index * stepX;
        }
        function y(value) {
            return pad.top + chartHeight - (value / yMax) * chartHeight;
        }
        function point(index, value) {
            return x(index).toFixed(2) + ',' + y(value).toFixed(2);
        }

        var line = values.map(function(value, index) {
            return (index === 0 ? 'M ' : 'L ') + point(index, value);
        }).join(' ');
        var area = line + ' L ' + x(values.length - 1).toFixed(2) + ',' + (pad.top + chartHeight).toFixed(2) +
            ' L ' + pad.left + ',' + (pad.top + chartHeight).toFixed(2) + ' Z';
        var grid = [0, 0.25, 0.5, 0.75, 1].map(function(factor) {
            var yy = pad.top + chartHeight - chartHeight * factor;
            var label = Math.round(yMax * factor);
            return '<g><line x1="' + pad.left + '" y1="' + yy.toFixed(2) + '" x2="' + (width - pad.right) + '" y2="' + yy.toFixed(2) + '"></line>' +
                '<text x="' + (pad.left - 10) + '" y="' + (yy + 4).toFixed(2) + '">' + label + '</text></g>';
        }).join('');
        var labelIndexes = [0, Math.floor((series.length - 1) / 2), series.length - 1].filter(function(value, index, arr) {
            return arr.indexOf(value) === index;
        });
        var xLabels = labelIndexes.map(function(index) {
            var label = series[index].label || '';
            if (!label) {
                var date = new Date((series[index].date || '') + 'T00:00:00');
                label = isNaN(date.getTime()) ? (series[index].date || '') : date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
            }
            return '<text x="' + x(index).toFixed(2) + '" y="' + (height - 8) + '" text-anchor="middle">' + escapeHtml(label) + '</text>';
        }).join('');
        var points = values.map(function(value, index) {
            var date = new Date((series[index].date || '') + 'T00:00:00');
            var label = series[index].label || (isNaN(date.getTime()) ? (series[index].date || '') : date.toLocaleDateString());
            return '<circle cx="' + x(index).toFixed(2) + '" cy="' + y(value).toFixed(2) + '" r="3"><title>' +
                escapeHtml(label + ': ' + value + ' ' + t('dashboard_home.views_label')) + '</title></circle>';
        }).join('');

        target.innerHTML = '<svg class="dashboard-traffic-svg" viewBox="0 0 ' + width + ' ' + height + '" role="img" aria-label="' + escapeHtml(t('dashboard_home.traffic_curve')) + '">' +
            '<g class="dashboard-chart-grid">' + grid + '</g>' +
            '<path class="dashboard-chart-area" d="' + area + '"></path>' +
            '<path class="dashboard-chart-line" d="' + line + '"></path>' +
            '<g class="dashboard-chart-points">' + points + '</g>' +
            '<g class="dashboard-chart-xlabels">' + xLabels + '</g>' +
            '</svg>';
    }

    function renderDashboardHourlyChart(hours) {
        var target = document.getElementById('dashboardHourlyChart');
        if (!target) return;
        if (!hours.length) {
            target.innerHTML = '<p class="dashboard-empty">' + t('dashboard_home.no_views') + '</p>';
            return;
        }
        var maxValue = Math.max.apply(null, hours.map(function(item) { return Math.max(0, parseInt(item.views || 0, 10)); }).concat([1]));
        target.innerHTML = hours.map(function(item, index) {
            var views = Math.max(0, parseInt(item.views || 0, 10));
            var height = Math.max(3, Math.round((views / maxValue) * 100));
            var label = item.label || String(index).padStart(2, '0') + ':00';
            var showLabel = index % 3 === 0;
            return '<div class="dashboard-hour-bar" title="' + escapeHtml(label + ': ' + views + ' ' + t('dashboard_home.views_label')) + '">' +
                '<span class="dashboard-hour-bar__track"><span class="dashboard-hour-bar__fill" style="height:' + height + '%"></span></span>' +
                '<span class="dashboard-hour-bar__label">' + (showLabel ? escapeHtml(String(index).padStart(2, '0')) : '') + '</span>' +
                '</div>';
        }).join('');
    }

    function renderDashboardBreakdown(targetId, items) {
        var target = document.getElementById(targetId);
        if (!target) return;
        if (!items.length) {
            target.innerHTML = '<p class="dashboard-empty">' + t('dashboard_home.no_data') + '</p>';
            return;
        }
        target.innerHTML = items.slice(0, 8).map(function(item) {
            return '<div class="dashboard-breakdown-row">' +
                '<span>' + escapeHtml(item.label || item.key || '') + '</span>' +
                '<strong>' + (item.views || item.count || 0) + '</strong>' +
                '</div>';
        }).join('');
    }

    function updateDashboardAiStatus(settings) {
        var target = document.getElementById('dashboardAiStatus');
        if (!target) return;
        if (!AI_FEATURES_ENABLED) {
            target.textContent = t('ai.module_disabled_status');
            return;
        }
        var configured = aiProviderIsConfigured(settings);
        if (!settings.enabled) {
            target.textContent = t('ai.disabled_status');
        } else if (!configured) {
            target.textContent = t('ai.not_configured_text');
        } else {
            target.textContent = t('ai.configured_status');
        }
    }

    function aiFeatureEnabled(settings, feature) {
        settings = settings || currentAiSettings || {};
        var features = settings.features || {};
        if (Object.prototype.hasOwnProperty.call(features, feature)) {
            return !!features[feature];
        }
        return !!AI_FEATURE_DEFAULTS[feature];
    }

    function aiServiceIsUsable(settings) {
        settings = settings || currentAiSettings || {};
        return AI_FEATURES_ENABLED && !!settings.enabled && aiProviderIsConfigured(settings);
    }

    function aiUnavailableNoticeDismissed() {
        try {
            return localStorage.getItem(AI_NOTICE_DISMISSED_KEY) === '1';
        } catch (e) {
            return false;
        }
    }

    function dismissAiUnavailableNotice() {
        try {
            localStorage.setItem(AI_NOTICE_DISMISSED_KEY, '1');
        } catch (e) {}
        var section = document.getElementById('dashboardAiSection');
        var usable = aiServiceIsUsable(currentAiSettings || {});
        if (section && !usable) section.hidden = true;
    }

    function updateDashboardAiPanel(settings) {
        settings = settings || currentAiSettings || {};
        var section = document.getElementById('dashboardAiSection');
        if (!section) return;

        var configured = AI_FEATURES_ENABLED && aiProviderIsConfigured(settings);
        var serviceEnabled = AI_FEATURES_ENABLED && !!settings.enabled;
        var usable = configured && serviceEnabled;
        var unavailableDismissed = aiUnavailableNoticeDismissed();
        var banner = document.getElementById('aiUnavailableBanner');
        var bannerText = document.getElementById('aiUnavailableText');
        var tools = document.getElementById('dashboardAiTools');
        var usage = document.getElementById('aiUsageSummary');
        var assistantEnabled = usable && aiFeatureEnabled(settings, 'backendAssistant');
        var textEnabled = usable && aiFeatureEnabled(settings, 'seoTextGeneration');
        var imageEnabled = usable && aiFeatureEnabled(settings, 'imageGeneration');
        var imageJobsPanel = document.getElementById('aiImageJobsPanel');

        dashboardAiImageUsable = imageEnabled;
        if (window.NbImageManager && typeof NbImageManager.refresh === 'function') {
            NbImageManager.refresh();
        }
        section.hidden = !AI_FEATURES_ENABLED || (!usable && unavailableDismissed);
        updateDashboardAiStatus(settings);

        if (usage && !usable) usage.hidden = true;

        if (banner) {
            banner.hidden = usable;
        }
        if (bannerText) {
            if (!AI_FEATURES_ENABLED) {
                bannerText.textContent = t('ai.module_disabled_status');
            } else if (!serviceEnabled) {
                bannerText.textContent = t('ai.disabled_status');
            } else {
                bannerText.textContent = t('ai.not_configured_text');
            }
        }

        if (tools) tools.hidden = !usable || (!assistantEnabled && !textEnabled && !imageEnabled);
        if (imageJobsPanel) imageJobsPanel.hidden = !imageEnabled;

        var assistantCard = document.getElementById('aiAssistantCard');
        if (assistantCard) assistantCard.hidden = !assistantEnabled;

        var toolsCard = document.getElementById('aiToolsCard');
        if (toolsCard) toolsCard.hidden = !textEnabled && !imageEnabled;

        document.querySelectorAll('.ai-tool-tab, .ai-tool-panel').forEach(function(el) {
            var feature = el.dataset.aiFeature;
            var visible = feature === 'imageGeneration' ? imageEnabled : (feature === 'seoTextGeneration' ? textEnabled : true);
            el.hidden = !visible;
            if (!visible) {
                el.classList.remove('active');
                if (el.classList.contains('ai-tool-tab')) el.setAttribute('aria-selected', 'false');
            }
        });

        if (textEnabled || imageEnabled) {
            var currentActive = document.querySelector('.ai-tool-tab.active:not([hidden])');
            switchAiToolTab(currentActive ? currentActive.dataset.aiToolTab : (imageEnabled ? 'image' : 'text'));
        }
    }

    document.getElementById('aiUnavailableDismiss')?.addEventListener('click', dismissAiUnavailableNotice);

    function aiIsLocalProviderUrl(baseUrl) {
        try {
            var host = new URL(baseUrl || '').hostname.toLowerCase();
            return host === 'localhost' || host === '127.0.0.1' || host === '::1' || host.startsWith('192.168.') || host.startsWith('10.') || /^172\\.(1[6-9]|2\\d|3[0-1])\\./.test(host);
        } catch (e) {
            return false;
        }
    }

    function aiProviderIsConfigured(settings) {
        settings = settings || currentAiSettings || {};
        var provider = document.getElementById('aiProvider')?.value || settings.provider || 'openai-compatible';
        var credentials = aiProviderCredentials(settings, provider);
        var activeProvider = settings.provider || 'openai-compatible';
        var hasKey = !!document.getElementById('aiApiKey')?.value.trim()
            || (provider === activeProvider ? !!settings.hasApiKey : false)
            || !!credentials.hasApiKey;
        var baseUrl = document.getElementById('aiBaseUrl')?.value || credentials.baseUrl || settings.baseUrl || '';
        var allowLocal = !!settings.allowLocalProvider || !!document.getElementById('aiAllowLocalProvider')?.checked;
        return hasKey || (allowLocal && aiIsLocalProviderUrl(baseUrl));
    }

    function aiProviderCredentials(settings, provider) {
        settings = settings || currentAiSettings || {};
        provider = provider || settings.provider || document.getElementById('aiProvider')?.value || 'openai-compatible';
        var defaults = {
            'openai-compatible': { baseUrl: 'https://api.openai.com/v1', organization: '', hasApiKey: false },
            openrouter: { baseUrl: 'https://openrouter.ai/api/v1', organization: '', hasApiKey: false },
            anthropic: { baseUrl: 'https://api.anthropic.com/v1', organization: '', hasApiKey: false },
            kie: { baseUrl: 'https://api.kie.ai', organization: '', hasApiKey: false }
        };
        var credentials = settings.providerCredentials && settings.providerCredentials[provider]
            ? settings.providerCredentials[provider]
            : {};
        return Object.assign({}, defaults[provider] || defaults['openai-compatible'], credentials);
    }

    function aiUsesOpenRouter(settings) {
        settings = settings || currentAiSettings || {};
        var provider = String(document.getElementById('aiProvider')?.value || settings.provider || '').trim();
        var credentials = aiProviderCredentials(settings, provider);
        var baseUrl = String(document.getElementById('aiBaseUrl')?.value || credentials.baseUrl || settings.baseUrl || '').trim();
        return provider === 'openrouter' || baseUrl.indexOf('openrouter.ai') !== -1;
    }

    function aiUsesAnthropic(settings) {
        settings = settings || currentAiSettings || {};
        var provider = String(document.getElementById('aiProvider')?.value || settings.provider || '').trim();
        var credentials = aiProviderCredentials(settings, provider);
        var baseUrl = String(document.getElementById('aiBaseUrl')?.value || credentials.baseUrl || settings.baseUrl || '').trim();
        return provider === 'anthropic' || baseUrl.indexOf('api.anthropic.com') !== -1;
    }

    function aiUsesKie(settings) {
        settings = settings || currentAiSettings || {};
        var provider = String(document.getElementById('aiProvider')?.value || settings.provider || '').trim();
        var credentials = aiProviderCredentials(settings, provider);
        var baseUrl = String(document.getElementById('aiBaseUrl')?.value || credentials.baseUrl || settings.baseUrl || '').trim();
        return provider === 'kie' || baseUrl.indexOf('api.kie.ai') !== -1;
    }

    var AI_TEXT_MODEL_PRESETS = Object.fromEntries(Object.entries(window.NB_AI_CATALOG).map(([key, provider]) => [key, provider.text]));

    var aiOpenRouterModelsCache = null;
    var aiOpenRouterModelsLoading = false;

    // Load the live OpenRouter catalog through the server cache; the static
    // preset list stays as fallback when the request fails or is pending.
    function loadOpenRouterModels() {
        if (aiOpenRouterModelsCache || aiOpenRouterModelsLoading) return;
        aiOpenRouterModelsLoading = true;
        var formData = new FormData();
        formData.append('action', 'ai-openrouter-models');
        formData.append('csrf_token', CSRF_TOKEN);
        fetch('api.php', { method: 'POST', body: formData, cache: 'no-store' })
            .then(function(response) { return response.json(); })
            .then(function(result) {
                if (!result.success || !result.data) return;
                aiOpenRouterModelsCache = result.data;
                applyOpenRouterModels();
            })
            .catch(function() { /* keep static fallback */ })
            .finally(function() { aiOpenRouterModelsLoading = false; });
    }

    function applyOpenRouterModels() {
        var data = aiOpenRouterModelsCache;
        if (!data) return;
        if (Array.isArray(data.textModels) && data.textModels.length) {
            AI_TEXT_MODEL_PRESETS.openrouter.suggestions = data.textModels.map(function(model) { return model.id; });
        }
        if (Array.isArray(data.imageModels) && data.imageModels.length) {
            AI_IMAGE_MODEL_OPTIONS.openrouter = data.imageModels.map(function(model) {
                return { value: model.id, label: model.name || model.id };
            });
        }
        var provider = document.getElementById('aiProvider')?.value || (currentAiSettings && currentAiSettings.provider) || '';
        if (provider === 'openrouter') {
            updateAiModelPlaceholders('openrouter');
            updateAiImageModelControl(currentAiSettings || {});
        }
        // Refresh the size/cost note now that live image prices are available.
        if (typeof updateAiImageRatioIcon === 'function') updateAiImageRatioIcon();
    }

    var AI_CAPS = window.NB_AI_CAPS || { models: [], levels: ['low', 'medium', 'high'] };
    var AI_MASK = '••••••••';

    function aiById(id) { return document.getElementById(id); }

    function aiHostOf(baseUrl) {
        try { return new URL(baseUrl || '').hostname.toLowerCase(); } catch (e) { return ''; }
    }

    function aiCurrentProvider() {
        return aiById('aiProvider')?.value || (currentAiSettings && currentAiSettings.provider) || 'openai-compatible';
    }

    function aiCurrentBaseUrl() {
        return aiById('aiBaseUrl')?.value || aiProviderCredentials(currentAiSettings || {}, aiCurrentProvider()).baseUrl || '';
    }

    // What the registry (includes/ai/model-capabilities.json) knows about a model on its provider; null when unknown.
    function aiModelCaps(provider, baseUrl, model) {
        var host = aiHostOf(baseUrl);
        model = String(model || '').trim().toLowerCase();
        return AI_CAPS.models.find(function(entry) {
            return entry.provider === provider && entry.host.toLowerCase() === host && entry.model.toLowerCase() === model;
        }) || null;
    }

    // The preset models of a provider; a custom OpenAI-compatible endpoint has its own model ids, so it gets none.
    function aiProviderModelDefaults(provider, baseUrl) {
        var preset = AI_TEXT_MODEL_PRESETS[provider] || AI_TEXT_MODEL_PRESETS['openai-compatible'];
        if (provider === 'openai-compatible' && baseUrl && aiHostOf(baseUrl) !== 'api.openai.com') return { chat: '', text: '' };
        return { chat: preset.chat, text: preset.text };
    }

    // ---- Reasoning effort (per purpose) -------------------------------------------------------------------------
    var aiReasoning = { chat: 'recommended', text: 'recommended' };
    var aiLegacy = { provider: '', chat: '', text: '' }; // saved before the setting existed: nothing is sent while the model stays the same
    var aiProviderDrafts = {};                           // unsaved model choices per provider while the page stays open
    var aiLastProvider = '';

    function aiPurposeModelInput(purpose) { return aiById(purpose === 'chat' ? 'aiChatModel' : 'aiTextModel'); }

    function aiEffortLevelLabel(level) { return t('ai.reasoning_' + level); }

    function aiRenderEffort(purpose) {
        var P = purpose === 'chat' ? 'Chat' : 'Text';
        var select = aiById('ai' + P + 'Effort');
        var hint = aiById('ai' + P + 'EffortHint');
        var model = String(aiPurposeModelInput(purpose)?.value || '').trim();
        if (!select || !hint) return;
        var label = select.closest('.form-group').querySelector('label');
        var caps = aiModelCaps(aiCurrentProvider(), aiCurrentBaseUrl(), model);
        var value = aiReasoning[purpose] || 'recommended';
        var legacyHolds = value === 'legacy' && aiLegacy.provider === aiCurrentProvider() && aiLegacy[purpose] === model.toLowerCase();
        var note = '';
        if (!caps) {
            // Unknown model or endpoint: no parameter is sent, so there is nothing to choose.
            if (!legacyHolds) aiReasoning[purpose] = 'recommended';
            select.innerHTML = '';
            (select.closest('.nb-select') || select).hidden = true;
            if (label) label.hidden = true;
            hint.textContent = model ? t('ai.reasoning_unknown') : '';
            return;
        }
        if (value === 'legacy' && !legacyHolds) value = 'recommended';
        if (value !== 'legacy' && value !== 'recommended' && caps.levels.indexOf(value) === -1) {
            value = 'recommended';
            note = t('ai.reasoning_reset');
        }
        (select.closest('.nb-select') || select).hidden = false;
        if (label) label.hidden = false;
        select.innerHTML = '';
        var options = [['recommended', t('ai.reasoning_recommended_with', { level: aiEffortLevelLabel((caps.recommended && caps.recommended[purpose]) || 'medium') })]];
        caps.levels.forEach(function(level) { options.push([level, aiEffortLevelLabel(level)]); });
        if (legacyHolds) options.push(['legacy', t('ai.reasoning_legacy')]);
        options.forEach(function(option) {
            var el = document.createElement('option');
            el.value = option[0];
            el.textContent = option[1];
            select.appendChild(el);
        });
        select.value = value;
        aiReasoning[purpose] = value;
        hint.textContent = note;
    }

    ['chat', 'text'].forEach(function(purpose) {
        var P = purpose === 'chat' ? 'Chat' : 'Text';
        aiById('ai' + P + 'Effort')?.addEventListener('change', function() { aiReasoning[purpose] = this.value; });
        ['input', 'change'].forEach(function(type) {
            aiPurposeModelInput(purpose)?.addEventListener(type, function() {
                aiRenderEffort(purpose);
                aiRenderPricing();
            });
        });
    });

    function aiStashProviderModels(provider) {
        if (!provider) return;
        aiProviderDrafts[provider] = {
            chatModel: String(aiById('aiChatModel')?.value || '').trim(),
            textModel: String(aiById('aiTextModel')?.value || '').trim(),
            reasoning: Object.assign({}, aiReasoning)
        };
    }

    // Show the models and reasoning levels a provider had: unsaved choices of this page first, then what is saved, then its presets.
    function aiApplyProviderModels(provider, baseUrl) {
        var settings = currentAiSettings || {};
        var saved = provider === settings.provider
            ? { chatModel: settings.chatModel, textModel: settings.textModel, reasoning: settings.reasoning }
            : (settings.providerModels && settings.providerModels[provider]) || null;
        var source = aiProviderDrafts[provider] || saved;
        var defaults = aiProviderModelDefaults(provider, baseUrl);
        var chatInput = aiById('aiChatModel');
        var textInput = aiById('aiTextModel');
        if (chatInput) chatInput.value = source ? (source.chatModel || '') : defaults.chat;
        if (textInput) textInput.value = source ? (source.textModel || source.chatModel || '') : defaults.text;
        var reasoning = (source && source.reasoning) || {};
        aiReasoning = { chat: reasoning.chat || 'recommended', text: reasoning.text || 'recommended' };
        if (provider === settings.provider && !aiProviderDrafts[provider]) {
            aiLegacy = { provider: provider, chat: aiReasoning.chat === 'legacy' ? String(chatInput?.value || '').trim().toLowerCase() : '', text: aiReasoning.text === 'legacy' ? String(textInput?.value || '').trim().toLowerCase() : '' };
        }
        [chatInput, textInput].forEach(function(input) { if (input) updateClearButton(input); });
    }

    // ---- Prices per model ---------------------------------------------------------------------------------------
    var aiPriceChanges = {}; // "provider|model" -> {input, output, currency} (set) or null (remove); only what was touched in this form

    function aiPriceKey(provider, model) { return provider + '|' + String(model || '').trim().toLowerCase(); }

    function aiFormatNumber(value) {
        var n = parseFloat(value);
        if (!Number.isFinite(n)) return '';
        return String(parseFloat(n.toFixed(6)));
    }

    function aiSavedOverride(key) {
        var models = currentAiSettings && currentAiSettings.pricing && currentAiSettings.pricing.models;
        return (models && !Array.isArray(models) && models[key]) || null;
    }

    function aiStoredOverride(key) {
        return Object.prototype.hasOwnProperty.call(aiPriceChanges, key) ? aiPriceChanges[key] : aiSavedOverride(key);
    }

    function aiCatalogPrice(provider, baseUrl, model) {
        var host = aiHostOf(baseUrl);
        model = String(model || '').trim().toLowerCase();
        var info = (currentAiSettings && currentAiSettings.pricingInfo) || { models: [] };
        return (info.models || []).find(function(entry) {
            return entry.provider === provider && entry.host.toLowerCase() === host && entry.model.toLowerCase() === model;
        }) || null;
    }

    function aiPriceStatus(purpose) {
        var P = purpose;
        var provider = aiCurrentProvider();
        var model = String(aiPurposeModelInput(purpose)?.value || '').trim();
        var status = aiById('aiPriceRow_' + P)?.querySelector('[data-price-status]');
        if (!status) return true;
        var override = aiStoredOverride(aiPriceKey(provider, model));
        var catalog = aiCatalogPrice(provider, aiCurrentBaseUrl(), model);
        var known = true;
        status.classList.remove('ai-price-status--unknown');
        if (override && override.input !== '' && override.output !== '') {
            status.textContent = t(override.migrated ? 'ai.price_status_migrated' : 'ai.price_status_override', {
                input: aiFormatNumber(override.input), output: aiFormatNumber(override.output), currency: override.currency || 'USD'
            });
        } else if (catalog) {
            var text = t('ai.price_status_catalog', { input: aiFormatNumber(catalog.input), output: aiFormatNumber(catalog.output), date: catalog.updatedAt || '' });
            (catalog.tiers || []).forEach(function(tier) {
                text += ' ' + (tier.input !== undefined
                    ? t('ai.price_tier_absolute', { tokens: Number(tier.aboveInputTokens).toLocaleString(), input: aiFormatNumber(tier.input), output: aiFormatNumber(tier.output) })
                    : t('ai.price_tier_multiplier', { tokens: Number(tier.aboveInputTokens).toLocaleString(), input: aiFormatNumber(tier.inputMultiplier || 1), output: aiFormatNumber(tier.outputMultiplier || 1) }));
            });
            status.textContent = text;
        } else {
            known = false;
            status.textContent = t('ai.price_unknown');
            status.classList.add('ai-price-status--unknown');
        }
        return known;
    }

    function aiRenderPricing() {
        if (!aiById('aiPricingSection')) return;
        var provider = aiCurrentProvider();
        var baseUrl = aiCurrentBaseUrl();
        var chatModel = String(aiById('aiChatModel')?.value || '').trim();
        var textModel = String(aiById('aiTextModel')?.value || '').trim();
        var shared = chatModel.toLowerCase() === textModel.toLowerCase();
        var anyUnknown = false;
        ['chat', 'text'].forEach(function(purpose) {
            var row = aiById('aiPriceRow_' + purpose);
            var model = purpose === 'chat' ? chatModel : textModel;
            if (!row) return;
            if (!model || (purpose === 'text' && shared)) { row.hidden = true; return; }
            row.hidden = false;
            var titleKey = purpose === 'chat' ? (shared ? 'ai.model_group_both' : 'ai.model_group_chat') : 'ai.model_group_text';
            row.querySelector('[data-price-title]').textContent = t(titleKey) + ' · ' + model;
            var key = aiPriceKey(provider, model);
            var override = aiStoredOverride(key);
            var catalog = aiCatalogPrice(provider, baseUrl, model);
            var inputEl = aiById('aiPriceIn_' + purpose);
            var outputEl = aiById('aiPriceOut_' + purpose);
            var currencyEl = aiById('aiPriceCur_' + purpose);
            var removeEl = aiById('aiPriceRemove_' + purpose);
            inputEl.value = override ? aiFormatNumber(override.input) : '';
            outputEl.value = override ? aiFormatNumber(override.output) : '';
            inputEl.placeholder = catalog ? aiFormatNumber(catalog.input) : '';
            outputEl.placeholder = catalog ? aiFormatNumber(catalog.output) : '';
            currencyEl.value = override ? (override.currency || 'USD') : 'USD';
            removeEl.hidden = !override;
            if (!aiPriceStatus(purpose)) anyUnknown = true;
        });
        var notice = aiById('aiUnpricedNotice');
        if (notice) notice.hidden = !anyUnknown;
        var fx = ((currentAiSettings && currentAiSettings.pricingInfo) || {}).fx;
        var fxInput = aiById('aiFxRate');
        if (fx && fxInput) {
            fxInput.placeholder = aiFormatNumber((currentAiSettings.pricingInfo.catalogFx || fx).usdPerEur);
            aiById('aiFxHint').textContent = t('ai.fx_hint', { rate: aiFormatNumber(fx.usdPerEur), date: fx.asOf || '', source: fx.source === 'settings' ? t('ai.fx_own') : fx.source });
        }
    }

    ['chat', 'text'].forEach(function(purpose) {
        ['aiPriceIn_', 'aiPriceOut_', 'aiPriceCur_'].forEach(function(prefix) {
            aiById(prefix + purpose)?.addEventListener('input', function() {
                var key = aiPriceKey(aiCurrentProvider(), aiPurposeModelInput(purpose)?.value);
                var input = aiById('aiPriceIn_' + purpose).value.trim();
                var output = aiById('aiPriceOut_' + purpose).value.trim();
                if (input === '' && output === '') {
                    if (aiSavedOverride(key)) aiPriceChanges[key] = null; else delete aiPriceChanges[key];
                } else {
                    aiPriceChanges[key] = { input: input, output: output, currency: aiById('aiPriceCur_' + purpose).value };
                }
                aiById('aiPriceRemove_' + purpose).hidden = !(input !== '' || output !== '');
                aiPriceStatus(purpose);
            });
        });
        aiById('aiPriceRemove_' + purpose)?.addEventListener('click', function() {
            aiPriceChanges[aiPriceKey(aiCurrentProvider(), aiPurposeModelInput(purpose)?.value)] = null;
            aiRenderPricing();
        });
    });

    // Only complete entries (input and output) are sent; incomplete ones leave the stored price as it is.
    function aiCollectPriceChanges() {
        var out = {};
        Object.keys(aiPriceChanges).forEach(function(key) {
            var entry = aiPriceChanges[key];
            if (entry === null) { out[key] = null; return; }
            if (entry.input !== '' && entry.output !== '') out[key] = { input: parseFloat(entry.input), output: parseFloat(entry.output), currency: entry.currency };
        });
        return out;
    }

    function maybeAutofillOpenRouterPricing(event) {
        var provider = aiCurrentProvider();
        if (provider !== 'openrouter' || !aiOpenRouterModelsCache) return;
        var model = String(event && event.target ? event.target.value : '').trim();
        var match = (aiOpenRouterModelsCache.textModels || []).find(function(entry) { return entry.id === model; });
        if (!match || (!match.promptCentsPerMillion && !match.completionCentsPerMillion)) return;
        var key = aiPriceKey(provider, model);
        if (aiStoredOverride(key)) return;
        // OpenRouter lists USD per token; the cents per million it gives are USD per million times 100.
        aiPriceChanges[key] = { input: String(match.promptCentsPerMillion / 100), output: String(match.completionCentsPerMillion / 100), currency: 'USD' };
        aiRenderPricing();
        if (typeof showToast === 'function') showToast(t('ai.pricing_autofilled'), 'info');
    }

    // ---- Stored keys: never shown, only whether one exists --------------------------------------------------------
    function aiSetSecretField(inputId, removeId, hintId, hasKey) {
        var input = aiById(inputId);
        if (input) {
            input.value = '';
            input.placeholder = hasKey ? AI_MASK : '';
        }
        var hint = aiById(hintId);
        if (hint) hint.textContent = hasKey ? t('ai.api_key_saved') : t('ai.api_key_missing');
        var remove = aiById(removeId);
        if (remove) remove.hidden = !hasKey;
    }

    // A mask (or anything made only of bullets) is never a key.
    function aiTypedSecret(id) {
        var value = String(aiById(id)?.value || '').trim();
        return /^[•●·*\s]*$/.test(value) ? '' : value;
    }

    function updateAiModelPlaceholders(provider) {
        var preset = aiProviderModelDefaults(provider, aiById('aiBaseUrl')?.value || '');
        var chatInput = aiById('aiChatModel');
        var textInput = aiById('aiTextModel');
        if (chatInput) { chatInput.placeholder = preset.chat; updateClearButton(chatInput); }
        if (textInput) { textInput.placeholder = preset.text; updateClearButton(textInput); }
        aiModelComboboxes.forEach(function(combobox) { combobox.refresh(); });
        aiRenderEffort('chat');
        aiRenderEffort('text');
        aiRenderPricing();
        if (provider === 'openrouter') {
            loadOpenRouterModels();
        } else {
            loadProviderModels(false);
        }
    }

    function aiModelSuggestions() {
        var provider = document.getElementById('aiProvider')?.value
            || (currentAiSettings && currentAiSettings.provider) || 'openai-compatible';
        var preset = AI_TEXT_MODEL_PRESETS[provider] || AI_TEXT_MODEL_PRESETS['openai-compatible'];
        // The provider's own list wins once it is loaded; the presets are the fallback.
        if (provider !== 'openrouter' && aiLiveModels[provider] && aiLiveModels[provider].length) return aiLiveModels[provider].slice();
        return preset.suggestions.slice();
    }

    // Models the saved provider offers (GET /models through the server, admins only).
    var aiLiveModels = {};
    var aiLiveModelsLoading = {};

    function loadProviderModels(force) {
        var provider = document.getElementById('aiProvider')?.value || (currentAiSettings && currentAiSettings.provider) || '';
        var hint = document.getElementById('aiModelsHint');
        if (!provider || provider === 'openrouter' || provider === 'kie' || aiLiveModelsLoading[provider]) return;
        var credentials = aiProviderCredentials(currentAiSettings || {}, provider);
        if (!credentials.hasApiKey && !(currentAiSettings && currentAiSettings.allowLocalProvider)) {
            if (hint) hint.textContent = '';
            return;
        }
        if (aiLiveModels[provider] && !force) return;
        aiLiveModelsLoading[provider] = true;
        var formData = new FormData();
        formData.append('action', 'ai-list-models');
        formData.append('csrf_token', CSRF_TOKEN);
        if (hint) hint.textContent = t('ai.models_loading');
        fetch('api.php', { method: 'POST', body: formData, cache: 'no-store' })
            .then(function(response) { return response.json(); })
            .then(function(result) {
                if (!result.success) throw new Error(result.message);
                var models = Array.isArray(result.data.models) ? result.data.models : [];
                aiLiveModels[result.data.provider || provider] = models;
                if (hint) hint.textContent = models.length ? t('ai.models_loaded', { count: models.length }) : '';
                aiModelComboboxes.forEach(function(combobox) { combobox.refresh(); });
            })
            .catch(function(error) {
                if (hint) hint.textContent = t('ai.models_failed', { message: error.message });
            })
            .finally(function() { delete aiLiveModelsLoading[provider]; });
    }

    // Custom combobox: identical rendering in every browser (incl. Safari and
    // embedded webviews) instead of the unstylable native datalist popup.
    var aiModelComboboxes = [];
    function setupModelCombobox(root) {
        var input = root.querySelector('input');
        var toggle = root.querySelector('.nb-combobox__toggle');
        var list = root.querySelector('.nb-combobox__list');
        if (!input || !list) return null;
        var activeIndex = -1;
        var items = [];

        function close() {
            list.hidden = true;
            input.setAttribute('aria-expanded', 'false');
            activeIndex = -1;
        }

        function select(value) {
            input.value = value;
            close();
            updateClearButton(input);
            input.dispatchEvent(new Event('change', { bubbles: true }));
            input.focus();
        }

        function highlight(index) {
            activeIndex = index;
            Array.from(list.children).forEach(function(item, i) {
                item.classList.toggle('is-active', i === index);
                item.setAttribute('aria-selected', i === index ? 'true' : 'false');
                if (i === index) item.scrollIntoView({ block: 'nearest' });
            });
        }

        function open(filterText) {
            var all = aiModelSuggestions();
            var filter = String(filterText || '').trim().toLowerCase();
            items = filter && all.indexOf(input.value.trim()) === -1
                ? all.filter(function(model) { return model.toLowerCase().indexOf(filter) !== -1; })
                : all;
            if (!items.length) { close(); return; }
            list.innerHTML = '';
            items.forEach(function(model, index) {
                var item = document.createElement('div');
                item.className = 'nb-combobox__option';
                item.setAttribute('role', 'option');
                item.textContent = model;
                item.addEventListener('mousedown', function(event) {
                    event.preventDefault();
                    select(model);
                });
                list.appendChild(item);
            });
            list.hidden = false;
            input.setAttribute('aria-expanded', 'true');
            highlight(-1);
        }

        input.addEventListener('input', function() {
            updateClearButton(input);
            open(input.value);
        });
        input.addEventListener('keydown', function(event) {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                if (list.hidden) { open(input.value); return; }
                var count = list.children.length;
                if (!count) return;
                var next = event.key === 'ArrowDown'
                    ? (activeIndex + 1) % count
                    : (activeIndex - 1 + count) % count;
                highlight(next);
            } else if (event.key === 'Enter') {
                if (!list.hidden && activeIndex >= 0 && items[activeIndex] !== undefined) {
                    event.preventDefault();
                    select(items[activeIndex]);
                }
            } else if (event.key === 'Escape' && !list.hidden) {
                event.stopPropagation();
                close();
            }
        });
        input.addEventListener('blur', function() {
            window.setTimeout(close, 120);
        });
        if (toggle) {
            toggle.addEventListener('mousedown', function(event) {
                event.preventDefault();
                loadProviderModels(false);
                if (list.hidden) {
                    input.focus();
                    open('');
                } else {
                    close();
                }
            });
        }

        return { refresh: function() { if (!list.hidden) open(input.value); } };
    }

    document.querySelectorAll('[data-model-combobox]').forEach(function(root) {
        var combobox = setupModelCombobox(root);
        if (combobox) aiModelComboboxes.push(combobox);
    });

    ['aiChatModel', 'aiTextModel'].forEach(function(id) { document.getElementById(id)?.addEventListener('change', maybeAutofillOpenRouterPricing); });

    var AI_IMAGE_MODEL_OPTIONS = Object.fromEntries(Object.entries(window.NB_AI_CATALOG).map(([key, provider]) => [key, provider.images.map(image => ({ value: image.value, label: image.label.startsWith('ai.') ? t(image.label) : image.label }))]));

    function normalizeOpenRouterImageModel(model) {
        model = String(model || '').trim();
        var aliases = window.NB_AI_CATALOG.openrouter.imageAliases;
        var valid = AI_IMAGE_MODEL_OPTIONS.openrouter.map(function(option) {
            return option.value;
        });
        if (valid.indexOf(model) !== -1) {
            return model;
        }
        return aliases[model] || 'openai/gpt-5.4-image-2';
    }

    function normalizeOpenAiImageModel(model) {
        model = String(model || '').trim();
        return model === 'gpt-image-2' ? model : 'gpt-image-2';
    }

    function normalizeKieImageModel(model) {
        model = String(model || '').trim();
        var aliases = window.NB_AI_CATALOG.kie.imageAliases;
        model = aliases[model] || model;
        return AI_IMAGE_MODEL_OPTIONS.kie.some(function(option) { return option.value === model; })
            ? model : 'gpt-image-2';
    }

    function currentAiProviderKey(settings) {
        settings = settings || currentAiSettings || {};
        var provider = document.getElementById('aiProvider')?.value || settings.provider || 'openai-compatible';
        if (provider === 'kie' || aiUsesKie(settings)) return 'kie';
        return provider === 'openrouter' || aiUsesOpenRouter(settings) ? 'openrouter' : 'openai-compatible';
    }

    function updateAiImageModelControl(settings) {
        settings = settings || currentAiSettings || {};
        var providerKey = currentAiProviderKey(settings);
        var input = document.getElementById('aiImageModel');
        var picker = document.getElementById('aiImageModelPicker');
        if (!input) return;
        var options = AI_IMAGE_MODEL_OPTIONS[providerKey] || AI_IMAGE_MODEL_OPTIONS['openai-compatible'];
        var selected = providerKey === 'openrouter'
            ? normalizeOpenRouterImageModel(settings.imageModel || input.value || '')
            : (providerKey === 'kie'
                ? normalizeKieImageModel(settings.imageModel || input.value || '')
                : normalizeOpenAiImageModel(settings.imageModel || input.value || ''));
        if (picker) {
            picker.innerHTML = options.map(function(option) {
                return '<option value="' + escapeHtml(option.value) + '">' + escapeHtml(option.label) + '</option>';
            }).join('');
            if (!options.some(function(option) { return option.value === selected; })) {
                selected = options[0] ? options[0].value : '';
            }
            picker.value = selected;
        }
        input.value = selected;
        updateAiImageScaleOptions();
    }

    function aiSelectedImageModelSupports4K() {
        var model = String(document.getElementById('aiImageModelPicker')?.value || document.getElementById('aiImageModel')?.value || '').trim();
        return !/(^|\/)gpt-5\.4-image-2(?:$|-)|^gpt-image-2(?:$|-)/i.test(model);
    }

    function updateAiImageScaleOptions() {
        var scale = document.getElementById('aiImageScale');
        if (!scale) return;
        var current = parseInt(scale.value || '2048', 10) || 2048;
        var supports4K = aiSelectedImageModelSupports4K();
        var options = [
            { value: '1024', label: '1K' },
            { value: '2048', label: '2K' }
        ];
        if (supports4K) {
            options.push({ value: '3072', label: '3K' }, { value: '3840', label: '4K' });
        }
        scale.innerHTML = options.map(function(option) {
            return '<option value="' + option.value + '">' + option.label + '</option>';
        }).join('');
        var maxScale = supports4K ? 3840 : 2048;
        scale.value = String(Math.min(current, maxScale));
        if (!scale.value) {
            scale.value = supports4K && current > 2048 ? '3840' : '2048';
        }
        if (typeof updateAiImageRatioIcon === 'function') updateAiImageRatioIcon();
    }

    function updateAiAvailability(settings) {
        settings = settings || currentAiSettings || {};
        updateDashboardAiPanel(settings);
        if (!AI_FEATURES_ENABLED) return;
        updateAiImageModelControl(settings);
        // Refresh the size note so the estimated per-image cost reflects the
        // current pricing once settings have loaded.
        if (typeof updateAiImageRatioIcon === 'function') updateAiImageRatioIcon();
        var configured = aiProviderIsConfigured(settings);
        var enabled = !!settings.enabled;
        var usable = configured && enabled;
        var assistantUsable = usable && aiFeatureEnabled(settings, 'backendAssistant');
        var textUsable = usable && aiFeatureEnabled(settings, 'seoTextGeneration');
        var imageUsable = usable && aiFeatureEnabled(settings, 'imageGeneration') && !aiUsesAnthropic(settings);
        dashboardAiImageUsable = imageUsable;
        var status = document.getElementById('aiProviderStatus');
        if (status) {
            status.className = 'ai-status-box ' + (usable ? 'ai-status-box--ok' : 'ai-status-box--warning');
            status.textContent = usable
                ? t('ai.configured_status')
                : (configured ? t('ai.disabled_status') : t('ai.not_configured_text'));
        }

        var banner = document.getElementById('aiUnavailableBanner');
        if (banner) banner.hidden = usable;

        document.querySelectorAll('#aiChatForm textarea, #aiChatForm button').forEach(function(el) {
            el.disabled = !assistantUsable;
        });
        document.querySelectorAll('#aiTextForm textarea, #aiTextForm input, #aiTextForm button').forEach(function(el) {
            el.disabled = !textUsable;
        });
        document.querySelectorAll('#aiImageForm textarea, #aiImageForm select, #aiImageForm input, #aiImageForm button').forEach(function(el) {
            el.disabled = !imageUsable;
        });

        var imageModel = String(document.getElementById('aiImageModelPicker')?.value || settings.imageModel || document.getElementById('aiImageModel')?.value || '').trim();
        var imageModelMissing = imageUsable && !imageModel;
        var imageModelNote = document.getElementById('aiImageModelNote');
        var imageButton = document.getElementById('aiGenerateImageButton');
        if (imageButton) {
            imageButton.disabled = !imageUsable || imageModelMissing;
        }
        if (imageModelNote) {
            imageModelNote.textContent = aiUsesAnthropic(settings)
                ? t('ai.image_anthropic_note')
                : (aiUsesOpenRouter(settings) ? t('ai.image_openrouter_note') : t('ai.image_model_missing_note'));
            imageModelNote.hidden = !imageUsable || (!imageModelMissing && !aiUsesOpenRouter(settings));
        }

        document.querySelectorAll('#aiFeatureAssistant, #aiFeatureSeo, #aiFeatureImages').forEach(function(el) {
            el.disabled = !configured;
        });
        refreshSeoAiButtons();
    }

    async function loadAiSettings() {
        if (!AI_FEATURES_ENABLED) return;
        try {
            var response = await fetch('api.php?action=load-ai-settings');
            var result = await response.json();
            if (!result.success) throw new Error(result.message || 'Could not load AI settings');
            currentAiSettings = result.data.settings || {};
            populateAiSettings(currentAiSettings);
            updateAiUsage(result.data.usage);
            updateDashboardAiPanel(currentAiSettings);
        } catch (error) {
            var usage = document.getElementById('aiUsageSummary');
            if (usage) usage.textContent = error.message;
        }
    }

    function populateAiSettings(settings) {
        settings = settings || {};
        var provider = settings.provider || 'openai-compatible';
        var providerCredentials = aiProviderCredentials(settings, provider);
        var fields = {
            aiEnabled: !!settings.enabled,
            aiAllowLocalProvider: !!settings.allowLocalProvider,
            aiAssistantForceEnglish: !!settings.assistantForceEnglish,
            aiAssistantSurfaceVisualEditor: !settings.assistantSurfaces || settings.assistantSurfaces.visualEditor !== false,
            aiAssistantSurfaceDashboard: !settings.assistantSurfaces || settings.assistantSurfaces.dashboard !== false,
            aiFeatureAssistant: !!(settings.features && settings.features.backendAssistant),
            aiFeatureSeo: !!(settings.features && settings.features.seoTextGeneration),
            aiFeatureImages: !!(settings.features && settings.features.imageGeneration)
        };
        Object.keys(fields).forEach(function(id) {
            var el = document.getElementById(id);
            if (el) el.checked = fields[id];
        });
        var values = {
            aiProvider: provider,
            aiBaseUrl: Object.prototype.hasOwnProperty.call(providerCredentials, 'baseUrl') ? (providerCredentials.baseUrl || '') : (settings.baseUrl || ''),
            aiOrganization: Object.prototype.hasOwnProperty.call(providerCredentials, 'organization') ? (providerCredentials.organization || '') : (settings.organization || ''),
            aiImageModel: Object.prototype.hasOwnProperty.call(settings, 'imageModel') ? (settings.imageModel || '') : 'gpt-image-2',
            aiMonthlyBudget: settings.limits?.monthlyBudgetCents ?? 1000,
            aiDailyRequests: settings.limits?.dailyRequests ?? 300,
            aiDailyTextRequests: settings.limits?.dailyTextRequests ?? 250,
            aiDailyImageRequests: settings.limits?.dailyImageRequests ?? 10,
            aiMaxInputTokens: settings.limits?.maxInputTokens ?? 64000,
            aiMaxOutputTokens: settings.limits?.maxOutputTokens ?? 16000,
            aiRequestTimeout: settings.limits?.requestTimeoutSeconds ?? 300,
            aiImagePrice: settings.pricing?.imageCentsPerRequest ?? 5,
            aiFxRate: settings.pricing?.usdPerEur > 0 ? settings.pricing.usdPerEur : ''
        };
        Object.keys(values).forEach(function(id) {
            var el = document.getElementById(id);
            if (el) el.value = values[id];
        });
        updateAiImageModelControl(settings);
        populateAgentSettings(settings);
        // Models and reasoning levels come from the saved settings of the active provider; presets fill what is empty.
        aiProviderDrafts = {};
        aiPriceChanges = {};
        aiLastProvider = provider;
        var defaults = aiProviderModelDefaults(provider, values.aiBaseUrl);
        var reasoning = settings.reasoning || {};
        aiReasoning = { chat: reasoning.chat || 'recommended', text: reasoning.text || 'recommended' };
        var chatModel = Object.prototype.hasOwnProperty.call(settings, 'chatModel') ? (settings.chatModel || '') : defaults.chat;
        var textModel = Object.prototype.hasOwnProperty.call(settings, 'textModel') ? (settings.textModel || chatModel) : defaults.text;
        aiById('aiChatModel').value = chatModel;
        aiById('aiTextModel').value = textModel;
        aiLegacy = { provider: provider, chat: aiReasoning.chat === 'legacy' ? chatModel.toLowerCase() : '', text: aiReasoning.text === 'legacy' ? textModel.toLowerCase() : '' };
        aiUpdateOrganizationVisibility(provider);
        updateAiApiKeyHint(provider);
        updateAiModelPlaceholders(provider);
        updateAiAvailability(settings);
        aiSyncDashboardCopilot(settings);
    }

    function updateAiApiKeyHint(provider) {
        var credentials = aiProviderCredentials(currentAiSettings || {}, provider || document.getElementById('aiProvider')?.value || 'openai-compatible');
        aiSetSecretField('aiApiKey', 'aiRemoveApiKey', 'aiApiKeyHint', !!credentials.hasApiKey);
    }

    // The organization header only goes to providers that take it.
    function aiUpdateOrganizationVisibility(provider) {
        var group = document.getElementById('aiOrganizationGroup');
        if (group) group.hidden = provider === 'anthropic' || provider === 'kie';
    }

    function applyAiDefaultsForNewApiKey() {
        var keyInput = document.getElementById('aiApiKey');
        if (!keyInput || !aiTypedSecret('aiApiKey')) return;
        var provider = document.getElementById('aiProvider')?.value || (currentAiSettings && currentAiSettings.provider) || 'openai-compatible';
        var credentials = aiProviderCredentials(currentAiSettings || {}, provider);
        if ((currentAiSettings && currentAiSettings.hasApiKey) || credentials.hasApiKey) return;
        [
            'aiEnabled',
            'aiAssistantSurfaceVisualEditor',
            'aiAssistantSurfaceDashboard',
            'aiFeatureAssistant',
            'aiFeatureSeo',
            'aiFeatureImages'
        ].forEach(function(id) {
            var el = document.getElementById(id);
            if (el) el.checked = true;
        });
    }

    function collectAiSettingsForm() {
        applyAiDefaultsForNewApiKey();
        var provider = document.getElementById('aiProvider').value;
        var imagePicker = document.getElementById('aiImageModelPicker');
        if (imagePicker) {
            document.getElementById('aiImageModel').value = imagePicker.value;
        }
        var agentPart = collectAgentSettings();
        return {
            enabled: document.getElementById('aiEnabled').checked,
            provider: provider,
            baseUrl: document.getElementById('aiBaseUrl').value.trim(),
            apiKey: aiTypedSecret('aiApiKey'),
            organization: document.getElementById('aiOrganization').value.trim(),
            allowLocalProvider: document.getElementById('aiAllowLocalProvider').checked,
            assistantForceEnglish: document.getElementById('aiAssistantForceEnglish').checked,
            assistantSurfaces: {
                visualEditor: document.getElementById('aiAssistantSurfaceVisualEditor').checked,
                dashboard: document.getElementById('aiAssistantSurfaceDashboard').checked
            },
            chatModel: document.getElementById('aiChatModel').value.trim(),
            textModel: document.getElementById('aiTextModel').value.trim(),
            reasoning: { chat: aiReasoning.chat, text: aiReasoning.text },
            imageModel: document.getElementById('aiImageModel').value.trim(),
            features: {
                backendAssistant: document.getElementById('aiFeatureAssistant').checked,
                seoTextGeneration: document.getElementById('aiFeatureSeo').checked,
                imageGeneration: document.getElementById('aiFeatureImages').checked
            },
            limits: {
                monthlyBudgetCents: parseInt(document.getElementById('aiMonthlyBudget').value || '0', 10),
                dailyRequests: parseInt(document.getElementById('aiDailyRequests').value || '0', 10),
                dailyTextRequests: parseInt(document.getElementById('aiDailyTextRequests').value || '0', 10),
                dailyImageRequests: parseInt(document.getElementById('aiDailyImageRequests').value || '0', 10),
                maxInputTokens: parseInt(document.getElementById('aiMaxInputTokens').value || '0', 10),
                maxOutputTokens: parseInt(document.getElementById('aiMaxOutputTokens').value || '0', 10),
                requestTimeoutSeconds: parseInt(document.getElementById('aiRequestTimeout').value || '0', 10)
            },
            pricing: {
                models: aiCollectPriceChanges(),
                usdPerEur: parseFloat(document.getElementById('aiFxRate').value || '0') || 0,
                imageCentsPerRequest: parseInt(document.getElementById('aiImagePrice').value || '0', 10)
            },
            agent: agentPart.agent,
            research: agentPart.research,
            stock: agentPart.stock
        };
    }

    // ---- AI agent section ----------------------------------------------------------------------------------
    var agentRightIds = ['publish', 'stockImages', 'uploadImages', 'generateImages', 'research', 'structural', 'createPages', 'createNews', 'createEvents', 'editSharedContent'];

    function agentInt(id, fallback) {
        var value = parseInt(document.getElementById(id)?.value || '', 10);
        return Number.isFinite(value) ? value : fallback;
    }

    function collectAgentSettings() {
        var roles = {};
        agentRightIds.forEach(function(right) {
            var el = document.getElementById('aiAgentRight_' + right);
            if (el) roles[right] = el.checked;
        });
        return {
            agent: {
                enabled: !!document.getElementById('aiAgentEnabled')?.checked,
                classicMode: document.getElementById('aiAgentClassicMode') ? !!document.getElementById('aiAgentClassicMode').checked : true,
                limits: {
                    runsPerHour: agentInt('aiAgentRunsPerHour', 10),
                    maxTurns: agentInt('aiAgentMaxTurns', 14),
                    maxToolCalls: agentInt('aiAgentMaxToolCalls', 40),
                    maxCostCentsPerRun: agentInt('aiAgentMaxCost', 100),
                    maxRuntimeSeconds: agentInt('aiAgentMaxRuntime', 15) * 60,
                    uploadsPer10Minutes: agentInt('aiAgentUploads', 10)
                },
                roles: { editor: roles },
                mediaFolder: (document.getElementById('aiAgentMediaFolder')?.value || '').trim() || 'agent',
                creditInNews: !!document.getElementById('aiAgentCreditInNews')?.checked,
                sourcesInNews: !!document.getElementById('aiAgentSourcesInNews')?.checked
            },
            research: {
                braveApiKey: aiTypedSecret('aiBraveApiKey'),
                allowDomains: document.getElementById('aiAgentAllowDomains')?.value || '',
                denyDomains: document.getElementById('aiAgentDenyDomains')?.value || '',
                respectRobots: !!document.getElementById('aiAgentRespectRobots')?.checked
            },
            stock: {
                pexelsApiKey: aiTypedSecret('aiPexelsApiKey')
            }
        };
    }

    function populateAgentSettings(settings) {
        var agent = settings.agent || {};
        var limits = agent.limits || {};
        var editor = (agent.roles && agent.roles.editor) || {};
        var research = settings.research || {};
        var stock = settings.stock || {};
        var checks = {
            aiAgentEnabled: !!agent.enabled,
            aiAgentClassicMode: agent.classicMode !== false,
            aiAgentCreditInNews: agent.creditInNews !== false,
            aiAgentSourcesInNews: agent.sourcesInNews !== false,
            aiAgentRespectRobots: research.respectRobots !== false
        };
        Object.keys(checks).forEach(function(id) {
            var el = document.getElementById(id);
            if (el) el.checked = checks[id];
        });
        agentRightIds.forEach(function(right) {
            var el = document.getElementById('aiAgentRight_' + right);
            if (el) el.checked = editor[right] === true;
        });
        var values = {
            aiAgentRunsPerHour: limits.runsPerHour ?? 10,
            aiAgentMaxTurns: limits.maxTurns ?? 14,
            aiAgentMaxToolCalls: limits.maxToolCalls ?? 40,
            aiAgentMaxCost: limits.maxCostCentsPerRun ?? 100,
            aiAgentMaxRuntime: Math.max(1, Math.round((limits.maxRuntimeSeconds ?? 900) / 60)),
            aiAgentUploads: limits.uploadsPer10Minutes ?? 10,
            aiAgentMediaFolder: agent.mediaFolder || 'agent',
            aiAgentAllowDomains: (research.allowDomains || []).join('\n'),
            aiAgentDenyDomains: (research.denyDomains || []).join('\n'),
            // Keys are never sent back: the fields stay empty and the hints say whether a key is saved.
            aiBraveApiKey: '',
            aiPexelsApiKey: ''
        };
        Object.keys(values).forEach(function(id) {
            var el = document.getElementById(id);
            if (el) el.value = values[id];
        });
        aiSetSecretField('aiBraveApiKey', 'aiRemoveBraveApiKey', 'aiBraveApiKeyHint', !!research.hasBraveApiKey);
        aiSetSecretField('aiPexelsApiKey', 'aiRemovePexelsApiKey', 'aiPexelsApiKeyHint', !!stock.hasPexelsApiKey);
        renderAgentStatus(settings);
    }

    function renderAgentStatus(settings) {
        var box = document.getElementById('aiAgentStatus');
        if (!box) return;
        var status = settings.agentStatus;
        if (!status) {
            box.hidden = true;
            return;
        }
        var messages = [];
        if (!(settings.agent && settings.agent.enabled)) {
            messages.push(t('ai.agent_status_off'));
        } else if (!settings.enabled || !settings.hasApiKey) {
            messages.push(t('ai.agent_status_ai_off'));
        } else if (!status.toolCalling) {
            messages.push(t('ai.agent_status_no_tools'));
        } else {
            messages.push(t('ai.agent_status_ready'));
        }
        if (!status.gd) messages.push(t('ai.agent_status_no_gd'));
        else if (!status.webp) messages.push(t('ai.agent_status_no_webp'));
        if (settings.agent && settings.agent.enabled && status.limits && (status.limits.maxInputTokens < 60000 || (status.limits.dailyRequests > 0 && status.limits.dailyRequests < 200))) {
            messages.push(t('ai.agent_status_limits', { requests: status.limits.dailyRequests, tokens: status.limits.maxInputTokens }));
        }
        box.textContent = messages.join(' ');
        box.hidden = false;
    }

    // What the agent needs to work (model turns carry the page content and tool results).
    var AI_AGENT_RECOMMENDED_LIMITS = { aiMaxInputTokens: 64000, aiMaxOutputTokens: 16000, aiDailyRequests: 300, aiDailyTextRequests: 250 };

    function raiseAgentLimits() {
        var raised = false;
        Object.keys(AI_AGENT_RECOMMENDED_LIMITS).forEach(function(id) {
            var el = document.getElementById(id);
            if (el && (parseInt(el.value || '0', 10) || 0) < AI_AGENT_RECOMMENDED_LIMITS[id]) {
                el.value = AI_AGENT_RECOMMENDED_LIMITS[id];
                raised = true;
            }
        });
        return raised;
    }

    document.getElementById('aiAgentRecommendedLimits')?.addEventListener('click', function() {
        showToast(t(raiseAgentLimits() ? 'ai.agent_limits_raised' : 'ai.agent_limits_fine'), 'info');
    });
    document.getElementById('aiAgentEnabled')?.addEventListener('change', function() {
        if (this.checked && raiseAgentLimits()) showToast(t('ai.agent_limits_raised'), 'info');
    });

    // After a save that adds a key or puts a powerful chat model in place, offer the agent while it is off.
    // A key that was just added always gets the question; a changed model only once per model.
    function maybeOfferAiAgent(previous, saved) {
        if (!saved || !saved.enabled || !saved.hasApiKey || (saved.agent && saved.agent.enabled)) return;
        if (!saved.agentStatus || !saved.agentStatus.toolCalling) return;
        var caps = aiModelCaps(saved.provider, saved.baseUrl, saved.chatModel);
        if (!caps || caps.tier !== 'power') return;
        var before = previous && previous.providerCredentials && previous.providerCredentials[saved.provider];
        var keyAdded = !before || !before.hasApiKey;
        var modelChanged = !previous || previous.provider !== saved.provider || previous.chatModel !== saved.chatModel;
        if (!keyAdded && !modelChanged) return;
        var memo = 'nbAgentOffer:' + saved.provider + '|' + String(saved.chatModel).toLowerCase();
        try {
            if (!keyAdded && localStorage.getItem(memo)) return;
            localStorage.setItem(memo, '1');
        } catch (e) { /* without storage the question may come again */ }
        showModal(t('ai.agent_offer_title'), t('ai.agent_offer_text', { model: caps.label }), function() {
            closeModal();
            var toggle = document.getElementById('aiAgentEnabled');
            if (!toggle) return;
            toggle.checked = true;
            raiseAgentLimits();
            saveAiSettingsForm();
        }, { confirmText: t('ai.agent_offer_confirm'), confirmClass: 'btn btn-primary' });
    }

    // The assistant button (bottom right) belongs to the page only when the assistant is usable. The page loads its script
    // and styles only then, so after the first key is saved they are loaded here, and the button goes away when it stops being usable.
    var aiCopilotLoading = false;
    function aiSyncDashboardCopilot(settings) {
        if (!AI_FEATURES_ENABLED || !settings) return;
        var available = !!settings.enabled && !!settings.hasApiKey && !!(settings.features && settings.features.backendAssistant)
            && !(settings.assistantSurfaces && settings.assistantSurfaces.dashboard === false);
        var root = document.querySelector('.nb-copilot');
        if (root) {
            root.style.display = available ? '' : 'none';
            if (!available && window.NibblyAiCopilot) window.NibblyAiCopilot.close();
            return;
        }
        if (!available || window.NibblyAiCopilot || aiCopilotLoading) return;
        aiCopilotLoading = true;
        window.NB_AI_COPILOT_AVAILABLE = true;
        var style = document.createElement('link');
        style.rel = 'stylesheet';
        style.href = '../css/ai-copilot.css?v=<?php echo @filemtime(__DIR__ . '/../../../css/ai-copilot.css') ?: time(); ?>';
        document.head.appendChild(style);
        var script = document.createElement('script');
        script.src = '../js/ai-copilot.js?v=<?php echo @filemtime(__DIR__ . '/../../../js/ai-copilot.js') ?: time(); ?>';
        script.onerror = function() { aiCopilotLoading = false; };
        document.body.appendChild(script);
    }

    document.getElementById('aiAgentClearCache')?.addEventListener('click', async function() {
        var button = this;
        button.disabled = true;
        try {
            var formData = new FormData();
            formData.append('action', 'ai-agent-clear-cache');
            formData.append('csrf_token', CSRF_TOKEN);
            var response = await fetch('api.php', { method: 'POST', body: formData });
            var result = await response.json();
            if (!result.success) throw new Error(result.message);
            showToast(t('ai.agent_cache_cleared', { count: result.data.removed }), 'success');
        } catch (error) {
            showToast(error.message, 'error');
        } finally {
            button.disabled = false;
        }
    });

    document.getElementById('aiProvider')?.addEventListener('change', function() {
        // The models and levels typed for the provider left behind are kept for this visit; the typed key is dropped.
        aiStashProviderModels(aiLastProvider);
        aiLastProvider = this.value;
        var baseUrl = document.getElementById('aiBaseUrl');
        var credentials = aiProviderCredentials(currentAiSettings || {}, this.value);
        if (baseUrl) baseUrl.value = credentials.baseUrl || '';
        var organization = document.getElementById('aiOrganization');
        if (organization) organization.value = credentials.organization || '';
        aiUpdateOrganizationVisibility(this.value);
        updateAiApiKeyHint(this.value);
        aiApplyProviderModels(this.value, baseUrl ? baseUrl.value : '');
        updateAiModelPlaceholders(this.value);
        updateAiImageModelControl({
            provider: this.value,
            baseUrl: baseUrl ? baseUrl.value : '',
            imageModel: document.getElementById('aiImageModel')?.value || ''
        });
        var draft = Object.assign({}, currentAiSettings || {}, collectAiSettingsForm());
        draft.hasApiKey = !!(currentAiSettings && currentAiSettings.hasApiKey) || !!document.getElementById('aiApiKey').value.trim();
        updateAiAvailability(draft);
    });

    document.getElementById('aiImageModelPicker')?.addEventListener('change', function() {
        var imageInput = document.getElementById('aiImageModel');
        if (imageInput) imageInput.value = this.value;
        updateAiImageScaleOptions();
        var draft = Object.assign({}, currentAiSettings || {}, collectAiSettingsForm());
        draft.hasApiKey = !!(currentAiSettings && currentAiSettings.hasApiKey) || !!document.getElementById('aiApiKey').value.trim();
        updateAiAvailability(draft);
    });

    ['aiApiKey', 'aiBaseUrl', 'aiAllowLocalProvider', 'aiEnabled', 'aiAssistantForceEnglish', 'aiAssistantSurfaceVisualEditor', 'aiAssistantSurfaceDashboard', 'aiFeatureAssistant', 'aiFeatureSeo', 'aiFeatureImages', 'aiImageModel', 'aiImageModelPicker'].forEach(function(id) {
        document.addEventListener('input', function(e) {
            if (e.target && e.target.id === id) {
                if (id === 'aiApiKey') applyAiDefaultsForNewApiKey();
                var draft = Object.assign({}, currentAiSettings || {}, collectAiSettingsForm());
                draft.hasApiKey = !!(currentAiSettings && currentAiSettings.hasApiKey) || !!document.getElementById('aiApiKey').value.trim();
                updateAiAvailability(draft);
            }
        });
        document.addEventListener('change', function(e) {
            if (e.target && e.target.id === id) {
                if (id === 'aiApiKey') applyAiDefaultsForNewApiKey();
                var draft = Object.assign({}, currentAiSettings || {}, collectAiSettingsForm());
                draft.hasApiKey = !!(currentAiSettings && currentAiSettings.hasApiKey) || !!document.getElementById('aiApiKey').value.trim();
                updateAiAvailability(draft);
            }
        });
    });

    document.getElementById('aiBaseUrl')?.addEventListener('input', function() {
        aiRenderEffort('chat');
        aiRenderEffort('text');
        aiRenderPricing();
    });

    // Remove a stored key right away, after asking. Unsaved edits in the form stay as they are.
    document.querySelectorAll('[data-secret-target]').forEach(function(button) {
        button.addEventListener('click', function() {
            var kind = button.getAttribute('data-secret-target');
            var target = kind === 'provider' ? 'provider:' + aiCurrentProvider() : kind;
            showModal(t('ai.remove_key_title'), t('ai.remove_key_confirm'), async function() {
                closeModal();
                try {
                    var formData = new FormData();
                    formData.append('action', 'ai-clear-secret');
                    formData.append('target', target);
                    formData.append('csrf_token', CSRF_TOKEN);
                    var response = await fetch('api.php', { method: 'POST', body: formData });
                    var result = await response.json();
                    if (!result.success) throw new Error(result.message);
                    var fresh = result.data.settings || {};
                    currentAiSettings = Object.assign({}, currentAiSettings, {
                        hasApiKey: fresh.hasApiKey,
                        providerCredentials: fresh.providerCredentials,
                        research: Object.assign({}, (currentAiSettings || {}).research, { hasBraveApiKey: !!(fresh.research && fresh.research.hasBraveApiKey) }),
                        stock: Object.assign({}, (currentAiSettings || {}).stock, { hasPexelsApiKey: !!(fresh.stock && fresh.stock.hasPexelsApiKey) }),
                        agentStatus: fresh.agentStatus
                    });
                    aiLiveModels = {};
                    updateAiApiKeyHint(aiCurrentProvider());
                    aiSetSecretField('aiBraveApiKey', 'aiRemoveBraveApiKey', 'aiBraveApiKeyHint', !!currentAiSettings.research.hasBraveApiKey);
                    aiSetSecretField('aiPexelsApiKey', 'aiRemovePexelsApiKey', 'aiPexelsApiKeyHint', !!currentAiSettings.stock.hasPexelsApiKey);
                    renderAgentStatus(currentAiSettings);
                    aiSyncDashboardCopilot(currentAiSettings);
                    updateAiAvailability(Object.assign({}, currentAiSettings, collectAiSettingsForm(), { hasApiKey: !!currentAiSettings.hasApiKey }));
                    if (result.data.usage) updateAiUsage(result.data.usage);
                    showToast(t('ai.key_removed'), 'success');
                } catch (error) {
                    showToast(error.message, 'error');
                }
            }, { confirmText: t('ai.remove_key_button'), confirmClass: 'btn btn-danger' });
        });
    });

    function appendAiChat(role, text) {
        var log = document.getElementById('aiChatLog');
        if (!log) return;
        var item = document.createElement('div');
        item.className = 'ai-chat-message ai-chat-message--' + role;
        if (role === 'assistant') {
            item.innerHTML = window.renderSimpleMarkup(text);
        } else {
            item.textContent = text;
        }
        log.appendChild(item);
        log.scrollTop = log.scrollHeight;
    }

    window.renderSimpleMarkup = function(text) {
        var escaped = escapeHtml(String(text || ''));
        return escaped
            .replace(/\[([^\]\n]+)\]\((https?:\/\/[^)\s]+|mailto:[^)\s]+)\)/g, function(match, label, url) {
                var safeUrl = url.replace(/&amp;/g, '&');
                return '<a href="' + safeUrl + '" target="_blank" rel="noopener noreferrer">' + label + '</a>';
            })
            .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
            .replace(/(^|[^*])\*([^*\\n]+)\*/g, '$1<em>$2</em>')
            .replace(/`([^`]+)`/g, '<code>$1</code>');
    };

    // Form definitions
    let formsAdminData = [];
    let currentFormEditorId = '';

    function defaultFormDefinition(id) {
        return {
            id: id || 'kontakt',
            label: t('forms.contact_default'),
            description: '',
            enabled: true,
            submit: {
                store: true,
                email: true,
                subject: '{form}: {name}',
                successText: t('forms.default_success')
            },
            fields: [
                { type: 'text', key: 'name', label: t('mails.name').replace(':', ''), placeholder: '', required: true, width: 6, options: [] },
                { type: 'email', key: 'email', label: t('mails.email').replace(':', ''), placeholder: '', required: true, width: 6, options: [] },
                { type: 'textarea', key: 'message', label: t('mails.message').replace(':', ''), placeholder: '', required: true, width: 12, options: [] }
            ]
        };
    }
