import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import { Placeholder } from '@tiptap/extensions';

const isHtml = (value) => /<\/?[a-z][^>]*>/i.test(value);

const escapeHtml = (value) => value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');

// Legacy entries are plain text; turn each line into a paragraph so line breaks survive.
const toEditorHtml = (value) => {
    const text = value ?? '';

    if (text === '' || isHtml(text)) {
        return text;
    }

    return text.split(/\r?\n/).map((line) => `<p>${escapeHtml(line)}</p>`).join('');
};

// The editor instance lives outside Alpine's reactive proxy; proxying it breaks ProseMirror.
export default ({ state, readonly = false, placeholder = '' }) => {
    let editor = null;

    const currentHtml = () => (editor.isEmpty ? '' : editor.getHTML());

    return {
        state,
        tick: 0,

        init() {
            editor = new Editor({
                element: this.$refs.editor,
                editable: !readonly,
                content: toEditorHtml(this.state),
                extensions: [
                    StarterKit.configure({
                        heading: false,
                        code: false,
                        codeBlock: false,
                        blockquote: false,
                        horizontalRule: false,
                        link: {
                            openOnClick: false,
                            autolink: true,
                            defaultProtocol: 'https',
                            protocols: ['http', 'https', 'mailto'],
                        },
                    }),
                    Placeholder.configure({ placeholder }),
                ],
                editorProps: {
                    attributes: {
                        class: 'rich-content min-h-36 px-3 py-2 text-base sm:text-sm text-zinc-700 dark:text-zinc-300 focus:outline-hidden',
                    },
                },
                onUpdate: () => {
                    this.state = currentHtml();
                },
                onTransaction: () => {
                    this.tick++;
                },
            });

            this.$watch('state', (value) => {
                if ((value ?? '') !== currentHtml()) {
                    editor.commands.setContent(toEditorHtml(value), { emitUpdate: false });
                }
            });
        },

        destroy() {
            editor?.destroy();
            editor = null;
        },

        isActive(name) {
            this.tick;

            return editor?.isActive(name) ?? false;
        },

        run(command) {
            editor?.chain().focus()[command]().run();
        },

        setLink() {
            if (!editor) {
                return;
            }

            const previous = editor.getAttributes('link').href ?? '';
            const href = window.prompt('ใส่ลิงก์ (URL)', previous);

            if (href === null) {
                return;
            }

            const chain = editor.chain().focus().extendMarkRange('link');

            if (href.trim() === '') {
                chain.unsetLink().run();

                return;
            }

            chain.setLink({ href: href.trim() }).run();
        },
    };
};
