<?php

namespace NPlusOneScanner\Reporting;

use NPlusOneScanner\Data\Issue;
use NPlusOneScanner\Data\ScanResult;

final class HtmlReporter
{
    public function render(ScanResult $result): string
    {
        $total = count($result->issues);
        $high = $result->countBySeverity(Issue::SEVERITY_HIGH);
        $medium = $result->countBySeverity(Issue::SEVERITY_MEDIUM);
        $duration = sprintf('%.2fs', $result->durationSeconds);
        $generatedAt = date('Y-m-d H:i:s');

        $rowsHtml = '';
        foreach ($result->issues as $issue) {
            $severityClass = $issue->severity === Issue::SEVERITY_HIGH ? 'badge-high' : 'badge-medium';
            $severityLabel = strtoupper($issue->severity);
            $location = $this->e("{$issue->file}:{$issue->line}");
            $type = $this->e($issue->type);
            $modelRel = $this->e(trim(($issue->model ?? '').($issue->relation ? '::'.$issue->relation : ''), ':'));
            $message = $this->e($issue->message);
            $suggestion = $this->e($issue->suggestion);
            $snippet = $issue->snippet !== ''
                ? '<pre class="snippet"><code>'.$this->e($issue->snippet).'</code></pre>'
                : '';

            $rowsHtml .= <<<HTML
            <tr data-severity="{$this->e($issue->severity)}" data-type="{$type}">
                <td class="col-loc"><code>{$location}</code></td>
                <td><span class="badge {$severityClass}">{$severityLabel}</span></td>
                <td><span class="badge badge-type">{$type}</span></td>
                <td><code>{$modelRel}</code></td>
                <td>
                    <div class="msg">{$message}</div>
                    {$snippet}
                    <div class="fix"><strong>Fix:</strong> {$suggestion}</div>
                </td>
            </tr>
            HTML;
        }

        $emptyState = $total === 0
            ? '<div class="empty-state">No N+1 query issues found!</div>'
            : '';

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Laravel N+1 Scanner Report</title>
            <style>
                :root {
                    --bg: #0f172a;
                    --card: #1e293b;
                    --border: #334155;
                    --text: #e2e8f0;
                    --muted: #94a3b8;
                    --high: #ef4444;
                    --medium: #f59e0b;
                    --ok: #10b981;
                    --accent: #38bdf8;
                }
                * { box-sizing: border-box; }
                body {
                    margin: 0;
                    padding: 24px;
                    font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
                    background: var(--bg);
                    color: var(--text);
                    line-height: 1.5;
                }
                .container { max-width: 1280px; margin: 0 auto; }
                header { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 20px; flex-wrap: wrap; gap: 12px; }
                h1 { margin: 0; font-size: 1.5rem; }
                .meta { color: var(--muted); font-size: 0.875rem; }
                .cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin-bottom: 20px; }
                .card { background: var(--card); border: 1px solid var(--border); border-radius: 10px; padding: 14px 16px; }
                .card-label { color: var(--muted); font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; }
                .card-value { font-size: 1.5rem; font-weight: 700; margin-top: 4px; }
                .val-high { color: var(--high); }
                .val-medium { color: var(--medium); }
                .val-ok { color: var(--ok); }
                .filters { display: flex; gap: 10px; margin-bottom: 16px; flex-wrap: wrap; }
                input, select {
                    background: var(--card);
                    color: var(--text);
                    border: 1px solid var(--border);
                    border-radius: 8px;
                    padding: 8px 12px;
                    font-size: 0.875rem;
                }
                input { flex: 1; min-width: 240px; }
                table { width: 100%; table-layout: fixed; border-collapse: collapse; background: var(--card); border: 1px solid var(--border); border-radius: 10px; overflow: hidden; }
                th, td { padding: 12px 14px; text-align: left; border-bottom: 1px solid var(--border); vertical-align: top; font-size: 0.875rem; overflow-wrap: anywhere; word-break: break-word; }
                th { background: #162032; color: var(--muted); font-weight: 600; font-size: 0.75rem; text-transform: uppercase; }
                th:nth-child(1) { width: 24%; }
                th:nth-child(2) { width: 95px; }
                th:nth-child(3) { width: 175px; }
                th:nth-child(4) { width: 15%; }
                tr:last-child td { border-bottom: none; }
                code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.82rem; color: var(--accent); overflow-wrap: anywhere; word-break: break-word; }
                .col-loc { overflow-wrap: anywhere; word-break: break-word; }
                .badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 0.72rem; font-weight: 700; white-space: nowrap; }
                .badge-high { background: rgba(239, 68, 68, 0.18); color: #fca5a5; border: 1px solid rgba(239, 68, 68, 0.4); }
                .badge-medium { background: rgba(245, 158, 11, 0.18); color: #fcd34d; border: 1px solid rgba(245, 158, 11, 0.4); }
                .badge-type { background: rgba(56, 189, 248, 0.12); color: #bae6fd; border: 1px solid rgba(56, 189, 248, 0.3); }
                .snippet { background: #0b1120; border: 1px solid var(--border); border-radius: 6px; padding: 8px 10px; margin: 8px 0; white-space: pre-wrap; overflow-wrap: anywhere; word-break: break-word; }
                .snippet code { color: #cbd5e1; white-space: pre-wrap; }
                .msg, .fix { overflow-wrap: anywhere; word-break: break-word; }
                .fix { color: #86efac; font-size: 0.82rem; margin-top: 4px; }
                .empty-state { background: var(--card); border: 1px solid var(--border); border-radius: 10px; padding: 32px; text-align: center; color: var(--ok); font-weight: 600; }
            </style>
        </head>
        <body>
            <div class="container">
                <header>
                    <h1>Laravel N+1 Static Scanner Report</h1>
                    <div class="meta">Generated: {$generatedAt} | Duration: {$duration}</div>
                </header>

                <section class="cards">
                    <div class="card"><div class="card-label">Files Scanned</div><div class="card-value">{$result->filesScanned}</div></div>
                    <div class="card"><div class="card-label">Models Found</div><div class="card-value">{$result->modelsDiscovered}</div></div>
                    <div class="card"><div class="card-label">Total Issues</div><div class="card-value">{$total}</div></div>
                    <div class="card"><div class="card-label">High Severity</div><div class="card-value val-high">{$high}</div></div>
                    <div class="card"><div class="card-label">Medium Severity</div><div class="card-value val-medium">{$medium}</div></div>
                    <div class="card"><div class="card-label">Suppressed</div><div class="card-value val-ok">{$result->ignoredCount} / {$result->baselinedCount}</div></div>
                </section>

                <div class="filters">
                    <input type="search" id="searchInput" placeholder="Filter by file, model, relation, or message...">
                    <select id="severityFilter">
                        <option value="all">All Severities</option>
                        <option value="high">High Only</option>
                        <option value="medium">Medium Only</option>
                    </select>
                    <select id="typeFilter">
                        <option value="all">All Patterns</option>
                        <option value="lazy_loading_in_loop">lazy_loading_in_loop</option>
                        <option value="query_in_loop">query_in_loop</option>
                        <option value="missing_when_loaded">missing_when_loaded</option>
                        <option value="blade_lazy_loading">blade_lazy_loading</option>
                    </select>
                </div>

                {$emptyState}

                <table id="issuesTable" style="display: {$this->tableDisplay($total)};">
                    <thead>
                        <tr>
                            <th>Location</th>
                            <th>Severity</th>
                            <th>Pattern</th>
                            <th>Model / Relation</th>
                            <th>Details &amp; Fix</th>
                        </tr>
                    </thead>
                    <tbody>
                        {$rowsHtml}
                    </tbody>
                </table>
            </div>

            <script>
                (function () {
                    const searchInput = document.getElementById('searchInput');
                    const severityFilter = document.getElementById('severityFilter');
                    const typeFilter = document.getElementById('typeFilter');
                    const rows = document.querySelectorAll('#issuesTable tbody tr');

                    function applyFilters() {
                        const q = searchInput.value.toLowerCase();
                        const sev = severityFilter.value;
                        const type = typeFilter.value;

                        rows.forEach((row) => {
                            const matchesSev = sev === 'all' || row.dataset.severity === sev;
                            const matchesType = type === 'all' || row.dataset.type === type;
                            const matchesText = q === '' || row.textContent.toLowerCase().includes(q);
                            row.style.display = (matchesSev && matchesType && matchesText) ? '' : 'none';
                        });
                    }

                    searchInput.addEventListener('input', applyFilters);
                    severityFilter.addEventListener('change', applyFilters);
                    typeFilter.addEventListener('change', applyFilters);
                })();
            </script>
        </body>
        </html>
        HTML;
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function tableDisplay(int $total): string
    {
        return $total > 0 ? 'table' : 'none';
    }
}
