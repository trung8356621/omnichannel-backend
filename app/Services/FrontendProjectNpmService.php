<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\FrontendProject;
use Illuminate\Support\Facades\Process;

final class FrontendProjectNpmService
{
    /**
     * Đọc scripts từ package.json của project.
     *
     * @return array<string, string> [ 'script_name' => 'command', ... ]
     */
    public function getScripts(FrontendProject $project): array
    {
        $dir = $project->getAbsolutePath();
        $file = $dir . DIRECTORY_SEPARATOR . 'package.json';
        if (!is_file($file)) {
            return [];
        }
        $json = @file_get_contents($file);
        if ($json === false) {
            return [];
        }
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['scripts']) || !is_array($data['scripts'])) {
            return [];
        }
        return $data['scripts'];
    }

    /**
     * Chạy lệnh npm (install, run <script>, ...).
     *
     * @return array{success: bool, output: string, error: string}
     */
    public function runCommand(FrontendProject $project, string $command, int $timeout = 300): array
    {
        $dir = $project->getAbsolutePath();
        if (!is_dir($dir) || !is_file($dir . DIRECTORY_SEPARATOR . 'package.json')) {
            return [
                'success' => false,
                'output'  => '',
                'error'   => 'Thư mục hoặc package.json không tồn tại.',
            ];
        }

        $args = $this->parseCommand($command);
        if ($args === null) {
            return [
                'success' => false,
                'output'  => '',
                'error'   => 'Lệnh không hợp lệ.',
            ];
        }

        $result = Process::path($dir)
            ->timeout($timeout)
            ->run($args);

        return [
            'success' => $result->successful(),
            'output'  => $result->output(),
            'error'   => $result->errorOutput(),
        ];
    }

    /**
     * Chạy lệnh và gọi callback với từng chunk output (stdout + stderr) để stream real-time.
     *
     * @param callable(string): void $onChunk
     * @return array{success: bool, exit_code: int|null}
     */
    public function runCommandStreaming(FrontendProject $project, string $command, int $timeout, callable $onChunk): array
    {
        $dir = $project->getAbsolutePath();

        // Debug: in ra thư mục và câu lệnh trước khi chạy
        $onChunk("[DEBUG] Thư mục: " . $dir . "\n");
        $onChunk("[DEBUG] Lệnh: " . $command . "\n");

        if (!is_dir($dir) || !is_file($dir . DIRECTORY_SEPARATOR . 'package.json')) {
            $onChunk("[Lỗi] Thư mục hoặc package.json không tồn tại.\n");
            return ['success' => false, 'exit_code' => null];
        }

        $args = $this->parseCommand($command);
        if ($args === null) {
            $onChunk("[Lỗi] Lệnh không hợp lệ.\n");
            return ['success' => false, 'exit_code' => null];
        }

        $onChunk("[DEBUG] Exec: " . implode(' ', array_map(function ($a) {
            return str_contains($a, ' ') ? '"' . str_replace('"', '\\"', $a) . '"' : $a;
        }, $args)) . "\n");
        $onChunk(str_repeat('-', 60) . "\n");

        $process = Process::path($dir)->timeout($timeout)->start($args);

        while ($process->running()) {
            $out = $process->latestOutput();
            $err = $process->latestErrorOutput();
            if ($out !== '') {
                $onChunk($out);
            }
            if ($err !== '') {
                $onChunk($err);
            }
            usleep(50_000); // 50ms
        }

        // Đọc phần output còn lại sau khi process kết thúc
        $out = $process->latestOutput();
        $err = $process->latestErrorOutput();
        if ($out !== '') {
            $onChunk($out);
        }
        if ($err !== '') {
            $onChunk($err);
        }

        $result = $process->wait();
        $exitCode = $result->exitCode();
        $onChunk("\n--- Exit code: " . $exitCode . " ---\n");

        return [
            'success' => $exitCode === 0,
            'exit_code' => $exitCode,
        ];
    }

    /**
     * Chạy lệnh trong nền (dùng cho npm run dev).
     *
     * @return array{success: bool, message: string}
     */
    public function runInBackground(FrontendProject $project, string $scriptName): array
    {
        $dir = $project->getAbsolutePath();
        if (!is_dir($dir) || !is_file($dir . DIRECTORY_SEPARATOR . 'package.json')) {
            return ['success' => false, 'message' => 'Thư mục hoặc package.json không tồn tại.'];
        }

        $process = Process::path($dir)
            ->timeout(null)
            ->start(['npm', 'run', $scriptName]);

        return [
            'success' => true,
            'message' => "Đã khởi chạy \"npm run {$scriptName}\" trong nền (PID: {$process->id}). Kiểm tra terminal để xem log.",
        ];
    }

    /**
     * @return list<string>|null
     */
    private function parseCommand(string $command): ?array
    {
        $command = trim($command);
        if ($command === '') {
            return null;
        }
        if($command === 'debug') {
            return ['where', 'npm'];
        }
        if (strtolower($command) === 'install' || $command === 'npm install') {
            return ['npm', 'install'];
        }
        if (str_starts_with(strtolower($command), 'npm run ')) {
            $script = trim(substr($command, 8));
            return $script !== '' ? ['npm', 'run', $script] : null;
        }
        if (str_starts_with(strtolower($command), 'run ')) {
            $script = trim(substr($command, 4));
            return $script !== '' ? ['npm', 'run', $script] : null;
        }
        if (preg_match('/^npm\s+/i', $command)) {
            $rest = trim(preg_replace('/^npm\s+/i', '', $command));
            if ($rest === '') {
                return null;
            }
            return array_merge(['npm'], array_filter(explode(' ', $rest)));
        }
        return ['npm', $command];
    }
}
