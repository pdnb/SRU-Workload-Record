import Chart from 'chart.js/auto';
import evidenceCapture from './evidence-capture';
import richEditor from './rich-editor';

window.Chart = Chart;

document.addEventListener('alpine:init', () => {
    window.Alpine.data('evidenceCapture', evidenceCapture);
    window.Alpine.data('richEditor', richEditor);
});

window.copyText = (text) => {
    if (navigator.clipboard && window.isSecureContext) {
        return navigator.clipboard.writeText(text);
    }

    const input = document.createElement('textarea');
    input.value = text;
    input.style.position = 'fixed';
    input.style.opacity = '0';
    document.body.appendChild(input);
    input.focus();
    input.select();
    document.execCommand('copy');
    document.body.removeChild(input);

    return Promise.resolve();
};
