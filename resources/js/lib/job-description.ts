/**
 * Display-only structure for a stored job description. The text is never modified or stored back: the backend already
 * flattens provider HTML to plain text (`<li>` becomes "- "), and Markdown bullets arrive as-is, so one plain-text
 * parse covers all three. Nothing here produces HTML — React renders the pieces as text.
 */
export type DescriptionBlock =
    { kind: "paragraph"; text: string } | { kind: "list"; items: string[] };

// `*` needs a following space so `**bold**` is not mistaken for a bullet.
const BULLET = /^\s*[-•*–·]\s+(\S.*)$/;

export function parseDescription(text: string): DescriptionBlock[] {
    const blocks: DescriptionBlock[] = [];
    let paragraph: string[] = [];
    let list: string[] | null = null;
    // True once a blank line followed the last bullet, so a plain line then ends the list.
    let gap = false;

    const flushParagraph = () => {
        if (paragraph.length > 0) {
            blocks.push({ kind: "paragraph", text: paragraph.join("\n") });
            paragraph = [];
        }
    };
    const flushList = () => {
        if (list) {
            blocks.push({ kind: "list", items: list });
            list = null;
        }
        gap = false;
    };

    for (const raw of text.replace(/\r\n?/g, "\n").split("\n")) {
        const line = raw.trimEnd();

        if (line.trim() === "") {
            if (list) {
                gap = true;
            } else {
                flushParagraph();
            }
            continue;
        }

        const bullet = BULLET.exec(line);

        if (bullet) {
            flushParagraph();
            list ??= [];
            list.push(bullet[1].trim());
            gap = false;
            continue;
        }

        if (list) {
            const indented = /^\s+\S/.test(line);
            const heading = line.trim().endsWith(":");

            // A wrapped bullet continues on the next line without a blank line (or is indented).
            if (!heading && (indented || !gap)) {
                list[list.length - 1] += ` ${line.trim()}`;
                gap = false;
                continue;
            }

            flushList();
        }

        paragraph.push(line);
    }

    flushParagraph();
    flushList();

    return blocks;
}
