<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Tests\Unit;

use App\Addons\SeoContentAi\Enums\ArticleReviewActionType;
use App\Addons\SeoContentAi\Enums\ArticleReviewStatus;
use App\Addons\SeoContentAi\Models\SeoArticle;
use App\Addons\SeoContentAi\Services\ArticleReviewService;
use App\Addons\SeoContentAi\Services\Exceptions\ArticleReviewException;
use App\Addons\SeoContentAi\Services\SeoProjectTaskEventRecorder;
use App\Addons\SeoContentAi\Services\SeoProjectTaskLifecycleService;
use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use ReflectionClass;
use Tests\TestCase;

/**
 * Structural + pure-logic tests cho ArticleReviewService (state machine submit_review →
 * approve → archive). Dùng Laravel Tests\TestCase (app boot đầy đủ để facades như Date/Carbon
 * hoạt động khi đọc attribute cast datetime) nhưng KHÔNG dùng RefreshDatabase — SeoArticle
 * model chỉ được new() (không save/query) nên không chạm DB thật.
 */
final class ArticleReviewServiceTest extends TestCase
{
    public function test_it_exposes_the_expected_public_api(): void
    {
        $ref = new ReflectionClass(ArticleReviewService::class);

        self::assertTrue($ref->hasMethod('performAction'));
        self::assertTrue($ref->hasMethod('availableActions'));
        self::assertTrue($ref->hasMethod('resolveStatus'));
        self::assertTrue($ref->hasMethod('history'));
        self::assertTrue($ref->hasMethod('toApiPayload'));
    }

    public function test_transitions_map_matches_the_documented_workflow(): void
    {
        $transitions = (new ReflectionClass(ArticleReviewService::class))->getConstant('TRANSITIONS');

        self::assertIsArray($transitions);
        self::assertSame(
            ['submit_review', 'approve', 'archive', 'reopen', 'unapprove'],
            array_keys($transitions),
        );

        self::assertSame(ArticleReviewStatus::Draft, $transitions['submit_review']['from']);
        self::assertSame(ArticleReviewStatus::PendingReview, $transitions['submit_review']['to']);
        self::assertSame(ArticleReviewStatus::PendingReview, $transitions['approve']['from']);
        self::assertSame(ArticleReviewStatus::Approved, $transitions['approve']['to']);
        self::assertSame(ArticleReviewStatus::Approved, $transitions['archive']['from']);
        self::assertSame(ArticleReviewStatus::Archived, $transitions['archive']['to']);
        self::assertSame(ArticleReviewStatus::Archived, $transitions['reopen']['from']);
        self::assertSame(ArticleReviewStatus::Approved, $transitions['reopen']['to']);
        self::assertSame(ArticleReviewStatus::Approved, $transitions['unapprove']['from']);
        self::assertSame(ArticleReviewStatus::PendingReview, $transitions['unapprove']['to']);
    }

    public function test_perform_action_signature_requires_article_user_and_action_type(): void
    {
        $params = (new ReflectionClass(ArticleReviewService::class))
            ->getMethod('performAction')
            ->getParameters();

        self::assertSame('article', $params[0]->getName());
        self::assertSame(SeoArticle::class, $params[0]->getType()?->getName());
        self::assertSame('user', $params[1]->getName());
        self::assertSame('action', $params[2]->getName());
        self::assertSame(ArticleReviewActionType::class, $params[2]->getType()?->getName());
        self::assertTrue($params[3]->allowsNull());
    }

    public function test_article_review_action_type_covers_the_workflow_actions(): void
    {
        self::assertSame(
            ['submit_review', 'approve', 'archive', 'request_changes', 'reopen', 'unapprove'],
            ArticleReviewActionType::values(),
        );

        self::assertNull(ArticleReviewActionType::tryFromString(null));
        self::assertNull(ArticleReviewActionType::tryFromString(''));
        self::assertNull(ArticleReviewActionType::tryFromString('not_a_real_action'));
        self::assertSame(ArticleReviewActionType::Approve, ArticleReviewActionType::tryFromString('approve'));
    }

    public function test_article_review_status_covers_the_workflow_states(): void
    {
        self::assertSame(
            ['draft', 'pending_review', 'approved', 'archived'],
            ArticleReviewStatus::values(),
        );

        self::assertNull(ArticleReviewStatus::tryFromString(null));
        self::assertSame(ArticleReviewStatus::Archived, ArticleReviewStatus::tryFromString('archived'));
    }

    public function test_article_review_exception_maps_codes_to_http_status(): void
    {
        self::assertSame(409, ArticleReviewException::conflict('x')->httpStatus());
        self::assertSame(403, ArticleReviewException::forbidden('x')->httpStatus());
        self::assertSame(422, ArticleReviewException::invalidTransition('x')->httpStatus());

        self::assertSame(ArticleReviewException::CODE_CONFLICT, ArticleReviewException::conflict('x')->errorCode());
        self::assertSame(ArticleReviewException::CODE_FORBIDDEN, ArticleReviewException::forbidden('x')->errorCode());
        self::assertSame(
            ArticleReviewException::CODE_INVALID_TRANSITION,
            ArticleReviewException::invalidTransition('x')->errorCode(),
        );
    }

