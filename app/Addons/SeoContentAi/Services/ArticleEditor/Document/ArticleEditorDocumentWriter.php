<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\ArticleEditor\Document;

use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\ArticleEditorHtmlSanitizeService;
use App\Addons\SeoContentAi\Support\ArticleEditorDocumentErrorCode;
use App\Support\RuntimeLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Atomic writer for canonical editor_document + derived body HTML.
 */
final class ArticleEditorDocumentWriter
{
    public function __construct(
        private readonly ArticleEditorDocumentSchema $schema,
        private readonly ArticleEditorDocumentHtmlIngest $ingest,
        private readonly ArticleEditorHtmlSanitizeService $htmlSanitize,
        private readonly ArticleEditorDocumentRoundTripGuard $roundTrip,
    ) {}

    public function persistenceEnabled(): bool
    {
        return $this->configBool('seo-content-ai.article_editor.json_persistence.enabled', true);
    }

    public function dualWriteEnabled(): bool
    {
        return $this->configBool('seo-content-ai.article_editor.json_persistence.dual_write', true);
    }

    public function readPreferred(): bool
    {
        return $this->configBool('seo-content-ai.article_editor.json_persistence.read_preferred', true);
    }

    public function columnsReady(SeoArticle $article): bool
    {
        try {
            $connection = $article->getConnectionName() ?: 'omi_seo_ai';

            return Schema::connection($connection)->hasColumn($article->getTable(), 'editor_document');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array{document: array<string, mixed>, hash: string, html: string, schema_version: int}
     */
    public function prepareCanonicalDocument(array $document): array
    {
        $validated = $this->schema->validate($document);
        if (! ($validated['ok'] ?? false)) {
            throw new ArticleEditorDocumentException(
                (string) ($validated['code'] ?? ArticleEditorDocumentErrorCode::INVALID),
                (string) ($validated['message'] ?? 'Invalid editor document.'),
            );
        }

        $normalized = $this->schema->normalize($document);
        $hash = $this->schema->hash($normalized);
        $html = $this->htmlSanitize->stripTransientEditorMarkup($this->schema->renderHtml($normalized));

        return [
            'document' => $normalized,
            'hash' => $hash,
            'html' => $html,
            'schema_version' => ArticleEditorDocumentSchema::CURRENT_VERSION,
        ];
    }

    /**
     * Apply JSON fields onto the model (caller still sets body + save/update).
     *
     * @param  array<string, mixed>  $document
     * @return array{document: array<string, mixed>, hash: string, html: string, schema_version: int}
     */
    public function applyCanonicalFields(SeoArticle $article, array $document, ?string $expectedHash = null): array
    {
        $prepared = $this->prepareCanonicalDocument($document);

        if ($expectedHash !== null && $expectedHash !== '') {
            $currentHash = trim((string) ($article->editor_document_hash ?? ''));
            if ($currentHash !== '' && ! hash_equals($currentHash, $expectedHash)) {
                throw new ArticleEditorDocumentException(
                    ArticleEditorDocumentErrorCode::HASH_CONFLICT,
                    'Editor document hash conflict.',
                    [
                        'expected_editor_document_hash' => $expectedHash,
                        'actual_editor_document_hash' => $currentHash,
                    ],
                );
            }
        }

        if ($this->columnsReady($article) && $this->dualWriteEnabled()) {
            $article->editor_document = $prepared['document'];
            $article->editor_document_schema_version = $prepared['schema_version'];
            $article->editor_document_hash = $prepared['hash'];
            $article->editor_document_status = ArticleEditorDocumentSchema::STATUS_CURRENT;
            $article->editor_document_updated_at = Carbon::now();
        }

        return $prepared;
    }

    public function invalidateForLegacyBodyWrite(SeoArticle $article, string $origin = 'legacy_body_writer'): void
    {
        if (! $this->columnsReady($article)) {
            return;
        }

        if ($article->editor_document === null) {
            return;
        }

        $article->editor_document_status = ArticleEditorDocumentSchema::STATUS_STALE;
        RuntimeLogger::warning('seo.editor.document_stale', [
            'article_id' => (int) $article->getKey(),
            'origin' => $origin,
        ]);
    }

    /**
     * Canonical dual-write API for system writers that already have TipTap envelope JSON.
     *
     * @param  array<string, mixed>  $document
     * @return array{document: array<string, mixed>, hash: string, html: string, schema_version: int}
     */
    public function writeCanonicalEditorDocument(
        SeoArticle $article,
        array $document,
        ?string $expectedHash = null,
        bool $persist = true,
    ): array {
        $prepared = $this->applyCanonicalFields($article, $document, $expectedHash);
        $article->body = $prepared['html'];

        if ($persist) {
            $payload = [
                'body' => $prepared['html'],
            ];
            if ($this->columnsReady($article) && $this->dualWriteEnabled()) {
                $payload['editor_document'] = $article->editor_document;
                $payload['editor_document_schema_version'] = $article->editor_document_schema_version;
                $payload['editor_document_hash'] = $article->editor_document_hash;
                $payload['editor_document_status'] = $article->editor_document_status;
                $payload['editor_document_updated_at'] = $article->editor_document_updated_at;
            }
            $article->forceFill($payload)->save();
        }

        return $prepared;
    }

    /**
     * Legacy HTML writer — updates body and marks JSON stale so bootstrap/publish cannot trust old JSON.
     */
    public function writeLegacyHtmlAndInvalidateDocument(
        SeoArticle $article,
        string $html,
        string $origin = 'legacy_body_writer',
        bool $persist = true,
    ): void {
        $article->body = $html;
        $this->invalidateForLegacyBodyWrite($article, $origin);

        if (! $persist) {
            return;
        }

        $payload = ['body' => $html];
        if ($this->columnsReady($article) && $article->isDirty('editor_document_status')) {
            $payload['editor_document_status'] = $article->editor_document_status;
        }
        $article->forceFill($payload)->save();
    }

    public function publishFromJsonEnabled(): bool
    {
        return $this->configBool('seo-content-ai.article_editor.json_persistence.publish_from_json', false);
    }

    /**
     * Pure PHPUnit has no Laravel `config` binding — fall back to defaults.
     */
    private function configBool(string $key, bool $default): bool
    {
        try {
            if (! function_exists('config')) {
                return $default;
            }

            return (bool) config($key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }

    /**
     * When publish_from_json flag on: re-render body from canonical JSON if status is current.
     * Does not mutate editor_document. Returns HTML to publish (may refresh persisted body).
     */
    public function ensureDerivedBodyForPublish(SeoArticle $article, bool $persistRefresh = true): string
    {
        $body = trim((string) ($article->body ?? ''));
        if (! $this->publishFromJsonEnabled() || ! $this->columnsReady($article)) {
            return $body;
        }

        $status = (string) ($article->editor_document_status ?? '');
        if (
            $status === ArticleEditorDocumentSchema::STATUS_STALE
            || $status === ArticleEditorDocumentSchema::STATUS_FAILED
            || $status === ArticleEditorDocumentSchema::STATUS_MANUAL_REVIEW
            || ! is_array($article->editor_document)
        ) {
            return $body;
        }

        try {
            $prepared = $this->prepareCanonicalDocument($article->editor_document);
            $rendered = trim($prepared['html']);
            if ($rendered === '') {
                return $body;
            }

            $bodyHash = hash('sha256', $body);
            $renderedHash = hash('sha256', $rendered);
            if (! hash_equals($bodyHash, $renderedHash) && $persistRefresh) {
                $article->forceFill(['body' => $rendered])->save();
                RuntimeLogger::warning('seo.editor.document_body_refreshed_for_publish', [
                    'article_id' => (int) $article->getKey(),
                    'editor_document_hash' => $prepared['hash'],
                ]);
            }

            return $rendered;
        } catch (\Throwable $exception) {
            RuntimeLogger::warning('seo.editor.document_publish_render_failed', [
                'article_id' => (int) $article->getKey(),
                'error' => $exception->getMessage(),
            ]);

            return $body;
        }
    }

    /**
     * @return array{ok: bool, status: string, document?: array<string, mixed>, html?: string, code?: string}
     */
    public function lazyMigrateFromBody(SeoArticle $article, bool $persist = true): array
    {
        $html = trim((string) ($article->body ?? ''));
        if ($html === '') {
            return ['ok' => false, 'status' => ArticleEditorDocumentSchema::STATUS_PENDING, 'code' => ArticleEditorDocumentErrorCode::INGEST_FAILED];
        }

        try {
            $envelope = $this->ingest->ingestHtmlToEnvelope($html, 'block-legacy-'.(int) $article->getKey());
            $prepared = $this->prepareCanonicalDocument($envelope);
            $guard = $this->roundTrip->compare($html, $prepared['html']);
            if (! $guard['equivalent']) {
                if ($persist && $this->columnsReady($article)) {
                    $article->forceFill([
                        'editor_document_status' => ArticleEditorDocumentSchema::STATUS_MANUAL_REVIEW,
                    ])->save();
                }

                return [
                    'ok' => false,
                    'status' => ArticleEditorDocumentSchema::STATUS_MANUAL_REVIEW,
                    'code' => ArticleEditorDocumentErrorCode::ROUNDTRIP_MISMATCH,
                    'document' => $prepared['document'],
                    'html' => $prepared['html'],
                ];
            }

            if ($persist && $this->columnsReady($article) && $this->dualWriteEnabled()) {
                $article->forceFill([
                    'editor_document' => $prepared['document'],
                    'editor_document_schema_version' => $prepared['schema_version'],
                    'editor_document_hash' => $prepared['hash'],
                    'editor_document_status' => ArticleEditorDocumentSchema::STATUS_MIGRATED,
                    'editor_document_updated_at' => Carbon::now(),
                ])->save();
            }

            return [
                'ok' => true,
                'status' => ArticleEditorDocumentSchema::STATUS_MIGRATED,
                'document' => $prepared['document'],
                'html' => $prepared['html'],
            ];
        } catch (\Throwable $exception) {
            RuntimeLogger::warning('seo.editor.document_ingest_failed', [
                'article_id' => (int) $article->getKey(),
                'error' => $exception->getMessage(),
            ]);

            return [
                'ok' => false,
                'status' => ArticleEditorDocumentSchema::STATUS_FAILED,
                'code' => ArticleEditorDocumentErrorCode::INGEST_FAILED,
            ];
        }
    }

    /**
     * @return array{source: string, document: array<string, mixed>|null, body_html: string, hash: string|null, schema_version: int|null, status: string|null}
     */
    public function resolveForBootstrap(SeoArticle $article): array
    {
        $bodyHtml = (string) ($article->body ?? '');
        $status = $article->editor_document_status ?? null;

        if (
            $this->readPreferred()
            && $this->columnsReady($article)
            && is_array($article->editor_document)
            && $status !== ArticleEditorDocumentSchema::STATUS_STALE
            && $status !== ArticleEditorDocumentSchema::STATUS_FAILED
        ) {
            $document = $article->editor_document;
            $validated = $this->schema->validate($document);
            if ($validated['ok'] ?? false) {
                return [
                    'source' => 'editor_document',
                    'document' => $this->schema->normalize($document),
                    'body_html' => $bodyHtml,
                    'hash' => (string) ($article->editor_document_hash ?? $this->schema->hash($document)),
                    'schema_version' => (int) ($article->editor_document_schema_version ?? ArticleEditorDocumentSchema::CURRENT_VERSION),
                    'status' => (string) ($status ?? ArticleEditorDocumentSchema::STATUS_CURRENT),
                ];
            }
        }

        return [
            'source' => 'body_html',
            'document' => null,
            'body_html' => $bodyHtml,
            'hash' => null,
            'schema_version' => null,
            'status' => is_string($status) ? $status : ArticleEditorDocumentSchema::STATUS_PENDING,
        ];
    }
}
