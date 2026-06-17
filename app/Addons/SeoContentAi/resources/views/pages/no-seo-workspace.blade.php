<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SEO Workspace</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #0f172a; color: #e2e8f0; display: grid; place-items: center; min-height: 100vh; margin: 0; }
        .card { max-width: 32rem; padding: 2rem; background: #1e293b; border-radius: 1rem; box-shadow: 0 10px 40px rgba(0,0,0,.35); }
        h1 { margin: 0 0 .75rem; font-size: 1.35rem; }
        p { margin: 0; line-height: 1.6; color: #94a3b8; }
        a { color: #34d399; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Chưa có không gian làm việc SEO</h1>
        <p>
            Tài khoản của bạn chưa được cấp không gian lưu trữ SEO. Vui lòng liên hệ quản trị viên
            hoặc cấu hình tại
            <a href="{{ url('/admin/seo-database-connections') }}">Admin → SEO Database Connections</a>.
        </p>
    </div>
</body>
</html>
