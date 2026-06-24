const ROUTES = {
    dashboard: '/ajax/dashboard.php',
    drafts:    '/ajax/drafts.php',
    publish:   '/ajax/publish_queue.php',
    settings:  '/ajax/settings.php',
    import:    '/ajax/import.php',
    generate:  '/ajax/generate.php',
    migrate:   '/ajax/migrate.php',
};

// ── Toast ─────────────────────────────────────────────────────────────────────

function toast(message, type) {
    const el = document.getElementById('toast');
    el.textContent = message;
    el.className = 'toast ' + (type || 'info') + ' show';
    clearTimeout(el._timer);
    el._timer = setTimeout(function() { el.classList.remove('show'); }, 2200);
}

// ── Router ────────────────────────────────────────────────────────────────────

function loadPage(route, params) {
    var url = ROUTES[route];
    if (!url) return;

    document.querySelectorAll('.nav-link').forEach(function(a) {
        a.classList.toggle('active', a.dataset.route === route);
    });

    document.getElementById('app').innerHTML = '<div class="loading">Loading</div>';

    fetch(url + (params ? '?' + params : ''))
        .then(function(res) {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.text();
        })
        .then(function(html) {
            document.getElementById('app').innerHTML = html;
        })
        .catch(function(err) {
            document.getElementById('app').innerHTML =
                '<div class="error">Failed to load page: ' + err.message + '</div>';
        });
}

function handleRoute() {
    var raw   = window.location.hash.slice(2) || 'dashboard';
    var parts = raw.split('?');
    var route = parts[0];
    var params = parts.slice(1).join('?');
    loadPage(route, params);
}

window.addEventListener('hashchange', handleRoute);
window.addEventListener('load', handleRoute);

// ── Draft actions ─────────────────────────────────────────────────────────────

window.draftAction = function(id, action) {
    fetch('/api/draft_action.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'id=' + id + '&action=' + action,
    })
    .then(function(r) {
        return r.json().then(function(data) {
            if (!r.ok) throw new Error(data.error || 'Server error ' + r.status);
            return data;
        });
    })
    .then(function(res) {
        var card = document.getElementById('card-' + id);
        if (!card) return;

        if (res.status === 'approved') {
            card.classList.remove('draft', 'rejected');
            card.classList.add('approved');
            card.style.boxShadow = '0 0 14px rgba(34,197,94,0.45)';
            card.style.transition = 'opacity 0.4s';
            setTimeout(function() { card.style.opacity = '0'; }, 600);
            setTimeout(function() { card.style.display = 'none'; }, 1000);
            toast('Draft approved — added to publish queue', 'success');
        }

        if (res.status === 'rejected') {
            card.classList.remove('draft', 'approved');
            card.classList.add('rejected');
            card.style.transition = 'opacity 0.3s';
            card.style.opacity = '0';
            setTimeout(function() { card.style.display = 'none'; }, 320);
            toast('Draft rejected', 'error');
        }
    })
    .catch(function(err) { toast('Error: ' + err.message, 'error'); });
};

window.draftSave = function(id) {
    var ta = document.getElementById('content-' + id);
    if (!ta) return;

    fetch('/api/draft_action.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'id=' + id + '&action=save&content=' + encodeURIComponent(ta.value),
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (res.status === 'saved') {
            var card = document.getElementById('card-' + id);
            if (card) {
                card.style.boxShadow = '0 0 10px #4da3ff';
                setTimeout(function() { card.style.boxShadow = ''; }, 800);
            }
            toast('Saved', 'info');
        }
    })
    .catch(function(err) { toast('Error: ' + err.message, 'error'); });
};

// ── Publish queue actions ─────────────────────────────────────────────────────

window.publishApprove = function(id) {
    var targets = Array.from(document.querySelectorAll('.tgt-' + id))
        .filter(function(b) { return b.checked; })
        .map(function(b) { return b.value; });

    fetch('/api/publish_action.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'id=' + id + '&action=publish&targets=' + encodeURIComponent(JSON.stringify(targets)),
    })
    .then(function(r) {
        return r.json().then(function(data) {
            if (!r.ok) throw new Error(data.error || 'Server error ' + r.status);
            return data;
        });
    })
    .then(function(res) {
        if (res.ok) {
            var card = document.getElementById('q-' + id);
            if (card) {
                card.style.opacity = '0.3';
                card.style.pointerEvents = 'none';
            }
            toast('Queued for publish', 'success');
        }
    })
    .catch(function(err) { toast('Error: ' + err.message, 'error'); });
};

window.publishReject = function(id) {
    fetch('/api/publish_action.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'id=' + id + '&action=reject',
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (res.ok) {
            var card = document.getElementById('q-' + id);
            if (card) {
                card.style.transition = 'opacity 0.3s';
                card.style.opacity = '0';
                setTimeout(function() { card.style.display = 'none'; }, 320);
            }
            toast('Rejected', 'error');
        }
    })
    .catch(function(err) { toast('Error: ' + err.message, 'error'); });
};

// ── Settings save ─────────────────────────────────────────────────────────────

window.saveSettings = function(form) {
    var data = new URLSearchParams(new FormData(form)).toString();

    fetch('/api/settings_save.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: data,
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (res.ok) {
            toast('Settings saved', 'success');
            loadPage('settings');
        }
    })
    .catch(function(err) { toast('Error: ' + err.message, 'error'); });
};

// ── Script runner (import / generate) ────────────────────────────────────────

window.runScript = function(route, btn) {
    btn.disabled = true;
    btn.textContent = 'Running...';

    var output = document.getElementById('script-output');
    if (output) output.innerHTML = '<div class="loading">Running</div>';

    fetch('/ajax/' + route + '.php?run=1')
        .then(function(r) { return r.text(); })
        .then(function(html) {
            if (output) output.innerHTML = html;
            btn.disabled = false;
            btn.textContent = 'Run Again';
            toast('Done', 'success');
        })
        .catch(function(err) {
            if (output) output.innerHTML = '<div class="error">' + err.message + '</div>';
            btn.disabled = false;
            btn.textContent = 'Try Again';
            toast('Error: ' + err.message, 'error');
        });
};
