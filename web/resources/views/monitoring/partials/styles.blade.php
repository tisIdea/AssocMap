<style>
    .monitoring-grid { display: grid; grid-template-columns: repeat(4, minmax(220px, 1fr)); gap: 16px; }
    .monitoring-scroll { overflow-x: auto; padding-bottom: 12px; }
    .monitoring-group { margin: 24px 0; }
    .monitoring-card { border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 18px; background: var(--card-bg); min-width: 0; margin: 12px 0; }
    .monitoring-card h2, .monitoring-card h3, .monitoring-card p { overflow-wrap: anywhere; }
    .monitoring-card dl { margin: 12px 0; }
    .monitoring-card dd { white-space: pre-wrap; overflow-wrap: anywhere; }
    .monitoring-quarter-card { position: relative; }
    .monitoring-quarter-link { color: inherit; }
    .monitoring-quarter-link::after { content: ''; position: absolute; inset: 0; border-radius: var(--radius-md); z-index: 1; }
    .monitoring-quarter-card:hover { border-color: #bacada; }
    .monitoring-quarter-link:focus-visible::after { outline: inherit; outline-offset: inherit; }
    .monitoring-reports-link { position: relative; z-index: 2; }
    .monitoring-overview-heading { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; }
    .monitoring-actions, .monitoring-nav { display: flex; gap: 10px; flex-wrap: wrap; margin: 16px 0; }
    .monitoring-empty { border: 1px dashed var(--border-color); padding: 18px; margin: 12px 0; }
    .monitoring-detail-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 220px), 1fr)); gap: 16px; }
</style>
