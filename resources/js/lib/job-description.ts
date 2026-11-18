/**
 * Display-only structure for a stored job description. The text is never modified or stored back: the backend already
 * flattens provider HTML to plain text (`<li>` becomes "- "), and Markdown bullets arrive as-is, so one plain-text
 * parse covers all three. Nothing here produces HTML — React renders the pieces as text.
 */
export type DescriptionBlock =
    { kind: 'paragraph'; text: string } | { kind: 'list'; items: string[] };

// `*` needs a following space so `**bold**` is not mistaken for a bullet.
const BULLET = /^\s*[-•*–·]\s+(\S.*)$/;

// A marker alone on its line; some providers put the bullet text on the next non-empty line.
const LONE_MARKER = /^\s*[-•*–·]\s*$/;

/**
 * Folds a lone marker into the next non-empty line ("-", blank lines, "text" becomes "- text"). A marker with nothing
 * usable after it (end of text, another marker or bullet, a heading ending in ":") is left alone as ordinary text.
 */
function foldLoneMarkers(lines: string[]): string[] {
    const out: string[] = [];

    for (let i = 0; i < lines.length; i++) {
        if (!LONE_MARKER.test(lines[i])) {
            out.push(lines[i]);
            continue;
        }

        let j = i + 1;
        while (j < lines.length && lines[j].trim() === '') {
            j++;
        }

        const next = lines[j]?.trim();

        if (
            next === undefined ||
            LONE_MARKER.test(next) ||
            BULLET.test(next) ||
            next.endsWith(':')
        ) {
            out.push(lines[i]);
            continue;
        }

        out.push(`- ${next}`);
        i = j;
    }

    return out;
}

export function parseDescription(text: string): DescriptionBlock[] {
    const blocks: DescriptionBlock[] = [];
    let paragraph: string[] = [];
    let list: string[] | null = null;
    // True once a blank line followed the last bullet, so a plain line then ends the list.
    let gap = false;

    const flushParagraph = () => {
        if (paragraph.length > 0) {
            blocks.push({ kind: 'paragraph', text: paragraph.join('\n') });
            paragraph = [];
        }
    };
    const flushList = () => {
        if (list) {
            blocks.push({ kind: 'list', items: list });
            list = null;
        }
        gap = false;
    };

    for (const raw of foldLoneMarkers(
        text.replace(/\r\n?/g, '\n').split('\n'),
    )) {
        const line = raw.trimEnd();

        if (line.trim() === '') {
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
            const heading = line.trim().endsWith(':');

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
