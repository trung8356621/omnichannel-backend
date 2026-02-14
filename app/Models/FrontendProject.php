<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FrontendProject extends Model
{
    protected $table = 'frontend_projects';

    protected $fillable = ['name', 'type', 'package_json_path', 'router', 'proxy_auto', 'port'];

    protected $casts = [
        'proxy_auto' => 'boolean',
    ];

    public const TYPE_NEXTJS = 'nextjs';
    public const TYPE_REACT = 'react';

    public static function typeOptions(): array
    {
        return [
            self::TYPE_NEXTJS => 'Next.js',
            self::TYPE_REACT  => 'React',
        ];
    }

    /**
     * Đường dẫn tuyệt đối tới thư mục chứa package.json.
     */
    public function getAbsolutePath(): string
    {
        $path = $this->package_json_path;
        if ($path === '') {
            return base_path();
        }
        if (str_starts_with($path, '/') || preg_match('#^[A-Za-z]:\\\\#', $path)) {
            return rtrim($path, DIRECTORY_SEPARATOR);
        }
        return base_path($path);
    }

    /**
     * Kiểm tra thư mục tồn tại và có file package.json.
     */
    public function hasValidPath(): bool
    {
        $dir = $this->getAbsolutePath();
        return is_dir($dir) && is_file($dir . DIRECTORY_SEPARATOR . 'package.json');
    }
}