    public function test_seo_access_control_exposes_review_permission_gates(): void
    {
        self::assertTrue(method_exists(SeoAccessControl::class, 'canSubmitArticleReview'));
        self::assertTrue(method_exists(SeoAccessControl::class, 'canApproveArticleReview'));
        self::assertTrue(method_exists(SeoAccessControl::class, 'canFinalizeArticleReview'));
    }

    /**
     * resolveStatus() không chạm DB: chỉ đọc attribute trong-bộ-nhớ của model chưa save().
     */
    public function test_resolve_status_prefers_the_stored_review_status_column(): void
    {
        $service = $this->makeService();

        $article = new SeoArticle([
            'review_status' => 'approved',
            'is_reviewed' => false,
            'content_archived_at' => null,
        ]);

        self::assertSame(ArticleReviewStatus::Approved, $service->resolveStatus($article));
    }

    public function test_resolve_status_falls_back_to_archived_when_content_archived_at_is_set(): void
    {
        $service = $this->makeService();

        $article = new SeoArticle([
            'review_status' => null,
            'is_reviewed' => true,
            'content_archived_at' => Carbon::now(),
        ]);

        self::assertSame(ArticleReviewStatus::Archived, $service->resolveStatus($article));
    }

    public function test_resolve_status_falls_back_to_approved_when_is_reviewed_is_true(): void
    {
        $service = $this->makeService();

        $article = new SeoArticle([
            'review_status' => null,
            'is_reviewed' => true,
            'content_archived_at' => null,
        ]);

        self::assertSame(ArticleReviewStatus::Approved, $service->resolveStatus($article));
    }

    public function test_resolve_status_defaults_to_draft_when_nothing_is_set(): void
    {
        $service = $this->makeService();

        $article = new SeoArticle([
            'review_status' => null,
            'is_reviewed' => false,
            'content_archived_at' => null,
        ]);

        self::assertSame(ArticleReviewStatus::Draft, $service->resolveStatus($article));
    }

    /**
     * Approved: manager (canFinalize) thấy "archive" (Hoàn tất); planner-only (canApprove,
     * không canFinalize) thấy "unapprove" (Bỏ duyệt) — hai action loại trừ lẫn nhau.
     */
    public function test_available_actions_offers_archive_to_manager_and_unapprove_to_planner_when_approved(): void
    {
        $service = $this->makeService();
        $article = new SeoArticle(['id' => 1, 'review_status' => 'approved']);

        $manager = $this->seoStaffUser(User::SEO_ROLE_MANAGER);
        $this->actingAs($manager);
        self::assertSame(
            ['archive'],
            array_column($service->availableActions($article, $manager), 'type'),
        );

        $planner = $this->seoStaffUser(User::SEO_ROLE_PLANNER);
        $this->actingAs($planner);
        self::assertSame(
            ['unapprove'],
            array_column($service->availableActions($article, $planner), 'type'),
        );
    }

    /**
     * Archived: cả manager lẫn planner đều thấy "reopen" (Đánh dấu chưa hoàn tất); content
     * manager thì không có action nào (chỉ xem badge).
     */
    public function test_available_actions_offers_reopen_when_archived_for_manager_and_planner_only(): void
    {
        $service = $this->makeService();
        $article = new SeoArticle(['id' => 2, 'review_status' => 'archived']);

        $manager = $this->seoStaffUser(User::SEO_ROLE_MANAGER);
        $this->actingAs($manager);
        self::assertSame(
            ['reopen'],
            array_column($service->availableActions($article, $manager), 'type'),
        );

        $planner = $this->seoStaffUser(User::SEO_ROLE_PLANNER);
        $this->actingAs($planner);
        self::assertSame(
            ['reopen'],
            array_column($service->availableActions($article, $planner), 'type'),
        );

        $contentManager = $this->seoStaffUser(User::SEO_ROLE_CONTENT_MANAGER);
        $this->actingAs($contentManager);
        self::assertSame([], $service->availableActions($article, $contentManager));
    }

    /**
     * ArticleReviewService giờ đòi hỏi SeoProjectTaskLifecycleService (detach task khi archive
     * — xem `ArticleReviewArchiveDetachesTaskTest`). Test này chỉ chạm resolveStatus/availableActions
     * (không gọi performAction), nên dependency thật không hit DB thật.
     */
    private function makeService(): ArticleReviewService
    {
        return new ArticleReviewService(new SeoProjectTaskLifecycleService(new SeoProjectTaskEventRecorder));
    }

    private function seoStaffUser(string $seoRole): User
    {
        return new User([
            'role' => User::ROLE_STAFF,
            'parent_id' => 10,
            'seo_role' => $seoRole,
            'status' => User::STATUS_NORMAL,
        ]);
    }

    protected function tearDown(): void
    {
        Auth::logout();

        parent::tearDown();
    }
}
