<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Models;

use Illuminate\Database\Eloquent\Model;

class KeywordTag extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'keyword_tags';

    protected $guarded = [];
}
