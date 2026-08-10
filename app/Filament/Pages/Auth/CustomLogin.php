<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\User;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Facades\Filament;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Filament\Models\Contracts\FilamentUser;
use Filament\Pages\Auth\Login;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Session;

class CustomLogin extends Login
{
    public ?string $return_url = null;

    public function getHeading(): string|Htmlable
    {
        return 'Đăng nhập Omnichannel';
    }

    protected static string $view = 'filament.pages.auth.login';

    public function mount(): void
    {
        if (Filament::auth()->check()) {
            $user = Filament::auth()->user();
            if ($user instanceof User && $user->isStaff()) {
                if (SeoAccessControl::canAccessAdminAutomationPanel($user)) {
                    redirect()->intended('/admin/automation/flows');

                    return;
                }

                redirect('/');

                return;
            }

            redirect()->intended(Filament::getUrl());

            return;
        }

        $this->form->fill();

        $emailFromUrl = request()->query('email');
        if ($emailFromUrl) {
            $this->form->fill([
                'email' => $emailFromUrl,
                'remember' => true,
            ]);
        }

        $returnUrl = request()->query('return_url');
        if ($returnUrl) {
            $this->return_url = $returnUrl;
            Session::put('url.intended', $returnUrl);
        }
    }

    public function authenticate(): ?LoginResponse
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        $data = $this->form->getState();

        if (! Filament::auth()->attempt($this->getCredentialsFromFormData($data), $data['remember'] ?? false)) {
            $this->throwFailureValidationException();
        }

        $user = Filament::auth()->user();

        if ($user instanceof User && $user->isStaff()) {
            session()->regenerate();

            if (SeoAccessControl::canAccessAdminAutomationPanel($user)) {
                $this->redirect('/admin/automation/flows');

                return null;
            }

            $this->redirect('/');

            return null;
        }

        if (
            ($user instanceof FilamentUser)
            && (! $user->canAccessPanel(Filament::getCurrentPanel()))
        ) {
            Filament::auth()->logout();

            $this->throwFailureValidationException();
        }

        session()->regenerate();

        return app(LoginResponse::class);
    }

    protected function getRedirectUrl(): string
    {
        if ($this->return_url) {
            return $this->return_url;
        }

        return parent::getRedirectUrl();
    }

    public function getGoogleLoginReturnUrl(): string
    {
        if ($this->return_url) {
            return $this->return_url;
        }

        return filament()->getCurrentPanel()->getUrl();
    }
}
