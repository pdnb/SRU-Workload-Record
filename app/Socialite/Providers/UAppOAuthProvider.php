<?php

namespace App\Socialite\Providers;

use GuzzleHttp\RequestOptions;
use Illuminate\Support\Arr;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\ProviderInterface;
use Laravel\Socialite\Two\User;

class UAppOAuthProvider extends AbstractProvider implements ProviderInterface
{
    protected $scopeSeparator = ' ';

    protected $scopes = [];

    protected function getAuthUrl($state): string
    {
        return $this->buildAuthUrlFromBase(config('services.uapp.authorize_url'), $state);
    }

    protected function getTokenUrl(): string
    {
        return config('services.uapp.token_url');
    }

    protected function getUserByToken($token): array
    {
        $response = $this->getHttpClient()->get(config('services.uapp.userinfo_url'), [
            RequestOptions::HEADERS => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer '.$token,
            ],
        ]);

        return json_decode((string) $response->getBody(), true);
    }

    protected function mapUserToObject(array $user): User
    {
        $name = Arr::get($user, 'name');

        if (! $name) {
            $givenName = Arr::get($user, 'first_name_th', '');
            $familyName = Arr::get($user, 'last_name_th', '');
            $name = trim($givenName.' '.$familyName) ?: null;
        }

        $email = Arr::get($user, 'email') ?? Arr::get($user, 'username');

        return (new User)->setRaw($user)->map([
            'id' => Arr::get($user, 'sub'),
            'nickname' => Arr::get($user, 'nickname'),
            'name' => $name,
            'email' => $email,
            'avatar' => Arr::get($user, 'picture'),
        ]);
    }
}
