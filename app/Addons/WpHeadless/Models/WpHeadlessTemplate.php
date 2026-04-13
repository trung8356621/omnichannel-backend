<?php

declare(strict_types=1);

namespace App\Addons\WpHeadless\Models;

use Illuminate\Database\Eloquent\Model;

class WpHeadlessTemplate extends Model
{
    protected $connection = 'wp_headless';

    protected $table = 'wp_headless_templates';

    protected $fillable = [
        'site_id',
        'parent_id',
        'type',
        'template_path',
        'global',
        'template',
        'classes',
        'ids',
        'body_class',
    ];

    protected $casts = [
        'parent_id'  => 'integer',
        'global'     => 'boolean',
        'classes'    => 'array',
        'ids'        => 'array',
        'body_class' => 'array',
    ];

    /**
     * Cột template (LONGTEXT): lưu chuỗi JSON hợp lệ; null/empty → EMPTY_JSON.
     * Luôn lưu chuỗi (không cast array) để tránh "Array to string conversion" khi bind vào DB.
     */
    public function setTemplateAttribute($value): void
    {
        $this->attributes['template'] = self::normalizeTemplateValue($value);
    }

    /** Chuỗi JSON tối thiểu khi template rỗng/lỗi. */
    private const EMPTY_JSON = '{"children":[],"classes":[],"ids":[]}';

    /**
     * Chuẩn hóa giá trị trước khi ghi vào cột template (JSON). Trả về chuỗi JSON hợp lệ (không trả null).
     * null hoặc chuỗi không phải JSON → lưu EMPTY_JSON; chuỗi hợp lệ → decode rồi encode lại (chuẩn hóa).
     */
    public static function normalizeTemplateValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return self::EMPTY_JSON;
        }
        if (is_array($value)) {
            $encoded = json_encode($value, \JSON_UNESCAPED_UNICODE);
            return $encoded !== false ? $encoded : self::EMPTY_JSON;
        }
        $s = trim((string) $value);
        if ($s === '') {
            return self::EMPTY_JSON;
        }
        $s = preg_replace('/^\xEF\xBB\xBF/', '', $s);
        try {
            $decoded = json_decode($s, false, 512, \JSON_THROW_ON_ERROR);
            return json_encode($decoded, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return self::EMPTY_JSON;
        }
    }

    public function wpHeadlessSite()
    {
        return $this->belongsTo(WpHeadlessSite::class, 'site_id', 'id');
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id', 'id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id', 'id');
    }
}
