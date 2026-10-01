const pad = (value) => String(value).padStart(2, '0');

const screenshotFilename = (index = 0) => {
    const now = new Date();
    const stamp = `${now.getFullYear()}${pad(now.getMonth() + 1)}${pad(now.getDate())}-${pad(now.getHours())}${pad(now.getMinutes())}${pad(now.getSeconds())}`;

    return `screenshot-${stamp}${index > 0 ? `-${index + 1}` : ''}.png`;
};

const blobToPngFile = (blob, index = 0) => new File([blob], screenshotFilename(index), { type: blob.type || 'image/png' });

const waitForVideoFrame = (video) => new Promise((resolve) => {
    if (video.requestVideoFrameCallback) {
        video.requestVideoFrameCallback(() => resolve());
    } else {
        setTimeout(resolve, 200);
    }
});

export default () => ({
    dragging: false,
    capturing: false,
    cropping: false,
    hint: '',
    snapshotUrl: null,
    snapshotCanvas: null,
    selection: null,
    selectStart: null,

    get canCapture() {
        return !! navigator.mediaDevices?.getDisplayMedia;
    },

    openFilePicker() {
        this.$refs.fileInput.click();
    },

    addFiles(files) {
        if (files.length === 0) {
            return;
        }

        const input = this.$refs.fileInput;
        const dt = new DataTransfer();

        for (const file of files) {
            dt.items.add(file);
        }

        input.files = dt.files;
        input.dispatchEvent(new Event('change', { bubbles: true }));
        this.hint = '';
    },

    handleDrop(e) {
        this.dragging = false;
        this.addFiles(Array.from(e.dataTransfer.files));
    },

    onPaste(e) {
        if (this.$el.offsetParent === null || this.cropping) {
            return;
        }

        const images = Array.from(e.clipboardData?.items ?? [])
            .filter((item) => item.kind === 'file' && item.type.startsWith('image/'))
            .map((item) => item.getAsFile())
            .filter(Boolean);

        if (images.length === 0) {
            return;
        }

        e.preventDefault();
        this.addFiles(images.map((blob, index) => blobToPngFile(blob, index)));
    },

    async pasteFromClipboard() {
        if (! navigator.clipboard?.read) {
            this.hint = 'เบราว์เซอร์นี้ไม่รองรับการอ่านคลิปบอร์ด กรุณาคลิกกรอบด้านบนแล้วกด Ctrl+V แทน';

            return;
        }

        try {
            const files = [];

            for (const item of await navigator.clipboard.read()) {
                const type = item.types.find((t) => t.startsWith('image/'));

                if (type) {
                    files.push(blobToPngFile(await item.getType(type), files.length));
                }
            }

            if (files.length === 0) {
                this.hint = 'ไม่พบรูปภาพในคลิปบอร์ด ลองกด Win+Shift+S เพื่อจับภาพหน้าจอก่อน';

                return;
            }

            this.addFiles(files);
        } catch {
            this.hint = 'ไม่สามารถอ่านคลิปบอร์ดได้ กรุณาคลิกกรอบด้านบนแล้วกด Ctrl+V แทน';
        }
    },

    async startCapture() {
        if (! this.canCapture || this.capturing) {
            return;
        }

        this.capturing = true;
        this.hint = '';
        let stream = null;

        try {
            stream = await navigator.mediaDevices.getDisplayMedia({ video: true, audio: false });

            const video = document.createElement('video');
            video.muted = true;
            video.playsInline = true;
            video.srcObject = stream;
            await video.play();
            await waitForVideoFrame(video);

            const canvas = document.createElement('canvas');
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);

            this.snapshotCanvas = canvas;
            this.snapshotUrl = canvas.toDataURL('image/png');
            this.selection = null;
            this.cropping = true;
            this.$nextTick(() => this.$refs.cropDialog?.showModal());
        } catch (error) {
            if (error?.name !== 'NotAllowedError' && error?.name !== 'AbortError') {
                this.hint = 'ไม่สามารถจับภาพหน้าจอได้ กรุณาใช้ Win+Shift+S แล้วกด Ctrl+V แทน';
            }
        } finally {
            stream?.getTracks().forEach((track) => track.stop());
            this.capturing = false;
        }
    },

    pointFromEvent(e) {
        const rect = this.$refs.cropArea.getBoundingClientRect();

        return {
            x: Math.min(Math.max(e.clientX - rect.left, 0), rect.width),
            y: Math.min(Math.max(e.clientY - rect.top, 0), rect.height),
        };
    },

    beginSelect(e) {
        e.preventDefault();
        e.currentTarget.setPointerCapture?.(e.pointerId);
        this.selectStart = this.pointFromEvent(e);
        this.selection = { ...this.selectStart, width: 0, height: 0 };
    },

    moveSelect(e) {
        if (! this.selectStart) {
            return;
        }

        const point = this.pointFromEvent(e);

        this.selection = {
            x: Math.min(point.x, this.selectStart.x),
            y: Math.min(point.y, this.selectStart.y),
            width: Math.abs(point.x - this.selectStart.x),
            height: Math.abs(point.y - this.selectStart.y),
        };
    },

    endSelect() {
        this.selectStart = null;

        if (this.selection && (this.selection.width < 5 || this.selection.height < 5)) {
            this.selection = null;
        }
    },

    selectionStyle() {
        if (! this.selection) {
            return 'display: none';
        }

        const { x, y, width, height } = this.selection;

        return `left: ${x}px; top: ${y}px; width: ${width}px; height: ${height}px`;
    },

    confirmCrop() {
        const source = this.snapshotCanvas;

        if (! source) {
            return this.cancelCrop();
        }

        let sx = 0;
        let sy = 0;
        let sw = source.width;
        let sh = source.height;

        if (this.selection) {
            const rect = this.$refs.cropArea.getBoundingClientRect();
            const scale = source.width / rect.width;

            sx = Math.round(this.selection.x * scale);
            sy = Math.round(this.selection.y * scale);
            sw = Math.max(1, Math.round(this.selection.width * scale));
            sh = Math.max(1, Math.round(this.selection.height * scale));
        }

        const output = document.createElement('canvas');
        output.width = sw;
        output.height = sh;
        output.getContext('2d').drawImage(source, sx, sy, sw, sh, 0, 0, sw, sh);

        output.toBlob((blob) => {
            if (blob) {
                this.addFiles([blobToPngFile(blob)]);
            }
        }, 'image/png');

        this.cancelCrop();
    },

    cancelCrop() {
        if (this.$refs.cropDialog?.open) {
            this.$refs.cropDialog.close();
        }

        this.cropping = false;
        this.snapshotUrl = null;
        this.snapshotCanvas = null;
        this.selection = null;
        this.selectStart = null;
    },
});
