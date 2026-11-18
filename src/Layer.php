<?php
namespace CacheManagerForCloudPanel;

/**
 * One cache the plugin can clear.
 *
 * The admin bar, the settings page, the request handler and the command line are
 * all driven from the list of layers in CacheLayers, so adding a cache is one new
 * class and one registry entry, not a branch in each of those places.
 */
interface Layer
{
    /** Name shown in menus, notices and status cards. */
    public function label(): string;

    /** Name of the icon (see Icons) shown beside the label in menus and on the settings page. */
    public function icon(): string;

    /**
     * Current state for status displays. Must not make remote requests: it runs on
     * every settings page view.
     *
     * @return array{state: string, tone: string, details: array<string, string>,
     *               meter?: array{label: string, value: int, max: int, text: string}}
     *         Tone is "good", "warning" or "neutral". The optional meter is a count
     *         drawn as a bar on the settings page, such as pages warmed; its text
     *         says the same in words for the command line and screen readers.
     */
    public function status(): array;

    /** Why this layer cannot be cleared on this site right now, or '' when it can. */
    public function unavailable(): string;

    /** Clear everything this layer holds for the site. */
    public function clear(): Result;

    /**
     * Manual actions this layer offers in the admin bar and on the settings page.
     *
     * @return array<string, string> Action ID (unique across layers) => button label.
     */
    public function actions(): array;

    /** Run one of the actions returned by actions(). */
    public function perform(string $action): Result;
}
