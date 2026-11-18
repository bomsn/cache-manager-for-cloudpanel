<?php
namespace CacheManagerForCloudPanel;

/**
 * The plugin's inline SVG icons, drawn on the 20 px grid Dashicons use so they
 * sit beside core icons at the same size.
 *
 * Icons are decorative: the text next to each one carries its meaning, so the
 * markup is hidden from assistive technology and never takes focus.
 */
final class Icons
{
    /** Icon name => SVG content, stroked in the current text colour. */
    private const PATHS = [
        // Cache layers (Layer::icon()).
        'layers' => '<path d="M10 2.75 2.75 6.5 10 10.25l7.25-3.75z"/><path d="m2.75 10 7.25 3.75L17.25 10"/><path d="m2.75 13.5 7.25 3.75 7.25-3.75"/>',
        'cloud' => '<path d="M5.75 15.75h8.5a3.25 3.25 0 0 0 .35-6.48 4.75 4.75 0 0 0-9.1-1.02 3.75 3.75 0 0 0 .25 7.5z"/>',
        'database' => '<ellipse cx="10" cy="4.75" rx="6.25" ry="2.25"/><path d="M3.75 4.75v10.5c0 1.24 2.8 2.25 6.25 2.25s6.25-1 6.25-2.25V4.75"/><path d="M3.75 10c0 1.24 2.8 2.25 6.25 2.25s6.25-1 6.25-2.25"/>',
        'brush' => '<path d="m17.25 2.75-6.5 6.5"/><path d="M9.6 8.4c-1.5-.6-3.2.1-3.8 1.7-.6 1.7-.8 3.7-3.05 4.4 2.6 1.9 6.3 1.4 7.75-1.1.9-1.6.6-3.6-.9-5z"/>',
        // States: status tones (good, warning, neutral) and results (success, skipped, error).
        'check' => '<circle cx="10" cy="10" r="7.25"/><path d="m6.75 10.25 2.25 2.25 4.25-4.5"/>',
        'warning' => '<path d="M8.7 3.5a1.5 1.5 0 0 1 2.6 0l6.1 10.75a1.5 1.5 0 0 1-1.3 2.25H3.9a1.5 1.5 0 0 1-1.3-2.25z"/><path d="M10 7.75v3.5"/><circle cx="10" cy="13.9" r="1" fill="currentColor" stroke="none"/>',
        'minus' => '<circle cx="10" cy="10" r="7.25"/><path d="M6.75 10h6.5"/>',
        'error' => '<circle cx="10" cy="10" r="7.25"/><path d="m7.5 7.5 5 5m0-5-5 5"/>',
        // Details and actions.
        'clock' => '<circle cx="10" cy="10" r="7.25"/><path d="M10 6.25V10l2.5 1.75"/>',
        'info' => '<circle cx="10" cy="10" r="7.25"/><path d="M10 9.25v4.5"/><circle cx="10" cy="6.6" r="1" fill="currentColor" stroke="none"/>',
        'refresh' => '<path d="M16.25 10a6.25 6.25 0 0 1-11.1 3.95"/><path d="M3.75 10a6.25 6.25 0 0 1 11.1-3.95"/><path d="M15.25 2.75v3.5h-3.5"/><path d="M4.75 17.25v-3.5h3.5"/>',
        'pulse' => '<path d="M2.75 10h3l2-5 4 10 2-5h3.5"/>',
        'page' => '<path d="M11.25 2.75H5.5a1 1 0 0 0-1 1v12.5a1 1 0 0 0 1 1h9a1 1 0 0 0 1-1V7z"/><path d="M11.25 2.75V7h4.25"/><path d="M7.5 10.75h5M7.5 13.75h3"/>',
        'sliders' => '<path d="M3 6h8.25M14.75 6H17M3 14h2.25M8.75 14H17"/><circle cx="13" cy="6" r="1.75"/><circle cx="7" cy="14" r="1.75"/>',
        'external' => '<path d="M11.5 3.25h5.25V8.5"/><path d="m16.75 3.25-7 7"/><path d="M14.25 11.5v4.25a1 1 0 0 1-1 1h-9a1 1 0 0 1-1-1v-9a1 1 0 0 1 1-1H8.5"/>',
    ];

    /** Icon for each status tone and result status, so a state never relies on colour alone. */
    private const STATES = [
        'good' => 'check',
        'success' => 'check',
        'warning' => 'warning',
        'neutral' => 'minus',
        'skipped' => 'minus',
        'error' => 'error',
    ];

    /** Whether $name is one of the icons. */
    public static function exists(string $name): bool
    {
        return isset(self::PATHS[$name]);
    }

    /** Inline SVG for $name, or '' when there is no such icon. */
    public static function svg(string $name, string $class = ''): string
    {
        if (!self::exists($name)) {
            return '';
        }
        return sprintf(
            '<svg class="%s" width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%s</svg>',
            esc_attr(trim('cmcp-icon ' . $class)),
            self::PATHS[$name]
        );
    }

    /** The icon for a tone or result status (see STATES), tagged with that state for colouring. */
    public static function state(string $state): string
    {
        return self::svg(self::STATES[$state] ?? 'minus', 'cmcp-icon--' . $state);
    }
}
