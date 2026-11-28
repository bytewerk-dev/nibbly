// Each SVG contains its static artwork. The player and an icon's JSON load when that icon first has to move.
const selector = 'g[data-nb-ua][data-nb-trigger]';
const controls = 'button, a[href], label, [role="button"], [role="switch"], [role="checkbox"]';
// Both lists are one line of JSON each. includes/content-loader.php reads them for the editors and saved placements.
// The first action of an event is its default in combined playback.
const EVENT_ACTIONS = {"intro":["once","off"],"hover":["loop","once","off"],"leave":["off","once"],"click":["toggle","once","off"]};
// The fixed modes are presets of the same four event clips. Events a preset omits are off.
const PRESETS = {"hover":{"hover":"follow","leave":"return"},"hover-replay":{"hover":"once"},"click-replay":{"click":"once"},"click-toggle":{"click":"toggle"},"loop":{"intro":"loop"},"hover-loop":{"hover":"loop"},"once":{"intro":"once"},"static":{}};
const reduced = matchMedia('(prefers-reduced-motion: reduce)');
const instances = new Map();
const sources = new Map();
const visibility = new WeakMap();
const viewport = new IntersectionObserver(entries => {
    for (const entry of entries) {
        visibility.set(entry.target, entry.isIntersecting);
        entry.target.querySelectorAll(selector).forEach(group => instances.get(group)?.show(entry.isIntersecting));
    }
});

function loadSource(id) {
    if (!sources.has(id)) {
        const url = new URL('./useanimations/' + id + '.json', import.meta.url);
        const pending = fetch(url).then(response => {
            if (!response.ok) throw Error(response.statusText);
            return response.json();
        }).catch(error => {
            sources.delete(id);
            throw error;
        });
        sources.set(id, pending);
    }
    return sources.get(id);
}

// All clips of an icon share one Lottie player. The toggle state survives temporary clips.
class Clips {
    constructor(icon) {
        this.icon = icon;
        this.clips = {};
        const preset = icon.mode === 'events' ? null : PRESETS[icon.mode] || {};
        const percent = (v, fallback) => /^\d{1,3}$/.test(v) ? Math.min(100, Number(v)) : fallback;
        for (const [event, choices] of Object.entries(EVENT_ACTIONS)) {
            let action = preset?.[event] || 'off';
            let from = 0;
            let to = 100;
            if (!preset) {
                const attr = 'data-nb-' + event;
                const [name, start, end] = (icon.svg.getAttribute(attr) ?? icon.group.getAttribute(attr) ?? '').split(':');
                action = choices.includes(name) ? name : choices[0];
                from = percent(start, 0);
                to = percent(end, 100);
            }
            // A preset toggle whose opening frame shows the active state runs the other way.
            const flip = (icon.direction < 0) !== !!(preset && action === 'toggle' && icon.startsOn);
            // A preset rests on the last frame of its clip. A combined clip returns to the idle pose.
            this.clips[event] = {action, stay: !!preset, from: flip ? to : from, to: flip ? from : to};
        }
        this.still = Object.values(this.clips).every(clip => clip.action === 'off');
        this.phase = 'idle';
        this.revision = 0;
        this.idlePercent = this.clips.intro.from;
    }

    get inside() { return this.icon.hover || this.icon.focus; }

    get active() { return this.icon.readState() ?? this.icon.on; }

    get rest() {
        const click = this.clips.click;
        return click.action === 'toggle' ? (this.active ? click.to : click.from) : this.idlePercent;
    }

    attach(player) {
        this.first = player.firstFrame;
        this.last = this.first + player.totalFrames - 1;
        player.addEventListener('complete', () => this.complete());
    }

    frame(percent) { return this.first + Math.round((this.last - this.first) * percent / 100); }

    pose(frame) {
        const p = this.icon.player;
        p.loop = false;
        p.setSegment(this.first, this.last + 1);
        p.goToAndStop(frame - this.first, true);
    }

