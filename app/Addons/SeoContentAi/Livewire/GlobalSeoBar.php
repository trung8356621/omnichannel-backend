<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi\Livewire;

use App\Addons\SeoContentAi\Support\SeoAccessControl;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

class GlobalSeoBar extends Component
{
    public ?int $globalSiteId = null;

    public string $simulatedRole = '';

    public function mount(): void
    {
        $hasStoredSelection = SeoAccessControl::hasGlobalSiteSelection();
        $this->globalSiteId = SeoAccessControl::globalSiteId();

        if (
            $this->globalSiteId !== null
            && ! $this->resolveSitesQuery()->whereKey($this->globalSiteId)->exists()
        ) {
            SeoAccessControl::clearGlobalSiteSelection();
            $this->globalSiteId = null;
            $hasStoredSelection = false;
        }

        if ($this->globalSiteId === null && ! $hasStoredSelection) {
            $this->globalSiteId = $this->resolveSitesQuery()->value('id');
            if ($this->globalSiteId !== null) {
                SeoAccessControl::setGlobalSiteId($this->globalSiteId);
            }
        }

        $actualRole = SeoAccessControl::actualRole();
        $current = SeoAccessControl::normalizeRole((string) session('seo_simulated_role', $actualRole));
        $allowed = SeoAccessControl::allowedSimulationTargets($actualRole);

        $this->simulatedRole = in_array($current, $allowed, true) ? $current : $actualRole;

        session(['seo_simulated_role' => $this->simulatedRole]);
    }

    public function updatedGlobalSiteId($value): void
    {
        $siteId = filled($value) ? (int) $value : null;
        if ($siteId !== null && $siteId <= 0) {
            $siteId = null;
        }

        if ($siteId !== null && ! $this->resolveSitesQuery()->whereKey($siteId)->exists()) {
            $this->globalSiteId = SeoAccessControl::globalSiteId();

            return;
        }

        $this->globalSiteId = $siteId;
        SeoAccessControl::setGlobalSiteId($siteId);
        session()->save();

        $this->redirect($this->resolveReturnUrl(), navigate: false);
    }

    public function updatedSimulatedRole($value): void
    {
        $actualRole = SeoAccessControl::actualRole();
        $role = SeoAccessControl::normalizeRole((string) $value);
        $allowed = SeoAccessControl::allowedSimulationTargets($actualRole);

        if (! in_array($role, $allowed, true)) {
            $role = $actualRole;
        }

        $this->simulatedRole = $role;
        session(['seo_simulated_role' => $role]);
        session()->save();

        $this->redirect($this->resolveReturnUrl(), navigate: false);
    }

    public function render()
    {
        $actualRole = SeoAccessControl::actualRole();
        $allowedRoles = SeoAccessControl::allowedSimulationTargets($actualRole);

        $roleLabels = [
            SeoAccessControl::ROLE_CONTENT_MANAGER => 'Quản lý nội dung',
            SeoAccessControl::ROLE_PLANNER => 'Kế hoạch viên',
            SeoAccessControl::ROLE_MANAGER => 'Quản lý (Manager)',
        ];

        $roleOptions = [];
        foreach ($allowedRoles as $role) {
            $roleOptions[$role] = $roleLabels[$role] ?? $role;
        }

        return view('seo-content-ai::livewire.global-seo-bar', [
            'sites' => $this->resolveSitesQuery()->get(),
            'roleOptions' => $roleOptions,
        ]);
    }

    private function resolveSitesQuery(): Builder
    {
        $query = Site::query()->orderBy('domain');

        if (auth()->user()?->role !== 'admin') {
            $query->where('user_id', SeoAccessControl::accountOwnerId() ?? auth()->id());
        }

        return $query;
    }

    private function resolveReturnUrl(): string
    {
        $fallback = url('/seo');

        $referer = (string) request()->headers->get('referer', '');
        if ($referer === '') {
            return $fallback;
        }

        $path = (string) parse_url($referer, PHP_URL_PATH);
        if ($path === '' || str_starts_with($path, '/livewire/')) {
            return $fallback;
        }

        return $referer;
    }
}
