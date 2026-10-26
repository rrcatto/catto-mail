import { Controller } from '@hotwired/stimulus';

/*
 * The menu bar (layout.html.twig). Its menus open on hover and keyboard focus by CSS alone;
 * this controller adds what CSS cannot: below 1100px the bar folds behind the Menu button
 * (the class js-menubar turns that on, so without JavaScript the bar stays open and wraps),
 * and Escape closes an open menu and puts the focus back on its area (the area stays closed
 * until the focus or the pointer leaves it).
 *
 *   <nav data-controller="navbar"><button data-navbar-target="toggle" data-action="navbar#toggle">…</button>…</nav>
 */
export default class extends Controller {
    static targets = ['toggle'];

    connect() {
        this.element.classList.add('js-menubar');
        this.onKey = (event) => {
            const item = document.activeElement?.closest?.('.menubar-item');
            if (event.key !== 'Escape' || !item || !this.element.contains(item)) {
                return;
            }
            item.classList.add('menu-closed');
            item.querySelector('.menubar-link')?.focus();
            const reopen = (e) => {
                if (e.type === 'mouseleave' || !item.contains(e.relatedTarget)) {
                    item.classList.remove('menu-closed');
                    item.removeEventListener('focusout', reopen);
                    item.removeEventListener('mouseleave', reopen);
                }
            };
            item.addEventListener('focusout', reopen);
            item.addEventListener('mouseleave', reopen);
        };
        this.onClick = (event) => {
            if (!this.element.contains(event.target)) {
                this.close();
            }
        };
        document.addEventListener('keydown', this.onKey);
        document.addEventListener('click', this.onClick);
    }

    disconnect() {
        document.removeEventListener('keydown', this.onKey);
        document.removeEventListener('click', this.onClick);
        this.element.classList.remove('js-menubar', 'is-open');
    }

    toggle() {
        const open = !this.element.classList.contains('is-open');
        this.element.classList.toggle('is-open', open);
        this.toggleTarget.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    close() {
        if (this.element.classList.contains('is-open')) {
            this.element.classList.remove('is-open');
            this.toggleTarget.setAttribute('aria-expanded', 'false');
        }
    }
}
