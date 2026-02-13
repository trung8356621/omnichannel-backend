<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\FrontendProject;
use App\Services\FrontendProjectNpmService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

class FrontendNpmCommandsPage extends Page
{
    protected static ?string $slug = 'frontend/npm-commands';

    protected static string $view = 'filament.pages.frontend-npm-commands';

    protected static ?string $navigationIcon = 'heroicon-o-command-line';

    protected static ?string $navigationGroup = 'React/Next';

    protected static ?string $navigationLabel = 'Lệnh NPM';

    protected static bool $shouldRegisterNavigation = true;

    public ?int $projectId = null;

    public ?FrontendProject $project = null;

    /** @var array<string, string> */
    public array $scripts = [];

    public bool $running = false;

    public ?string $lastOutput = null;

    public ?string $lastError = null;

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->role === 'admin';
    }

    public function mount(): void
    {
        $this->projectId = (int) request()->query('project_id');
        $this->loadProject();
    }

    public function updatedProjectId(): void
    {
        $this->loadProject();
    }

    public function getTitle(): string|Htmlable
    {
        return $this->project
            ? 'Lệnh NPM: ' . $this->project->name
            : 'Quản lý lệnh NPM';
    }

    public function getHeading(): string|Htmlable
    {
        return $this->getTitle();
    }

    public function loadProject(): void
    {
        if (!$this->projectId || (int) $this->projectId <= 0) {
            $this->project = null;
            $this->scripts = [];
            return;
        }
        $this->project = FrontendProject::find((int) $this->projectId);
        if (!$this->project) {
            $this->scripts = [];
            return;
        }
        
        $this->scripts = ["debug" => "debug",...app(FrontendProjectNpmService::class)->getScripts($this->project)];
    }

    public function runNpmInstall(): void
    {
        $this->runCommandWithStream('npm install', 900); // 15 phút cho npm install
    }

    public function runScript(string $scriptName): void
    {
        $this->runCommandWithStream('npm run ' . $scriptName, 120);
    }

    public function runScriptInBackground(string $scriptName): void
    {
        if (!$this->project) {
            return;
        }
        $this->running = true;
        try {
            $result = app(FrontendProjectNpmService::class)->runInBackground($this->project, $scriptName);
            Notification::make()
                ->title($result['success'] ? 'Đã khởi chạy' : 'Lỗi')
                ->body($result['message'])
                ->success($result['success'])
                ->danger(!$result['success'])
                ->send();
        } catch (\Throwable $e) {
            Notification::make()->title('Lỗi: ' . $e->getMessage())->danger()->send();
        } finally {
            $this->running = false;
        }
    }

    /**
     * Chạy lệnh và stream output real-time ra terminal (wire:stream).
     */
    private function runCommandWithStream(string $command, int $timeout): void
    {
        if (!$this->project) {
            Notification::make()->title('Chưa chọn project')->danger()->send();
            return;
        }
        $this->running = true;
        set_time_limit(0); // Bỏ giới hạn 300s để npm install / build chạy đủ lâu
        try {
            $header = '> ' . $command . "\n";
            $this->stream(to: 'terminalOutput', content: e($header), replace: true);

            $service = app(FrontendProjectNpmService::class);
            $result = $service->runCommandStreaming($this->project, $command, $timeout, function (string $chunk) {
                $this->stream(to: 'terminalOutput', content: e($chunk), replace: false);
            });

            if ($result['success']) {
                Notification::make()->title('Chạy xong')->success()->send();
            } else {
                Notification::make()->title('Lệnh thoát với mã ' . ($result['exit_code'] ?? 'null'))->danger()->send();
            }
        } catch (\Throwable $e) {
            $this->stream(to: 'terminalOutput', content: e("\n[Exception] " . $e->getMessage() . "\n"), replace: false);
            Notification::make()->title('Lỗi: ' . $e->getMessage())->danger()->send();
        } finally {
            $this->running = false;
        }
    }

    public function getProjects(): \Illuminate\Database\Eloquent\Collection
    {
        return FrontendProject::orderBy('name')->get();
    }
}
