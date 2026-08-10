<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Database\LegacySchemaMigrationReconciler;
use PHPUnit\Framework\TestCase;

final class LegacySchemaMigrationReconcilerTest extends TestCase
{
    public function test_extracts_create_and_add_column_intent_from_source(): void
    {
        $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'omi_mig_'.uniqid('', true).'.php';
        file_put_contents($tmp, <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::connection('omi_seo_ai')->create('demo_widgets', function (Blueprint $table) {
            $table->id();
        });
        Schema::connection('omi_seo_ai')->table('articles', function (Blueprint $table) {
            $table->string('type', 50)->nullable();
        });
    }
};
PHP);

        $reconciler = new LegacySchemaMigrationReconciler;
        $ref = new \ReflectionClass($reconciler);

        $extractCreate = $ref->getMethod('extractCreateTables');
        $extractCreate->setAccessible(true);
        $extractCols = $ref->getMethod('extractAddedColumns');
        $extractCols->setAccessible(true);

        $source = (string) file_get_contents($tmp);
        self::assertSame(['demo_widgets'], $extractCreate->invoke($reconciler, $source));
        self::assertSame([['articles', 'type']], $extractCols->invoke($reconciler, $source));

        @unlink($tmp);
    }
}
