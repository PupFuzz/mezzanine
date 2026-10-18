/**
 * THE CAB's BUTTON PANEL — `docs/design/FLOOR.md` § 4.5's elevator ride (card#11795): one button per stop in
 * the order the screen hands them, the current floor lit and marked, the lobby last.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ IT DECIDES NOTHING ABOUT THE RIDE. The stops — which floors, their names, which is current, the lobby's
 * place — are the floor screen's (`frame.ride.stops`), re-derived on every frame; this module draws them and
 * hands a press back as the stop's key (`null` for the lobby). Whether a press is accepted is the screen's
 * (`choose()` answers `null` out of phase), so a button pressed in the wrong phase does nothing.
 *
 * ⛔ RECONCILED BY KEY, NEVER REBUILT. A floor added, removed or renamed changes that stop's button and no
 * other, so the button the keyboard is on keeps its focus across every frame; a focused stop the building
 * removed hands focus to the current floor's button. A rebuilt list would drop focus to the page on every
 * render.
 *
 * ⛔ ONE PRESS IS ONE CHOICE. A double click's second click is ignored (`detail > 1`), and so is a keyboard
 * activation from a key still held from before the buttons were armed (`arm()`, on `inside`): an Enter held
 * on the elevator repeats into the focused button, and a Space held there activates the button on its
 * release. Only a fresh key press — a keydown that is not a repeat — or a key released since the buttons
 * were armed can activate one from the keyboard.
 *
 * ⚠ NO FLOOR-SPECIFIC IMPORT, so the lobby's cab (card#11800) can draw its panel through this module too.
 */

/**
 * @param {HTMLElement} list the panel's stop list (`<ul>`)
 * @param {function(string|null): void} choose a press, as the stop's key — `null` the lobby
 * @returns {{reconcile: function(list<{key: string|null, name: string, current: boolean}>): void,
 *            arm: function(): void, focusCurrent: function(): void}}
 */
export function cabPanel(list, choose) {
    const doc = list.ownerDocument;
    let armed = false;

    // A key pressed fresh arms the buttons at once; a key released arms them only after its own activation
    // (a Space activates on keyup), so the release of a key held from the elevator activates nothing.
    list.addEventListener('keydown', (event) => {
        if (!event.repeat) {
            armed = true;
        }
    });
    list.addEventListener('keyup', () => {
        setTimeout(() => {
            armed = true;
        }, 0);
    });

    /** One stop's button, created once and kept for as long as the stop is offered. */
    function item(key) {
        const li = doc.createElement('li');
        const button = doc.createElement('button');
        const cap = doc.createElement('span');
        const name = doc.createElement('span');
        const here = doc.createElement('span');

        li.dataset.stop = key ?? '';
        button.type = 'button';
        cap.className = 'cab-cap';
        cap.setAttribute('aria-hidden', 'true');
        name.className = 'cab-name';
        here.className = 'cab-here';
        here.textContent = ' — you are here';
        button.append(cap, name, here);
        li.append(button);

        button.addEventListener('click', (event) => {
            // A keyboard activation is `detail === 0`; a pointer's is its click count.
            if (event.detail > 1 || (event.detail === 0 && !armed)) {
                return;
            }

            choose(key);
        });

        return li;
    }

    function current() {
        return list.querySelector('button[aria-current="true"]') ?? list.querySelector('button');
    }

    return {
        reconcile(stops) {
            const held = new Map([...list.children].map((li) => [li.dataset.stop, li]));
            const wanted = stops.map((stop) => stop.key ?? '');
            const focusLost = [...held.entries()].some(([key, li]) => !wanted.includes(key) && li.contains(doc.activeElement));

            for (const [key, li] of held) {
                if (!wanted.includes(key)) {
                    li.remove();
                    held.delete(key);
                }
            }

            stops.forEach((stop) => {
                const key = stop.key ?? '';
                const li = held.get(key) ?? item(stop.key);
                const button = li.firstElementChild;

                held.set(key, li);
                button.querySelector('.cab-name').textContent = stop.name;
                button.querySelector('.cab-here').hidden = !stop.current;
                li.classList.toggle('current', stop.current);

                if (stop.current) {
                    button.setAttribute('aria-current', 'true');
                } else {
                    button.removeAttribute('aria-current');
                }
            });

            // In the stops' order — moving only what is out of place, since a moved node loses focus.
            wanted.forEach((key, i) => {
                const li = held.get(key);

                if (list.children[i] !== li) {
                    list.insertBefore(li, list.children[i] ?? null);
                }
            });

            if (focusLost) {
                current()?.focus();
            }
        },

        /** The buttons have faded in (`inside`): a keyboard activation now needs a fresh press. */
        arm() {
            armed = false;
        },

        focusCurrent() {
            current()?.focus();
        },
    };
}
