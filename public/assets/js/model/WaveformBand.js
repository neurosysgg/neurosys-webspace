export var WaveformBand;
(function (WaveformBand) {
    WaveformBand[WaveformBand["Level"] = 0] = "Level";
    WaveformBand[WaveformBand["Low"] = 1] = "Low";
    WaveformBand[WaveformBand["Mid"] = 2] = "Mid";
    WaveformBand[WaveformBand["High"] = 3] = "High";
})(WaveformBand || (WaveformBand = {}));
export const STRIDE = Object.values(WaveformBand).filter((v) => typeof v === 'number').length;
//# sourceMappingURL=WaveformBand.js.map