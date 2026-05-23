<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services;

use App\Addons\SeoContentAi\Exceptions\PromptRunException;
use App\Addons\SeoContentAi\Models\SeoPrompt;
use App\Addons\SeoContentAi\Models\SeoPromptPart;
use Anthropic;

final class AiExecutionService
{
    /**
     * Thực thi gọi API Claude thông qua mozex/anthropic-laravel.
     *
     * @param  array<string, string>  $variables
     * @return array{0: string, 1: array<string, mixed>|null}
     */
    public function executeClaude(
        SeoPrompt $prompt,
        ?string $inputData = null,
        bool $isTaskMode = true,
        array $variables = [],
        ?string $modelOverride = null,
    ): array {
        $prompt->loadMissing(['parts', 'aiConnection']);

        $connection = $prompt->aiConnection;
        if ($connection === null || $connection->provider !== 'claude') {
            throw new PromptRunException('Không tìm thấy kết nối Claude hợp lệ cho Prompt này.');
        }

        if ($connection->status !== 'active') {
            throw new PromptRunException('Kết nối AI đang tắt hoặc không khả dụng.');
        }

        if (blank($connection->api_key)) {
            throw new PromptRunException('Kết nối AI chưa có API Key.');
        }

        $apiKey = $connection->api_key;
        $modelName = trim((string) ($modelOverride ?? ''));
        if ($modelName === '') {
            $modelName = trim((string) ($connection->default_model ?? ''));
        }
        if ($modelName === '') {
            $modelName = 'claude-3-5-sonnet-20240620';
        }

        $client = Anthropic::factory()->withApiKey($apiKey)->make();

        $parts = $prompt->parts()->orderBy('position')->get();

        $systemInstructions = [];
        $userMessages = [];

        foreach ($parts as $part) {
            $block = $this->buildPartBlock($part, $variables);
            if ($block === '') {
                continue;
            }

            $type = strtolower((string) $part->role);

            if ($isTaskMode) {
                if ($type === 'task') {
                    $userMessages[] = $block;
                } else {
                    $systemInstructions[] = $block;
                }
            } else {
                $userMessages[] = $block;
            }
        }

        if ($userMessages === [] && $systemInstructions === []) {
            throw new PromptRunException('Prompt không có nội dung thành phần nào.');
        }

        if (! empty($inputData)) {
            $userMessages[] = "DỮ LIỆU ĐẦU VÀO CẦN XỬ LÝ:\n" . $inputData;
        }

        if ($userMessages === []) {
            throw new PromptRunException('Prompt không có khối nhiệm vụ (task) để gửi tới Claude.');
        }

        $payload = [
            'model' => $modelName,
            'max_tokens' => 4096,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => implode("\n\n---\n\n", $userMessages),
                ],
            ],
        ];

        if ($isTaskMode && $systemInstructions !== []) {
            $payload['system'] = implode("\n\n", $systemInstructions);
        }

        try {
            $response = $client->messages()->create($payload);

            $text = collect($response->content)
                ->filter(static fn ($chunk): bool => $chunk->type === 'text' && filled($chunk->text))
                ->map(static fn ($chunk): string => (string) $chunk->text)
                ->implode("\n");

            if ($text === '') {
                throw new PromptRunException('Claude không trả về nội dung.');
            }

            return [$text, $response->usage->toArray()];
        } catch (PromptRunException $exception) {
            throw $exception;
        } catch (\Throwable $th) {
            throw new PromptRunException('Lỗi API Claude: ' . $th->getMessage(), (int) $th->getCode(), $th);
        }
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function buildPartBlock(SeoPromptPart $part, array $variables): string
    {
        $content = trim($this->substituteVariables((string) $part->content, $variables));
        if ($content === '') {
            return '';
        }

        $blockTitle = filled($part->name) ? strtoupper((string) $part->name) . ":\n" : '';

        $meta = is_array($part->meta) ? $part->meta : [];
        $formatExtra = '';
        if (isset($meta['format']) && trim((string) $meta['format']) !== '') {
            $formatExtra = "\nFormat yêu cầu: " . $this->substituteVariables((string) $meta['format'], $variables);
        }

        $rules = trim((string) ($meta['rules'] ?? ''));
        if ($rules !== '') {
            $formatExtra .= "\nQuy tắc:\n" . $this->substituteVariables($rules, $variables);
        }

        return $blockTitle . $content . $formatExtra;
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function substituteVariables(string $text, array $variables): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/',
            static function (array $matches) use ($variables): string {
                $key = $matches[1];

                return array_key_exists($key, $variables)
                    ? (string) $variables[$key]
                    : $matches[0];
            },
            $text,
        );
    }
}
