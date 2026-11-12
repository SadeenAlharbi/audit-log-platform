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
</style>
