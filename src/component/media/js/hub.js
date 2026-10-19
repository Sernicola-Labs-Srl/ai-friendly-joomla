(function () {
  'use strict';

  var hub = document.querySelector('[data-aif-hub]');
  if (!hub) return;

  var state = { page: 1, pages: 1, total: 0, dirty: false, loaded: {}, snapshots: [] };
  var tokenInput = hub.querySelector('input[type="hidden"][value="1"]');
  var token = tokenInput ? tokenInput.name : '';

  /* ---------------------------------------------------------------- i18n */

  function T(key, fallback) {
    if (window.Joomla && Joomla.Text && typeof Joomla.Text._ === 'function') {
      return Joomla.Text._(key, fallback === undefined ? key : fallback);
    }
    return fallback === undefined ? key : fallback;
  }

  // Supports both %s and positional %1$s placeholders, as used in the INI files.
  function format(template, values) {
    var index = 0;
    return String(template).replace(/%(\d+\$)?s/g, function (match, position) {
      var at = position ? parseInt(position, 10) - 1 : index++;
      return values[at] === undefined ? '' : String(values[at]);
    });
  }

  function TF(key, fallback) {
    var values = Array.prototype.slice.call(arguments, 2);
    return format(T(key, fallback), values);
  }

  /* ------------------------------------------------------------- helpers */

  function esc(value) {
    return String(value === null || value === undefined ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function safeUrl(value) {
    var url = String(value || '').trim();
    return /^(https?:\/\/|\/|\.\/|#)/i.test(url) ? url : '#';
  }

  function endpoint(task) {
    return 'index.php?option=com_aifriendly&format=json&task=api.' + encodeURIComponent(task);
  }

  function formData(extra, includeOptions) {
    var data = new FormData();
    if (token) data.append(token, '1');
    if (includeOptions) {
      hub.querySelectorAll('[name^="options["]').forEach(function (field) {
        if (field.type === 'checkbox' && !field.checked) { data.append(field.name, '0'); return; }
        if (field.type === 'radio' && !field.checked) return;
        data.append(field.name, field.value);
      });
    }
    Object.keys(extra || {}).forEach(function (key) { data.append(key, extra[key]); });
    return data;
  }

  function request(task, extra, includeOptions) {
    return fetch(endpoint(task), { method: 'POST', credentials: 'same-origin', body: formData(extra, includeOptions) })
      .then(function (response) { return response.json(); })
      .then(function (payload) {
        if (payload.success === false) throw new Error(payload.message || T('COM_AIFRIENDLY_GENERIC_ERROR', 'The operation could not be completed.'));
        return payload.data !== undefined ? payload.data : payload;
      });
  }

  // Blocks double submits and exposes a pending state to assistive technology.
  function busy(button, pending) {
    if (!button) return;
    button.disabled = !!pending;
    if (pending) button.setAttribute('aria-busy', 'true');
    else button.removeAttribute('aria-busy');
  }

  // Reports failures once, through the notice, so call sites never have to.
  function run(button, promise) {
    busy(button, true);
    return promise
      .catch(function (error) { notice(error.message, { error: true }); })
      .finally(function () { busy(button, false); });
  }

  /* -------------------------------------------------------------- notice */

  var noticeBox = hub.querySelector('[data-notice]');
  var noticeText = hub.querySelector('[data-notice-text]');
  var noticeAction = hub.querySelector('[data-notice-action]');

  function hideNotice() {
    noticeBox.hidden = true;
    noticeAction.hidden = true;
    noticeAction.onclick = null;
  }

  function notice(message, options) {
    var settings = options || {};
    window.clearTimeout(notice.timer);
    noticeText.textContent = message;
    noticeBox.classList.toggle('is-error', !!settings.error);
    noticeBox.hidden = false;

    if (settings.action) {
      noticeAction.hidden = false;
      noticeAction.textContent = settings.action.label;
      noticeAction.onclick = settings.action.run;
    } else {
      noticeAction.hidden = true;
      noticeAction.onclick = null;
    }

    // Errors and actionable messages stay until dismissed.
    if (!settings.error && !settings.action) {
      notice.timer = window.setTimeout(hideNotice, 6000);
    }
  }

  hub.querySelector('[data-notice-close]').addEventListener('click', hideNotice);

  /* ---------------------------------------------------------------- tabs */

  var tabs = Array.prototype.slice.call(hub.querySelectorAll('[role="tab"]'));

  function selectSection(name, moveFocus) {
    tabs.forEach(function (tab) {
      var active = tab.dataset.sectionTarget === name;
      tab.setAttribute('aria-selected', active ? 'true' : 'false');
      tab.tabIndex = active ? 0 : -1;
      tab.classList.toggle('is-active', active);
      if (active && moveFocus) tab.focus();
    });

    hub.querySelectorAll('[data-section]').forEach(function (section) {
      var active = section.dataset.section === name;
      section.classList.toggle('is-active', active);
      section.hidden = !active;
    });

    if (window.history && window.history.replaceState) {
      window.history.replaceState(null, '', '#aif-' + name);
    }

    ensureLoaded(name);
  }

  // Section data is fetched the first time a section is opened.
  function ensureLoaded(name) {
    if (state.loaded[name]) return;
    state.loaded[name] = true;
    if (name === 'content') { loadContent(); loadSnapshots(); }
  }

  tabs.forEach(function (tab, position) {
    tab.addEventListener('click', function () { selectSection(tab.dataset.sectionTarget); });
    tab.addEventListener('keydown', function (event) {
      var keys = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 };
      var next = null;

      if (keys[event.key]) next = (position + keys[event.key] + tabs.length) % tabs.length;
      else if (event.key === 'Home') next = 0;
      else if (event.key === 'End') next = tabs.length - 1;
      if (next === null) return;

      event.preventDefault();
      selectSection(tabs[next].dataset.sectionTarget, true);
    });
  });

  /* --------------------------------------------------------- dirty state */

  function setDirty(dirty) {
    state.dirty = dirty;
    hub.querySelectorAll('[data-dirty]').forEach(function (flag) { flag.hidden = !dirty; });
  }

  hub.addEventListener('input', function (event) {
    if (event.target.name && event.target.name.indexOf('options[') === 0) setDirty(true);
  });

  hub.addEventListener('change', function (event) {
    if (event.target.name && event.target.name.indexOf('options[') === 0) setDirty(true);
  });

  window.addEventListener('beforeunload', function (event) {
    if (!state.dirty) return;
    event.preventDefault();
    event.returnValue = '';
  });

  /* ------------------------------------------------------------ overview */

  function setMetric(name, value) {
    hub.querySelectorAll('[data-metric="' + name + '"]').forEach(function (node) { node.textContent = value; });
  }

  function diagnosticMessage(row) {
    var code = String(row.code || '').toUpperCase();
    return code ? T('COM_AIFRIENDLY_DIAG_' + code, row.message || '') : (row.message || '');
  }

  function renderDiagnostics(rows) {
    var list = hub.querySelector('[data-diagnostics]');
    list.innerHTML = rows.map(function (row) {
      var level = row.level || 'info';
      return '<li data-level="' + esc(level) + '"><span class="aif-tag">'
        + esc(T('COM_AIFRIENDLY_LEVEL_' + level.toUpperCase(), level)) + '</span>'
        + esc(diagnosticMessage(row)) + '</li>';
    }).join('') || '<li class="aif-empty">' + esc(T('COM_AIFRIENDLY_DIAGNOSTICS_EMPTY', 'No alerts.')) + '</li>';
  }

  function renderEvents(items) {
    hub.querySelector('[data-events]').innerHTML = items.map(function (item) {
      var type = String(item.event_type || '');
      return '<li><time>' + esc(item.created) + '</time><span>'
        + esc(T('COM_AIFRIENDLY_EVENT_' + type.toUpperCase(), type)) + '</span></li>';
    }).join('') || '<li class="aif-empty">' + esc(T('COM_AIFRIENDLY_TIMELINE_EMPTY', 'No events yet.')) + '</li>';
  }

  function refreshOverview(button) {
    return run(button, request('overview').then(function (data) {
      var alerts = (data.errors || []).concat(data.warnings || []);
      setMetric('included', data.included_count || 0);
      setMetric('total', data.total_count || 0);
      setMetric('static', (data.static || {}).count || 0);
      setMetric('warnings', alerts.length);
      var card = hub.querySelector('[data-metric-card="warnings"]');
      if (card) card.classList.toggle('is-flagged', alerts.length > 0);
      renderDiagnostics(alerts);
      renderEvents(data.events || []);
      var full = data.llms_full || {};
      var fullStatus = hub.querySelector('[data-full-status]');
      if (fullStatus) {
        fullStatus.innerHTML = esc(T('COM_AIFRIENDLY_LLMS_FULL_STATUS', 'Full corpus')) + ': '
          + (full.ready
            ? esc(T('COM_AIFRIENDLY_LLMS_FULL_READY', 'Ready') + ' (' + (full.size || 0) + ' B)')
              + ' <a href="' + esc(safeUrl(full.url)) + '" target="_blank" rel="noopener">llms-full.txt</a>'
            : esc(T('COM_AIFRIENDLY_LLMS_FULL_WAITING', 'Waiting for a complete regeneration')));
      }
    }));
  }

  /* ------------------------------------------------------- save and editor */

  function save(button) {
    return run(button, request('save', {}, true).then(function () {
      setDirty(false);
      notice(T('COM_AIFRIENDLY_SAVED', 'Settings saved.'));
      return refreshOverview();
    }));
  }

  function preview(button) {
    var editor = hub.querySelector('[data-llms-editor]');
    return run(button, request('preview', { content: editor.value }).then(function (data) {
      var target = hub.querySelector('[data-preview]');
      target.textContent = data.content || '';
      target.classList.toggle('aif-empty', !data.content);
      hub.querySelector('[data-preview-lines]').textContent = TF('COM_AIFRIENDLY_PREVIEW_LINES', '%s lines', data.lines || 0);
      hub.querySelector('[data-preview-tokens]').textContent = TF('COM_AIFRIENDLY_PREVIEW_TOKENS', '%s tokens', data.tokens || 0);

      var links = data.links || [];
      var broken = links.filter(function (link) { return !link.valid; }).length;
      var meta = hub.querySelector('[data-preview-links]');
      meta.textContent = broken
        ? TF('COM_AIFRIENDLY_PREVIEW_LINKS_INVALID', '%s invalid links', broken)
        : TF('COM_AIFRIENDLY_PREVIEW_LINKS', '%s links', links.length);
      meta.style.color = broken ? 'var(--aif-error)' : '';
    }));
  }

  /* ------------------------------------------------------------- content */

  function statusOf(item) {
    if (item.excluded) return { label: T('COM_AIFRIENDLY_STATUS_EXCLUDED_MANUAL', 'Excluded manually'), modifier: 'excluded' };
    if (item.included) return { label: T('COM_AIFRIENDLY_STATUS_INCLUDED', 'Included'), modifier: 'included' };
    return { label: T('COM_AIFRIENDLY_STATUS_EXCLUDED_RULES', 'Excluded by rules'), modifier: 'excluded' };
  }

  function renderRows(items) {
    return items.map(function (item) {
      var status = statusOf(item);
      var action = item.excluded
        ? { label: T('COM_AIFRIENDLY_ACTION_INCLUDE', 'Include'), aria: TF('COM_AIFRIENDLY_ACTION_INCLUDE_LABEL', 'Include %s', item.title) }
        : { label: T('COM_AIFRIENDLY_ACTION_EXCLUDE', 'Exclude'), aria: TF('COM_AIFRIENDLY_ACTION_EXCLUDE_LABEL', 'Exclude %s', item.title) };

      return '<tr>'
        + '<td><a href="' + esc(safeUrl(item.canonical_url)) + '" target="_blank" rel="noopener">' + esc(item.title) + '</a></td>'
        + '<td>' + esc(item.provider) + '</td>'
        + '<td>' + esc(item.language) + '</td>'
        + '<td><span class="aif-status aif-status--' + status.modifier + '">' + esc(status.label) + '</span></td>'
        + '<td><button type="button" data-toggle-key="' + esc(item.key) + '" aria-label="' + esc(action.aria) + '">' + esc(action.label) + '</button></td>'
        + '</tr>';
    }).join('');
  }

  function loadContent(button) {
    var wrap = hub.querySelector('.aif-table-wrap');
    var search = hub.querySelector('[data-content-search]').value || '';
    wrap.setAttribute('aria-busy', 'true');

    return run(button, request('content', { page: state.page, limit: 25, search: search }).then(function (data) {
      state.total = data.total || 0;
      state.pages = Math.max(1, Math.ceil(state.total / (data.limit || 25)));
      state.page = Math.min(state.page, state.pages);

      hub.querySelector('[data-content-page]').textContent = TF('COM_AIFRIENDLY_PAGE_OF', 'Page %1$s of %2$s', state.page, state.pages);
      hub.querySelector('[data-content-total]').textContent = TF('COM_AIFRIENDLY_RESULTS', '%s results', state.total);
      hub.querySelector('[data-content-prev]').disabled = state.page <= 1;
      hub.querySelector('[data-content-next]').disabled = state.page >= state.pages;
      hub.querySelector('[data-content-rows]').innerHTML = renderRows(data.items || [])
        || '<tr><td colspan="5" class="aif-empty">' + esc(T('COM_AIFRIENDLY_CONTENT_EMPTY', 'No content found.')) + '</td></tr>';
    })).finally(function () { wrap.removeAttribute('aria-busy'); });
  }

  hub.addEventListener('click', function (event) {
    var toggle = event.target.closest('[data-toggle-key]');
    if (!toggle) return;
    run(toggle, request('toggle', { key: toggle.dataset.toggleKey }).then(function () {
      return Promise.all([loadContent(), refreshOverview()]);
    }));
  });

  var searchField = hub.querySelector('[data-content-search]');
  searchField.addEventListener('keydown', function (event) {
    if (event.key !== 'Enter') return;
    event.preventDefault();
    state.page = 1;
    loadContent();
  });
  // Fires when the native clear button of type="search" is used.
  searchField.addEventListener('search', function () { state.page = 1; loadContent(); });

  hub.querySelector('[data-content-prev]').addEventListener('click', function () {
    if (state.page > 1) { state.page--; loadContent(); }
  });

  hub.querySelector('[data-content-next]').addEventListener('click', function () {
    if (state.page < state.pages) { state.page++; loadContent(); }
  });

  /* ----------------------------------------------------------- snapshots */

  function loadSnapshots(button) {
    return run(button, request('snapshots').then(function (data) {
      state.snapshots = data.items || [];
      hub.querySelector('[data-snapshots]').innerHTML = state.snapshots.map(function (item) {
        var meta = TF('COM_AIFRIENDLY_SNAPSHOT_META', '%1$s - %2$s lines', item.created, item.line_count);
        return '<li>'
          + '<label><input type="checkbox" data-snapshot-id="' + esc(item.id) + '" aria-label="'
          + esc(TF('COM_AIFRIENDLY_SNAPSHOT_COMPARE_LABEL', 'Select snapshot %s', item.id)) + '">'
          + '<span><b>#' + esc(item.id) + '</b> ' + esc(item.reason) + '<small>' + esc(meta) + '</small></span></label>'
          + '<button type="button" data-restore-id="' + esc(item.id) + '" aria-label="'
          + esc(TF('COM_AIFRIENDLY_SNAPSHOT_RESTORE_LABEL', 'Restore snapshot %s', item.id)) + '">'
          + esc(T('COM_AIFRIENDLY_SNAPSHOT_RESTORE', 'Restore')) + '</button>'
          + '</li>';
      }).join('') || '<li class="aif-empty">' + esc(T('COM_AIFRIENDLY_SNAPSHOTS_EMPTY', 'No snapshots yet.')) + '</li>';
    }));
  }

  function diffHint(message) {
    hub.querySelector('[data-diff]').innerHTML = '<p class="aif-empty">' + esc(message) + '</p>';
  }

  function compareSnapshots() {
    var selected = Array.prototype.map.call(
      hub.querySelectorAll('[data-snapshot-id]:checked'),
      function (input) { return input.dataset.snapshotId; }
    );

    if (selected.length === 0) { diffHint(T('COM_AIFRIENDLY_SNAPSHOT_SELECT', 'Select two snapshots to compare them.')); return; }
    if (selected.length === 1) { diffHint(T('COM_AIFRIENDLY_SNAPSHOT_SELECT_ONE', 'Select one more snapshot.')); return; }
    if (selected.length > 2) { diffHint(T('COM_AIFRIENDLY_SNAPSHOT_SELECT_MAX', 'Compare two snapshots at a time.')); return; }

    request('snapshotDiff', { left: selected[0], right: selected[1] }).then(function (data) {
      var rows = data.rows || [];
      var changed = rows.filter(function (row) { return row.changed; });
      if (!changed.length) { diffHint(T('COM_AIFRIENDLY_DIFF_IDENTICAL', 'The two snapshots are identical.')); return; }

      var head = '<div class="aif-diff-head">'
        + '<b>' + esc(T('COM_AIFRIENDLY_DIFF_LINE', 'Line')) + '</b>'
        + '<span>' + esc(TF('COM_AIFRIENDLY_DIFF_LEFT', 'Snapshot %s', selected[0])) + '</span>'
        + '<span>' + esc(TF('COM_AIFRIENDLY_DIFF_RIGHT', 'Snapshot %s', selected[1])) + '</span>'
        + '</div>';

      hub.querySelector('[data-diff]').innerHTML = head + rows.map(function (row) {
        return '<div class="aif-diff-row' + (row.changed ? ' is-changed' : '') + '">'
          + '<b>' + esc(row.line) + '</b>'
          + '<span>' + esc(row.left) + '</span>'
          + '<span>' + esc(row.right) + '</span>'
          + '</div>';
      }).join('');
    }).catch(function (error) { notice(error.message, { error: true }); });
  }

  hub.addEventListener('change', function (event) {
    if (event.target.matches('[data-snapshot-id]')) compareSnapshots();
  });

  hub.addEventListener('click', function (event) {
    var restore = event.target.closest('[data-restore-id]');
    if (!restore) return;
    if (!window.confirm(T('COM_AIFRIENDLY_SNAPSHOT_RESTORE_CONFIRM', 'Restore this snapshot?'))) return;

    run(restore, request('snapshotRestore', { id: restore.dataset.restoreId }).then(function () {
      setDirty(false);
      notice(T('COM_AIFRIENDLY_SNAPSHOT_RESTORED', 'Snapshot restored.'), {
        action: { label: T('COM_AIFRIENDLY_RELOAD', 'Reload the page'), run: function () { window.location.reload(); } }
      });
    }));
  });

  /* ---------------------------------------------------------- automation */

  function renderRun(stats) {
    var cells = [
      ['COM_AIFRIENDLY_RUN_PROCESSED', 'Processed', stats.processed],
      ['COM_AIFRIENDLY_RUN_REGENERATED', 'Regenerated', stats.regenerated],
      ['COM_AIFRIENDLY_RUN_SKIPPED', 'Skipped', stats.skipped],
      ['COM_AIFRIENDLY_RUN_EXCLUDED', 'Excluded', stats.excluded],
      ['COM_AIFRIENDLY_RUN_ERRORS', 'Errors', stats.errors]
    ].filter(function (cell) { return cell[2] !== undefined; });

    return '<p>' + esc(T('COM_AIFRIENDLY_RUN_DONE', 'Regeneration completed.')) + '</p>'
      + '<ul class="aif-run-stats">' + cells.map(function (cell) {
        var flagged = cell[0] === 'COM_AIFRIENDLY_RUN_ERRORS' && cell[2] > 0;
        return '<li><span>' + esc(T(cell[0], cell[1])) + '</span><strong'
          + (flagged ? ' style="color:var(--aif-error)"' : '') + '>' + esc(cell[2]) + '</strong></li>';
      }).join('') + '</ul>';
  }

  function regenerate(button) {
    var output = hub.querySelector('[data-run-output]');
    output.innerHTML = '<p>' + esc(T('COM_AIFRIENDLY_RUN_PENDING', 'Regeneration in progress...')) + '</p>';
    return run(button, request('regenerationStart', { force: 1 }).then(followRegeneration)
      .catch(function (error) {
        output.innerHTML = '<p class="aif-empty">' + esc(error.message) + '</p>';
        throw error;
      }));
  }

  function followRegeneration(job) {
    var output = hub.querySelector('[data-run-output]');
    if (!job) return Promise.resolve();
    if (job.status === 'running' || job.status === 'indexing') {
      output.innerHTML = '<p>' + esc(T('COM_AIFRIENDLY_RUN_PROGRESS', 'Regeneration')) + ': '
        + esc((job.stats || {}).processed || 0) + '/' + esc(job.total || 0) + '</p>';
      return new Promise(function (resolve) { window.setTimeout(resolve, 400); })
        .then(function () { return request(job.status === 'indexing' ? 'regenerationStatus' : 'regenerationStep'); })
        .then(followRegeneration);
    }
    output.innerHTML = job.status === 'failed'
      ? '<p class="aif-empty">' + esc(job.error || T('COM_AIFRIENDLY_RUN_FAILED', 'Regeneration failed.')) + '</p>' + renderRun(job.stats || {})
      : renderRun(job.stats || {});
    return refreshOverview();
  }

  request('regenerationStatus').then(function (job) {
    if (job && (job.status === 'running' || job.status === 'indexing')) return followRegeneration(job);
  }).catch(function () {});

  function clearVersions(button) {
    if (!window.confirm(T('COM_AIFRIENDLY_CLEAR_CONFIRM', 'Delete all static Markdown files?'))) return Promise.resolve();
    return run(button, request('clearVersions').then(function (data) {
      notice(TF('COM_AIFRIENDLY_CLEARED', '%s files deleted.', data.deleted || 0));
      return refreshOverview();
    }));
  }

  // Adds or removes the optional 4SEO bridge: a file of 4SEO is edited, so only on an explicit click.
  function forseoBridge(button, enable) {
    if (enable && !window.confirm(T('COM_AIFRIENDLY_FORSEO_CONFIRM', 'Add the AI Friendly bridge to the 4SEO functions file?'))) return Promise.resolve();
    return run(button, request('forseoBridge', { enable: enable ? 1 : 0 }).then(function () {
      notice(T(enable ? 'COM_AIFRIENDLY_FORSEO_ENABLED' : 'COM_AIFRIENDLY_FORSEO_DISABLED', enable ? 'Bridge enabled.' : 'Bridge removed.'), {
        action: { label: T('COM_AIFRIENDLY_RELOAD', 'Reload the page'), run: function () { window.location.reload(); } }
      });
    }));
  }

  /* ------------------------------------------------------------- actions */

  var actions = {
    save: save,
    preview: preview,
    diagnostics: refreshOverview,
    events: function (button) {
      return run(button, request('events').then(function (data) { renderEvents(data.items || []); }));
    },
    content: function (button) { state.page = 1; return loadContent(button); },
    snapshots: loadSnapshots,
    'snapshot-create': function (button) {
      return run(button, request('snapshotCreate', { reason: 'manual' }).then(function () {
        notice(T('COM_AIFRIENDLY_SNAPSHOT_CREATED', 'Snapshot created.'));
        return loadSnapshots();
      }));
    },
    regenerate: regenerate,
    prune: function (button) {
      return run(button, request('prune').then(function (data) {
        notice(data.skipped
          ? T('COM_AIFRIENDLY_PRUNE_SKIPPED', 'Cleanup skipped: the catalogue is too large to scan in one pass.')
          : TF('COM_AIFRIENDLY_PRUNED', '%s obsolete files deleted.', data.deleted || 0));
        return refreshOverview();
      }));
    },
    clear: clearVersions,
    'forseo-enable': function (button) { return forseoBridge(button, true); },
    'forseo-disable': function (button) { return forseoBridge(button, false); }
  };

  hub.querySelectorAll('[data-action]').forEach(function (button) {
    button.addEventListener('click', function () {
      var action = actions[button.dataset.action];
      if (action) action(button);
    });
  });

  /* -------------------------------------------------------------- schema */

  var schemaToggle = hub.querySelector('[data-schema-toggle]');
  var schemaHint = hub.querySelector('[data-schema-hint]');

  if (schemaToggle && schemaHint) {
    schemaToggle.addEventListener('change', function () { schemaHint.hidden = schemaToggle.checked; });
  }

  /* --------------------------------------------------- llms.txt tooling */

  // Pure parsers, kept free of DOM access so they can be exercised directly.

  function parseOutline(text) {
    var lines = String(text).split(/\r?\n/);
    var out = [];
    var fenced = false;

    lines.forEach(function (line, index) {
      if (/^\s*```/.test(line)) { fenced = !fenced; return; }
      if (fenced) return;

      var heading = line.match(/^(#{1,6})\s+(.*\S)\s*$/);
      if (heading) {
        out.push({ level: heading[1].length, text: heading[2], line: index + 1 });
      }
    });

    return out;
  }

  function parseLinkIssues(text) {
    var issues = [];
    var pattern = /\[([^\]]*)\]\(([^)]*)\)/g;
    var match;

    while ((match = pattern.exec(String(text))) !== null) {
      var label = match[1].trim();
      var url = match[2].trim();
      var line = String(text).slice(0, match.index).split(/\r?\n/).length;

      if (url === '') {
        issues.push({ line: line, reason: 'empty', text: match[0] });
      } else if (/\s/.test(url)) {
        issues.push({ line: line, reason: 'space', text: match[0] });
      } else if (!/^(https?:\/\/|\/|\.{1,2}\/|#|mailto:|tel:)/i.test(url)) {
        issues.push({ line: line, reason: 'scheme', text: match[0] });
      } else if (label === '') {
        issues.push({ line: line, reason: 'label', text: match[0] });
      }
    }

    return issues;
  }

  function estimateTokens(text) {
    return Math.ceil(String(text).length / 4);
  }

  var editor = hub.querySelector('[data-llms-editor]');
  var outlineList = hub.querySelector('[data-outline]');
  var linkBox = hub.querySelector('[data-linkcheck]');
  var linkList = hub.querySelector('[data-linkcheck-list]');
  var statsBox = hub.querySelector('[data-editor-stats]');

  function refreshEditorTools() {
    var text = editor.value;
    var outline = parseOutline(text);
    var issues = parseLinkIssues(text);

    statsBox.textContent = [
      TF('COM_AIFRIENDLY_PREVIEW_LINES', '%s lines', text === '' ? 0 : text.split(/\r?\n/).length),
      TF('COM_AIFRIENDLY_PREVIEW_TOKENS', '%s tokens', estimateTokens(text)),
      TF('COM_AIFRIENDLY_OUTLINE_COUNT', '%s headings', outline.length)
    ].join(' · ');

    outlineList.innerHTML = outline.map(function (entry) {
      return '<li style="padding-left:' + ((entry.level - 1) * 14) + 'px">'
        + '<button type="button" data-outline-line="' + entry.line + '">' + esc(entry.text) + '</button>'
        + '</li>';
    }).join('') || '<li class="aif-empty">' + esc(T('COM_AIFRIENDLY_OUTLINE_EMPTY', 'No headings yet.')) + '</li>';

    linkBox.hidden = issues.length === 0;
    linkList.innerHTML = issues.map(function (issue) {
      return '<li><b>' + esc(TF('COM_AIFRIENDLY_LINE_N', 'Line %s', issue.line)) + '</b> '
        + esc(T('COM_AIFRIENDLY_LINKCHECK_' + issue.reason.toUpperCase(), issue.reason))
        + ' <code>' + esc(issue.text) + '</code></li>';
    }).join('');
  }

  // Jump the caret to a heading, which is the point of an outline.
  hub.addEventListener('click', function (event) {
    var jump = event.target.closest('[data-outline-line]');
    if (!jump) return;

    var lines = editor.value.split(/\r?\n/);
    var offset = lines.slice(0, parseInt(jump.dataset.outlineLine, 10) - 1).join('\n').length;

    editor.focus();
    editor.setSelectionRange(offset, offset);
    // Rough but effective: scroll proportionally to the caret position.
    editor.scrollTop = editor.scrollHeight * (offset / Math.max(1, editor.value.length));
  });

  var toolsTimer;
  editor.addEventListener('input', function () {
    window.clearTimeout(toolsTimer);
    toolsTimer = window.setTimeout(refreshEditorTools, 250);
  });
  refreshEditorTools();

  /* --------------------------------------------------------- copy button */

  hub.addEventListener('click', function (event) {
    var trigger = event.target.closest('[data-copy-target]');
    if (!trigger) return;

    var source = hub.querySelector(trigger.dataset.copyTarget);
    if (!source) return;

    var text = source.textContent.trim();
    var done = function () { notice(T('COM_AIFRIENDLY_COPIED', 'Copied to the clipboard.')); };

    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done, function () { selectText(source); });
    } else {
      selectText(source);
    }
  });

  // Fallback when the clipboard API is unavailable or blocked: select the text
  // so the usual copy shortcut works.
  function selectText(node) {
    var range = document.createRange();
    range.selectNodeContents(node);
    var selection = window.getSelection();
    selection.removeAllRanges();
    selection.addRange(range);
  }

  /* -------------------------------------------------------------- wizard */

  hub.querySelectorAll('[data-wizard-preset]').forEach(function (button) {
    button.addEventListener('click', function () {
      var all = button.dataset.wizardPreset === 'all';
      var articles = hub.querySelector('[name="options[include_articles]"]');
      var categories = hub.querySelector('[name="options[include_categories]"]');

      if (articles) articles.checked = true;
      if (categories) categories.checked = all;
      setDirty(true);

      notice(all
        ? T('COM_AIFRIENDLY_WIZARD_ALL_DONE', 'All available providers enabled.')
        : T('COM_AIFRIENDLY_WIZARD_BASE_DONE', 'Joomla articles enabled.'));
    });
  });

  function completeOnboarding() {
    if (hub.querySelector('[name="options[onboarding_done]"]')) return;
    var flag = document.createElement('input');
    flag.type = 'hidden';
    flag.name = 'options[onboarding_done]';
    flag.value = '1';
    hub.appendChild(flag);
  }

  var wizardGenerate = hub.querySelector('[data-wizard-generate]');
  if (wizardGenerate) {
    wizardGenerate.addEventListener('click', function () {
      completeOnboarding();
      run(wizardGenerate, request('preview', { content: '' }).then(function (data) {
        hub.querySelector('[data-llms-editor]').value = data.content || '';
        selectSection('content');
        return save().then(function () { return preview(); });
      }));
    });
  }

  var wizardSkip = hub.querySelector('[data-wizard-skip]');
  if (wizardSkip) {
    wizardSkip.addEventListener('click', function () {
      completeOnboarding();
      run(wizardSkip, save().then(function () {
        var wizard = hub.querySelector('[data-wizard]');
        if (wizard) wizard.hidden = true;
      }));
    });
  }

  /* ---------------------------------------------------------------- boot */

  var requested = (window.location.hash || '').replace('#aif-', '');
  var known = tabs.some(function (tab) { return tab.dataset.sectionTarget === requested; });
  selectSection(known ? requested : 'overview');
})();
