<?php



declare(strict_types=1);



namespace App\Support;



final class RankMathSchemaParser

{

    private const ARTICLE_TYPES = [

        'article',

        'newsarticle',

        'blogposting',

        'scholarlyarticle',

        'techarticle',

    ];



    /**

     * @return array{

     *     type: 'product'|'article'|'unknown',

     *     title: string,

     *     url: string,

     *     description: string,

     *     meta: array<string, mixed>

     * }

     */

    public function parse(mixed $raw): array

    {

        $empty = $this->emptyResult();



        if ($raw === null || $raw === '') {

            return $empty;

        }



        if (is_array($raw)) {

            $data = $raw;

        } elseif (is_string($raw)) {

            $decoded = json_decode(trim($raw), true);

            if (! is_array($decoded)) {

                return $empty;

            }

            $data = $decoded;

        } else {

            return $empty;

        }



        $graph = $this->resolveGraph($data);

        if ($graph === []) {

            return $empty;

        }



        foreach ($graph as $node) {

            if (! is_array($node)) {

                continue;

            }



            if ($this->nodeIsProduct($node)) {

                return $this->mapProduct($this->hydrateProductNode($node, $graph));

            }

        }



        foreach ($graph as $node) {

            if (! is_array($node)) {

                continue;

            }



            if ($this->nodeIsArticle($node)) {

                return $this->mapArticle($node);

            }

        }



        $first = is_array($graph[0] ?? null) ? $graph[0] : [];



        return [

            'type' => 'unknown',

            'title' => $this->stringValue($first['name'] ?? $first['headline'] ?? ''),

            'url' => $this->stringValue($first['url'] ?? ''),

            'description' => $this->stringValue($first['description'] ?? ''),

            'meta' => [],

        ];

    }



    /**

     * @return array{

     *     type: 'product'|'article'|'unknown',

     *     title: string,

     *     url: string,

     *     description: string,

     *     meta: array<string, mixed>

     * }

     */

    private function emptyResult(): array

    {

        return [

            'type' => 'unknown',

            'title' => '',

            'url' => '',

            'description' => '',

            'meta' => [],

        ];

    }



    /**

     * @param  array<string, mixed>  $data

     * @return list<mixed>

     */

    private function resolveGraph(array $data): array

    {

        if (isset($data['@graph']) && is_array($data['@graph'])) {

            return array_values($data['@graph']);

        }



        if ($this->nodeHasType($data)) {

            return [$data];

        }



        return [];

    }



    /**

     * @param  list<mixed>  $graph

     * @return array<string, array<string, mixed>>

     */

    private function indexGraphById(array $graph): array

    {

        $index = [];



        foreach ($graph as $node) {

            if (! is_array($node)) {

                continue;

            }



            $id = $this->stringValue($node['@id'] ?? '');

            if ($id !== '') {

                $index[$id] = $node;

            }

        }



        return $index;

    }



    /**

     * @param  array<string, mixed>  $node

     * @param  list<mixed>  $graph

     * @return array<string, mixed>

     */

    private function hydrateProductNode(array $node, array $graph): array

    {

        $index = $this->indexGraphById($graph);



        if (isset($node['offers'])) {

            $resolved = $this->resolveNodeReference($node['offers'], $index);

            if ($resolved !== null) {

                $node['offers'] = $resolved;

            }

        }



        if (isset($node['aggregateRating'])) {

            $resolved = $this->resolveNodeReference($node['aggregateRating'], $index);

            if ($resolved !== null) {

                $node['aggregateRating'] = $resolved;

            }

        }



        if ($this->resolveOffers($node['offers'] ?? null) === null) {

            foreach ($graph as $candidate) {

                if (! is_array($candidate)) {

                    continue;

                }



                if ($this->typesContain($candidate['@type'] ?? null, ['aggregateoffer', 'offer'])) {

                    $node['offers'] = $candidate;

                    break;

                }

            }

        }



        $rating = is_array($node['aggregateRating'] ?? null) ? $node['aggregateRating'] : [];

        if ($this->toFloat($rating['ratingValue'] ?? null) === null) {

            foreach ($graph as $candidate) {

                if (! is_array($candidate)) {

                    continue;

                }



                if ($this->typesContain($candidate['@type'] ?? null, ['aggregaterating'])) {

                    $node['aggregateRating'] = $candidate;

                    break;

                }

            }

        }



        return $node;

    }



