{{-- Demo accounts under the sign-in form (local/review only, see Login::demoAccounts()). --}}
@php($accounts ??= [])

<div class="alh-demo">
    <div class="alh-demo-head">
        <span class="alh-demo-title">
            <x-filament::icon icon="heroicon-o-beaker" class="alh-demo-icon" />
            حسابات تجريبية
        </span>
        <span class="alh-demo-hint">انقر للدخول بالحساب</span>
    </div>

    <div class="alh-demo-list">
        @foreach ($accounts as $email => $role)
            <button
                type="button"
                class="alh-demo-item"
                wire:click="loginAsDemo(@js($email))"
                wire:loading.attr="disabled"
                wire:target="loginAsDemo, authenticate"
            >
                <span class="alh-demo-email" dir="ltr">{{ $email }}</span>
                <span class="alh-demo-role">{{ $role }}</span>
            </button>
        @endforeach
    </div>

    <p class="alh-demo-note">بيئة التطوير فقط. المصادقة الثنائية تُطلب كالمعتاد.</p>
</div>

<style>
    .alh-demo { margin-top: .25rem; padding: .875rem; border: 1px dashed var(--primary-300); border-radius: .875rem; background: color-mix(in oklab, var(--primary-50) 70%, white); }
    .alh-demo-head { display: flex; align-items: center; justify-content: space-between; gap: .5rem; margin-bottom: .625rem; }
    .alh-demo-title { display: inline-flex; align-items: center; gap: .375rem; font-weight: 700; color: var(--primary-700); }
    .alh-demo-icon { width: 1.125rem; height: 1.125rem; }
    .alh-demo-hint { font-size: .75rem; color: var(--gray-500); }
    .alh-demo-list { display: grid; gap: .5rem; }
    .alh-demo-item { display: flex; align-items: center; justify-content: space-between; gap: .75rem; width: 100%; padding: .625rem .875rem; border: 1px solid var(--primary-100); border-radius: .625rem; background: white; text-align: start; cursor: pointer; transition: border-color .15s, box-shadow .15s; }
    .alh-demo-item:hover { border-color: var(--primary-400); box-shadow: 0 1px 3px rgb(0 0 0 / .06); }
    .alh-demo-item:disabled { opacity: .6; cursor: wait; }
    .alh-demo-email { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-weight: 700; font-size: .875rem; color: var(--gray-900); }
    .alh-demo-role { font-size: .8125rem; color: var(--gray-600); }
    .alh-demo-note { margin-top: .625rem; font-size: .7rem; color: var(--gray-500); text-align: center; }
    .dark .alh-demo { background: color-mix(in oklab, var(--primary-950) 40%, transparent); border-color: var(--primary-800); }
    .dark .alh-demo-item { background: var(--gray-900); border-color: var(--gray-700); }
    .dark .alh-demo-email { color: var(--gray-100); }
    .dark .alh-demo-role, .dark .alh-demo-hint, .dark .alh-demo-note { color: var(--gray-400); }
</style>
