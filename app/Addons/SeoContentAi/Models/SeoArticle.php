<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Models;

use App\Addons\SeoContentAi\Models\Concerns\BelongsToOnDefaultConnection;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Bản ghi nội dung SEO trên DB addon (bảng `articles`, connection `omi_seo_ai`).
 */
class SeoArticle extends Model
{
    use BelongsToOnDefaultConnection;
    use SoftDeletes;

    protected $connection = 'omi_seo_ai';

    /** @var string Bảng vật lý là `articles` (SEO / bài viết đồng bộ). */
    protected $table = 'articles';

    protected $guarded = [];

    protected $casts = [
        'blocks' => 'array',
        'seo_score' => 'decimal:2',
        'skip_seo_score' => 'boolean',
        'is_reviewed' => 'boolean',
        'reviewed_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    public function updateTimestamps()
    {
        if ((int) ($this->wp_post_id ?? 0) > 0) {
            return $this;
        }

        return parent::updateTimestamps();
    }

    public function countsTowardSeoScore(): bool
    {
        return ! (bool) ($this->skip_seo_score ?? false);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeCountsTowardSeoScore($query)
    {
        return $query->where(function ($sub): void {
            $sub->where('skip_seo_score', false)->orWhereNull('skip_seo_score');
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsToOnDefaultConnection(Site::class, 'site_id');
    }

    public function keywords(): BelongsToMany
    {
        return $this->belongsToMany(Keyword::class, 'article_keyword', 'article_id', 'keyword_id')
            ->withPivot('weight')
            ->withTimestamps();
    }

    public function articleMetas(): HasMany
    {
        return $this->hasMany(ArticleMeta::class, 'article_id');
    }

    public function articleKeywords(): HasMany
    {
        return $this->hasMany(ArticleKeyword::class, 'article_id');
    }

    public function links(): HasMany
    {
        return $this->hasMany(SeoLink::class, 'source_article_id');
    }

    public function headings(): HasMany
    {
        return $this->hasMany(SeoArticleHeading::class, 'article_id')->orderBy('sort_order');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(SeoArticleRevision::class, 'article_id')->orderByDesc('created_at');
    }

    public function faqs(): HasMany
    {
        return $this->hasMany(SeoFaq::class, 'article_id')->orderBy('sort_order');
    }

    /**
     * @return list<array{question: string, answer: string, more?: string}>
     */
    public function resolveFaqs(): array
    {
        if ($this->relationLoaded('faqs')) {
            return $this->faqsToArray($this->faqs);
        }

        if (SeoFaq::query()->where('article_id', $this->id)->exists()) {
            return $this->faqsToArray(
                $this->faqs()->orderBy('sort_order')->get()
            );
        }

        $legacy = $this->articleMetas()
            ->where('meta_key', 'seo_article_faqs')
            ->value('meta_value');

        if (! is_string($legacy) || $legacy === '') {
            return [];
        }

        $decoded = json_decode($legacy, true);

        if (! is_array($decoded)) {
            return [];
        }

        $faqs = [];
        foreach ($decoded as $item) {
            if (! is_array($item)) {
                continue;
            }
            $question = trim((string) ($item['question'] ?? ''));
            $answer = trim((string) ($item['answer'] ?? ''));
            if ($question === '' || $answer === '') {
                continue;
            }
            $more = trim((string) ($item['more'] ?? ''));
            $row = ['question' => $question, 'answer' => $answer];
            if ($more !== '') {
                $row['more'] = $more;
            }
            $faqs[] = $row;
        }

        return $faqs;
    }

    /**
     * @param  iterable<int, SeoFaq>  $faqs
     * @return list<array{question: string, answer: string, more?: string}>
     */
    private function faqsToArray(iterable $faqs): array
    {
        $result = [];
        foreach ($faqs as $faq) {
            $row = [
                'question' => (string) $faq->question,
                'answer' => (string) $faq->answer,
            ];
            $more = trim((string) ($faq->more ?? ''));
            if ($more !== '') {
                $row['more'] = $more;
            }
            $result[] = $row;
        }

        return $result;
    }

    /**
     * @return array{
     *   internal: array<int, array{href:string,text:string,is_nofollow:bool}>,
     *   external: array<int, array{href:string,text:string,is_nofollow:bool}>
     * }
     */
    public function resolveExtractedLinks(): array
    {
        if ($this->relationLoaded('links')) {
            return $this->linksToExtractedArray($this->links);
        }

        if (SeoLink::query()->where('source_article_id', $this->id)->exists()) {
            return $this->linksToExtractedArray(
                $this->links()->with('keywords')->orderBy('id')->get()
            );
        }

        return $this->resolveExtractedLinksFromLegacyMeta();
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, SeoLink>|\Illuminate\Support\Collection<int, SeoLink>  $links
     * @return array{
     *   internal: array<int, array{href:string,text:string,is_nofollow:bool}>,
     *   external: array<int, array{href:string,text:string,is_nofollow:bool}>
     * }
     */
    private function linksToExtractedArray($links): array
    {
        $internal = [];
        $external = [];

        foreach ($links as $link) {
            $row = [
                'href' => (string) $link->url,
                'text' => (string) $link->anchorText(),
                'is_nofollow' => (bool) $link->is_nofollow,
            ];

            if ($link->type === 'external') {
                $external[] = $row;
            } else {
                $internal[] = $row;
            }
        }

        return ['internal' => $internal, 'external' => $external];
    }

    /**
     * @return array{internal: array<int, mixed>, external: array<int, mixed>}
     */
    private function resolveExtractedLinksFromLegacyMeta(): array
    {
        $raw = null;

        if ($this->relationLoaded('articleMetas')) {
            $meta = $this->articleMetas->firstWhere('meta_key', 'seo_extracted_links');
            $raw = $meta?->meta_value;
        } else {
            $raw = $this->articleMetas()
                ->where('meta_key', 'seo_extracted_links')
                ->value('meta_value');
        }

        if (! is_string($raw) || trim($raw) === '') {
            return ['internal' => [], 'external' => []];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return ['internal' => [], 'external' => []];
        }

        return [
            'internal' => is_array($decoded['internal'] ?? null) ? $decoded['internal'] : [],
            'external' => is_array($decoded['external'] ?? null) ? $decoded['external'] : [],
        ];
    }

    public function getInternalLinkCountAttribute(): int
    {
        if (array_key_exists('internal_link_count', $this->attributes) && $this->attributes['internal_link_count'] !== null) {
            return (int) $this->attributes['internal_link_count'];
        }

        return count($this->resolveExtractedLinks()['internal']);
    }

    public function getExternalLinkCountAttribute(): int
    {
        if (array_key_exists('external_link_count', $this->attributes) && $this->attributes['external_link_count'] !== null) {
            return (int) $this->attributes['external_link_count'];
        }

        return count($this->resolveExtractedLinks()['external']);
    }
}
