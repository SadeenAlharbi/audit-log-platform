{{-- Platforms Code details that Filament's tokens do not cover (see PlatformsCode). --}}
<style>
    :root { --alh-text: #161616; }
    html:not(.dark) .fi-body { color: var(--alh-text); }
    .fi-body { font-feature-settings: "kern"; }
    /* Visible keyboard focus (accessibility). */
    .fi-body :focus-visible { outline: 2px solid var(--primary-600); outline-offset: 2px; }
    .dark .fi-body :focus-visible { outline-color: var(--primary-400); }
    .alh-footer {
        display: flex; flex-wrap: wrap; gap: .5rem 1.5rem; justify-content: space-between;
        padding: 1rem 1.5rem; margin-top: 2rem; font-size: .8125rem;
        color: var(--gray-500); border-top: 1px solid var(--gray-200);
    }
    .dark .alh-footer { color: var(--gray-400); border-top-color: var(--gray-800); }
    /* Integration guide (project page). */
    .alh-guide { display: grid; gap: 1rem; font-size: .875rem; min-width: 0; max-width: 100%; overflow: hidden; }
    .alh-guide > * { min-width: 0; }
    .alh-guide-facts { display: grid; gap: .75rem 1.5rem; grid-template-columns: repeat(auto-fit, minmax(20rem, 1fr)); }
    .alh-guide-facts dt { color: var(--gray-500); font-weight: 500; margin-bottom: .25rem; }
    .alh-guide-facts dd { display: flex; flex-wrap: wrap; gap: .375rem; justify-content: flex-end; }
    .alh-guide-facts code { font-size: .75rem; padding: .125rem .375rem; border-radius: .375rem; background: var(--gray-100); overflow-wrap: anywhere; }
    .dark .alh-guide-facts code { background: rgb(255 255 255 / .05); }
    .alh-guide-facts a { color: var(--primary-600); text-decoration: underline; }
    .alh-guide-tabs { display: flex; flex-wrap: wrap; gap: .5rem; margin-bottom: .5rem; }
    .alh-guide-tabs button { font-size: .75rem; font-weight: 500; padding: .25rem .75rem; border-radius: .5rem; background: var(--gray-100); color: var(--gray-700); }
    .alh-guide-tabs button[aria-selected="true"] { background: var(--primary-600); color: #fff; }
    .dark .alh-guide-tabs button { background: rgb(255 255 255 / .05); color: var(--gray-200); }
    .dark .alh-guide-tabs button[aria-selected="true"] { background: var(--primary-600); color: #fff; }
    .alh-guide-code { max-width: 100%; overflow-x: auto; margin: 0; padding: 1rem; border-radius: .5rem;
        background: var(--gray-950); color: var(--gray-100); font-size: .75rem; line-height: 1.6; }
    /* Key / value lists: Arabic keys in the body font; technical keys (settings, dir=ltr) stay monospace. */
    .fi-in-key-value th, .fi-in-key-value td { font-family: var(--font-family), ui-sans-serif, system-ui, sans-serif !important; }
    [dir="ltr"] .fi-in-key-value tbody th { font-family: ui-monospace, SFMono-Regular, Menlo, monospace !important; }
    /* Before / after table of an audit log. */
    .alh-diff { overflow-x: auto; border: 1px solid var(--gray-200); border-radius: .5rem; }
    .dark .alh-diff { border-color: rgb(255 255 255 / .1); }
    .alh-diff table { width: 100%; border-collapse: collapse; font-size: .875rem; }
    .alh-diff th { text-align: start; font-weight: 600; font-size: .8125rem; color: var(--gray-600); background: var(--gray-50); padding: .625rem .875rem; border-bottom: 1px solid var(--gray-200); }
    .dark .alh-diff th { color: var(--gray-300); background: rgb(255 255 255 / .05); border-bottom-color: rgb(255 255 255 / .1); }
    .alh-diff td { padding: .625rem .875rem; border-bottom: 1px solid var(--gray-100); vertical-align: top; overflow-wrap: anywhere; }
    .dark .alh-diff td { border-bottom-color: rgb(255 255 255 / .05); }
    .alh-diff tr:last-child td { border-bottom: 0; }
    .alh-diff code { font-size: .8125rem; padding: .125rem .375rem; border-radius: .375rem; background: var(--gray-100); }
    .dark .alh-diff code { background: rgb(255 255 255 / .05); }
    .alh-diff-changed .alh-diff-old, .alh-diff-removed .alh-diff-old { color: var(--danger-700); text-decoration: line-through; text-decoration-color: var(--danger-300); }
    .alh-diff-changed .alh-diff-new, .alh-diff-added .alh-diff-new { color: var(--success-700); font-weight: 600; }
    .dark .alh-diff-changed .alh-diff-old, .dark .alh-diff-removed .alh-diff-old { color: var(--danger-400); }
    .dark .alh-diff-changed .alh-diff-new, .dark .alh-diff-added .alh-diff-new { color: var(--success-400); }
    .alh-diff-badge { display: inline-block; font-size: .75rem; font-weight: 500; padding: .0625rem .5rem; border-radius: 999px; background: var(--gray-100); color: var(--gray-600); white-space: nowrap; }
    .alh-diff-changed .alh-diff-badge { background: var(--warning-50); color: var(--warning-700); }
    .alh-diff-added .alh-diff-badge { background: var(--success-50); color: var(--success-700); }
    .alh-diff-removed .alh-diff-badge { background: var(--danger-50); color: var(--danger-700); }
    .alh-diff-note { font-size: .8125rem; color: var(--gray-500); margin-bottom: .5rem; }
    /* One-time code (2FA) fields: a centred block (label, digits, help text)
       wherever they appear: sign-in challenge, set-up and disable modals.
       The digits stay left-to-right, as codes are read. */
    .fi-fo-field:has(.fi-one-time-code-input-ctn) { text-align: center; }
    .fi-fo-field:has(.fi-one-time-code-input-ctn) .fi-fo-field-label-ctn { justify-content: center; }
    .fi-one-time-code-input-ctn { margin-inline: auto; gap: .625rem; }
    .fi-one-time-code-input-ctn > .fi-one-time-code-input-digit { height: 3rem; width: 2.75rem; font-size: 1.25rem; }
    /* 2FA set-up modal: instructions, QR code and manual key centred too. */
    .fi-modal-content:has(.fi-sc-image):has(.fi-one-time-code-input-ctn) .fi-sc-text { text-align: center; }
    .fi-modal-content:has(.fi-sc-image):has(.fi-one-time-code-input-ctn) .fi-sc-flex { justify-content: center; }
    /* Filter lists: values such as "employee.*" or "student.grade.updated"
       keep their left-to-right order in the right-to-left page. */
    .fi-ta-filters .fi-select-input-option, .fi-ta-filters .fi-select-input-option > span, .fi-ta-filters .fi-select-input-value-label { unicode-bidi: plaintext; text-align: right; }
    /* Dashboard tables (top events, most active projects): side by side at
       the same height; the rows scroll inside, under a fixed header. */
    .fi-wi-table .fi-ta-ctn { height: 28rem; display: flex; flex-direction: column; }
    .fi-wi-table .fi-ta-content-ctn { flex: 1 1 auto; min-height: 0; overflow-y: auto; }
    .fi-wi-table .fi-ta-content-ctn thead th { position: sticky; top: 0; z-index: 1; background: var(--gray-50); }
    .dark .fi-wi-table .fi-ta-content-ctn thead th { background: var(--gray-900); }
</style>