    /**

     * @param  array<string, array<string, mixed>>  $index

     * @return array<string, mixed>|null

     */

    private function resolveNodeReference(mixed $value, array $index): ?array

    {

        if (is_array($value) && $this->nodeHasType($value)) {

            return $value;

        }

        if (is_array($value) && isset($value['@id'])) {

            $nested = $this->resolveNodeReference($value['@id'], $index);

            if ($nested !== null) {

                return $nested;

            }

        }

        if (! is_string($value)) {

            return null;

        }



        $ref = trim($value);

        if ($ref === '') {

            return null;

        }



        if (isset($index[$ref]) && is_array($index[$ref])) {

            return $index[$ref];

        }



        foreach ($index as $id => $node) {

            if ($ref === $id || str_ends_with($id, $ref) || str_ends_with($ref, $id)) {

                return $node;

            }

        }



        return null;

    }



    /**

     * @param  array<string, mixed>  $node

     */

    private function nodeIsProduct(array $node): bool

    {

        return $this->typesContain($node['@type'] ?? null, ['product', 'individualproduct', 'productgroup']);

    }



    /**

     * @param  array<string, mixed>  $node

     */

    private function nodeIsArticle(array $node): bool

    {

        return $this->typesContain($node['@type'] ?? null, self::ARTICLE_TYPES);

    }



    /**

     * @param  array<string, mixed>  $data

     */

    private function nodeHasType(array $data): bool

    {

        $type = $data['@type'] ?? null;



        return is_string($type) && $type !== '' || is_array($type) && $type !== [];

    }



    /**

     * @param  list<string>  $needles

     */

    private function typesContain(mixed $type, array $needles): bool

    {

        $types = $this->normalizeTypes($type);



        foreach ($types as $value) {

            foreach ($needles as $needle) {

                if ($value === $needle || str_contains($value, $needle)) {

                    return true;

                }

            }

        }



        return false;

    }



    /**

     * @return list<string>

     */

    private function normalizeTypes(mixed $type): array

    {

        if (is_string($type)) {

            return [strtolower(trim($type))];

        }



        if (! is_array($type)) {

            return [];

        }



        $normalized = [];

        foreach ($type as $item) {

            if (is_string($item) && trim($item) !== '') {

                $normalized[] = strtolower(trim($item));

            }

        }



        return $normalized;

    }



    /**

     * @param  array<string, mixed>  $node

     * @return array{type: 'product', title: string, url: string, description: string, meta: array<string, mixed>}

     */

    private function mapProduct(array $node): array

    {

        $offers = $this->resolveOffers($node['offers'] ?? null);

        $rating = is_array($node['aggregateRating'] ?? null) ? $node['aggregateRating'] : [];



        $price = $this->extractPrice($offers);

        $availability = $this->extractAvailability($offers);



        return [

            'type' => 'product',

            'title' => $this->stringValue($node['name'] ?? ''),

            'url' => $this->stringValue($node['url'] ?? ''),

            'description' => $this->stringValue($node['description'] ?? ''),

            'meta' => [

                'price' => $price['display'],

                'price_low' => $price['low'],

                'price_high' => $price['high'],

                'price_currency' => $price['currency'],

                'rating_value' => $this->toFloat($rating['ratingValue'] ?? null),

                'review_count' => $this->toInt($rating['reviewCount'] ?? $rating['ratingCount'] ?? null),

                'availability' => $availability,

                'availability_label' => $this->formatAvailabilityLabel($availability),

            ],

        ];

    }



    /**

     * @param  array<string, mixed>  $node

     * @return array{type: 'article', title: string, url: string, description: string, meta: array<string, mixed>}

     */

    private function mapArticle(array $node): array

