<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Contract: 423 article_editor_locked → hard read-only UX (not soft banner only).
 */
final class ArticleEditorLockedReadonlyUxTest extends TestCase
{
    private function readAddon(string $relative): string
    {
        $path = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
        self::assertFileExists($path);
        $body = file_get_contents($path);
        self::assertIsString($body);

        return $body;
    }

    public function test_423_sets_locked_status_writable_false_read_only_true(): void
    {
        $session = $this->readAddon('resources/js/utils/editorSessionState.js');
        $shell = $this->readAddon('resources/js/article-editor.jsx');

        self::assertStringContainsString("LOCKED: 'locked'", $session);
        self::assertStringContainsString("case 'article_editor_locked':", $session);
        self::assertStringContainsString('return EDITOR_SESSION_STATUS.LOCKED', $session);
        self::assertStringContainsString('read_only:', $session);
        self::assertStringContainsString('const writable = Boolean(partial.writable);', $session);
        self::assertStringContainsString("result.error?.code || 'article_editor_locked'", $shell);
        self::assertStringContainsString('seo-editor-session-shell--hard-readonly', $shell);
        self::assertStringContainsString('data-seo-editor-hard-readonly', $shell);
    }

    public function test_tiptap_set_editable_false_on_session_readonly(): void
    {
        $editor = $this->readAddon('resources/js/components/SeoArticleEditor.jsx');

        self::assertStringContainsString('editor.setEditable(!sessionReadOnly && !window.__SEO_EDITOR_READ_ONLY__)', $editor);
        self::assertStringContainsString('editor.setEditable(writable)', $editor);
        self::assertStringContainsString('editable={!sessionReadOnly && !window.__SEO_EDITOR_READ_ONLY__}', $editor);
        self::assertStringContainsString('seo-article-editor-root--hard-readonly', $editor);
    }

    public function test_save_and_save_close_disabled_when_not_writable(): void
    {
        $actions = $this->readAddon(
            'resources/views/filament/resources/article-resource/pages/partials/article-editor-page-actions.blade.php',
        );

        self::assertStringContainsString('canMutateDocument()', $actions);
        self::assertStringContainsString('x-bind:disabled="!canMutateDocument()"', $actions);
        self::assertStringContainsString('data-seo-page-action="save-close"', $actions);
        self::assertStringContainsString("detail: { action: 'save' }", $actions);
        self::assertStringContainsString("detail: { action: 'save-close' }", $actions);
    }

    public function test_autosave_stopped_when_session_readonly(): void
    {
        $editor = $this->readAddon('resources/js/components/SeoArticleEditor.jsx');

        self::assertStringContainsString('|| Boolean(sessionReadOnly)', $editor);
        self::assertStringContainsString('if (sessionReadOnly || window.__SEO_EDITOR_READ_ONLY__) {', $editor);
        self::assertStringContainsString('scheduleServerAutosave', $editor);
    }

    public function test_toolbar_and_command_layer_block_mutations(): void
    {
        $toolbar = $this->readAddon('resources/js/components/BlockFormatToolbar.jsx');
        $runtimeToolbar = $this->readAddon('resources/js/editor/runtime/RuntimeToolbarCommandButtons.jsx');
        $commandCtx = $this->readAddon('resources/js/utils/editorCommands/editorCommandContext.js');
        $core = $this->readAddon('resources/js/editor/modules/core/index.js');

        self::assertStringContainsString('mutationLocked', $toolbar);
        self::assertStringContainsString('canMutateEditor()', $toolbar);
        self::assertStringContainsString('mutationLocked', $runtimeToolbar);
        self::assertStringContainsString('isRegistryMutationEnabled', $runtimeToolbar);
        self::assertStringContainsString('EDITOR_COMMAND_CODES.READ_ONLY', $commandCtx);
        self::assertStringContainsString('!context.writable', $commandCtx);
        self::assertStringContainsString('requiresWritable: true', $core);
        self::assertStringContainsString('mutation: true', $core);
    }

