import { parseDescription } from "@/lib/job-description";

/** The posting's verbatim text, laid out for reading: bullets as a compact list, paragraphs as before. */
export function JobDescription({ text }: { text: string }) {
    const blocks = parseDescription(text);

    return (
        <div className="text-fg space-y-3 text-sm leading-relaxed break-words">
            {blocks.map((block, i) =>
                block.kind === "list" ? (
                    <ul
                        key={i}
                        className="list-disc space-y-1.5 pl-5 leading-normal"
                    >
                        {block.items.map((item, j) => (
                            <li key={j}>{item}</li>
                        ))}
                    </ul>
                ) : (
                    <p key={i} className="whitespace-pre-line">
                        {block.text}
                    </p>
                ),
            )}
        </div>
    );
}
