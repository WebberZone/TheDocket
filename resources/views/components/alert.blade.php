@props(['type' => 'success', 'message' => null])

@if ($message || session('status'))
    @php
        $alertMessage = $message ?? session('status');
        $alertType = $type;

        // Auto-detect type from message content.
        if (! $message && session('status')) {
            $status = session('status');
            if (str_contains(strtolower($status), 'error') || str_contains(strtolower($status), 'failed')) {
                $alertType = 'error';
            }
        }
    @endphp

    <div
        class="mb-6 p-4 rounded-md @if ($alertType === 'error') bg-red-50 border border-red-200 @elseif ($alertType === 'info') bg-blue-50 border border-blue-200 @else bg-green-50 border border-green-200 @endif">
        <p
            class="text-sm @if ($alertType === 'error') text-red-800 @elseif ($alertType === 'info') text-blue-800 @else text-green-800 @endif">
            {{ $alertMessage }}
        </p>
    </div>
@endif