import { HtmlAttribute } from '../model/HtmlAttribute.js';
import { HtmlTag } from '../model/HtmlTag.js';
import { MachineAttribute } from '../model/MachineAttribute.js';
import { MachineCounter } from '../model/MachineCounter.js';
import { MachineReading } from '../model/MachineReading.js';
import { MachineTag } from '../model/MachineTag.js';
import { MediaType } from '../model/MediaType.js';
import { RequestHeader } from '../model/RequestHeader.js';
import { ResultKey } from '../model/ResultKey.js';
export class MachineStats extends HTMLElement {
    static EVERY = 2000;
    static UNITS = 'KMGTPE';
    timer = undefined;
    last = null;
    visibility = () => { this.schedule(); };
    connectedCallback() {
        document.addEventListener('visibilitychange', this.visibility);
        this.schedule();
    }
    disconnectedCallback() {
        document.removeEventListener('visibilitychange', this.visibility);
        this.stop();
    }
    schedule() {
        this.stop();
        if (document.visibilityState !== 'hidden') {
            this.timer = setInterval(() => { void this.refresh(); }, MachineStats.EVERY);
        }
    }
    stop() {
        clearInterval(this.timer);
        this.timer = undefined;
    }
    async refresh() {
        const counters = await MachineStats.read(this.getAttribute(MachineAttribute.Source) ?? '');
        if (counters === null) {
            this.stop();
            return;
        }
        this.show(counters);
        this.last = counters;
    }
    static async read(source) {
        try {
            const response = await fetch(source, {
                headers: { [RequestHeader.Accept]: MediaType.Json },
                credentials: 'same-origin',
            });
            return MachineStats.counters(await response.json());
        }
        catch {
            return null;
        }
    }
    static counters(data) {
        const sections = data instanceof Object ? data[ResultKey.Sections] : null;
        const found = Array.isArray(sections)
            ? sections.find((section) => section instanceof Object && ResultKey.Counters in section)
            : undefined;
        return found === undefined ? null : found[ResultKey.Counters];
    }
    show(now) {
        const before = this.last;
        const shown = new Map([
            [MachineReading.Cpu, before === null ? [0, ''] : MachineStats.busy(now, before)],
            [MachineReading.Memory, MachineStats.share(now[MachineCounter.MemoryUsed], now[MachineCounter.MemoryTotal])],
            [
                MachineReading.Swap,
                now[MachineCounter.SwapTotal] > 0
                    ? MachineStats.share(now[MachineCounter.SwapUsed], now[MachineCounter.SwapTotal])
                    : [0, ''],
            ],
            [MachineReading.Received, [0, MachineStats.flow(now, before, MachineCounter.Received)]],
            [MachineReading.Sent, [0, MachineStats.flow(now, before, MachineCounter.Sent)]],
            [MachineReading.Load, [0, (now[MachineCounter.Load] / 100).toFixed(2)]],
        ]);
        shown.forEach(([percent, text], reading) => {
            this.querySelectorAll(`[${MachineAttribute.Reading}="${reading}"]`).forEach((element) => {
                if (element.localName === HtmlTag.Meter) {
                    element.setAttribute(HtmlAttribute.Value, String(percent));
                }
                else {
                    element.textContent = text;
                }
            });
        });
    }
    static busy(now, before) {
        const percent = MachineStats.percent(now[MachineCounter.CpuBusy] - before[MachineCounter.CpuBusy], now[MachineCounter.CpuTotal] - before[MachineCounter.CpuTotal]);
        return [percent, `${percent}%`];
    }
    static share(part, whole) {
        const percent = MachineStats.percent(part, whole);
        return [percent, `${MachineStats.bytes(part)} of ${MachineStats.bytes(whole)} (${percent}%)`];
    }
    static flow(now, before, counter) {
        if (before === null)
            return MachineStats.bytes(now[counter]);
        const seconds = Math.max(1, now[MachineCounter.Time] - before[MachineCounter.Time]) / 1000;
        return `${MachineStats.bytes(Math.max(0, Math.round((now[counter] - before[counter]) / seconds)))}/s`;
    }
    static percent(part, whole) {
        return whole > 0 ? Math.round(part * 100 / whole) : 0;
    }
    static bytes(count) {
        if (count < 1024)
            return `${count} B`;
        let value = count;
        let unit = -1;
        while (value >= 1024 && unit < MachineStats.UNITS.length - 1) {
            value /= 1024;
            unit++;
        }
        return `${value.toFixed(1)} ${MachineStats.UNITS.charAt(unit)}iB`;
    }
}
customElements.define(MachineTag.Stats, MachineStats);
//# sourceMappingURL=MachineStats.js.map