    // "restart" plays a hover clip from its beginning, for a tap that has no pointer to follow.
    start(phase, restart = false) {
        this.continueToggle = phase === 'click' && this.phase === 'click' && this.applied === this.revision;
        this.restart = restart;
        this.phase = phase;
        this.revision++;
        this.sync();
    }

    // Returns to the idle pose without loading a player that was never needed.
    stop() {
        this.phase = 'idle';
        this.pendingLeave = false;
        this.revision++;
    }

    sync() {
        if (!this.icon.visible || document.hidden) return;
        this.icon.run(() => {
            const p = this.icon.player;
            if (this.applied === this.revision) {
                if (this.phase !== 'idle' && !reduced.matches) p.play();
                return;
            }
            this.applied = this.revision;
            if (this.phase === 'idle' || reduced.matches) return this.pose(this.frame(this.rest));
            const clip = this.clips[this.phase];
            const current = Math.min(this.last, Math.max(this.first, Math.round(p.firstFrame + p.currentRawFrame)));
            let from = this.frame(clip.from);
            let to = this.frame(clip.to);
            if (clip.action === 'toggle') {
                if (!this.active) [from, to] = [to, from];
                if (this.continueToggle) from = current;
            }
            // "follow" and "return" move from wherever the pointer left the icon.
            if (clip.action === 'return') to = this.frame(this.clips.hover.from);
            if (clip.action === 'return' || (clip.action === 'follow' && !this.restart)) from = current;
            if (from === to) {
                this.pose(to);
                this.complete();
                return;
            }
            const low = Math.min(from, to);
            p.loop = clip.action === 'loop' && (this.phase === 'intro' || this.inside);
            p.setSegment(low, Math.max(from, to) + 1);
            p.goToAndStop(from - low, true);
            p.setDirection(to > from ? 1 : -1);
            p.play();
        });
    }

    complete() {
        if (this.phase === 'idle') return;
        const finished = this.phase;
        const clip = this.clips[finished];
        // The icon holds its hover pose until the pointer leaves.
        if (clip.action === 'follow' && this.inside) return;
        if (finished !== 'hover' || clip.stay) this.idlePercent = clip.action === 'return' ? this.clips.hover.from : clip.to;
        if (this.pendingLeave && !this.inside) {
            this.pendingLeave = false;
            this.start('leave');
        } else if (finished !== 'hover' && this.inside && !this.blockHover && this.clips.hover.action !== 'off') this.start('hover');
        else this.start('idle');
    }

    show() {
        const intro = this.clips.intro.action;
        const first = !this.seen;
        this.seen = true;
        if (this.still) return;
        // A loop restarts whenever the icon is idle. A single entrance plays on the first appearance.
        if (!reduced.matches && intro !== 'off' && (intro === 'loop' ? this.phase === 'idle' : first && this.phase !== 'click')) {
            return this.start('intro');
        }
        // The bundled artwork already shows the opening frame.
        if (!this.icon.player && !this.icon.loading && this.phase === 'idle' && this.rest === 0) return;
        this.sync();
    }

    click() {
        this.pendingLeave = false;
        this.blockHover = this.inside;
        this.start(reduced.matches ? 'idle' : 'click');
    }

    pointer(inside) {
        this.blockHover = false;
        if (reduced.matches) return;
        if (this.phase === 'click' || this.phase === 'intro') {
            this.pendingLeave = !inside && this.clips.leave.action !== 'off';
            return;
        }
        const {hover, leave} = this.clips;
        const p = this.icon.player;
        // Without an exit clip a running pass finishes. A loop continues only while the pointer stays.
        if (this.phase === 'hover' && this.applied === this.revision && p && !p.isPaused
            && hover.action !== 'follow' && (inside || leave.action === 'off')) {
            p.loop = inside && hover.action === 'loop';
            return;
        }
        const event = inside ? 'hover' : 'leave';
        if (this.clips[event].action !== 'off') this.start(event);
        else if (!inside || this.phase === 'leave') this.start('idle');
    }
}

