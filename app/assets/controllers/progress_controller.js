import { Controller } from '@hotwired/stimulus';

/*
 * Follows a running validation batch (specification 2.11): polls the batch's progress
 * JSON every few seconds, updates the bar and the counts, and reloads the page once the
 * validator has finished. Progressive enhancement: without JavaScript the page shows the
 * state at load time and the operator reloads it.
 *
 *   <section data-controller="progress" data-progress-url-value="/…/progress">
 *       <progress data-progress-target="bar" max="100"></progress> <span data-progress-target="text"></span>
 */
export default class extends Controller {
    static values = { url: String, interval: { type: Number, default: 4000 } };
    static targets = ['bar', 'text'];

    connect() {
        this.timer = setInterval(() => this.poll(), this.intervalValue);
    }

    disconnect() {
        clearInterval(this.timer);
    }

    async poll() {
        let data;
        try {
            const response = await fetch(this.urlValue, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!response.ok) {
                return;
            }
            data = await response.json();
        } catch {
            return;
        }
        if (this.hasBarTarget) {
            this.barTarget.value = data.percent;
        }
        if (this.hasTextTarget) {
            const c = data.counts;
            this.textTarget.textContent = `${data.done.toLocaleString()} / ${data.linked.toLocaleString()} processed (${data.percent}%) — `
                + `valid ${c.valid}, invalid ${c.invalid}, risky ${c.risky}, unknown ${c.unknown}, temporary failure ${c.temporary_failure}`;
        }
        if (!data.running) {
            clearInterval(this.timer);
            window.location.reload();
        }
    }
}
