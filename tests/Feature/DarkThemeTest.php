<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Perfect dark: the dark canvas and every static surface are pure black.
 *
 * Separation comes from borders alone, so this guards three things together:
 *
 *   1. the static surface tokens in the dark block are #000000;
 *   2. the interactive fills (hover, active, selected) are NOT black - feedback
 *      that is also black is no feedback at all;
 *   3. surface-filled layers that a shadow used to lift (the modal) carry a
 *      real border, because a shadow is invisible on black.
 */
class DarkThemeTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, string> token name => raw value in the dark block */
    protected function darkTokens(): array
    {
        $css = File::get(public_path('css/base.css'));

        preg_match('/\[data-theme="dark"\]\s*\{(.*?)\n\}/s', $css, $match);

        $this->assertNotEmpty($match, 'No [data-theme="dark"] block in base.css.');

        $tokens = [];

        if (preg_match_all('/(--[a-z0-9-]+)\s*:\s*([^;]+);/i', $match[1], $found, PREG_SET_ORDER)) {
            foreach ($found as $row) {
                $tokens[trim($row[1])] ??= trim($row[2]);
            }
        }

        return $tokens;
    }

    public function test_static_surfaces_are_pure_black(): void
    {
        $tokens = $this->darkTokens();

        foreach (['--bg-app', '--bg-surface', '--bg-surface-2', '--bg-surface-3', '--bg-inset', '--bg-sidebar'] as $token) {
            $this->assertArrayHasKey($token, $tokens, "{$token} is not defined in the dark block.");

            $this->assertEquals(
                '#000000',
                strtolower($tokens[$token]),
                "{$token} must be pure black in perfect dark. Separation comes from borders, "
                .'not from lifted fills.'
            );
        }
    }

    public function test_interactive_fills_stay_lifted(): void
    {
        $tokens = $this->darkTokens();

        foreach (['--bg-hover', '--bg-active', '--bg-sidebar-hover'] as $token) {
            $this->assertArrayHasKey($token, $tokens, "{$token} is not defined in the dark block.");

            $this->assertNotEquals(
                '#000000',
                strtolower($tokens[$token]),
                "{$token} must stay lifted: momentary feedback needs a fill the canvas does not have."
            );
        }
    }

    public function test_the_modal_keeps_an_edge_in_the_dark(): void
    {
        $css = File::get(public_path('css/layout.css'));

        $this->assertMatchesRegularExpression(
            '/\[data-theme="dark"\]\s*\.modal\s*\{[^}]*border[^}]*\}/s',
            $css,
            'The modal has no dark-scoped border. Panel and backdrop are both black and a '
            .'shadow is invisible on black, so the dialog would have no edge.'
        );
    }
}
