<x-filament-panels::page>
    @vite([
        'app/Addons/SeoContentAi/resources/css/global-ai-chat.css',
        'app/Addons/SeoContentAi/resources/css/agent-workspace.css',
    ])

    @php
        $siteName = $workspaceContext['site_name'] ?? null;
        $bootOk = is_array($workspaceContext) && ($workspaceContext['site_ref'] ?? null);
        $subtitle = $siteName
            ? ('Site: '.$siteName.($workspaceContext['project_ref'] ?? null ? ' · Project context' : ''))
            : __('seo-content-ai::filament.agent_workspace.empty_hint');
    @endphp

    <div
        class="seo-agent-workspace"
        wire:key="seo-agent-workspace-root"
        x-data="seoAgentWorkspace"
        x-on:keydown.escape.window="onEscape()"
        x-on:agent-focus-composer.window="focusComposer()"
        x-on:agent-cli-template-ready.window="onCliTemplateReady()"
    >
        @unless ($bootOk)
            <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100">
                {{ \App\Addons\SeoContentAi\Services\AgentWorkspace\AgentWorkspaceDeepLink::MISSING_SITE_MESSAGE }}
            </div>
        @else
            @if ($contextNotice !== '')
                <div class="mb-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100">
                    {{ $contextNotice }}
                </div>
            @endif

            @if (($composerError ?? '') !== '')
                <div class="mb-3 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-900 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-100" wire:key="agent-composer-error">
                    {{ $composerError }}
                </div>
            @endif

            <div class="seo-agent-workspace__toolbar">
                <div class="ml-auto flex flex-wrap gap-2">
                    <x-filament::button type="button" size="sm" color="gray" wire:click="createConversation" wire:loading.attr="disabled" wire:target="createConversation">
                        {{ __('seo-content-ai::filament.agent_workspace.new_chat') }}
                    </x-filament::button>
                </div>
            </div>

            @if (($activePanel ?? 'chat') === 'chat')
            <div class="seo-agent-workspace__grid">
                <section class="seo-global-chat seo-agent-workspace-chat">
                    <x-seo-content-ai::seo-agent-chat.header
                        :title="__('seo-content-ai::filament.agent_workspace.title')"
                        :subtitle="$subtitle"
                    />

                    <div
                        class="seo-global-chat__messages seo-agent-workspace-chat__messages"
                        wire:loading.class="opacity-60"
                        wire:target="sendMessage,submitComposer,submitClarification,saveProposedPlan"
                        wire:poll.5s="pollActiveExecutions"
                    >
                        @if (count($messages) === 0)
                            <x-seo-content-ai::seo-agent-chat.empty-state
                                :title="__('seo-content-ai::filament.agent_workspace.empty_title')"
                                :description="__('seo-content-ai::filament.agent_workspace.empty_hint')"
                            />
                            {{-- Template cards: value + static wire:click selectTemplate($event.currentTarget.value). --}}
                            <div class="seo-agent-workspace__template-grid">
                                @foreach ($suggestedActions as $card)
                                    <x-seo-content-ai::agent-workspace.action-button
                                        action="selectTemplate"
                                        :value="$card['key']"
                                        wire:key="agent-template-{{ $card['key'] }}"
                                        class="seo-agent-workspace__template-card"
                                        wire:loading.attr="disabled"
                                        wire:target="selectTemplate"
                                    >
                                        <div class="seo-agent-workspace__template-title">{{ $card['title'] }}</div>
                                        <div class="seo-agent-workspace__template-desc">{{ $card['description'] }}</div>
                                    </x-seo-content-ai::agent-workspace.action-button>
                                @endforeach
                            </div>
                        @endif

                        @foreach ($messages as $message)
                            <div wire:key="agent-msg-{{ $message['public_ref'] ?? ($loop->index) }}">
                                <x-seo-content-ai::seo-agent-chat.message
                                    :role="$message['role'] ?? 'assistant'"
                                    :content="$message['content'] ?? ''"
                                    :message-type="$message['message_type'] ?? 'text'"
                                >
                                    @include('seo-content-ai::filament.pages.partials.agent-message-structured', ['message' => $message])
                                </x-seo-content-ai::seo-agent-chat.message>
                            </div>
                        @endforeach

                        @if ($composerSubmitting ?? false)
                            <div class="seo-agent-workspace__pending" wire:key="agent-pending">
                                <div class="seo-global-chat__typing" aria-live="polite">
                                    <span></span><span></span><span></span>
                                </div>
                                <span class="text-xs text-gray-500">Đang xử lý…</span>
                            </div>
                        @endif
                    </div>

                    <div class="seo-agent-workspace-chat__composer-wrap relative">
                        @if ($showPalette)
                            <div class="seo-agent-workspace__palette" role="listbox" aria-label="Slash commands" wire:key="agent-slash-palette" x-ref="paletteRoot">
                                <div class="seo-agent-workspace__palette-head">Slash commands</div>
                                @forelse ($paletteSkills as $idx => $row)
                                    <button
                                        type="button"
                                        role="option"
                                        class="seo-agent-workspace__palette-item"
                                        wire:key="agent-palette-{{ $row['command'] ?? $row['key'] }}"
                                        value="{{ $row['command'] ?? $row['key'] }}"
                                        data-index="{{ (int) $idx }}"
                                        x-bind:class="paletteIndex === Number($el.dataset.index) && 'is-active'"
                                        x-on:mouseenter="paletteIndex = Number($el.dataset.index)"
                                        x-on:click="selectPaletteElement($el)"
                                    >
                                        <div>
                                            <div class="seo-agent-workspace__palette-cmd">{{ $row['slash_command'] }}</div>
                                            <div class="seo-agent-workspace__palette-desc">{{ $row['description'] }}</div>
                                            @if (! empty($row['example']))
                                                <div class="seo-agent-workspace__palette-example">Example: {{ $row['example'] }}</div>
                                            @endif
                                            @if (! empty($row['category']))
                                                <div class="seo-agent-workspace__palette-cat">{{ $row['category'] }}</div>
                                            @endif
                                        </div>
                                        <div class="seo-agent-workspace__palette-badges">
                                            @if (($row['confirmation_policy'] ?? 'none') !== 'none')
                                                <span class="seo-agent-workspace__badge is-warn">Cần xác nhận</span>
                                            @else
                                                <span class="seo-agent-workspace__badge is-ok">Read</span>
                                            @endif
                                            @if (! ($row['availability']['usable'] ?? true))
                                                <span class="seo-agent-workspace__badge">
                                                    {{ ($row['availability']['status'] ?? '') === 'coming_soon' ? 'Đang phát triển' : 'Chưa cấu hình' }}
                                                </span>
                                            @endif
                                        </div>
                                    </button>
                                @empty
                                    <div class="px-3 py-4 text-sm text-gray-500">Không tìm thấy lệnh.</div>
                                @endforelse
                            </div>
                        @endif

                        @if (is_array($cliHelpPanel) && ($cliHelpPanel['command'] ?? '') !== '')
                            <div class="seo-agent-workspace__cli-help" wire:key="agent-cli-help">
                                <div class="seo-agent-workspace__cli-help-cmd">{{ $cliHelpPanel['command'] }}</div>
                                <div class="text-xs text-gray-600 dark:text-gray-300">
                                    <span class="font-semibold">Description:</span>
                                    {{ $cliHelpPanel['description'] ?? '' }}
                                </div>
                                <div class="mt-1 font-mono text-[11px] text-gray-500 dark:text-gray-400">
                                    <span class="font-semibold font-sans">Example:</span>
                                    {{ $cliHelpPanel['example'] ?? '' }}
                                </div>
                            </div>
                        @endif

                        @if (count($cliArgumentSuggestions) > 0)
                            <div class="seo-agent-workspace__arg-suggest" wire:key="agent-cli-arg-suggest">
                                @foreach ($cliArgumentSuggestions as $suggest)
                                    <button
                                        type="button"
                                        class="seo-agent-workspace__arg-suggest-item"
                                        data-value="{{ $suggest['value'] }}"
                                        x-on:click="applyArgSuggestion($el.dataset.value)"
                                    >
                                        {{ $suggest['label'] }}
                                    </button>
                                @endforeach
                            </div>
                        @endif

                        <x-seo-content-ai::seo-agent-chat.composer
                            :placeholder="__('seo-content-ai::filament.agent_workspace.composer_placeholder')"
                        >
                            <x-slot:below>
                                <x-seo-content-ai::seo-agent-chat.disclaimer />
                            </x-slot:below>
                        </x-seo-content-ai::seo-agent-chat.composer>
                    </div>
                </section>
            </div>
            @endif
            {{-- No skill/modal/drawer UI in chat-only UX --}}
        @endunless
    </div>

    <script>
        (function () {
            if (window.__seoAgentWorkspaceAlpineRegistered) {
                return;
            }
            window.__seoAgentWorkspaceAlpineRegistered = true;

            var register = function () {
                if (! window.Alpine || typeof window.Alpine.data !== 'function') {
                    return;
                }
                if (window.__seoAgentWorkspaceDataRegistered) {
                    return;
                }
                window.__seoAgentWorkspaceDataRegistered = true;

                window.Alpine.data('seoAgentWorkspace', function () {
                    return {
                        conversationsOpen: false,
                        contextOpen: false,
                        paletteIndex: 0,
                        placeholderIndex: 0,
                        skillBrowserOpen: false,
                        composer: '',
                        composerSubmitting: false,
                        _composerSyncTimer: null,
                        _argSuggestTimer: null,

                        init: function () {
                            var self = this;
                            this.composer = this.$wire.composerText || '';
                            this.$watch('$wire.composerText', function (value) {
                                if (self.composerSubmitting) {
                                    return;
                                }
                                if (typeof value === 'string' && value !== self.composer) {
                                    self.composer = value;
                                }
                            });
                        },

                        closePalette: function () {
                            this.$wire.set('showPalette', false);
                        },

                        movePalette: function (delta) {
                            var items = this.$wire.paletteSkills || [];
                            if (! items.length) {
                                return;
                            }
                            this.paletteIndex = (this.paletteIndex + delta + items.length) % items.length;
                        },

                        selectPaletteElement: function (element) {
                            var command = element && element.value ? String(element.value) : '';
                            if (command === '') {
                                return;
                            }
                            this.closePalette();
                            this.$wire.selectCommand(command);
                        },

                        selectBrowserElement: function (element) {
                            this.closeSkillBrowser();
                            this.selectPaletteElement(element);
                        },

                        selectPalette: function () {
                            var root = this.$refs.paletteRoot;
                            if (! root) {
                                var items = this.$wire.paletteSkills || [];
                                var row = items[this.paletteIndex];
                                if (! row || ! row.key) {
                                    return;
                                }
                                this.closePalette();
                                this.$wire.selectCommand(row.key);
                                return;
                            }
                            var el = root.querySelector('[data-index="' + this.paletteIndex + '"]');
                            if (el) {
                                this.selectPaletteElement(el);
                            }
                        },

                        openSkillBrowser: function () {
                            this.skillBrowserOpen = true;
                            this.$wire.openSkillBrowser();
                        },

                        closeSkillBrowser: function () {
                            this.skillBrowserOpen = false;
                        },

                        onEscape: function () {
                            if (this.skillBrowserOpen) {
                                this.closeSkillBrowser();
                                return;
                            }
                            this.closePalette();
                        },

                        focusComposer: function () {
                            var self = this;
                            this.$nextTick(function () {
                                var el = document.getElementById('seo-agent-composer-input');
                                if (el) {
                                    el.focus();
                                }
                            });
                        },

                        onCliTemplateReady: function () {
                            var self = this;
                            this.placeholderIndex = 0;
                            this.$nextTick(function () {
                                self.composer = self.$wire.composerText || self.composer;
                                self.focusFirstPlaceholder();
                            });
                        },

                        findPlaceholders: function (text) {
                            var matches = [];
                            var re = /(-{1,2}[a-z0-9-]+)=""|(-{1,2}[a-z])=""/gi;
                            var m;
                            while ((m = re.exec(text)) !== null) {
                                var start = m.index + m[0].length - 2;
                                var end = m.index + m[0].length - 1;
                                matches.push({ start: start, end: end });
                            }
                            return matches;
                        },

                        focusFirstPlaceholder: function () {
                            var ph = this.findPlaceholders(this.composer);
                            if (! ph.length) {
                                return;
                            }
                            this.selectPlaceholder(0);
                        },

                        selectPlaceholder: function (idx) {
                            var el = document.getElementById('seo-agent-composer-input');
                            if (! el) {
                                return;
                            }
                            var ph = this.findPlaceholders(this.composer);
                            if (! ph.length) {
                                return;
                            }
                            var p = ph[idx % ph.length];
                            this.placeholderIndex = idx % ph.length;
                            el.focus();
                            el.setSelectionRange(p.start, p.end);
                        },

                        applyArgSuggestion: function (value) {
                            var el = document.getElementById('seo-agent-composer-input');
                            if (! el) {
                                return;
                            }
                            var text = this.composer;
                            var cursor = el.selectionStart || text.length;
                            var before = text.slice(0, cursor);
                            var after = text.slice(cursor);
                            var replaced = before.replace(/((?:--project-id|-p|--member)=)([^"\s]*)$/i, '$1' + value);
                            if (replaced === before) {
                                return;
                            }
                            this.composer = replaced + after;
                            this.$wire.set('composerText', this.composer);
                            this.$wire.set('cliArgumentSuggestions', []);
                        },

                        detectArgSuggest: function (text, cursor) {
                            var before = text.slice(0, cursor);
                            var projectMatch = before.match(/(?:--project-id|-p)=("?)([^"\s]*)$/i);
                            if (projectMatch) {
                                return { type: 'project', query: projectMatch[2] || '' };
                            }
                            var memberMatch = before.match(/--member=("?)([^"]*)$/i);
                            if (memberMatch) {
                                return { type: 'member', query: memberMatch[2] || '' };
                            }
                            return null;
                        },

                        scheduleArgSuggest: function () {
                            var self = this;
                            var el = document.getElementById('seo-agent-composer-input');
                            if (! el) {
                                return;
                            }
                            clearTimeout(this._argSuggestTimer);
                            this._argSuggestTimer = setTimeout(function () {
                                var ctx = self.detectArgSuggest(self.composer, el.selectionStart || 0);
                                if (! ctx) {
                                    self.$wire.set('cliArgumentSuggestions', []);
                                    return;
                                }
                                self.$wire.getCliArgumentSuggestions(ctx.type, ctx.query);
                            }, 200);
                        },

                        onComposerInput: function (event) {
                            var el = event.target;
                            el.style.height = 'auto';
                            el.style.height = Math.min(el.scrollHeight, 160) + 'px';
                            this.paletteIndex = 0;
                            var self = this;
                            clearTimeout(this._composerSyncTimer);
                            this._composerSyncTimer = setTimeout(function () {
                                self.$wire.set('composerText', self.composer);
                            }, 150);
                            this.scheduleArgSuggest();
                        },

                        onComposerArrow: function (delta) {
                            if (this.$wire.showPalette) {
                                this.movePalette(delta);
                            }
                        },

                        onComposerTab: function (event) {
                            var items = this.$wire.paletteSkills || [];
                            if (this.$wire.showPalette && items.length) {
                                event.preventDefault();
                                this.selectPalette();
                                return;
                            }
                            var ph = this.findPlaceholders(this.composer);
                            if (ph.length) {
                                event.preventDefault();
                                if (event.shiftKey) {
                                    this.selectPlaceholder(this.placeholderIndex - 1 + ph.length);
                                } else {
                                    this.selectPlaceholder(this.placeholderIndex + 1);
                                }
                            }
                        },

                        onComposerEnter: function (event) {
                            if (event.shiftKey) {
                                return;
                            }
                            event.preventDefault();
                            event.stopPropagation();
                            if (this.composerSubmitting || this.$wire.composerSubmitting) {
                                return;
                            }
                            if (this.$wire.showPalette) {
                                this.selectPalette();
                                return;
                            }
                            this.submitAgentComposer();
                        },

                        submitAgentComposer: function () {
                            var self = this;
                            if (this.composerSubmitting || this.$wire.composerSubmitting) {
                                return;
                            }
                            var message = String(this.composer || '').trim();
                            if (message === '') {
                                return;
                            }
                            this.composerSubmitting = true;
                            this.$wire.sendMessage(message).then(function () {
                                self.composer = '';
                            }).catch(function () {
                                // Keep composer text on failure.
                            }).finally(function () {
                                self.composerSubmitting = false;
                            });
                        }
                    };
                });
            };

            if (window.Alpine) {
                register();
            }
            document.addEventListener('alpine:init', register);
        })();
    </script>
</x-filament-panels::page>
