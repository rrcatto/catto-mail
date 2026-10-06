import { Controller } from '@hotwired/stimulus';

/*
 * Asks for confirmation before submitting a consequential form (status changes,
 * lifting suppressions, DSN match requests). Progressive enhancement only: without
 * JavaScript the form submits normally and the server still checks CSRF and
 * permissions.
 *
 *   <form data-controller="confirm" data-confirm-message-value="Really?">
 */
export default class extends Controller {
    static values = { message: String };

    connect() {
        this.onSubmit = (event) => {
            if (!window.confirm(this.messageValue || 'Are you sure?')) {
                event.preventDefault();
            }
        };
        this.element.addEventListener('submit', this.onSubmit);
    }

    disconnect() {
        this.element.removeEventListener('submit', this.onSubmit);
    }
}
