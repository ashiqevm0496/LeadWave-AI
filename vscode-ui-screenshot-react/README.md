# UI Screenshot to React + Tailwind

VS Code extension scaffold for turning pasted UI screenshots into heuristic React + Tailwind component output.

## Included capabilities

- Screenshot paste, drop, and file upload inside a VS Code webview
- Heuristic component detection from connected visual regions
- Spacing cluster analysis for horizontal and vertical gaps
- Dominant palette extraction, including detected background color
- Responsive layout generation using inferred row groups and breakpoint stacking
- Live structural preview inside VS Code
- Copy and save actions for the generated component

## Run locally

```bash
npm install
npm run compile
```

Then open this folder in VS Code and press `F5` to launch the extension host. Run the command:

```text
UI Screenshot: Open Generator
```

## Notes

- The analysis pipeline is heuristic, not model-based. It works best on clean product screenshots with distinct blocks, cards, inputs, and buttons.
- Generated output focuses on layout structure and reusable component shells rather than OCR-perfect text recreation.
