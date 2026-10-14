<style>
/* ==============================================================================
   Flat SmartOLT Layout Theme Styling - Adaptive Dark/Light Mode
   ============================================================================== */
.smartolt-layout {
    font-family: var(--font-family, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif);
    color: var(--text-main) !important;
    background: transparent !important;
    padding: 28px 0;
    margin-top: 15px;
}

.smartolt-top-section {
    display: flex;
    gap: 40px;
    margin-bottom: 25px;
    border-bottom: 1px solid var(--border-color, #e2e8f0);
    padding-bottom: 25px;
}

@media (max-width: 992px) {
    .smartolt-top-section {
        flex-direction: column;
        gap: 20px;
    }
}

.smartolt-left-col {
    flex: 1.1;
}

.smartolt-right-col {
    flex: 1.1;
    display: flex;
    flex-direction: column;
    align-items: stretch;
}

.onu-device-image-wrapper {
    margin-bottom: 20px;
    text-align: left;
    width: 100%;
}

.onu-device-image {
    max-height: 110px;
    object-fit: contain;
    display: inline-block;
}

.smartolt-details-table {
    width: 100%;
    border-collapse: collapse;
}

.smartolt-details-table tr {
    border-bottom: 1px solid var(--border-color);
}

.smartolt-details-table tr:last-child {
    border-bottom: none;
}

.smartolt-details-table td {
    padding: 10px 0;
    vertical-align: middle;
    font-size: 0.9rem;
    border: none !important;
}

.smartolt-details-table td.label-cell {
    width: 210px;
    color: var(--text-muted, #475569);
    font-weight: 500;
}

.smartolt-details-table td.val-cell {
    color: var(--text-main, #1e293b);
    font-weight: 600;
}

.smartolt-details-table td.val-cell a {
    color: var(--text-accent, #0284c7);
    text-decoration: none;
    font-weight: 600;
    transition: var(--transition-fast);
}

.smartolt-details-table td.val-cell a:hover {
    color: var(--text-main);
    text-decoration: underline;
}

.smartolt-details-table td.val-cell strong {
    color: var(--text-main, #1e293b);
}

.smartolt-details-table td.val-cell code {
    background: var(--bg-tertiary, #f1f5f9);
    color: var(--text-main, #0f172a);
    border: 1px solid var(--border-color);
    padding: 3px 8px;
    border-radius: var(--radius-sm, 4px);
    font-size: 0.85em;
    font-weight: 500;
    font-family: monospace;
}

/* Rows for status, charts, tables */
.smartolt-row {
    display: flex;
    margin: 0 auto 24px;
    max-width: 72rem;
    border-bottom: 1px solid var(--border-color, #f1f5f9);
    padding-bottom: 24px;
    align-items: flex-start;
}

@media (max-width: 768px) {
    .smartolt-row {
        flex-direction: column;
        align-items: stretch;
        gap: 10px;
    }
    .modal-body-grid {
        grid-template-columns: 1fr !important;
    }
}

.smartolt-row:last-child {
    border-bottom: none;
    padding-bottom: 0;
}

.smartolt-label {
    width: 160px;
    font-weight: 600;
    color: var(--text-muted, #475569);
    font-size: 0.95rem;
    flex-shrink: 0;
    padding-top: 6px;
}

.smartolt-content {
    flex-grow: 1;
}

/* Charts grid */
.flex-row-gap {
    display: flex;
    gap: 20px;
    width: 100%;
}

@media (max-width: 992px) {
    .flex-row-gap {
        flex-direction: column;
    }
}

.chart-wrapper {
    flex: 1;
    background: var(--bg-secondary, #ffffff);
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: var(--radius-md, 8px);
    padding: 20px;
    box-shadow: var(--shadow-sm);
}

.chart-title {
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--text-main, #475569);
    margin-bottom: 16px;
    text-align: center;
}

.chart-legend-stats {
    margin-top: 15px;
    font-size: 0.8rem;
    color: var(--text-muted, #64748b);
    display: grid;
    grid-template-columns: auto 1fr auto;
    align-items: center;
    row-gap: 6px;
    column-gap: 10px;
}
.chart-legend-stats .legend-row {
    display: contents;
}
.chart-legend-stats .legend-label {
    display: flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
}
.chart-legend-stats .legend-current,
.chart-legend-stats .legend-max {
    white-space: nowrap;
}
.chart-legend-stats .legend-current {
    justify-self: start;
}
.chart-legend-stats .legend-max {
    justify-self: end;
}

.legend-dot {
    width: 10px;
    height: 10px;
    border-radius: 2px;
    display: inline-block;
    flex-shrink: 0;
}
.download-dot { background: #3081d1; }
.upload-dot { background: #f59e0b; }
.signal-dot { background: #f97316; }

.action-link {
    color: var(--text-accent, #0284c7);
    text-decoration: none;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 0.85rem;
    transition: var(--transition-fast);
}
.action-link:hover {
    color: var(--text-main);
    text-decoration: underline;
}

/* Edit icons */
.edit-icon-btn {
    background: none;
    border: none;
    padding: 0;
    cursor: pointer;
    color: var(--text-accent, #0284c7);
    margin-left: 6px;
    vertical-align: middle;
    display: inline-flex;
    align-items: center;
    transition: var(--transition-fast);
}
.edit-icon-btn:hover {
    color: var(--text-main);
    transform: scale(1.1);
}

.status-live-indicator {
    background: var(--color-success, #28a745);
    color: #ffffff;
    font-size: 0.75rem;
    font-weight: bold;
    padding: 2px 6px;
    border-radius: 3px;
    text-transform: uppercase;
    display: inline-block;
    margin-left: 8px;
    animation: pulse-glow 2s infinite;
}

/* Status tab modal styles */
.status-tab-btn {
    width: 100%;
    padding: 12px 18px;
    background: none;
    border: none;
    border-left: 3px solid transparent;
    text-align: left;
    font-size: 0.88rem;
    font-weight: 500;
    color: var(--text-muted, #475569);
    cursor: pointer;
    transition: var(--transition-fast);
}
.status-tab-btn:hover {
    background: var(--bg-tertiary, #f1f5f9);
    color: var(--text-main, #0f172a);
}
.status-tab-btn.active {
    background: var(--bg-tertiary, #e2e8f0);
    color: var(--text-accent, #0284c7);
    border-left-color: var(--text-accent, #0284c7);
    font-weight: 600;
}

.status-pre {
    background: var(--bg-primary, #0f172a);
    color: var(--text-accent, #38bdf8);
    border: 1px solid var(--border-color);
    padding: 18px;
    border-radius: var(--radius-md, 8px);
    font-family: monospace;
    font-size: 0.85rem;
    line-height: 1.6;
    overflow-x: auto;
    white-space: pre-wrap;
    margin: 0;
}


/* Page-specific helpers (onu-detail) */
.detail-plain-value--ellipsis { flex-wrap: nowrap; overflow: hidden; min-width: 0; white-space: nowrap; text-overflow: ellipsis; }
.detail-autorefresh { font-size: 0.75rem; color: var(--text-muted); margin-left: 6px; }
.status-cli-output { display: none; margin-top: 10px; padding: 16px; font-size: 0.85rem; line-height: 1.6; color: var(--text-main); width: 100%; }
.chart-legend-note { margin-top: 4px; color: var(--text-muted); font-size: 0.8rem; }
.rx-onu-dot { background: #22d3ee; }
.rx-olt-dot { background: #a855f7; }
.port-table { width: 100%; margin-top: 6px; }
.w-45 { width: 45%; }
.w-10 { width: 10%; }
.smartolt-row--tight { margin-bottom: 12px; padding-bottom: 12px; border-bottom: none; }
.smartolt-row--actions { border-top: 1px solid var(--border-color); padding-top: 24px; }


/* Modal key-value tables */
.kv-table { width: 100%; border-collapse: collapse; }
.kv-table td { padding: 8px 0; vertical-align: middle; }
.kv-key { font-weight: 600; color: var(--text-main); vertical-align: middle; width: 140px; }
.kv-key--wide { width: 160px; }
.kv-key--top { vertical-align: top; padding-top: 12px; }
.kv-val { vertical-align: middle; }
.kv-val--inline { display: flex; gap: 15px; align-items: center; }
.kv-val--note { padding: 0 0 15px; }

/* Modal form helpers */
.form-control--md { max-width: 320px; }
.form-control--sm { max-width: 280px; }
.form-control--mono { font-family: monospace; }
.form-control--mono[readonly] { background: var(--bg-tertiary); cursor: not-allowed; }
.form-control--upper { text-transform: uppercase; }
.form-hint { display: block; line-height: 1.4; font-size: 0.85rem; }
.form-hint-block { display: block; margin-top: 12px; line-height: 1.4; }
.field-hint { font-size: 0.85rem; color: var(--text-muted); font-weight: 500; margin-bottom: 8px; }
.radio-inline { display: inline-flex; align-items: center; gap: 5px; margin-right: 14px; cursor: pointer; font-size: 0.9rem; }
.flex-1 { flex: 1; }
.text-warning { color: var(--color-amber); }
.is-disabled { opacity: 0.5; cursor: not-allowed; }
.info-box { margin-top: 16px; padding: 14px; background: var(--bg-secondary); border: 1px solid var(--border-color); border-radius: var(--radius-sm); font-size: 0.85rem; line-height: 1.8; }
.alert-warning-box { background-color: var(--tint-warning); border: 1px solid var(--color-amber); border-radius: var(--radius-sm); padding: 12px 16px; margin-bottom: 18px; color: var(--color-amber); line-height: 1.5; font-size: 0.85rem; }

/* Update ONU Mode modal: stack table rows on narrow screens */
@media (max-width: 500px) {
    #update-onu-mode-modal .modal-body table,
    #update-onu-mode-modal .modal-body tbody,
    #update-onu-mode-modal .modal-body tr,
    #update-onu-mode-modal .modal-body td {
        display: block;
        width: 100% !important;
    }
    #update-onu-mode-modal .modal-body tr {
        margin-bottom: 10px;
    }
    #update-onu-mode-modal .modal-body td {
        padding: 4px 0 !important;
        height: auto !important;
    }
}
</style>
