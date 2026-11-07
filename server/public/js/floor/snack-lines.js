/**
 * card#12785 — THE FOOD THOUGHTS (FLOOR.md § 6.6 item 4, § 12's *Snack lines*): what a BUSY walker thinks on its way to
 * the snack corner, in the thought bubble it carries. The operator's request of 2026-11-02, verbatim on the card: *"If
 * they go when busy, their thought bubble should have a witty comment relating to food"*, and the operator's ruling the
 * same day on the seat's draft (card#12785's comment 15002): *"Your thought bubbles texts are better than mine. Remove #1
 * and #2 thoughts and replace with your own. Generate more so that there are a total of 50 to randomly choose from"*.
 * The lines are that comment's list, verbatim and in its order.
 *
 * ⛔ THIS IS THE ONE HOME OF THE LINES. The scene picks one per break by the break's own seeded draw
 * (`scene.js`'s `snackBreak()`, `draw(key, 'snack-line:' + start) mod` this list's length), so every viewer and every
 * reload show one line for one break; nothing else holds a copy, and AT-D3-32 reads the list from here.
 *
 * ⛔ A LINE IS NEVER A FACT. No line names a seat, a task, a tool or a time, and none is ever drawn on a desk: it rides
 * the walker of a seat or an intern whose held state is `working` while that walker is away on its break, and nothing
 * else. The desk keeps its own facts at the desk — the chip, the monitor and the task bubble.
 */
export const SNACK_LINES = Object.freeze([
    'My mug is looking suspiciously empty.',
    'Is that fresh coffee I smell?',
    'Refuel, then refactor.',
    'Just one more donut. Probably.',
    'Caffeine levels: critical.',
    'Sprinkles make everything better.',
    'Coffee first, merge later.',
    'Is it donut o\'clock?',
    'Need a refill.',
    'Brain needs sugar.',
    'I\'ll think better with a cruller.',
    'The tests can wait five minutes.',
    'Hope someone made a fresh pot.',
    'Glazed or chocolate... decisions.',
    'Stretch legs, grab a cup.',
    'Snack break: approved.',
    'Running on empty.',
    'Coffee: the original build tool.',
    'A quick bite, then back to it.',
    'Does decaf even count?',
    'Save the last jelly one for me!',
    'Hydrate. Caffeinate. Repeat.',
    'Thinking about donuts again.',
    'Back in a jiffy, mug in hand.',
    'The hole is the healthiest part.',
    'Fueling up for the next commit.',
    'One donut per bug fixed. Seems fair.',
    'Brb, consulting the coffee oracle.',
    'Powdered sugar is not a crime.',
    'The coffee maker never judges me.',
    'Ship it... right after a bear claw.',
    'Low battery. Seeking donut.',
    'Espresso yourself.',
    'A latte thoughts right now.',
    'My code compiles better with crumbs.',
    'Cream and sugar, the dream team.',
    'Snack-driven development.',
    'Taking five. Maybe six.',
    'Someone said maple bar?',
    'Hot coffee, cool head.',
    'Debugging requires donuts. It\'s science.',
    'Just grabbing a quick sip.',
    'Croissant? In this economy?',
    'A Boston cream would fix this.',
    'Mug half full, thankfully.',
    'On a coffee quest.',
    'Chocolate frosting, here I come.',
    'Brewing ideas. And coffee.',
    'Short break, big donut.',
    'The coffee pot calls.',
]);
