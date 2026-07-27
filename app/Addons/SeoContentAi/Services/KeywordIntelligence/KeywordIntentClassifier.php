<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Services\KeywordIntelligence;

use App\Addons\SeoContentAi\Enums\KeywordIntelligence\KeywordFunnelStage;
use App\Addons\SeoContentAi\Enums\KeywordIntelligence\KeywordSearchIntent;

/**
 * Rule-based intent classifier — AI optional via provider boundary later.
 * Manual override luôn thắng.
 */
final class KeywordIntentClassifier
{
    /**
     * @return array{
     *   primary: KeywordSearchIntent,
     *   secondary: list<string>,
     *   funnel: KeywordFunnelStage,
     *   confidence: float,
     *   source: string
     * }
     */
    public function classify(string $displayKeyword, string $normalizedKeyword): array
    {
        $text = mb_strtolower(trim($displayKeyword !== '' ? $displayKeyword : $normalizedKeyword), 'UTF-8');
        $secondary = [];

        $primary = KeywordSearchIntent::Unknown;
        $confidence = 0.35;

        $localMarkers = ['tphcm', 'tp hcm', 'hồ chí minh', 'hà nội', 'ha noi', 'đà nẵng', 'da nang', 'cần thơ', 'can tho', 'gần đây', 'near me'];
        $transactional = ['mua', 'đặt hàng', 'dat hang', 'giá', 'gia ', 'báo giá', 'bao gia', 'order', 'buy', 'pricing', 'cost'];
        $commercial = ['dịch vụ', 'dich vu', 'best', 'top', 'so sánh', 'so sanh', 'review', 'đánh giá', 'danh gia', 'agency', 'công ty', 'cong ty'];
        $navigational = ['login', 'đăng nhập', 'dang nhap', 'official', 'trang chủ', 'trang chu'];
        $informational = ['là gì', 'la gi', 'what is', 'how to', 'cách', 'cach ', 'hướng dẫn', 'huong dan', 'tips', 'checklist'];

        if ($this->containsAny($text, $localMarkers)) {
            $primary = KeywordSearchIntent::Local;
            $confidence = 0.75;
            if ($this->containsAny($text, $commercial) || $this->containsAny($text, $transactional)) {
                $secondary[] = KeywordSearchIntent::Commercial->value;
                $secondary[] = KeywordSearchIntent::Transactional->value;
            }
        } elseif ($this->containsAny($text, $transactional)) {
            $primary = KeywordSearchIntent::Transactional;
            $confidence = 0.7;
        } elseif ($this->containsAny($text, $commercial)) {
            $primary = KeywordSearchIntent::Commercial;
            $confidence = 0.68;
        } elseif ($this->containsAny($text, $navigational)) {
            $primary = KeywordSearchIntent::Navigational;
            $confidence = 0.65;
        } elseif ($this->containsAny($text, $informational)) {
            $primary = KeywordSearchIntent::Informational;
            $confidence = 0.72;
        }

        if ($primary === KeywordSearchIntent::Local && $secondary !== []) {
            $primary = KeywordSearchIntent::Mixed;
            $confidence = min(0.8, $confidence + 0.05);
            array_unshift($secondary, KeywordSearchIntent::Local->value);
            $secondary = array_values(array_unique($secondary));
        }

        $funnel = match ($primary) {
            KeywordSearchIntent::Informational => KeywordFunnelStage::Awareness,
            KeywordSearchIntent::Commercial => KeywordFunnelStage::Consideration,
            KeywordSearchIntent::Transactional, KeywordSearchIntent::Local => KeywordFunnelStage::Decision,
            KeywordSearchIntent::Mixed => KeywordFunnelStage::Consideration,
            default => KeywordFunnelStage::Unknown,
        };

        return [
            'primary' => $primary,
            'secondary' => $secondary,
            'funnel' => $funnel,
            'confidence' => $confidence,
            'source' => 'rule',
        ];
    }

    /**
     * @param  list<string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
