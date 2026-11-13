// The Floors page's one script — card#13117 (plan-13117-seat-identity.md § 5.2, § 5.3). Beside each name field it
// shows the address the typed name will get and, before the save, which floor holds that address now — so a
// take-over is never a surprise and a name the save will refuse says so while it is typed. It decides nothing: the
// save is decided under the layout's lock (`App\Building\FloorEdits`) against the document it replaces, which may
// have moved since this page was rendered. The page works with this script absent.
import { floorSlug } from '../floor/floor-slug.js';

/** The Unassigned floor's address, which no floor may take (`App\Building\FloorSlug::RESERVED`). */
const RESERVED = 'unassigned';

/**
 * What a name typed for floor `self` (its address, or `null` for a new floor) will do, against `floors` as rendered.
 *
 * @param {string} name
 * @param {list<{key: string, name: string, aliases: list<string>}>} floors
 * @param {string|null} self
 * @returns {{address: string|null, note: string}}
 */
export function addressNote(name, floors, self) {
    const address = floorSlug(name);

    if (address === '') {
        return { address: null, note: 'no address: a floor\'s name must contain a letter or digit a–z 0–9 once accents are folded' };
    }

    if (address === RESERVED) {
        return { address, note: `/floor/${address} is the Unassigned floor's, and the save will be refused` };
    }

    for (const floor of floors) {
        if (floor.key === self) {
            continue;
        }

        if (floor.key === address) {
            return { address, note: `/floor/${address} is floor ${floor.name}'s address, and the save will be refused` };
        }

        if (floor.aliases.includes(address)) {
            return { address, note: `/floor/${address} is a former address of floor ${floor.name}: this floor takes it over, and its links open this floor` };
        }
    }

    return { address, note: address === self ? `/floor/${address}, as now` : `/floor/${address}` };
}

if (typeof document !== 'undefined') {
    const root = document.querySelector('[data-floors]');
    const floors = root === null ? [] : JSON.parse(root.dataset.floors);

    for (const input of document.querySelectorAll('input[data-floor-name]')) {
        const output = document.getElementById(input.dataset.floorName);
        const self = input.dataset.floorSelf || null;
        const show = () => { output.textContent = addressNote(input.value, floors, self).note; };

        input.addEventListener('input', show);
        show();
    }
}