class IconPlayback {
    constructor(group) {
        this.group = group;
        this.svg = group.ownerSVGElement;
        this.id = group.dataset.nbUa;
        this.mode = this.svg.dataset.nbPlay || group.dataset.nbPlay || group.dataset.nbTrigger;
        this.direction = (this.svg.dataset.nbReverse ?? group.dataset.nbReverse) === 'true' ? -1 : 1;
        // data-nb-start="on" marks artwork whose opening frame is the active state, e.g. an open eye.
        this.startsOn = (this.svg.dataset.nbStart ?? group.dataset.nbStart) === 'on';
        const control = group.closest(controls);
        // A surrounding element can lend its hover area with data-nb-icon-host.
        this.host = control || group.closest('[data-nb-icon-host]') || this.svg;
        // Only a preview button keeps its own pressed state. Other controls own their semantics,
        // and an icon outside a control stays decorative.
        this.ownsState = this.host.hasAttribute('data-nb-preview');
        this.listeners = new AbortController();
        this.events = new Clips(this);
        const {hover: enter, leave, click: press} = this.events.clips;
        const hover = enter.action !== 'off' || leave.action !== 'off';
        const click = press.action !== 'off';
        const toggle = press.action === 'toggle';
        this.on = toggle && this.mode !== 'events' && this.startsOn;
        this.hover = false;
        this.focus = false;
        if (!control && click) this.host = this.svg;
        const options = { signal: this.listeners.signal };
        const listen = (name, fn) => this.host.addEventListener(name, fn, options);
        if (hover || click) {
            // The first sign of interest loads the player, so the animation is ready for the click.
            const warm = { signal: this.listeners.signal, once: true };
            for (const name of ['pointerenter', 'pointerdown', 'focusin']) this.host.addEventListener(name, () => this.prepare(), warm);
        }
        if (hover) {
            listen('pointerenter', e => { if (e.pointerType !== 'touch') this.hoverChange('hover', true); });
            listen('pointerleave', () => this.hoverChange('hover', false));
            listen('focusin', e => this.hoverChange('focus', e.target.matches(':focus-visible')));
            listen('focusout', e => { if (!this.host.contains(e.relatedTarget)) this.hoverChange('focus', false); });
            // A touchscreen can run an otherwise hover-only icon with a tap. Not every browser
            // reports the pointer type on the click itself.
            listen('pointerdown', e => { this.touch = e.pointerType === 'touch'; });
            listen('click', e => {
                const touch = e.pointerType ? e.pointerType === 'touch' : this.touch;
                if (!click && touch && !reduced.matches && enter.action !== 'off') this.events.start('hover', true);
            });
        }
        if (click) {
            listen('click', () => {
                if (this.host.matches(':disabled, [aria-disabled="true"]')) return;
                if (!toggle) return this.events.click();
                if (this.readState() === null || this.ownsState) {
                    this.on = !this.on;
                    if (this.ownsState) this.host.setAttribute('aria-pressed', String(this.on));
                    this.events.click();
                }
            });
        }
        if (toggle) {
            if (this.ownsState) this.host.setAttribute('aria-pressed', String(this.on));
            this.on = this.readState() ?? this.on;
            this.stateObserver = new MutationObserver(() => {
                const state = this.readState();
                if (state === null || state === this.on) return;
                this.on = state;
                this.events.click();
            });
            this.stateObserver.observe(this.host, { attributes: true, attributeFilter: ['aria-pressed', 'aria-checked', 'aria-expanded'] });
        }
    }

    readState() {
        for (const name of ['aria-pressed', 'aria-checked', 'aria-expanded']) {
            const value = this.host.getAttribute(name);
            if (value === 'true' || value === 'false') return value === 'true';
        }
        return null;
    }