    public function test_cta_link_media_ai_faq_blocked_when_locked(): void
    {
        $cta = $this->readAddon('resources/js/components/CtaContactInsertList.jsx');
        $editor = $this->readAddon('resources/js/components/SeoArticleEditor.jsx');
        $ai = $this->readAddon('resources/js/editor/host/hooks/useEditorAi.js');
        $faq = $this->readAddon('resources/js/components/ArticleFaqEditor.jsx');
        $media = $this->readAddon('resources/js/editor/host/SharedMediaPicker.jsx');
        $featured = $this->readAddon('resources/js/editor/modules/featured/FeaturedSidebarPanel.jsx');

        self::assertStringContainsString('if (!canMutateEditor())', $cta);
        self::assertStringContainsString('dispatchCtaInsert', $cta);
        self::assertStringContainsString("assertWritableEditorSession('editor_read_only')", $editor);
        self::assertStringContainsString("assertWritableEditorSession('link_insert_blocked')", $editor);
        self::assertStringContainsString("assertWritableEditorSession('image_insert_blocked')", $editor);
        self::assertStringContainsString('if (!canMutateEditor()) return false', $ai);
        self::assertStringContainsString('canGenerateImage: !sessionReadOnly', $editor);
        self::assertStringContainsString('if (!canMutateEditor())', $faq);
        self::assertStringContainsString('const readOnly = !canMutateEditor()', $media);
        self::assertStringContainsString('media.canMutate()', $featured);
    }

    public function test_keyboard_mutation_shortcuts_blocked_copy_allowed(): void
    {
        $editor = $this->readAddon('resources/js/components/SeoArticleEditor.jsx');

        self::assertStringContainsString("if (key === 'c')", $editor);
        self::assertStringContainsString("['z', 'y', 'b', 'i', 'u', 'k'].includes(key)", $editor);
        self::assertStringContainsString('sessionReadOnly || window.__SEO_EDITOR_READ_ONLY__ || !canMutateEditor()', $editor);
    }

    public function test_retry_manual_only_takeover_permission_gated(): void
    {
        $shell = $this->readAddon('resources/js/article-editor.jsx');

        self::assertStringContainsString('onRetry={() => { void runAcquire(); }}', $shell);
        self::assertStringNotContainsString('setInterval', $shell);
        self::assertStringContainsString('Boolean(canTakeover || lockInfo?.can_takeover)', $shell);
        self::assertStringContainsString('Phiên chỉnh sửa hiện tại sẽ bị thu hồi', $shell);
        self::assertStringContainsString('client.takeover(documentVersion)', $shell);
        self::assertStringContainsString('applyClientState(client)', $shell);
    }

    public function test_archived_project_hides_retry_and_takeover(): void
    {
        $shell = $this->readAddon('resources/js/article-editor.jsx');

        self::assertStringContainsString("code === 'content_project_archived'", $shell);
        self::assertStringContainsString('const showRetry = typeof onRetry === \'function\' && !archived && !conflict', $shell);
        self::assertStringContainsString('!archived && !conflict && Boolean(canTakeover', $shell);
        self::assertStringContainsString('editor_archived_body', $shell);
    }

    public function test_lock_shell_independent_of_runtime_modules_and_keeps_local_draft(): void
    {
        $shell = $this->readAddon('resources/js/article-editor.jsx');
        $editor = $this->readAddon('resources/js/components/SeoArticleEditor.jsx');
        $css = $this->readAddon('resources/css/article-editor.css');
        $i18n = $this->readAddon('resources/js/utils/i18n.js');

        self::assertStringContainsString('EditorSessionLockBanner', $shell);
        self::assertStringContainsString('seo-editor-session-shell', $shell);
        self::assertStringContainsString('data-seo-editor-hard-readonly="1"', $shell);
        self::assertStringContainsString('.seo-editor-hard-lock-bar', $css);
        self::assertStringContainsString('editor_locked_title', $i18n);
        self::assertStringContainsString('Article is locked', $i18n);
        self::assertStringContainsString('Bài viết đang bị khóa', $i18n);
        self::assertStringContainsString('Locked session: keep local draft', $editor);
        self::assertStringContainsString('hardReadonly', $editor);
        self::assertStringContainsString('After lock → writable (retry/takeover)', $editor);
        self::assertStringNotContainsString('clearDraft(articleId, connHash, scope);', $this->extractLockedDraftBranch($editor));
    }

    private function extractLockedDraftBranch(string $editor): string
    {
        $start = strpos($editor, 'Locked session: keep local draft');
        self::assertNotFalse($start);
        $end = strpos($editor, "if (decision === 'restore_local' && draft)", $start);
        self::assertNotFalse($end);

        return substr($editor, $start, $end - $start);
    }
}
