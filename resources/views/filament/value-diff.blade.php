@php
    /** @var list<array{key: string, old: ?string, new: ?string, status: string}> $rows */
    $classes = [
        'added' => 'bg-success-50 dark:bg-success-500/10',
        'removed' => 'bg-danger-50 dark:bg-danger-500/10',
        'changed' => 'bg-warning-50 dark:bg-warning-500/10',
        'same' => '',
    ];
    $labels = ['added' => 'أُضيف', 'removed' => 'حُذف', 'changed' => 'تغيّر', 'same' => ''];
@endphp

@if ($masked)
    <p class="mb-2 text-xs text-gray-500">بعض القيم مخفية (Masking). كشفها يتطلب صلاحية logs.view_sensitive.</p>
@endif

@if (empty($rows))
    <p class="text-sm text-gray-500">لا توجد قيم قديمة أو جديدة لهذا السجل.</p>
@else
    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
        <table class="w-full text-sm" dir="ltr">
            <thead class="bg-gray-50 text-xs uppercase text-gray-500 dark:bg-white/5">
                <tr>
                    <th class="px-3 py-2 text-left">Field</th>
                    <th class="px-3 py-2 text-left">Old</th>
                    <th class="px-3 py-2 text-left">New</th>
                    <th class="px-3 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($rows as $row)
                    <tr class="{{ $classes[$row['status']] }}">
                        <td class="px-3 py-2 font-mono text-xs">{{ $row['key'] }}</td>
                        <td class="px-3 py-2 font-mono text-xs break-all">{{ $row['old'] ?? '—' }}</td>
                        <td class="px-3 py-2 font-mono text-xs break-all">{{ $row['new'] ?? '—' }}</td>
                        <td class="px-3 py-2 text-xs text-gray-500" dir="rtl">{{ $labels[$row['status']] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
