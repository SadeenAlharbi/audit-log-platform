@php
    /** @var \App\Models\Project $project */
    $snippets = \App\Filament\Support\IntegrationSnippets::for($project);
    $base = rtrim((string) config('app.url'), '/').'/api/v1';
@endphp

<div class="space-y-4 text-sm" dir="rtl">
    <dl class="grid gap-2 sm:grid-cols-2">
        <div>
            <dt class="font-medium text-gray-500 dark:text-gray-400">نقطة الاستقبال</dt>
            <dd><code dir="ltr">POST {{ $base }}/logs</code> · <code dir="ltr">POST {{ $base }}/logs/batch</code></dd>
        </div>
        <div>
            <dt class="font-medium text-gray-500 dark:text-gray-400">الترويسات</dt>
            <dd dir="ltr"><code>Authorization: Bearer &lt;api_key&gt;</code> · <code>X-Project: {{ $project->slug }}</code></dd>
        </div>
        <div>
            <dt class="font-medium text-gray-500 dark:text-gray-400">التوقيع (للمفاتيح الموقّعة)</dt>
            <dd dir="ltr"><code>HMAC-SHA256(secret, "{timestamp}\n{METHOD}\n{path?query}\n{body}")</code></dd>
        </div>
        <div>
            <dt class="font-medium text-gray-500 dark:text-gray-400">التوثيق الكامل</dt>
            <dd><a class="text-primary-600 underline" href="{{ route('api.docs') }}" target="_blank" rel="noopener">OpenAPI</a></dd>
        </div>
    </dl>

    <div x-data="{ tab: 'curl' }" class="space-y-2">
        <div class="flex flex-wrap gap-2">
            @foreach ($snippets as $key => $snippet)
                <button type="button"
                        x-on:click="tab = '{{ $key }}'"
                        x-bind:class="tab === '{{ $key }}' ? 'bg-primary-600 text-white' : 'bg-gray-100 dark:bg-white/5'"
                        class="rounded-md px-3 py-1 text-xs font-medium">
                    {{ $snippet['label'] }}
                </button>
            @endforeach
        </div>

        @foreach ($snippets as $key => $snippet)
            <pre x-show="tab === '{{ $key }}'" x-cloak dir="ltr"
                 class="overflow-x-auto rounded-lg bg-gray-950 p-4 text-xs leading-relaxed text-gray-100"><code>{{ $snippet['code'] }}</code></pre>
        @endforeach
    </div>
</div>
