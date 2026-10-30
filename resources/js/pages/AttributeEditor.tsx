import React, { useState } from "react";
export default function AttributeEditor({
    initial,
    onChange,
}: {
    initial: Record<string, string>;
    onChange: (value: Record<string, string>) => void;
}) {
    const [rows, setRows] = useState(() => {
        const entries = Object.entries(initial);
        return entries.length ? entries : [["", ""]];
    });
    const update = (next: string[][]) => {
        setRows(next);
        onChange(
            Object.fromEntries(
                next
                    .filter(([name]) => name.trim())
                    .map(([name, value]) => [name.trim(), value]),
            ),
        );
    };
    return (
        <fieldset className="attribute-editor">
            <legend>Custom attributes</legend>
            <p className="muted">
                Add details such as Size, Colour, RAM or Storage.
            </p>
            {rows.map(([name, value], i) => (
                <div className="attribute-row" key={i}>
                    <label className="field">
                        Attribute name
                        <input
                            maxLength={60}
                            placeholder="e.g. Size"
                            value={name}
                            onChange={(e) =>
                                update(
                                    rows.map((row, j) =>
                                        i === j ? [e.target.value, value] : row,
                                    ),
                                )
                            }
                        />
                    </label>
                    <label className="field">
                        Attribute value
                        <input
                            maxLength={255}
                            placeholder="e.g. 0–3 months"
                            value={value}
                            onChange={(e) =>
                                update(
                                    rows.map((row, j) =>
                                        i === j ? [name, e.target.value] : row,
                                    ),
                                )
                            }
                        />
                    </label>
                    <button
                        className="secondary"
                        type="button"
                        onClick={() => update(rows.filter((_, j) => j !== i))}
                    >
                        Remove
                    </button>
                </div>
            ))}
            {rows.length < 30 && (
                <button
                    type="button"
                    className="secondary"
                    onClick={() => update([...rows, ["", ""]])}
                >
                    Add attribute
                </button>
            )}
        </fieldset>
    );
}
