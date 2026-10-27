import { Controller } from '@hotwired/stimulus';

/*
 * A sending domain's steps (owner decision 2026-10-27): while the page waits for something (a DNS
 * record to appear, the signing key to be made or switched on), it asks the server every 30
 * seconds to look again, says when it last looked, and moves on by itself when the domain's
 * stage changes. Progressive enhancement: without JavaScript the page has a Check now button.
 *
 *   <div data-controller="dnscheck" data-dnscheck-url-value="…/check" data-dnscheck-token-value="…"
 *        data-dnscheck-stage-value="added" data-dnscheck-interval-value="30000">
 *       <p data-dnscheck-target="status"></p>
 */
export default class extends Controller {
    static values = { url: String, token: String, stage: String, interval: { type: Number, default: 30000 } };
    static targets = ['status'];

    connect() {
        this.timer = setInterval(() => this.check(), this.intervalValue);
    }

    disconnect() {
        clearInterval(this.timer);
    }

    async check() {
        if (document.hidden) {
            return;
        }
        const body = new FormData();
        body.append('_token', this.tokenValue);
        let data;
        try {
            const response = await fetch(this.urlValue, { method: 'POST', body, headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!response.ok) {
                return;
            }
            data = await response.json();
        } catch {
            return;
        }
        if (data.stage && data.stage !== this.stageValue) {
            clearInterval(this.timer);
            window.location.reload();
            return;
        }
        if (this.hasStatusTarget && data.checked_at) {
            this.statusTarget.textContent = `Last looked at ${data.checked_at}${data.message ? ': ' + data.message : '.'} Looking again in ${Math.round(this.intervalValue / 1000)} seconds.`;
        }
    }
}
