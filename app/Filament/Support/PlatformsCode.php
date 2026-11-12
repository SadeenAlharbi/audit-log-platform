<?php

namespace App\Filament\Support;

/**
 * Visual foundation of the dashboard, aligned with the Digital Government
 * Authority's "Platforms Code" (كود المنصات) - the unified design system for
 * Saudi government digital platforms.
 *
 *  - Primary: the Saudi-flag green scale (600 = #1B8354 is the primary colour,
 *    500 = #25935F, 400 = #54C08A is the primary text colour in dark mode).
 *  - Neutral: the Platforms Code gray scale (50 = page background #F9FAFB,
 *    400 = field border #9DA4AE, 900/950 = dark backgrounds).
 *  - Typeface: IBM Plex Sans Arabic for Arabic and Latin text.
 *  - Corner radius: 8px (Filament's default "lg" radius already matches).
 *
 * Filament converts every hex value to OKLCH when the panel is registered.
 * See docs/decisions.md (D12).
 */
final class PlatformsCode
{
    public const FONT = 'IBM Plex Sans Arabic';

    /** @var array<int, string> */
    public const PRIMARY = [
        50 => '#F3FCF6',
        100 => '#DFF6E7',
        200 => '#B8EACB',
        300 => '#88D8AD',
        400 => '#54C08A',
        500 => '#25935F',
        600 => '#1B8354',
        700 => '#166A45',
        800 => '#14573A',
        900 => '#104631',
        950 => '#092A1E',
    ];

    /** @var array<int, string> */
    public const GRAY = [
        50 => '#F9FAFB',
        100 => '#F3F4F6',
        200 => '#E5E7EB',
        300 => '#D2D6DB',
        400 => '#9DA4AE',
        500 => '#6C737F',
        600 => '#4D5761',
        700 => '#384250',
        800 => '#1F2A37',
        900 => '#111927',
        950 => '#0D121C',
    ];

    /** @var array<int, string> */
    public const DANGER = [
        50 => '#FEF3F2',
        100 => '#FEE4E2',
        200 => '#FECDCA',
        300 => '#FDA29B',
        400 => '#F97066',
        500 => '#F04438',
        600 => '#D92D20',
        700 => '#B42318',
        800 => '#912018',
        900 => '#7A271A',
        950 => '#55160C',
    ];

    /** @var array<int, string> */
    public const WARNING = [
        50 => '#FFFAEB',
        100 => '#FEF0C7',
        200 => '#FEDF89',
        300 => '#FEC84B',
        400 => '#FDB022',
        500 => '#F79009',
        600 => '#DC6803',
        700 => '#B54708',
        800 => '#93370D',
        900 => '#7A2E0E',
        950 => '#4E1D09',
    ];

    /** @var array<int, string> */
    public const SUCCESS = [
        50 => '#ECFDF3',
        100 => '#DCFAE6',
        200 => '#ABEFC6',
        300 => '#75E0A7',
        400 => '#47CD89',
        500 => '#17B26A',
        600 => '#079455',
        700 => '#067647',
        800 => '#085D3A',
        900 => '#074D31',
        950 => '#053321',
    ];

    /** @var array<int, string> */
    public const INFO = [
        50 => '#EFF8FF',
        100 => '#D1E9FF',
        200 => '#B2DDFF',
        300 => '#84CAFF',
        400 => '#53B1FD',
        500 => '#2E90FA',
        600 => '#1570EF',
        700 => '#175CD3',
        800 => '#1849A9',
        900 => '#194185',
        950 => '#102A56',
    ];

    /**
     * @return array<string, array<int, string>>
     */
    public static function colors(): array
    {
        return [
            'primary' => self::PRIMARY,
            'gray' => self::GRAY,
            'danger' => self::DANGER,
            'warning' => self::WARNING,
            'success' => self::SUCCESS,
            'info' => self::INFO,
        ];
    }
}
