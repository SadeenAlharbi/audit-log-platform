@php
    /** @var \App\Models\Project $project */
    $snippets = \App\Filament\Support\IntegrationSnippets::for($project);
    $base = rtrim((string) config('app.url'), '/').'/api/v1';
@endphp

{{-- Styled by filament/partials/platforms-code-styles (Filament ships a
     precompiled stylesheet, so arbitrary Tailwind classes are not available). --}}
<div class="alh-guide" dir="rtl">
    <dl class="alh-guide-facts">
        <div>
            <dt>نقطة الاستقبال</dt>
            <dd dir="ltr"><code>POST {{ $base }}/logs</code><code>POST {{ $base }}/logs/batch</code></dd>
        </div>
        <div>
            <dt>الترويسات</dt>
            <dd dir="ltr"><code>Authorization: Bearer &lt;api_key&gt;</code><code>X-Project: {{ $project->slug }}</code></dd>
        </div>
        <div>
            <dt>التوقيع (للمفاتيح الموقّعة)</dt>
            <dd dir="ltr"><code>HMAC-SHA256(secret, "{timestamp}\n{METHOD}\n{path?query}\n{body}")</code></dd>
        </div>
        <div>
            <dt>التوثيق الكامل</dt>
            <dd><a href="{{ route('api.docs') }}" target="_blank" rel="noopener">OpenAPI</a></dd>
        </div>
    </dl>

    <div x-data="{ tab: 'curl' }">
        <div class="alh-guide-tabs" role="tablist" dir="ltr">
            @foreach ($snippets as $key => $snippet)
                <button type="button" role="tab"
                        x-on:click="tab = '{{ $key }}'"
                        x-bind:aria-selected="tab === '{{ $key }}'">
                    {{ $snippet['label'] }}
                </button>
            @endforeach
        </div>

        @foreach ($snippets as $key => $snippet)
            <pre x-show="tab === '{{ $key }}'" x-cloak dir="ltr" class="alh-guide-code"><code>{{ $snippet['code'] }}</code></pre>
        @endforeach
    </div>
</div>
