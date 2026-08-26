import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Toggle } from '@/components/ui/toggle';
import { cn } from '@/lib/utils';
import Placeholder from '@tiptap/extension-placeholder';
import { EditorContent, useEditor } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import { Bold, Italic, Link as LinkIcon, List, ListOrdered, Underline as UnderlineIcon } from 'lucide-react';
import { forwardRef, useEffect, useImperativeHandle, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';

export interface ComposerHandle {
    getHTML: () => string;
    getText: () => string;
}

/**
 * The box a message is typed into, shared by the reply pane and the
 * new-conversation pane. Grows with content up to a cap, then scrolls — a
 * contenteditable div never gets the native drag-to-resize handle a
 * `<textarea>` does, so that requirement falls out for free.
 *
 * `canFormat` follows the channel being written on, and it governs the
 * *extensions themselves*, not just whether the toolbar looks enabled. A
 * disabled-looking button is only a hint — Mod+B would still apply a mark —
 * so on a channel that carries no formatting the marks are not registered at
 * all and there is nothing for a shortcut to do.
 *
 * Even then the parent has the last word: it sends `getHTML()` only where
 * the channel takes it, and the server derives the plain text from that HTML
 * rather than trusting a second copy.
 */
const Composer = forwardRef<
    ComposerHandle,
    {
        placeholder: string;
        ariaLabel: string;
        canFormat: boolean;
        autoFocus?: boolean;
        /** Nothing is loaded to reply to yet — not the same as read-only. */
        disabled?: boolean;
        onSubmit: () => void;
        onEmptyChange: (empty: boolean) => void;
        /**
         * A subject line for the message, when it is starting an exchange
         * that needs naming. Inside this box rather than above it because it
         * is part of the same message — the way a mail client writes one.
         */
        subject?: { value: string; placeholder: string; label: string; onChange: (value: string) => void };
    }
>(({ placeholder, ariaLabel, canFormat, autoFocus, disabled, onSubmit, onEmptyChange, subject }, ref) => {
    const { t } = useTranslation('inbox');

    // StarterKit reads `undefined` as "on with defaults" and `false` as
    // "not registered at all" — there is no `true`.
    const enabled = canFormat ? undefined : (false as const);

    const editor = useEditor({
        extensions: [
            // The starting set and nothing else: bold, italic, underline,
            // bullet and numbered lists, links, paragraph breaks. Everything
            // outside it is off, on both channels, because the server's
            // sanitizer would strip it anyway and a button that silently
            // does nothing is worse than no button.
            StarterKit.configure({
                blockquote: false,
                code: false,
                codeBlock: false,
                heading: false,
                horizontalRule: false,
                strike: false,
                bold: enabled,
                italic: enabled,
                underline: enabled,
                bulletList: enabled,
                orderedList: enabled,
                listItem: enabled,
                listKeymap: enabled,
                link: canFormat ? { openOnClick: false } : false,
            }),
            Placeholder.configure({ placeholder }),
        ],
        editable: !disabled,
        autofocus: autoFocus ?? false,
        // TipTap 3 stopped re-rendering on every transaction for
        // performance, which leaves a toolbar reading `editor.isActive()`
        // permanently stale — the buttons never light up for the mark the
        // cursor is actually in. This toolbar is a handful of buttons, so
        // the cost is nothing and the alternative is a lie.
        shouldRerenderOnTransaction: true,
        editorProps: {
            attributes: { role: 'textbox', 'aria-label': ariaLabel },
            handleKeyDown: (_view, event) => {
                if (event.key !== 'Enter' || !editor) return false;

                if (event.shiftKey || event.altKey) {
                    editor.chain().focus().setHardBreak().run();
                    return true;
                }

                event.preventDefault();
                onSubmit();
                return true;
            },
        },
        onUpdate: ({ editor }) => onEmptyChange(editor.isEmpty),
    });

    useImperativeHandle(ref, () => ({
        getHTML: () => editor?.getHTML() ?? '',
        getText: () => editor?.getText() ?? '',
    }));

    // `editable` in the useEditor options only applies at construction —
    // toggling the prop later needs this to actually take effect.
    useEffect(() => {
        editor?.setEditable(!disabled);
    }, [editor, disabled]);

    // A small popover under the toolbar, not a native prompt() — a system
    // dialog looks broken next to the rest of the app, blocks the page, and
    // cannot be styled or driven the way this one can (Enter applies,
    // Escape cancels, an empty value removes the link).
    const [linkOpen, setLinkOpen] = useState(false);
    const [linkValue, setLinkValue] = useState('');
    const linkPopoverRef = useRef<HTMLDivElement>(null);
    const linkInputRef = useRef<HTMLInputElement>(null);

    function openLinkPopover() {
        if (!editor) return;

        setLinkValue((editor.getAttributes('link').href as string | undefined) ?? '');
        setLinkOpen(true);
    }

    function applyLink() {
        if (!editor) return;

        const href = linkValue.trim();

        if (href === '') {
            editor.chain().focus().extendMarkRange('link').unsetLink().run();
        } else {
            editor.chain().focus().extendMarkRange('link').setLink({ href }).run();
        }

        setLinkOpen(false);
    }

    function cancelLinkPopover() {
        setLinkOpen(false);
        editor?.chain().focus().run();
    }

    // Focus the field the moment it appears, and close on an outside click —
    // the two things a native prompt() gave for free.
    useEffect(() => {
        if (!linkOpen) return;

        linkInputRef.current?.focus();
        linkInputRef.current?.select();

        function onPointerDown(event: PointerEvent) {
            if (linkPopoverRef.current && !linkPopoverRef.current.contains(event.target as Node)) {
                cancelLinkPopover();
            }
        }

        document.addEventListener('pointerdown', onPointerDown);

        return () => document.removeEventListener('pointerdown', onPointerDown);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [linkOpen]);

    return (
        <div className={cn('border-input rounded-md border', disabled && 'opacity-60')}>
            {/* No toolbar at all where nothing can be formatted — a row of
                dead buttons above an SMS box is furniture. */}
            {canFormat && (
                <div className="flex items-center gap-1 border-b p-1">
                    <Toggle
                        size="sm"
                        pressed={editor?.isActive('bold') ?? false}
                        onPressedChange={() => editor?.chain().focus().toggleBold().run()}
                        aria-label={t('format_bold')}
                    >
                        <Bold className="h-4 w-4" />
                    </Toggle>
                    <Toggle
                        size="sm"
                        pressed={editor?.isActive('italic') ?? false}
                        onPressedChange={() => editor?.chain().focus().toggleItalic().run()}
                        aria-label={t('format_italic')}
                    >
                        <Italic className="h-4 w-4" />
                    </Toggle>
                    <Toggle
                        size="sm"
                        pressed={editor?.isActive('underline') ?? false}
                        onPressedChange={() => editor?.chain().focus().toggleUnderline().run()}
                        aria-label={t('format_underline')}
                    >
                        <UnderlineIcon className="h-4 w-4" />
                    </Toggle>
                    <Toggle
                        size="sm"
                        pressed={editor?.isActive('bulletList') ?? false}
                        onPressedChange={() => editor?.chain().focus().toggleBulletList().run()}
                        aria-label={t('format_bullet_list')}
                    >
                        <List className="h-4 w-4" />
                    </Toggle>
                    <Toggle
                        size="sm"
                        pressed={editor?.isActive('orderedList') ?? false}
                        onPressedChange={() => editor?.chain().focus().toggleOrderedList().run()}
                        aria-label={t('format_ordered_list')}
                    >
                        <ListOrdered className="h-4 w-4" />
                    </Toggle>
                    <div className="relative">
                        <Toggle
                            size="sm"
                            pressed={editor?.isActive('link') ?? linkOpen}
                            onPressedChange={() => (linkOpen ? cancelLinkPopover() : openLinkPopover())}
                            aria-label={t('format_link')}
                        >
                            <LinkIcon className="h-4 w-4" />
                        </Toggle>

                        {linkOpen && (
                            <div
                                ref={linkPopoverRef}
                                className="bg-popover text-popover-foreground absolute top-full left-0 z-10 mt-1 flex w-64 items-center gap-1 rounded-md border p-1.5 shadow-md"
                            >
                                <Input
                                    ref={linkInputRef}
                                    value={linkValue}
                                    onChange={(e) => setLinkValue(e.target.value)}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter') {
                                            e.preventDefault();
                                            applyLink();
                                        } else if (e.key === 'Escape') {
                                            e.preventDefault();
                                            cancelLinkPopover();
                                        }
                                    }}
                                    placeholder={t('link_url_placeholder')}
                                    aria-label={t('link_url_label')}
                                    className="h-8"
                                />
                                <Button type="button" size="sm" className="h-8 px-2" onClick={applyLink}>
                                    {t('link_apply')}
                                </Button>
                            </div>
                        )}
                    </div>
                </div>
            )}
            {subject && (
                <input
                    value={subject.value}
                    disabled={disabled}
                    onChange={(e) => subject.onChange(e.target.value)}
                    onKeyDown={(e) => {
                        // Enter belongs to the body, not to sending something
                        // that is only a subject.
                        if (e.key === 'Enter') e.preventDefault();
                    }}
                    placeholder={subject.placeholder}
                    aria-label={subject.label}
                    // A shade larger than the body, so the message reads as
                    // a titled thing rather than two equal fields.
                    className="placeholder:text-muted-foreground w-full border-b px-3 py-2 text-base font-medium outline-hidden md:text-sm"
                />
            )}

            <EditorContent
                editor={editor}
                className={cn(
                    'max-h-52 overflow-y-auto px-3 py-2 text-base md:text-sm',
                    '[&_.ProseMirror]:outline-hidden',
                    '[&_.ProseMirror_p.is-editor-empty:first-child::before]:text-muted-foreground',
                    '[&_.ProseMirror_p.is-editor-empty:first-child::before]:pointer-events-none',
                    '[&_.ProseMirror_p.is-editor-empty:first-child::before]:float-left',
                    '[&_.ProseMirror_p.is-editor-empty:first-child::before]:h-0',
                    '[&_.ProseMirror_p.is-editor-empty:first-child::before]:content-[attr(data-placeholder)]',
                )}
            />
        </div>
    );
});

Composer.displayName = 'Composer';

export default Composer;
