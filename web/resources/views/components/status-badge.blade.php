@props(['value' => 'Pending'])
@php
    $tone = match (strtolower((string) $value)) {
        'active', 'completed', 'delivered', 'published', 'present', 'yes', 'good', 'create' => 'success',
        'pending', 'planned', 'partially delivered', 'needs maintenance', 'invite user' => 'warning',
        'suspended', 'cancelled', 'damaged', 'absent', 'reject' => 'danger',
        'ongoing', 'update', 'fisherfolk', 'youth', 'login' => 'blue',
        default => 'neutral',
    };
@endphp
<span class="badge badge-{{ $tone }}">{{ $value }}</span>
