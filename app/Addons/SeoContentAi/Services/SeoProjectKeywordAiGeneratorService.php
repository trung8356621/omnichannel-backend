<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Exceptions\PromptRunException;
use App\Addons\SeoContentAi\Models\SeoPrompt;
use Carbon\Carbon;

final class SeoProjectKeywordAiGeneratorService
{
    public function __construct(
        private readonly PromptRunnerService $promptRunner,
        private readonly SeoCreateArticleSettingsService $workflowSettings,
        private readonly SeoProjectKeywordListParser $keywordParser,
    ) {}

    /**
     * @return list<string>
     */
    public function generate(
        Carbon|string $month,
        int $count,
        string $brief = '',
        string $description = '',
    ): array {
        $promptId = $this->workflowSettings->getProjectKeywordsPromptId();
        if ($promptId === null) {
            throw new \InvalidArgumentException(
                'Chưa cấu hình Prompt AI từ khóa dự án. Vào SEO → Tùy chỉnh → Quy trình.',
            );
        }

        $prompt = SeoPrompt::query()->where('is_active', true)->find($promptId);
        if ($prompt === null) {
            throw new \InvalidArgumentException('Prompt AI từ khóa dự án không tồn tại hoặc đã tắt.');
        }

        $carbonMonth = Carbon::parse($month)->startOfMonth();
        $count = max(1, min($count, $carbonMonth->daysInMonth));

        $variables = [
            'project_month' => $carbonMonth->format('m/Y'),
            'project_month_label' => $carbonMonth->translatedFormat('F Y'),
            'days_in_month' => (string) $carbonMonth->daysInMonth,
            'keyword_count' => (string) $count,
            'project_description' => trim($description) !== '' ? trim($description) : '(không có)',
            'user_brief' => trim($brief) !== '' ? trim($brief) : '(không có)',
        ];

        try {
            $result = $this->promptRunner->run($prompt, $variables);
        } catch (PromptRunException $exception) {
            throw new \InvalidArgumentException($exception->getMessage(), 0, $exception);
        }

        $keywords = $this->extractKeywordsFromAiOutput((string) ($result->output_text ?? ''));

        if ($keywords === []) {
            throw new \InvalidArgumentException(
                'AI không trả về từ khóa hợp lệ. Prompt nên yêu cầu mỗi từ khóa một dòng (hoặc JSON mảng chuỗi).',
            );
        }

        return array_slice($keywords, 0, $count);
    }

    /**
     * @return list<string>
     */
    private function extractKeywordsFromAiOutput(string $output): array
    {
        $output = trim($output);
        if ($output === '') {
            return [];
        }

        if (str_starts_with($output, '[')) {
            $decoded = json_decode($output, true);
            if (is_array($decoded)) {
                $flat = [];
                array_walk_recursive($decoded, static function (mixed $value) use (&$flat): void {
                    if (is_string($value) && trim($value) !== '') {
                        $flat[] = trim($value);
                    }
                });

                if ($flat !== []) {
                    return array_values(array_unique($flat));
                }
            }
        }

        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $output, $matches)) {
            $fromFence = $this->extractKeywordsFromAiOutput(trim($matches[1]));
            if ($fromFence !== []) {
                return $fromFence;
            }
        }

        $fromLines = $this->keywordParser->parse($output);
        if ($fromLines !== []) {
            return $fromLines;
        }

        $fromMarkdown = app(WorkflowParserService::class)->parseKeywords($output);
        $flat = [];
        foreach ($fromMarkdown as $items) {
            foreach ($items as $item) {
                $item = trim((string) $item);
                if ($item !== '') {
                    $flat[] = $item;
                }
            }
        }

        return array_values(array_unique($flat));
    }
}
