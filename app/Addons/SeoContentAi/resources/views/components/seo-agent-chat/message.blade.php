@props([
    'role' => 'assistant',
    'content' => '',
    'messageType' => 'text',
    'structured' => [],
])

@php
    $isUser = $role === 'user';
@endphp

<div class="{{ $isUser ? 'seo-global-chat__user-row' : 'seo-global-chat__assistant-row' }}">
    @unless ($isUser)
        <span class="seo-global-chat__assistant-icon">
            <x-seo-content-ai::seo-agent-chat.star-icon />
        </span>
    @endunless

    <div class="{{ $isUser ? 'seo-global-chat__user-message' : 'seo-global-chat__assistant-message' }} {{ $messageType === 'error' ? 'is-error' : '' }}">
        @if (filled($content))
            <div class="whitespace-pre-wrap">{{ $content }}</div>
        @endif

        {{ $slot }}
    </div>
</div>
