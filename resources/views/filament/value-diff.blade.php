@php
    /** @var list<array{key: string, old: ?string, new: ?string, status: string}> $rows */
    $labels = ['added' => 'أُضيف', 'removed' => 'حُذف', 'changed' => 'تغيّر', 'same' => 'بدون تغيير'];
@endphp

{{-- Styled by filament/partials/platforms-code-styles (alh-diff-*). --}}
@if ($masked)
    <p class="alh-diff-note">بعض القيم مخفية لحمايتها. كشفها يتطلب صلاحية «عرض البيانات الحساسة».</p>
@endif

@if (empty($rows))
    <p class="alh-diff-note">لا توجد قيم قبل أو بعد لهذا السجل.</p>
@else
    <div class="alh-diff">
        <table>
            <thead>
                <tr>
                    <th>الحقل</th>
                    <th>قبل</th>
                    <th>بعد</th>
                    <th>الحالة</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr class="alh-diff-{{ $row['status'] }}">
                        <td><code dir="auto">{{ $row['key'] }}</code></td>
                        <td><span class="alh-diff-old" dir="auto">{{ $row['old'] ?? '—' }}</span></td>
                        <td><span class="alh-diff-new" dir="auto">{{ $row['new'] ?? '—' }}</span></td>
                        <td><span class="alh-diff-badge">{{ $labels[$row['status']] ?? $row['status'] }}</span></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
