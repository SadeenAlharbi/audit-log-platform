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
    .alh-guide-facts { display: grid; gap: .75rem 1.5rem; grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr)); }
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
</style>
