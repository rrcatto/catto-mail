import { Controller } from '@hotwired/stimulus';

/*
 * A copy button beside a value a person copies somewhere else (owner decision 2026-10-27): DNS
 * record names and values, a key or secret shown once, webhook and invitation addresses. It
 * copies the source's text to the clipboard and says so on the button for a moment; where the
 * browser refuses the clipboard it selects the text instead, ready for Ctrl+C. Without
 * JavaScript the button is hidden and the value can be selected as usual.
 *
 *   <span class="copy" data-controller="copy">
 *       <code data-copy-target="source">value</code>
 *       <button type="button" class="copy-btn" data-copy-target="button" data-action="copy#copy" hidden>Copy</button>
 *   </span>
 */
export default class extends Controller {
    static targets = ['source', 'button', 'label'];

    connect() {
        this.buttonTarget.hidden = false;
    }

    disconnect() {
        clearTimeout(this.timer);
    }

    async copy() {
        const text = this.sourceTarget.textContent.trim();
        try {
            await navigator.clipboard.writeText(text);
            this.say('Copied');
        } catch {
            const range = document.createRange();
            range.selectNodeContents(this.sourceTarget);
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
            this.say('Selected: press Ctrl+C');
        }
    }

    say(words) {
        const label = this.hasLabelTarget ? this.labelTarget : this.buttonTarget;
        this.original ??= label.textContent;
        label.textContent = words;
        this.buttonTarget.classList.add('is-done');
        clearTimeout(this.timer);
        this.timer = setTimeout(() => {
            label.textContent = this.original;
            this.buttonTarget.classList.remove('is-done');
        }, 2000);
    }
}
