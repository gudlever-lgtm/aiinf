<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AIINF Control Center</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: #0f0f0f;
            color: #eaeaea;
            display: flex;
            min-height: 100vh;
        }

        /* ── Sidebar ──────────────────────────────────────────────────────── */

        .sidebar {
            width: 220px;
            background: #141414;
            border-right: 1px solid #1e1e1e;
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            z-index: 10;
        }

        .sidebar-header {
            padding: 22px 20px 18px;
            border-bottom: 1px solid #1e1e1e;
        }

        .sidebar-header h1 {
            font-size: 15px;
            font-weight: 700;
            color: #fff;
            letter-spacing: 0.5px;
        }

        .sidebar-header p {
            font-size: 11px;
            color: #555;
            margin-top: 4px;
        }

        .nav { flex: 1; padding: 8px 0; }

        .nav-link {
            display: block;
            padding: 9px 20px;
            color: #666;
            text-decoration: none;
            font-size: 13px;
            border-left: 3px solid transparent;
            transition: color 0.12s, background 0.12s;
            cursor: pointer;
        }

        .nav-link:hover  { background: #1a1a1a; color: #ccc; }

        .nav-link.active {
            background: #1a1a1a;
            color: #4da3ff;
            border-left-color: #4da3ff;
        }

        .nav-sep {
            height: 1px;
            background: #1e1e1e;
            margin: 6px 0;
        }

        /* ── Main content ─────────────────────────────────────────────────── */

        .main {
            margin-left: 220px;
            flex: 1;
            padding: 32px 36px;
            min-height: 100vh;
        }

        #app { max-width: 980px; }

        /* ── Loading / error states ───────────────────────────────────────── */

        .loading {
            color: #444;
            padding: 40px 0;
            font-size: 14px;
        }

        .loading::after {
            content: '';
            animation: ellipsis 1.4s steps(3, end) infinite;
        }

        @keyframes ellipsis {
            0%, 100% { content: ''; }
            33%       { content: '.'; }
            66%       { content: '..'; }
        }

        .error {
            color: #f87171;
            padding: 16px 20px;
            background: #1c0a0a;
            border-radius: 8px;
            border: 1px solid #3a1010;
            font-size: 14px;
        }

        /* ── Typography ───────────────────────────────────────────────────── */

        h2 { margin-bottom: 20px; font-size: 20px; font-weight: 600; }
        h3 { margin-bottom: 12px; font-size: 15px; color: #999; font-weight: 500; }
        hr { border: 0; border-top: 1px solid #1e1e1e; margin: 24px 0; }
        p  { line-height: 1.5; }

        /* ── Stats grid (dashboard) ───────────────────────────────────────── */

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 14px;
            margin-bottom: 28px;
        }

        .stat-card {
            background: #1c1c1c;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            border: 1px solid #252525;
            display: block;
            text-decoration: none;
            color: inherit;
            transition: background 0.15s, border-color 0.15s;
        }

        a.stat-card:hover { background: #222; border-color: #3a3a3a; }

        .stat-card h2 { font-size: 30px; color: #fff; margin-bottom: 6px; }
        .stat-card p  { font-size: 11px; color: #555; }

        /* ── Draft cards ──────────────────────────────────────────────────── */

        .card {
            background: #1c1c1c;
            padding: 16px;
            margin-bottom: 14px;
            border-radius: 8px;
            border-left: 4px solid #f97316;
            transition: box-shadow 0.25s, opacity 0.3s;
        }

        .card.approved { border-left-color: #22c55e; }
        .card.rejected { border-left-color: #ef4444; }
        .card.draft    { border-left-color: #f97316; }

        .meta {
            font-size: 11px;
            color: #555;
            margin-bottom: 10px;
        }

        .meta strong { color: #888; }

        textarea {
            width: 100%;
            height: 140px;
            background: #1a1a1a;
            color: #ddd;
            border: 1px solid #2a2a2a;
            padding: 10px;
            border-radius: 6px;
            font-family: inherit;
            font-size: 13px;
            resize: vertical;
        }

        textarea:focus { outline: none; border-color: #333; }

        /* ── Buttons ──────────────────────────────────────────────────────── */

        button {
            padding: 6px 13px;
            margin-right: 6px;
            cursor: pointer;
            border: 0;
            border-radius: 5px;
            background: #252525;
            color: #bbb;
            font-size: 13px;
            transition: background 0.15s;
        }

        button:hover { background: #2e2e2e; }

        .btn-approve { background: #14532d; color: #86efac; }
        .btn-approve:hover { background: #166534; }

        .btn-reject  { background: #450a0a; color: #fca5a5; }
        .btn-reject:hover  { background: #5c1212; }

        .btn-save    { background: #1e3a5f; color: #93c5fd; }
        .btn-save:hover    { background: #1d4ed8; }

        .btn-publish { background: #14532d; color: #86efac; }
        .btn-publish:hover { background: #166534; }

        .run-btn {
            padding: 10px 22px;
            background: #1e3a5f;
            color: #93c5fd;
            border: 0;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            transition: background 0.15s;
        }

        .run-btn:hover    { background: #1d4ed8; }
        .run-btn:disabled { opacity: 0.5; cursor: not-allowed; }

        /* ── Filter chips (drafts) ────────────────────────────────────────── */

        .filters {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .filter-btn {
            padding: 4px 14px;
            background: #1a1a1a;
            border: 1px solid #2a2a2a;
            border-radius: 20px;
            color: #666;
            cursor: pointer;
            font-size: 12px;
            text-decoration: none;
            transition: all 0.12s;
        }

        .filter-btn:hover  { border-color: #0af; color: #0af; }
        .filter-btn.active { background: #0af; border-color: #0af; color: #000; }

        /* ── Publish queue ────────────────────────────────────────────────── */

        .queue-card {
            background: #1c1c1c;
            border: 1px solid #252525;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 14px;
            transition: opacity 0.3s;
        }

        .queue-card p {
            color: #aaa;
            font-size: 13px;
            line-height: 1.55;
            margin: 10px 0;
        }

        .target-checkboxes {
            display: flex;
            gap: 18px;
            margin: 12px 0;
        }

        .target-checkboxes label {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            color: #888;
            cursor: pointer;
        }

        /* ── Settings form ────────────────────────────────────────────────── */

        .settings-form input {
            display: block;
            width: 100%;
            max-width: 420px;
            margin-bottom: 10px;
            padding: 8px 12px;
            background: #1a1a1a;
            border: 1px solid #2a2a2a;
            border-radius: 6px;
            color: #eee;
            font-size: 13px;
        }

        .settings-form input:focus { outline: none; border-color: #4da3ff; }

        pre {
            background: #1a1a1a;
            padding: 16px;
            border-radius: 8px;
            font-size: 12px;
            overflow-x: auto;
            color: #888;
            border: 1px solid #222;
        }

        /* ── Import / Generate output ─────────────────────────────────────── */

        .output-box {
            background: #111;
            border: 1px solid #1e1e1e;
            border-radius: 8px;
            padding: 18px;
            margin-top: 18px;
        }

        .output-box pre {
            white-space: pre-wrap;
            word-break: break-word;
            color: #aaa;
            border: 0;
            padding: 0;
            background: none;
        }

        /* ── Toast ────────────────────────────────────────────────────────── */

        .toast {
            position: fixed;
            bottom: 28px;
            right: 28px;
            padding: 11px 18px;
            border-radius: 8px;
            font-size: 13px;
            z-index: 9999;
            opacity: 0;
            transform: translateY(8px);
            transition: opacity 0.2s ease, transform 0.2s ease;
            pointer-events: none;
        }

        .toast.show    { opacity: 1; transform: translateY(0); }
        .toast.success { background: #14532d; color: #86efac; }
        .toast.error   { background: #450a0a; color: #fca5a5; }
        .toast.info    { background: #1e3a5f; color: #93c5fd; }
    </style>
</head>
<body>

<aside class="sidebar">
    <div class="sidebar-header">
        <h1>AIINF</h1>
        <p>Fellis Content Pipeline</p>
    </div>

    <nav class="nav">
        <a class="nav-link" href="#/dashboard" data-route="dashboard">Dashboard</a>
        <a class="nav-link" href="#/drafts"    data-route="drafts">Drafts</a>
        <a class="nav-link" href="#/publish"   data-route="publish">Publish Queue</a>

        <div class="nav-sep"></div>

        <a class="nav-link" href="#/settings" data-route="settings">API Settings</a>
        <a class="nav-link" href="#/import"   data-route="import">Import Commits</a>
        <a class="nav-link" href="#/generate" data-route="generate">Generate AI</a>
    </nav>
</aside>

<main class="main">
    <div id="app">
        <div class="loading">Loading</div>
    </div>
</main>

<div class="toast" id="toast"></div>

<script src="/app.js"></script>
</body>
</html>
