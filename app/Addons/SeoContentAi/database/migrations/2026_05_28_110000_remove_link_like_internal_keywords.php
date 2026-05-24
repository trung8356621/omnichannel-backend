<?php

declare(strict_types=1);

use App\Addons\SeoContentAi\Models\Keyword;
use App\Addons\SeoContentAi\Models\SeoArticleLink;
use App\Addons\SeoContentAi\Support\InternalAnchorKeywordFilter;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        Keyword::on($this->connection)
            ->where('type', 'internal')
            ->orderBy('id')
            ->chunkById(200, function ($keywords): void {
                $ids = [];

                foreach ($keywords as $keyword) {
                    if (InternalAnchorKeywordFilter::looksLikeUrlOrLinkLabel((string) $keyword->phrase)) {
                        $ids[] = $keyword->id;
                    }
                }

                if ($ids === []) {
                    return;
                }

                SeoArticleLink::on($this->connection)
                    ->whereIn('keyword_id', $ids)
                    ->update(['keyword_id' => null]);

                Keyword::on($this->connection)->whereIn('id', $ids)->delete();
            });
    }

    public function down(): void
    {
        // Không khôi phục keyword dạng URL đã xóa.
    }
};