    {

        $title = $this->stringValue($node['headline'] ?? '');

        if ($title === '') {

            $title = $this->stringValue($node['name'] ?? '');

        }



        return [

            'type' => 'article',

            'title' => $title,

            'url' => $this->stringValue($node['url'] ?? ''),

            'description' => $this->stringValue($node['description'] ?? ''),

            'meta' => [],

        ];

    }



    /**

     * @return array{display: string, low: ?float, high: ?float, currency: string}

     */

    private function extractPrice(?array $offers): array

    {

        if ($offers === null) {

            return [

                'display' => '',

                'low' => null,

                'high' => null,

                'currency' => '',

            ];

        }



        $currency = strtoupper($this->stringValue($offers['priceCurrency'] ?? 'VND'));

        $type = strtolower($this->stringValue($offers['@type'] ?? ''));



        if ($type === 'aggregateoffer' || isset($offers['lowPrice']) || isset($offers['highPrice'])) {

            $low = $this->toFloat($offers['lowPrice'] ?? $offers['price'] ?? null);

            $high = $this->toFloat($offers['highPrice'] ?? $offers['price'] ?? null);



            if ($low !== null && $high !== null && abs($low - $high) > 0.001) {

                return [

                    'display' => $this->formatMoney($low, $currency) . ' – ' . $this->formatMoney($high, $currency),

                    'low' => $low,

                    'high' => $high,

                    'currency' => $currency,

                ];

            }



            $single = $low ?? $high;



            return [

                'display' => $single !== null ? $this->formatMoney($single, $currency) : '',

                'low' => $single,

                'high' => $single,

                'currency' => $currency,

            ];

        }



        $price = $this->toFloat($offers['price'] ?? null);



        return [

            'display' => $price !== null ? $this->formatMoney($price, $currency) : '',

            'low' => $price,

            'high' => $price,

            'currency' => $currency,

        ];

    }



    /**

     * @return array<string, mixed>|null

     */

    private function resolveOffers(mixed $offers): ?array

    {

        if (! is_array($offers)) {

            return null;

        }



        if ($this->typesContain($offers['@type'] ?? null, ['aggregateoffer', 'offer'])) {

            return $offers;

        }



        if (isset($offers[0]) && is_array($offers[0])) {

            return $offers[0];

        }



        return $offers;

    }



    private function extractAvailability(?array $offers): string

    {

        if ($offers === null) {

            return '';

        }



        return $this->stringValue($offers['availability'] ?? '');

    }



    private function formatAvailabilityLabel(string $availability): string

    {

        $availability = strtolower(trim($availability));

        if ($availability === '') {

            return '';

        }



        $slug = str_contains($availability, '/')

            ? strtolower((string) basename(str_replace('\\', '/', $availability)))

            : $availability;



        return match ($slug) {

            'instock' => 'In stock',

            'outofstock' => 'Out of stock',

            'preorder' => 'Pre-order',

            'backorder' => 'Backorder',

            'discontinued' => 'Discontinued',

            'limitedavailability' => 'Limited availability',

            default => ucfirst(str_replace(['_', '-'], ' ', $slug)),

        };

    }



    private function formatMoney(float $amount, string $currency): string

    {

        $currency = $currency !== '' ? $currency : 'VND';



        if ($currency === 'VND') {

            return number_format($amount, 0, ',', '.') . ' ₫';

        }



        return $currency . ' ' . number_format($amount, 2, '.', ',');

    }



    private function stringValue(mixed $value): string

    {

        if (is_string($value)) {

            return trim($value);

        }



        if (is_numeric($value)) {

            return (string) $value;

        }



        return '';

    }



    private function toFloat(mixed $value): ?float

    {

        if ($value === null || $value === '') {

            return null;

        }



        if (! is_numeric($value)) {

            return null;

        }



        return (float) $value;

    }



    private function toInt(mixed $value): ?int

    {

        if ($value === null || $value === '') {

            return null;

        }



        if (! is_numeric($value)) {

            return null;

        }



        return (int) round((float) $value);

    }

}


