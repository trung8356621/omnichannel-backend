<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Support\SeoProjectRunItemsDisplayPresenter;
use Tests\TestCase;

final class SeoProjectRunItemsDisplayPresenterTest extends TestCase
{
    private SeoProjectRunItemsDisplayPresenter $presenter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->presenter = new SeoProjectRunItemsDisplayPresenter;
    }

    public function test_one_attempt_yields_one_row_without_rerun_badge(): void
    {
        $rows = $this->presenter->consolidate([[
            'task_id' => 10,
            'article_id' => 100,
            'status' => 'success',
            'message' => 'Đã chạy quy trình và tạo/cập nhật bài. · AI xong 6 / bỏ qua 1 / lỗi 0.',
            'last_run_at' => '2026-07-17 10:00:00',
            'retry_count' => 0,
        ]]);

        $this->assertCount(1, $rows);
        $this->assertSame(10, (int) $rows[0]['task_id']);
        $this->assertSame(0, (int) $rows[0]['retry_count']);
        $this->assertStringNotContainsString('Chạy lại', (string) $rows[0]['message']);
    }

    public function test_three_attempts_same_task_become_one_row_with_rerun_two(): void
    {
        $rows = $this->presenter->consolidate([
            [
                'task_id' => 123,
                'article_id' => 50,
                'status' => 'failed',
                'message' => 'Lỗi lần 1. · AI xong 1 / bỏ qua 0 / lỗi 1.',
                'last_run_at' => '2026-07-17 10:00:00',
                'retry_count' => 0,
            ],
            [
                'task_id' => 123,
                'article_id' => 50,
                'status' => 'failed',
                'message' => 'Lỗi lần 2. · AI xong 2 / bỏ qua 0 / lỗi 1.',
                'last_run_at' => '2026-07-17 11:00:00',
                'retry_count' => 1,
            ],
            [
                'task_id' => 123,
                'article_id' => 50,
                'status' => 'success',
                'message' => 'Đã chạy quy trình và tạo/cập nhật bài. · AI xong 6 / bỏ qua 1 / lỗi 0.',
                'last_run_at' => '2026-07-17 12:00:00',
                'retry_count' => 2,
            ],
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame(123, (int) $rows[0]['task_id']);
        $this->assertSame(2, (int) $rows[0]['retry_count']);
        $this->assertSame('success', (string) $rows[0]['status']);
        $this->assertSame('2026-07-17 12:00:00', (string) $rows[0]['last_run_at']);
        $this->assertStringContainsString('AI xong 6 / bỏ qua 1 / lỗi 0', (string) $rows[0]['message']);
        $this->assertStringNotContainsString('AI xong 9', (string) $rows[0]['message']);
        $this->assertStringContainsString('Chạy lại 2 lần', (string) $rows[0]['message']);
    }

    public function test_string_and_int_task_id_duplicates_merge(): void
    {
        $rows = $this->presenter->consolidate([
            [
                'task_id' => '77',
                'status' => 'failed',
                'last_run_at' => '2026-07-17 09:00:00',
                'message' => 'Fail.',
            ],
            [
                'task_id' => 77,
                'status' => 'success',
                'article_id' => 9,
                'last_run_at' => '2026-07-17 10:00:00',
                'message' => 'OK. · AI xong 3 / bỏ qua 0 / lỗi 0.',
                'retry_count' => 1,
            ],
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame(77, (int) $rows[0]['task_id']);
        $this->assertSame(9, (int) $rows[0]['article_id']);
        $this->assertSame(1, (int) $rows[0]['retry_count']);
    }

    public function test_same_keyword_different_task_ids_stay_separate(): void
    {
        $rows = $this->presenter->consolidate([
            [
                'task_id' => 1,
                'source_content' => 'cùng keyword',
                'status' => 'success',
                'last_run_at' => '2026-07-17 10:00:00',
            ],
            [
                'task_id' => 2,
                'source_content' => 'cùng keyword',
                'status' => 'success',
                'last_run_at' => '2026-07-17 11:00:00',
            ],
        ]);

        $this->assertCount(2, $rows);
    }

    public function test_retry_task_id_pending_shadow_merges_into_parent(): void
    {
        $rows = $this->presenter->consolidate([
            [
                'task_id' => 10,
                'article_id' => 200,
                'status' => 'failed',
                'retry_task_id' => 99,
                'message' => 'Failed once.',
                'last_run_at' => '2026-07-17 08:00:00',
                'retry_count' => 0,
            ],
            [
                'task_id' => 99,
                'status' => 'pending',
                'source_content' => 'same keyword',
                'message' => '',
            ],
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame(10, (int) $rows[0]['task_id']);
        $this->assertSame('failed', (string) $rows[0]['status']);
        $this->assertSame(200, (int) $rows[0]['article_id']);
        $this->assertSame(99, (int) $rows[0]['retry_task_id']);
    }

    public function test_latest_failed_after_success_is_not_hidden(): void
    {
        $rows = $this->presenter->consolidate([
            [
                'task_id' => 5,
                'article_id' => 1,
                'status' => 'success',
                'last_run_at' => '2026-07-17 10:00:00',
                'message' => 'OK. · AI xong 6 / bỏ qua 0 / lỗi 0.',
                'retry_count' => 0,
            ],
            [
                'task_id' => 5,
                'article_id' => 1,
                'status' => 'failed',
                'last_run_at' => '2026-07-17 12:00:00',
                'message' => 'Boom. · AI xong 2 / bỏ qua 0 / lỗi 1.',
                'retry_count' => 1,
            ],
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame('failed', (string) $rows[0]['status']);
        $this->assertStringContainsString('AI xong 2 / bỏ qua 0 / lỗi 1', (string) $rows[0]['message']);
        $this->assertSame('2026-07-17 12:00:00', (string) $rows[0]['last_run_at']);
    }

    public function test_article_only_identity_is_safe(): void
    {
        $rows = $this->presenter->consolidate([[
            'task_id' => 0,
            'article_id' => '888',
            'status' => 'success',
            'message' => 'Legacy row.',
            'last_run_at' => '2026-07-16 01:00:00',
            'article_edit_url' => '/seo/articles/888/edit',
        ]]);

        $this->assertCount(1, $rows);
        $this->assertSame(888, (int) $rows[0]['article_id']);
        $this->assertSame('/seo/articles/888/edit', (string) $rows[0]['article_edit_url']);
        $this->assertSame(0, (int) $rows[0]['retry_count']);
    }

    public function test_same_article_id_links_different_task_ids(): void
    {
        $rows = $this->presenter->consolidate([
            [
                'task_id' => 11,
                'article_id' => 500,
                'status' => 'failed',
                'last_run_at' => '2026-07-17 09:00:00',
                'message' => 'Old.',
            ],
            [
                'task_id' => 22,
                'article_id' => 500,
                'status' => 'success',
                'last_run_at' => '2026-07-17 10:00:00',
                'message' => 'New. · AI xong 4 / bỏ qua 0 / lỗi 0.',
                'retry_count' => 1,
                'article_edit_url' => '/edit/500',
            ],
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame(500, (int) $rows[0]['article_id']);
        $this->assertSame('success', (string) $rows[0]['status']);
        $this->assertSame('/edit/500', (string) $rows[0]['article_edit_url']);
    }

    public function test_does_not_mutate_input_arrays_identity(): void
    {
        $raw = [[
            'task_id' => 1,
            'status' => 'success',
            'last_run_at' => '2026-07-17 10:00:00',
            'retry_count' => 0,
            'message' => 'OK.',
        ]];

        $before = json_encode($raw);
        $this->presenter->consolidate($raw);

        $this->assertSame($before, json_encode($raw));
    }
}
