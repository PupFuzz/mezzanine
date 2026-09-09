<?php

namespace App\Admin;

/**
 * ⛔ THE CONSOLE'S MODULE LIST — ONE PLACE, BECAUSE USERS ARE THE FIRST MODULE AND NOT THE ONLY
 * ONE. Card#9070's operator direction is an admin console covering **users, agents and floors**,
 * split across three cards in dependency order; card#9070 built the shell and the first two
 * modules; `card#9085` added floors with the schema that card owns.
 *
 * A module is added by adding an entry HERE — the navigation, the console's landing page and the
 * "which module am I in" highlight all read this list. The alternative, and the reason this class
 * exists at all, is a nav hand-written into the layout: the next module would then be added in
 * the layout, the landing page and wherever else a link happened to be, and the three would drift
 * the first time one was missed.
 *
 * ⚠ NO MODULE IS LISTED BEFORE IT EXISTS, because a nav item pointing at nothing is a
 * statement to the operator that is false. There was deliberately no `floors` entry with a
 * "coming soon" flag while that module was somebody else's card; the entry below arrived in
 * card#9085, in the same change as its routes.
 */
final class ConsoleModules
{
    /**
     * @return list<array{key: string, label: string, route: string, blurb: string}>
     */
    public static function all(): array
    {
        return [
            [
                'key' => 'users',
                'label' => 'Users',
                'route' => 'admin.users.index',
                'blurb' => 'Operator accounts: create, edit, and retire. Every account here can '
                    .'administer this install.',
            ],
            [
                'key' => 'floors',
                'label' => 'Floors',
                'route' => 'admin.floors.index',
                'blurb' => 'The floors this deploy renders, and the Tiled map each one takes its '
                    .'desk slots from. The map says how many desks a room has and where they '
                    .'sit; which seat sits at which desk stays derived.',
            ],
            [
                'key' => 'agents',
                'label' => 'Agents',
                'route' => 'admin.agents.index',
                'blurb' => 'Seats reporting to this install: their current state, and the '
                    .'operator retirement act that takes one off the floor.',
            ],
        ];
    }
}
