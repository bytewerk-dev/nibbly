// The library owns default clips. A placement can replace individual event rows.
class IconEventFields {
    constructor(container, translate, inherit = false, saved = {}) {
        this.container = container;
        this.translate = translate;
        this.inherit = inherit;
        this.rows = {};
        for (const [event, actions] of Object.entries(window.NB_ICON_EVENT_ACTIONS)) {
            const row = document.createElement('div');
            row.className = 'nb-icon-event';
            const caption = translate('icons.event_' + event);
            const field = (input, key, text) => {
                const label = document.createElement('label');
                label.append(text, input);
                input.dataset.playbackField = event + '-' + key;
                input.setAttribute('aria-label', key === 'action' ? caption : caption + ' · ' + text);
                row.append(label);
                return input;
            };
            const action = field(document.createElement('select'), 'action', caption);
            if (inherit) action.append(new Option('', ''));
            for (const value of actions) action.append(new Option(translate('icons.event_' + value), value));
            const bound = key => {
                const input = field(document.createElement('input'), key, translate('icons.event_' + key));
                input.type = 'number';
                input.min = '0';
                input.max = '100';
                input.step = '1';
                input.addEventListener('change', () => {
                    if (!action.value) action.value = this.rows[event].base.action;
                    input.value = IconEventFields.percent(input.value, key === 'from' ? 0 : 100);
                });
                return input;
            };
            this.rows[event] = {action, from: bound('from'), to: bound('to')};
            container.append(row);
        }
        const hint = document.createElement('small');
        hint.className = 'form-hint';
        hint.textContent = translate('icons.event_hint');
        container.append(hint);
        this.load(saved);
    }

    static percent(value, fallback) {
        if (value == null || value === '' || !Number.isFinite(Number(value))) return fallback;
        return Math.min(100, Math.max(0, Math.trunc(Number(value))));
    }

    static clipsFrom(element) {
        const clips = {};
        for (const [event, actions] of Object.entries(window.NB_ICON_EVENT_ACTIONS)) {
            const [action, from, to] = (element?.getAttribute('data-nb-' + event) || '').split(':');
            if (actions.includes(action)) clips[event] = {action, from: this.percent(from, 0), to: this.percent(to, 100)};
        }
        return clips;
    }

    load(clips = {}) {
        for (const [event, row] of Object.entries(this.rows)) {
            const clip = clips?.[event];
            row.action.value = clip?.action || (this.inherit ? '' : window.NB_ICON_EVENT_ACTIONS[event][0]);
            row.from.value = IconEventFields.percent(clip?.from, 0);
            row.to.value = IconEventFields.percent(clip?.to, 100);
        }
    }

    update(visible, defaults = {}) {
        this.container.hidden = !visible;
        if (!visible) return;
        for (const [event, row] of Object.entries(this.rows)) {
            row.base = defaults[event] || {action: window.NB_ICON_EVENT_ACTIONS[event][0], from: 0, to: 100};
            if (this.inherit) {
                const action = this.translate('icons.event_' + row.base.action);
                const caption = this.translate('icons.event_default', {value: action});
                if (row.action.options[0].textContent !== caption) row.action.options[0].textContent = caption;
                if (!row.action.value) {
                    row.from.value = row.base.from;
                    row.to.value = row.base.to;
                }
            }
            row.from.disabled = row.to.disabled = (row.action.value || row.base.action) === 'off';
        }
    }

    get value() {
        const clips = {};
        if (!this.container.hidden) {
            for (const [event, row] of Object.entries(this.rows)) {
                if (!row.action.value) continue;
                clips[event] = {action: row.action.value, from: IconEventFields.percent(row.from.value, 0),
                    to: IconEventFields.percent(row.to.value, 100)};
            }
        }
        return clips;
    }
}

// Shared by the dashboard page editor and the inline icon picker.
window.NibblyIconPlacement = class NibblyIconPlacement {
    constructor(container, modes, translate, playback = {}) {
        this.container = container;
        this.modes = modes;
        this.translate = translate;
        const select = (labelKey, key, choices) => {
            const label = document.createElement('label');
            label.append(translate(labelKey));
            const input = document.createElement('select');
            input.dataset.playbackField = key;
            input.setAttribute('aria-label', translate(labelKey));
            input.append(new Option('', ''));
            for (const [value, text] of choices) input.append(new Option(translate(text), value));
            input.value = playback?.[key] || '';
            label.append(input);
            container.append(label);
            return input;
        };
        this.mode = select('icons.playback', 'mode', modes.map(mode => [mode, 'icons.motion_' + mode]));
        this.direction = select('icons.direction', 'direction', [['forward', 'icons.direction_forward'], ['reverse', 'icons.direction_reverse']]);
        this.savedEvents = playback?.events;
        container.hidden = true;
    }

    get value() {
        const playback = {};
        if (!this.container.hidden) {
            if (this.mode.value) playback.mode = this.mode.value;
            if (this.direction.value) playback.direction = this.direction.value;
            const events = this.events?.value;
            if (events && Object.keys(events).length) playback.events = events;
        }
        return playback;
    }

    updatePreview(button, markup) {
        const svg = NibblyIconPlacement.svg(markup);
        const motion = svg.querySelector('g[data-nb-ua][data-nb-trigger]');
        this.container.hidden = !motion;
        if (motion) {
            const mode = motion.dataset.nbPlay || motion.dataset.nbTrigger;
            const direction = motion.dataset.nbReverse === 'true' ? 'reverse' : 'forward';
            const inherited = key => this.translate('icons.placement_inherit', {value: this.translate(key)});
            this.mode.options[0].textContent = inherited('icons.motion_' + mode);
            this.direction.options[0].textContent = inherited('icons.direction_' + direction);
            this.direction.disabled = (this.mode.value || mode) === 'static';
            const combined = (this.mode.value || mode) === 'events';
            if (combined && !this.events) {
                const fields = document.createElement('div');
                fields.className = 'nb-icon-events';
                this.container.append(fields);
                this.events = new IconEventFields(fields, this.translate, true, this.savedEvents);
            }
            this.events?.update(combined, combined ? IconEventFields.clipsFrom(motion) : {});
            NibblyIconPlacement.applyPlayback(svg, this.value, this.modes);
        }
        button.disabled = !motion;
        button.removeAttribute('aria-pressed');
        button.replaceChildren(svg);
    }

    static applyPlayback(svg, playback, modes) {
        if (modes?.includes(playback?.mode)) svg.dataset.nbPlay = playback.mode;
        if (['forward', 'reverse'].includes(playback?.direction)) svg.dataset.nbReverse = String(playback.direction === 'reverse');
        for (const [event, value] of Object.entries(this.eventAttributes(playback?.events))) {
            svg.setAttribute('data-nb-' + event, value);
        }
    }

    static eventAttributes(clips) {
        const attrs = {};
        if (!clips) return attrs;
        for (const [event, actions] of Object.entries(window.NB_ICON_EVENT_ACTIONS)) {
            const clip = clips[event];
            if (actions.includes(clip?.action)) attrs[event] = [clip.action, IconEventFields.percent(clip.from, 0),
                IconEventFields.percent(clip.to, 100)].join(':');
        }
        return attrs;
    }

    static svg(markup, playback, modes) {
        const template = document.createElement('template');
        template.innerHTML = markup;
        const svg = template.content.firstElementChild;
        this.applyPlayback(svg, playback, modes);
        return svg;
    }
};
