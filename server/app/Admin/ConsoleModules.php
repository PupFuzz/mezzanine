<?php

namespace App\Admin;

/**
 * ⛔ THE CONSOLE'S MODULE LIST — ONE PLACE, BECAUSE USERS ARE THE FIRST MODULE AND NOT THE ONLY
 * ONE. Card#9070's operator direction is an admin console covering **users, agents and floors**,
 * split across three cards in dependency order; this card builds the shell and the first two
 * modules, and `card#9085` adds floors once the schema that card owns exists.
 *
 * A module is added by adding an entry HERE — the navigation, the console's landing page and the
 * "which module am I in" highlight all read this list. The alternative, and the reason this class
 * exists at all, is a nav hand-written into the layout: the next module would then be added in
 * the layout, the landing page and wherever else a link happened to be, and the three would drift
 * the first time one was missed.
 *
 * ⚠ NO MODULE IS LISTED BEFORE IT EXISTS. There is no `floors` entry with a "coming soon" flag,
 * because a nav item pointing at nothing is a statement to the operator that is false; the entry
 * arrives with the routes.
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
                'key' => 'agents',
                'label' => 'Agents',
                'route' => 'admin.agents.index',
                'blurb' => 'Seats reporting to this install: their current state, and the '
                    .'operator retirement act that takes one off the floor.',
            ],
        ];
    }
}
