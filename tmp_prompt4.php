<?php

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$p = App\Addons\SeoContentAi\Models\SeoPrompt::query()->with('aiConnection')->find(4);
if (!$p) {
    echo "prompt 4 not found\n";
    exit(1);
}

echo 'name=' . $p->name . "\n";
echo 'tools=' . ($p->tools ?? 'null') . "\n";
echo 'connection=' . ($p->aiConnection?->name ?? 'null') . "\n";
echo 'provider=' . ($p->aiConnection?->provider ?? 'null') . "\n";
echo 'has_sub_tasks=' . (app(App\Addons\SeoContentAi\Services\PromptRunnerService::class)->hasDependentSubTasks($p) ? 'yes' : 'no') . "\n";
echo 'markdown_first_200=' . mb_substr((string) $p->markdown_content, 0, 200) . "\n";

$r = App\Addons\SeoContentAi\Models\PromptResult::query()->where('prompt_id', 4)->orderByDesc('id')->first();
if ($r) {
    $snap = is_array($r->input_snapshot) ? $r->input_snapshot : json_decode((string) $r->input_snapshot, true);
    echo 'last_result_tools=' . ($snap['tools'] ?? '?') . "\n";
    echo 'last_result_chain=' . ($snap['chain_mode'] ?? 'no') . "\n";
    echo 'last_output_prefix=' . mb_substr((string) $r->output_text, 0, 80) . "\n";
    $vars = is_array($snap['variables'] ?? null) ? $snap['variables'] : [];
    echo 'last_input_var=' . mb_substr((string) ($vars['input'] ?? ''), 0, 120) . "\n";
}
