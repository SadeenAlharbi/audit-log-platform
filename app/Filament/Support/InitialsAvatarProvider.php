<?php

namespace App\Filament\Support;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Avatars drawn locally as an SVG data URI. Filament's default sends every
 * user's name to ui-avatars.com; an audit platform must not leak who uses
 * it to a third party, and must work without internet access.
 */
class InitialsAvatarProvider implements AvatarProvider
{
    public function get(Model|Authenticatable $record): string
    {
        $initials = collect(preg_split('/\s+/u', trim(Filament::getNameForDefaultAvatar($record))) ?: [])
            ->map(fn (string $word) => mb_substr((string) preg_replace('/^[^\p{L}\p{N}]+/u', '', $word), 0, 1))
            ->filter()
            ->take(2)
            ->implode('');

        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64">'
            .'<rect width="64" height="64" fill="#0f766e"/>'
            .'<text x="50%%" y="50%%" dy=".35em" text-anchor="middle" font-family="sans-serif" font-size="26" fill="#fff">%s</text></svg>',
            htmlspecialchars(mb_strtoupper($initials !== '' ? $initials : '?'), ENT_XML1),
        );

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
