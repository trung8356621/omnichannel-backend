<?php

declare(strict_types=1);

namespace App\Forms\Components;

use App\Support\RankMathSchemaParser;
use Filament\Forms\Components\Field;
use Filament\Forms\Get;

class GoogleSerpPreview extends Field
{
    /**
     * @var view-string
     */
    protected string $view = 'filament.forms.components.google-serp-preview';

    protected ?string $titleFieldName = null;

    protected ?string $descriptionFieldName = null;

    protected ?string $urlFieldName = null;

    public function titleField(string $fieldName): static
    {
        $this->titleFieldName = $fieldName;

        return $this;
    }

    public function descriptionField(string $fieldName): static
    {
        $this->descriptionFieldName = $fieldName;

        return $this;
    }

    public function urlField(string $fieldName): static
    {
        $this->urlFieldName = $fieldName;

        return $this;
    }

    public function getTitleFieldName(): ?string
    {
        return $this->titleFieldName;
    }

    public function getDescriptionFieldName(): ?string
    {
        return $this->descriptionFieldName;
    }

    public function getUrlFieldName(): ?string
    {
        return $this->urlFieldName;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->dehydrated(false);
    }

    /**
     * @return array{
     *     type: string,
     *     title: string,
     *     url: string,
     *     description: string,
     *     meta: array<string, mixed>,
     *     display_url: string
     * }
     */
    public function getParsedPreview(?Get $get = null): array
    {
        $parsed = app(RankMathSchemaParser::class)->parse($this->getState());

        if ($get !== null) {
            if ($this->titleFieldName !== null) {
                $title = $get($this->titleFieldName);
                if (filled($title)) {
                    $parsed['title'] = (string) $title;
                }
            }

            if ($this->descriptionFieldName !== null) {
                $description = $get($this->descriptionFieldName);
                if (filled($description)) {
                    $parsed['description'] = (string) $description;
                }
            }

            if ($this->urlFieldName !== null) {
                $url = $get($this->urlFieldName);
                if (filled($url)) {
                    $parsed['url'] = (string) $url;
                }
            }
        } else {
            $livewire = $this->getLivewire();
            $data = is_object($livewire) && property_exists($livewire, 'data')
                ? (array) $livewire->data
                : [];

            if ($this->titleFieldName !== null && filled(data_get($data, $this->titleFieldName))) {
                $parsed['title'] = (string) data_get($data, $this->titleFieldName);
            }

            if ($this->descriptionFieldName !== null && filled(data_get($data, $this->descriptionFieldName))) {
                $parsed['description'] = (string) data_get($data, $this->descriptionFieldName);
            }

            if ($this->urlFieldName !== null && filled(data_get($data, $this->urlFieldName))) {
                $parsed['url'] = (string) data_get($data, $this->urlFieldName);
            }
        }

        $parsed['display_url'] = $this->formatDisplayUrl($parsed['url']);

        return $parsed;
    }

    private function formatDisplayUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return 'www.example.com';
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            return $url;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return $url;
        }

        $path = trim((string) ($parts['path'] ?? ''), '/');
        if ($path === '') {
            return $host;
        }

        $segments = array_filter(explode('/', $path));

        return $host . ' › ' . implode(' › ', $segments);
    }
}
