@php
    $modelsUrl = route('seo.global-ai-chat.models');
    $chatUrl = route('seo.global-ai-chat.store');
    $storageKey = 'seo_global_ai_chat_'.((int) auth()->id());
@endphp

@vite('app/Addons/SeoContentAi/resources/css/global-ai-chat.css')

<div
    class="seo-global-chat"
    x-data="{
        openChat: false,
        loading: false,
        loadingModels: true,
        message: '',
        imageFile: null,
        imagePreview: '',
        models: [],
        selectedModel: '',
        messages: [],
        storageKey: @js($storageKey),
        modelsUrl: @js($modelsUrl),
        chatUrl: @js($chatUrl),

        init() {
            this.restore();
            this.loadModels();
        },

        async loadModels() {
            try {
                const response = await fetch(this.modelsUrl, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                const data = await response.json();
                this.models = Array.isArray(data.models) ? data.models : [];
                const savedModel = localStorage.getItem(`${this.storageKey}_model`);
                const matched = this.models.find((model) => String(model.id) === String(savedModel));
                this.selectedModel = String(matched?.id ?? this.models[0]?.id ?? '');
            } catch (error) {
                this.models = [];
            } finally {
                this.loadingModels = false;
            }
        },

        restore() {
            try {
                const stored = JSON.parse(localStorage.getItem(this.storageKey) || '[]');
                this.messages = Array.isArray(stored)
                    ? stored.filter((item) => ['user', 'assistant'].includes(item?.role) && item?.content)
                    : [];
            } catch (error) {
                this.messages = [];
            }
        },

        persist() {
            const persistent = this.messages
                .filter((item) => ! item.loading)
                .slice(-30)
                .map(({ role, content, error }) => ({ role, content, error: Boolean(error) }));
            localStorage.setItem(this.storageKey, JSON.stringify(persistent));
        },

        selectImage(event) {
            const file = event.target.files?.[0] ?? null;
            this.clearImage();
            if (! file) return;

            this.imageFile = file;
            this.imagePreview = URL.createObjectURL(file);
        },

        clearImage() {
            if (this.imagePreview) URL.revokeObjectURL(this.imagePreview);
            this.imagePreview = '';
            this.imageFile = null;
            if (this.$refs.imageInput) this.$refs.imageInput.value = '';
        },

        resizeInput() {
            const input = this.$refs.messageInput;
            input.style.height = 'auto';
            input.style.height = `${Math.min(input.scrollHeight, 96)}px`;
        },

        scrollToBottom() {
            this.$nextTick(() => {
                const area = this.$refs.messages;
                if (area) area.scrollTop = area.scrollHeight;
            });
        },

        async send() {
            const text = this.message.trim();
            if (this.loading || ! this.selectedModel || (! text && ! this.imageFile)) return;

            const imageFile = this.imageFile;
            const imageUrl = this.imagePreview;
            const history = this.messages
                .filter((item) => ! item.loading && ! item.error)
                .slice(-12)
                .map(({ role, content }) => ({ role, content }));

            this.messages.push({
                role: 'user',
                content: text || 'Hãy phân tích hình ảnh này.',
                image: imageUrl,
            });
            this.message = '';
            this.imageFile = null;
            this.imagePreview = '';
            if (this.$refs.imageInput) this.$refs.imageInput.value = '';
            this.resizeInput();
            this.loading = true;
            this.messages.push({ role: 'assistant', content: '', loading: true });
            this.scrollToBottom();

            const form = new FormData();
            form.append('model', this.selectedModel);
            form.append('message', text);
            history.forEach((item, index) => {
                form.append(`history[${index}][role]`, item.role);
                form.append(`history[${index}][content]`, item.content);
            });
            if (imageFile) form.append('image', imageFile);

            try {
                const response = await fetch(this.chatUrl, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
                    },
                    credentials: 'same-origin',
                    body: form,
                });
                const data = await response.json();
                if (! response.ok) {
                    const validation = data.errors
                        ? Object.values(data.errors).flat().join(' ')
                        : '';
                    throw new Error(validation || data.message || 'Không gửi được tin nhắn.');
                }

                this.messages.splice(this.messages.length - 1, 1, {
                    role: 'assistant',
                    content: data.answer || 'AI không trả về nội dung.',
                });
            } catch (error) {
                this.messages.splice(this.messages.length - 1, 1, {
                    role: 'assistant',
                    content: error.message || 'Không thể kết nối trợ lý AI.',
                    error: true,
                });
            } finally {
                this.loading = false;
                this.persist();
                this.scrollToBottom();
                this.$nextTick(() => this.$refs.messageInput?.focus());
            }
        },

        clearConversation() {
            this.messages.forEach((item) => {
                if (item.image) URL.revokeObjectURL(item.image);
            });
            this.messages = [];
            localStorage.removeItem(this.storageKey);
        },
    }"
    x-on:keydown.escape.window="openChat = false"
