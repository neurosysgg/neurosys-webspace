import { CustomProperty } from '../../model/CustomProperty.js';
import { HtmlTag } from '../../model/HtmlTag.js';
import { Tag } from '../../model/Tag.js';
import { WaveformAttribute } from '../../model/WaveformAttribute.js';
import { STRIDE, WaveformBand } from '../../model/WaveformBand.js';
export class DemoWaveform extends HTMLElement {
    static PALETTE = [
        [WaveformBand.Low, CustomProperty.WaveLow],
        [WaveformBand.Mid, CustomProperty.WaveMid],
        [WaveformBand.High, CustomProperty.WaveHigh],
    ];
    static UNSTYLED = '#e8e8f0';
    static DIMMED = 0.3;
    static REACH = 0.52;
    columns = null;
    canvas = null;
    audio = null;
    built = false;
    repaint = () => { this.paint(); };
    resizes = new ResizeObserver(this.repaint);
    connectedCallback() {
        if (!this.built) {
            this.built = true;
            this.build();
        }
        this.resizes.observe(this);
        this.paint();
    }
    disconnectedCallback() {
        this.resizes.disconnect();
    }
    build() {
        this.columns = DemoWaveform.decode(this.getAttribute(WaveformAttribute.Peaks));
        if (this.columns === null)
            return;
        this.canvas = document.createElement(HtmlTag.Canvas);
        this.canvas.addEventListener('click', (event) => { this.seek(event); });
        this.prepend(this.canvas);
        this.audio = this.querySelector(HtmlTag.Audio);
        for (const event of ['timeupdate', 'seeking', 'ended', 'loadedmetadata']) {
            this.audio?.addEventListener(event, this.repaint);
        }
    }
    static decode(encoded) {
        if (encoded === null || encoded === '')
            return null;
        let binary;
        try {
            binary = atob(encoded);
        }
        catch {
            return null;
        }
        if (binary.length < STRIDE || binary.length % STRIDE !== 0)
            return null;
        return binary;
    }
    paint() {
        const canvas = this.canvas;
        const columns = this.columns;
        if (canvas === null || columns === null)
            return;
        const width = this.clientWidth;
        const height = this.clientHeight;
        if (width < 1 || height < 1)
            return;
        const ratio = window.devicePixelRatio || 1;
        const backingWidth = Math.round(width * ratio);
        const backingHeight = Math.round(height * ratio);
        if (canvas.width !== backingWidth || canvas.height !== backingHeight) {
            canvas.width = backingWidth;
            canvas.height = backingHeight;
        }
        const context = canvas.getContext('2d');
        if (context === null)
            return;
        context.setTransform(ratio, 0, 0, ratio, 0, 0);
        context.clearRect(0, 0, width, height);
        const colours = this.colours();
        const count = columns.length / STRIDE;
        const step = width / count;
        const middle = height / 2;
        const played = this.progress() * width;
        for (let column = 0; column < count; column++) {
            const offset = column * STRIDE;
            const left = column * step;
            const total = colours.reduce((sum, [band]) => sum + columns.charCodeAt(offset + band), 0);
            if (total === 0)
                continue;
            const reach = (columns.charCodeAt(offset + WaveformBand.Level) / 255) * middle * DemoWaveform.REACH;
            context.globalAlpha = left + step <= played ? 1 : DemoWaveform.DIMMED;
            let stacked = 0;
            for (const [band, colour] of colours) {
                const grow = reach * (columns.charCodeAt(offset + band) / total);
                if (grow > 0) {
                    context.fillStyle = colour;
                    context.fillRect(left, middle - stacked - grow, Math.ceil(step), grow);
                    context.fillRect(left, middle + stacked, Math.ceil(step), grow);
                }
                stacked += grow;
            }
        }
        context.globalAlpha = 1;
        this.drawPlayhead(context, played, height);
    }
    drawPlayhead(context, played, height) {
        if (played <= 0)
            return;
        context.fillStyle = this.colour(CustomProperty.WaveHigh);
        context.fillRect(played, 0, 1, height);
    }
    progress() {
        const audio = this.audio;
        const duration = this.duration();
        if (audio === null || duration <= 0)
            return 0;
        return Math.min(1, Math.max(0, audio.currentTime / duration));
    }
    duration() {
        const audio = this.audio;
        if (audio !== null && Number.isFinite(audio.duration) && audio.duration > 0)
            return audio.duration;
        return Number(this.getAttribute(WaveformAttribute.Duration)) || 0;
    }
    seek(event) {
        const audio = this.audio;
        const duration = this.duration();
        const box = this.canvas?.getBoundingClientRect();
        if (audio === null || duration <= 0 || box === undefined || box.width < 1)
            return;
        const fraction = Math.min(1, Math.max(0, (event.clientX - box.left) / box.width));
        audio.currentTime = fraction * duration;
        this.paint();
    }
    colours() {
        return DemoWaveform.PALETTE.map(([band, property]) => [band, this.colour(property)]);
    }
    colour(property) {
        return getComputedStyle(this).getPropertyValue(property).trim() || DemoWaveform.UNSTYLED;
    }
}
customElements.define(Tag.DemoWaveform, DemoWaveform);
//# sourceMappingURL=DemoWaveform.js.map