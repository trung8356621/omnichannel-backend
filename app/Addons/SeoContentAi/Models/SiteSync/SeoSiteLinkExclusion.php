<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Models\SiteSync;

use Illuminate\Database\Eloquent\Model;

class SeoSiteLinkExclusion extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_site_link_exclusions';

    protected $fillable = [
        'site_id',
        'url',
        'url_hash',
        'wordpress_id',
        'reason',
    ];
}
