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
     * Có heartbeat khi npm buffer (Windows): mỗi vài giây không có output thì gửi "." để UI không treo.
     *
     * @param callable(string): void $onChunk
     * @return array{success: bool, exit_code: int|null}
     */
    public function runCommandStreaming(FrontendProject $project, string $command, int $timeout, callable $onChunk): array
    {
        $dir = $project->getAbsolutePath();

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

        $onChunk("[DEBUG] Exec: " . implode(' ', $args) . "\n");
        $onChunk(str_repeat('-', 60) . "\n");

        // Trên Windows dùng npm.cmd để tránh không tìm thấy npm
        if (str_starts_with(strtoupper(PHP_OS), 'WIN')) {
            if (isset($args[0]) && $args[0] === 'npm') {
                $args[0] = 'npm.cmd';
            }
        }

        $env = [];
        $path = getenv('PATH') ?: '';
        if (str_starts_with(strtoupper(PHP_OS), 'WIN')) {
            $nodeDir = 'C:\Program Files\nodejs';
            if (is_dir($nodeDir)) {
                $env['PATH'] = $nodeDir . ';' . $path;
            }
        }
        // Giảm buffer: npm trong CI thường flush sớm hơn
        $env['CI'] = '1';
        $env['NPM_CONFIG_LOGLEVEL'] = 'info';
        // Next.js khi chạy từ process con (Laravel) trên Windows lỗi bind 0.0.0.0:3000 → ép listen 127.0.0.1
        $env['HOST'] = '127.0.0.1';

        $builder = Process::path($dir)->timeout($timeout)->input(null);
        if ($env !== []) {
            $builder = $builder->env($env);
        }
        $process = $builder->start($args);

        $lastOutputTime = time();
        $heartbeatInterval = 2; // mỗi 2 giây không có output thì gửi heartbeat

        while ($process->running()) {
            $out = $process->latestOutput();
            $err = $process->latestErrorOutput();
            if ($out !== '') {
                $onChunk($out);
                $lastOutputTime = time();
            }
            if ($err !== '') {
                $onChunk($err);
                $lastOutputTime = time();
            }
            // Heartbeat: npm thường buffer khi tải package → gửi hint để user biết vẫn đang chạy
            if (time() - $lastOutputTime >= $heartbeatInterval) {
                $onChunk("\n[running... npm đang tải package, lần đầu có thể 2–5 phút]\n");
                $lastOutputTime = time();
            }
            usleep(200_000); // 200ms
        }

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

        $env = ['HOST' => '127.0.0.1'];
        if (str_starts_with(strtoupper(PHP_OS), 'WIN')) {
            $nodeDir = 'C:\Program Files\nodejs';
            if (is_dir($nodeDir)) {
                $env['PATH'] = $nodeDir . ';' . (getenv('PATH') ?: '');
            }
        }

        $process = Process::path($dir)
            ->timeout(null)
            ->input(null)
            ->env($env)
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
        if (strtolower($command) === 'install' || $command === 'npm install') {
            return ['npm', 'install', '--no-audit', '--no-fund', '--loglevel', 'info'];
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
