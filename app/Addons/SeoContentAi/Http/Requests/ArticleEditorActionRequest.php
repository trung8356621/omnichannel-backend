<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ArticleEditorActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'html' => ['required', 'string'],
            'seo_analysis' => ['nullable', 'array'],
            'publish_box' => ['nullable', 'array'],
            'publish_box.post_type' => ['nullable', 'string'],
            'publish_box.status' => ['nullable', 'string'],
            'publish_box.visibility' => ['nullable', 'string'],
            'publish_box.publish_day' => ['nullable', 'string'],
            'publish_box.publish_month' => ['nullable', 'string'],
            'publish_box.publish_year' => ['nullable', 'string'],
            'publish_box.publish_hour' => ['nullable', 'string'],
            'publish_box.publish_minute' => ['nullable', 'string'],
            'article_meta' => ['nullable', 'array'],
            'article_meta.title' => ['nullable', 'string'],
            'article_meta.slug' => ['nullable', 'string'],
            'article_meta.seo_meta_description' => ['nullable', 'string'],
            'article_meta.focus_keyword' => ['nullable', 'string'],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['integer'],
            'expected_updated_at' => ['nullable', 'string'],
            'expected_content_hash' => ['nullable', 'string'],
            'featured_image' => ['nullable', 'array'],
            'product_album' => ['nullable', 'array'],
            'faqs' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function editorBundle(): array
    {
        return $this->validated();
    }
}
