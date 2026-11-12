@php($organization = config('audit.branding.organization'))
<footer class="alh-footer" role="contentinfo">
    <span>{{ config('app.name') }}@if (filled($organization)) · {{ $organization }}@endif</span>
    <span>© {{ now()->year }} — سجلات تدقيق غير قابلة للتعديل</span>
</footer>