    async prepare() {
        if (this.player || this.loading) return this.loading;
        const mount = document.createElement('div');
        this.loading = (async () => {
            const [{default: lottie}, data] = await Promise.all([import('./vendor/lottie-web/lottie_light.min.js'), loadSource(this.id)]);
            if (this.listeners.signal.aborted) return;
            const player = lottie.loadAnimation({ container: mount, renderer: 'svg', loop: false, autoplay: false, animationData: structuredClone(data) });
            this.pendingPlayer = player;
            await new Promise(resolve => {
                this.onDispose = resolve;
                player.addEventListener('DOMLoaded', resolve);
            });
            this.onDispose = null;
            if (this.listeners.signal.aborted) return;
            this.player = player;
            const svg = mount.firstElementChild;
            // Keep Lottie's live layer nodes and unique masks, using the surrounding Nibbly SVG as the viewport.
            for (const path of svg.querySelectorAll('path')) {
                if (path.closest('defs')) continue;
                for (const attribute of ['stroke', 'fill']) {
                    if (path.getAttribute(attribute) === 'rgb(0,0,0)') path.setAttribute(attribute, 'currentColor');
                }
                if (path.getAttribute('stroke-width') === '2') path.removeAttribute('stroke-width');
            }
            this.group.replaceChildren(...svg.childNodes);
            this.events.attach(player);
            this.reset();
        })().catch(() => { /* The SVG remains usable if local assets cannot load. */ })
            .finally(() => { this.loading = null; });
        return this.loading;
    }

    async run(action) {
        // Only the latest intent matters while an icon or its player is loading.
        const request = this.request = Symbol();
        await this.prepare();
        if (this.player && this.request === request && !this.listeners.signal.aborted && !document.hidden) action();
    }

    hoverChange(key, value) {
        const wasInside = this.hover || this.focus;
        this[key] = value;
        const inside = this.hover || this.focus;
        if (inside !== wasInside) this.events.pointer(inside);
    }

    reset() {
        this.events.pose(this.events.frame(this.events.rest));
    }

    show(visible) {
        this.visible = visible;
        if (!visible || document.hidden) {
            this.request = null;
            this.player?.pause();
            return;
        }
        this.events.show();
    }

    dispose() {
        this.listeners.abort();
        this.stateObserver?.disconnect();
        this.pendingPlayer?.destroy();
        this.onDispose?.();
        if (!this.svg.isConnected || !this.svg.querySelector(selector)) {
            viewport.unobserve(this.svg);
            visibility.delete(this.svg);
        }
        instances.delete(this.group);
    }
}

function register(group) {
    if (instances.has(group) || !/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(group.dataset.nbUa)) return;
    const instance = new IconPlayback(group);
    instances.set(group, instance);
    viewport.observe(instance.svg);
    // A modal can replace its artwork inside an SVG that is already visible and observed.
    if (visibility.has(instance.svg)) instance.show(visibility.get(instance.svg));
}

function scan(node, callback) {
    if (node.nodeType !== 1) return;
    if (node.matches(selector)) callback(node);
    node.querySelectorAll(selector).forEach(callback);
}
new MutationObserver(records => {
    for (const record of records) {
        record.removedNodes.forEach(node => scan(node, group => {
            if (!group.isConnected) instances.get(group)?.dispose();
        }));
        record.addedNodes.forEach(node => { if (node.isConnected) scan(node, register); });
    }
}).observe(document.documentElement, { childList: true, subtree: true });

document.querySelectorAll(selector).forEach(register);
document.addEventListener('visibilitychange', () => instances.forEach(icon => icon.show(icon.visible)));
reduced.addEventListener('change', () => {
    instances.forEach(icon => {
        icon.request = null;
        icon.events.stop();
        if (icon.player) icon.reset();
        icon.show(icon.visible);
    });
});
