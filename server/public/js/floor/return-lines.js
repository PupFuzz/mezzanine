/**
 * card#13521 — THE RETURN THOUGHTS (FLOOR.md § 6.6 item 4, § 6.7 item 4, § 12's *Return lines*): what a walker thinks on
 * its walk back to its desk, in the thought bubble it carried out — one list for the walk back from the snack corner, one
 * for the walk back from the restroom. The operator's request, verbatim on the card: *"That bubble text should change
 * when they are returning to their desk. Generate some random relevant thoughts do display that make sense on their way
 * back to the desk."*
 *
 * ⛔ THIS IS THE ONE HOME OF THE LINES. The scene picks one per break by the break's own seeded draw (`scene.js`'s
 * `snackBreak()`, `draw(key, 'snack-return-line:' + start) mod` the first list's length, and `restroomBreak()`,
 * `draw(key, 'restroom-return-line:' + start) mod` the second's), so every viewer and every reload show one line for one
 * walk back; nothing else holds a copy, and AT-D3-32 and AT-D3-33 read the lists from here.
 *
 * ⛔ A LINE IS NEVER A FACT, as `snack-lines.js`'s are not: no line names a seat, a task, a tool or a time, none is ever
 * drawn on a desk, and a line rides only a walker that carried a thought on its way out. A restroom line stays tasteful.
 */
export const SNACK_RETURN_LINES = Object.freeze([
    'Refueled and ready to ship.',
    'Okay, brain. Back to work.',
    'Caffeine deployed.',
    'That donut fixed nothing. Worth it.',
    'Crumbs brushed off. Focus on.',
    'Mug full, mind clear.',
    'Sugar rush: engaged.',
    'Now, where was I?',
    'Fresh coffee, fresh eyes.',
    'Back to the bugs, well fed.',
    'Powered by sprinkles.',
    'That hit the spot.',
    'Recharged. Bring it on.',
    'No regrets about that second donut.',
    'Warm mug, warm thoughts.',
    'Snack break: complete.',
    'Back with a full tank.',
    'Coffee in hand, code in mind.',
    'I can see clearly now.',
    'Nothing a cruller couldn\'t fix.',
    'Ready to refactor the world.',
    'Okay, that was a great donut.',
    'Let\'s get this build green.',
    'Hope nobody saw me take two.',
    'Full mug, empty plate.',
    'Glazed, amazed and ready.',
    'Feeling sprinkled with genius.',
    'Back to it, one sip at a time.',
    'Fueled up. Let\'s do this.',
    'Brew-tiful. Back to the grind.',
]);

export const RESTROOM_RETURN_LINES = Object.freeze([
    'Hands washed, mind refreshed.',
    'Much better. Where was I?',
    'Ready to dive back in.',
    'A fresh start, literally.',
    'Back to the keyboard.',
    'Stretched my legs. Feeling good.',
    'Clean hands, clear head.',
    'Refreshed and ready.',
    'That walk cleared my head.',
    'Had a good idea on the way back!',
    'Best ideas come mid-hallway.',
    'Back to business.',
    'Splashed some water. Wide awake.',
    'All set. Onward!',
    'Okay, back in the zone.',
    'Recharged and ready to roll.',
    'Now, about that bug...',
    'The mirror says: you got this.',
    'Feeling brand new.',
    'Quick reset, back to work.',
    'Soap, rinse, back to work.',
    'Fresh as a daisy.',
    'Back to it, mind on track.',
    'Pause button released.',
    'Break over. Game face on.',
    'Rested, reset, ready.',
    'Back to solving puzzles.',
    'That hallway is great for thinking.',
    'Okay, where did I leave off?',
    'Feeling fresh. Let\'s ship it.',
]);
