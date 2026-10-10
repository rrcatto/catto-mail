import { Controller } from '@hotwired/stimulus';

/*
 * Closes a <details> menu (search, the Stop sending panel, the account menu) on a click
 * outside it or on Escape, and focuses its field when it opens. Progressive enhancement:
 * without JavaScript the menu opens and closes with its summary like any <details>.
 *
 *   <details data-controller="dropdown"><summary>…</summary><div>…</div></details>
 */
export default class extends Controller {
    connect() {
        this.onClick = (event) => {
            if (this.element.open && !this.element.contains(event.target)) {
                this.element.open = false;
            }
        };
        this.onKey = (event) => {
            if (event.key === 'Escape' && this.element.open) {
                this.element.open = false;
                this.element.querySelector('summary')?.focus();
            }
        };
        // A menu with a field (the search) puts the cursor in it when it opens.
        this.onToggle = () => {
            if (this.element.open) {
                this.element.querySelector('input:not([type="hidden"])')?.focus();
            }
        };
        document.addEventListener('click', this.onClick);
        document.addEventListener('keydown', this.onKey);
        this.element.addEventListener('toggle', this.onToggle);
    }

    disconnect() {
        document.removeEventListener('click', this.onClick);
        document.removeEventListener('keydown', this.onKey);
        this.element.removeEventListener('toggle', this.onToggle);
    }
}