>
    <button
        type="button"
        class="seo-global-chat__launcher"
        x-on:click="openChat = true; scrollToBottom()"
        x-show="! openChat"
        x-transition.opacity
        aria-label="Mở trợ lý AI"
    >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm3.75 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm3.75 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M21 12c0 4.142-4.03 7.5-9 7.5a10.8 10.8 0 0 1-3.75-.658L3 20.25l1.575-3.675A6.9 6.9 0 0 1 3 12c0-4.142 4.03-7.5 9-7.5s9 3.358 9 7.5Z" />
        </svg>
    </button>

    <div
        class="seo-global-chat__backdrop"
        x-show="openChat"
        x-transition.opacity
        x-on:click="openChat = false"
        x-cloak
    ></div>

    <aside
        class="seo-global-chat__sidebar"
        x-bind:class="{ 'is-open': openChat }"
        aria-label="Trợ lý AI"
    >
        <header class="seo-global-chat__header">
            <div class="seo-global-chat__brand">
                <span class="seo-global-chat__brand-icon">
                    <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M12 2.75c.48 4.91 4.34 8.77 9.25 9.25-4.91.48-8.77 4.34-9.25 9.25-.48-4.91-4.34-8.77-9.25-9.25C7.66 11.52 11.52 7.66 12 2.75Z" />
                    </svg>
                </span>
                <div>
                    <h2>Trợ lý AI</h2>
                    <p>Hỏi nhanh trên mọi trang</p>
                </div>
            </div>

            <div class="seo-global-chat__header-actions">
                <button
                    type="button"
                    class="seo-global-chat__icon-button"
                    x-on:click="clearConversation"
                    x-show="messages.length > 0"
                    title="Xóa cuộc trò chuyện"
                    aria-label="Xóa cuộc trò chuyện"
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673A2.25 2.25 0 0 1 15.916 21H8.084a2.25 2.25 0 0 1-2.244-1.327L4.772 5.79m14.456 0a48.1 48.1 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.1 48.1 0 0 1 3.478-.397m7.5 0V4.477c0-1.18-.91-2.164-2.09-2.201a51 51 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.7 48.7 0 0 0-7.5 0" />
                    </svg>
                </button>
                <button
                    type="button"
                    class="seo-global-chat__icon-button"
                    x-on:click="openChat = false"
                    aria-label="Đóng trợ lý AI"
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </header>

        <div class="seo-global-chat__model-row">
            <label for="seo-global-chat-model">Model</label>
            <select
                id="seo-global-chat-model"
                x-model="selectedModel"
                x-on:change="localStorage.setItem(`${storageKey}_model`, selectedModel)"
                x-bind:disabled="loadingModels || models.length === 0"
            >
                <template x-for="model in models" x-bind:key="model.id">
                    <option x-bind:value="String(model.id)" x-text="model.label"></option>
                </template>
                <option x-show="loadingModels" value="">Đang tải model...</option>
                <option x-show="! loadingModels && models.length === 0" value="">Chưa có model AI active</option>
            </select>
        </div>

        <div class="seo-global-chat__messages" x-ref="messages">
            <div class="seo-global-chat__empty" x-show="messages.length === 0">
                <span class="seo-global-chat__empty-icon">
                    <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M12 2.75c.48 4.91 4.34 8.77 9.25 9.25-4.91.48-8.77 4.34-9.25 9.25-.48-4.91-4.34-8.77-9.25-9.25C7.66 11.52 11.52 7.66 12 2.75Z" />
                    </svg>
                </span>
                <h3>Tôi có thể giúp gì?</h3>
                <p>Hỏi về nội dung, SEO, dữ liệu đang xử lý hoặc gửi một hình ảnh để phân tích.</p>
            </div>

            <template x-for="(item, index) in messages" x-bind:key="index">
                <div>
                    <div class="seo-global-chat__user-row" x-show="item.role === 'user'">
                        <div class="seo-global-chat__user-message">
                            <img x-show="item.image" x-bind:src="item.image" alt="Ảnh đính kèm" />
                            <span x-text="item.content"></span>
                        </div>
                    </div>

                    <div class="seo-global-chat__assistant-row" x-show="item.role === 'assistant'">
                        <span class="seo-global-chat__assistant-icon">
                            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <path d="M12 2.75c.48 4.91 4.34 8.77 9.25 9.25-4.91.48-8.77 4.34-9.25 9.25-.48-4.91-4.34-8.77-9.25-9.25C7.66 11.52 11.52 7.66 12 2.75Z" />
                            </svg>
                        </span>
                        <div class="seo-global-chat__assistant-message" x-bind:class="{ 'is-error': item.error }">
                            <div class="seo-global-chat__typing" x-show="item.loading">
                                <span></span><span></span><span></span>
                            </div>
                            <div x-show="! item.loading" x-text="item.content"></div>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <footer class="seo-global-chat__composer">
            <div class="seo-global-chat__image-preview" x-show="imagePreview" x-cloak>
                <img x-bind:src="imagePreview" alt="Xem trước ảnh" />
                <button type="button" x-on:click="clearImage" aria-label="Bỏ ảnh">
                    <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" />
                    </svg>
                </button>
            </div>

            <div class="seo-global-chat__input-shell">
                <input
                    x-ref="imageInput"
                    type="file"
                    accept="image/jpeg,image/png,image/webp,image/gif"
                    class="seo-global-chat__file-input"
                    x-on:change="selectImage($event)"
                />
                <button
                    type="button"
                    class="seo-global-chat__attach"
                    x-on:click="$refs.imageInput.click()"
                    x-bind:disabled="loading"
                    aria-label="Đính kèm hình ảnh"
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m18.375 12.739-7.693 7.693a4.5 4.5 0 0 1-6.364-6.364l9.724-9.724a3 3 0 0 1 4.243 4.243l-9.193 9.193a1.5 1.5 0 0 1-2.121-2.121l8.662-8.662" />
                    </svg>
                </button>

                <textarea
                    x-ref="messageInput"
                    x-model="message"
                    rows="1"
                    placeholder="Hỏi Trợ lý AI..."
                    x-on:input="resizeInput"
                    x-on:keydown.enter.prevent="if (!$event.shiftKey) send(); else message += '\n'"
                    x-bind:disabled="loading"
                ></textarea>

                <button
                    type="button"
                    class="seo-global-chat__send"
                    x-on:click="send"
                    x-bind:disabled="loading || !selectedModel || (!message.trim() && !imageFile)"
                    aria-label="Gửi tin nhắn"
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.27 3.125A59.8 59.8 0 0 1 21.485 12 59.8 59.8 0 0 1 3.27 20.875L6 12Zm0 0h7.5" />
                    </svg>
                </button>
            </div>
            <p class="seo-global-chat__hint">AI có thể đưa ra thông tin chưa chính xác. Hãy kiểm tra nội dung quan trọng.</p>
        </footer>
    </aside>
</div>
