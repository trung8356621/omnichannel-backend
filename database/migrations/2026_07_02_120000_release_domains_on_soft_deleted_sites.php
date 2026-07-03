<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! $this->tableExists('sites')) {
            return;
        }

        $trashedSites = DB::table('sites')
            ->whereNotNull('deleted_at')
            ->select(['id', 'domain'])
            ->get();

        foreach ($trashedSites as $site) {
            $domain = strtolower(trim((string) ($site->domain ?? '')));

            if ($domain === '' || str_contains($domain, '__trashed__')) {
                continue;
            }

            DB::table('sites')
                ->where('id', $site->id)
                ->update([
                    'domain' => $domain.'__trashed__'.$site->id,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Không hoàn tác: domain đã giải phóng có thể đã được gán cho site mới.
    }

    private function tableExists(string $table): bool
    {
        return DB::getSchemaBuilder()->hasTable($table);
    }
};
