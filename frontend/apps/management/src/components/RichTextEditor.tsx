import { useEffect } from 'react'
import { EditorContent, useEditor } from '@tiptap/react'
import StarterKit from '@tiptap/starter-kit'

const toolbarButton =
  'flex h-8 w-8 cursor-pointer items-center justify-center rounded text-gray-600 transition-colors hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-40'
const toolbarButtonActive = 'bg-primary-50 text-primary-600'

// Tailwind's preflight strips default list/heading styling, and this
// codebase has no typography plugin configured — restyle the editor's
// own content area directly rather than pulling one in for a single field.
const editorContentStyle = `
  .rich-text-editor-content .ProseMirror { min-height: 7rem; outline: none; }
  .rich-text-editor-content .ProseMirror p { margin: 0 0 0.5em; }
  .rich-text-editor-content .ProseMirror p:last-child { margin-bottom: 0; }
  .rich-text-editor-content .ProseMirror ul { list-style: disc; padding-left: 1.5rem; margin: 0 0 0.5em; }
  .rich-text-editor-content .ProseMirror ol { list-style: decimal; padding-left: 1.5rem; margin: 0 0 0.5em; }
  .rich-text-editor-content .ProseMirror h1, .rich-text-editor-content .ProseMirror h2, .rich-text-editor-content .ProseMirror h3 { font-weight: 600; margin: 0 0 0.4em; }
  .rich-text-editor-content .ProseMirror blockquote { border-left: 3px solid #e5e7eb; padding-left: 0.75rem; color: #6b7280; }
`

export default function RichTextEditor({ value, onChange }: { value: string; onChange: (html: string) => void }) {
  const editor = useEditor({
    extensions: [StarterKit],
    content: value,
    // TipTap's "empty" document still serializes to "<p></p>", not "" —
    // report it as an empty string instead, so a caller's plain
    // `!value.trim()` required-field check (this codebase's established
    // pattern, e.g. GeoCrudTable) still works without special-casing
    // rich text.
    onUpdate: ({ editor }) => onChange(editor.isEmpty ? '' : editor.getHTML()),
  })

  // Keep the editor's content in sync when `value` changes from outside
  // the user's own typing (opening the form for a different row, or
  // resetting after save) — never while the editor has focus, or this
  // would overwrite the cursor position mid-keystroke.
  useEffect(() => {
    if (!editor || editor.isFocused) return
    if (editor.getHTML() === value) return
    editor.commands.setContent(value, false)
  }, [editor, value])

  return (
    <div className="rounded-md border border-gray-200 bg-white focus-within:border-primary-500">
      <style>{editorContentStyle}</style>
      {editor && (
        <div className="flex items-center gap-1 border-b border-gray-100 px-2 py-1.5">
          <button
            type="button"
            className={`${toolbarButton} ${editor.isActive('bold') ? toolbarButtonActive : ''}`}
            onClick={() => editor.chain().focus().toggleBold().run()}
            aria-label="Bold"
          >
            <span className="material-symbols-outlined !text-[18px]">format_bold</span>
          </button>
          <button
            type="button"
            className={`${toolbarButton} ${editor.isActive('italic') ? toolbarButtonActive : ''}`}
            onClick={() => editor.chain().focus().toggleItalic().run()}
            aria-label="Italic"
          >
            <span className="material-symbols-outlined !text-[18px]">format_italic</span>
          </button>
          <button
            type="button"
            className={`${toolbarButton} ${editor.isActive('bulletList') ? toolbarButtonActive : ''}`}
            onClick={() => editor.chain().focus().toggleBulletList().run()}
            aria-label="Bullet list"
          >
            <span className="material-symbols-outlined !text-[18px]">format_list_bulleted</span>
          </button>
          <button
            type="button"
            className={`${toolbarButton} ${editor.isActive('orderedList') ? toolbarButtonActive : ''}`}
            onClick={() => editor.chain().focus().toggleOrderedList().run()}
            aria-label="Numbered list"
          >
            <span className="material-symbols-outlined !text-[18px]">format_list_numbered</span>
          </button>
        </div>
      )}
      <EditorContent editor={editor} className="rich-text-editor-content px-3 py-2 text-sm text-gray-800" />
    </div>
  )
}
