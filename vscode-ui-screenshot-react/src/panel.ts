import * as vscode from "vscode";

export class UiGeneratorPanel {
  private static currentPanel: UiGeneratorPanel | undefined;

  public static render(extensionUri: vscode.Uri): void {
    if (UiGeneratorPanel.currentPanel) {
      UiGeneratorPanel.currentPanel.panel.reveal(vscode.ViewColumn.One);
      return;
    }

    const panel = vscode.window.createWebviewPanel(
      "uiScreenshotReactGenerator",
      "UI Screenshot Generator",
      vscode.ViewColumn.One,
      {
        enableScripts: true,
        retainContextWhenHidden: true,
        localResourceRoots: [vscode.Uri.joinPath(extensionUri, "media")]
      }
    );

    UiGeneratorPanel.currentPanel = new UiGeneratorPanel(panel, extensionUri);
  }

  private constructor(
    private readonly panel: vscode.WebviewPanel,
    private readonly extensionUri: vscode.Uri
  ) {
    this.panel.onDidDispose(() => {
      UiGeneratorPanel.currentPanel = undefined;
    });

    this.panel.webview.onDidReceiveMessage((message) => {
      void this.handleMessage(message);
    });

    this.panel.webview.html = this.getHtml(this.panel.webview);
  }

  private async handleMessage(message: { type?: string; payload?: unknown }): Promise<void> {
    switch (message.type) {
      case "copyCode": {
        const code = typeof message.payload === "string" ? message.payload : "";
        await vscode.env.clipboard.writeText(code);
        void vscode.window.setStatusBarMessage("Generated component copied to clipboard.", 2500);
        return;
      }
      case "saveCode": {
        const code = typeof message.payload === "string" ? message.payload : "";
        const defaultFolder = vscode.workspace.workspaceFolders?.[0]?.uri ?? vscode.Uri.file(".");
        const target = await vscode.window.showSaveDialog({
          defaultUri: vscode.Uri.joinPath(defaultFolder, "GeneratedScreen.tsx"),
          filters: {
            TypeScript: ["tsx", "ts"],
            JavaScript: ["jsx", "js"]
          }
        });

        if (!target) {
          return;
        }

        await vscode.workspace.fs.writeFile(target, Buffer.from(code, "utf8"));
        void vscode.window.showInformationMessage(`Saved generated component to ${target.fsPath}`);
        return;
      }
      default:
        return;
    }
  }

  private getHtml(webview: vscode.Webview): string {
    const stylesUri = webview.asWebviewUri(vscode.Uri.joinPath(this.extensionUri, "media", "styles.css"));
    const scriptUri = webview.asWebviewUri(vscode.Uri.joinPath(this.extensionUri, "media", "app.js"));
    const nonce = getNonce();

    return `<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta
      http-equiv="Content-Security-Policy"
      content="default-src 'none'; img-src ${webview.cspSource} data: blob:; style-src ${webview.cspSource} 'unsafe-inline'; script-src 'nonce-${nonce}';"
    />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>UI Screenshot Generator</title>
    <link rel="stylesheet" href="${stylesUri}" />
  </head>
  <body>
    <div class="app-shell">
      <aside class="side-panel">
        <div class="hero">
          <p class="eyebrow">Screenshot to Code</p>
          <h1>React + Tailwind Generator</h1>
          <p class="hero-copy">
            Paste a UI screenshot, inspect the detected structure, and export heuristic React + Tailwind components without leaving VS Code.
          </p>
        </div>

        <section class="card drop-card">
          <div id="dropzone" class="dropzone" tabindex="0">
            <input id="fileInput" type="file" accept="image/*" hidden />
            <div class="drop-icon">+</div>
            <p class="drop-title">Paste or drop a screenshot</p>
            <p class="drop-subtitle">Ctrl/Cmd+V, drag and drop, or choose a file.</p>
            <button id="browseButton" class="ghost-button" type="button">Choose Image</button>
          </div>
          <p id="status" class="status">Waiting for an image.</p>
        </section>

        <section class="card">
          <div class="section-heading">
            <h2>Analysis</h2>
            <span id="analysisBadge" class="badge">Idle</span>
          </div>
          <div id="analysisSummary" class="analysis-summary empty-state">
            Paste a screenshot to populate component detection, spacing metrics, and the extracted palette.
          </div>
        </section>

        <section class="card">
          <div class="section-heading">
            <h2>Detected Components</h2>
          </div>
          <div id="componentList" class="component-list empty-state">
            No components detected yet.
          </div>
        </section>
      </aside>

      <main class="main-panel">
        <section class="card preview-card">
          <div class="section-heading">
            <h2>Live Preview</h2>
            <div class="viewport-switcher">
              <button data-viewport="mobile" class="viewport-button" type="button">Mobile</button>
              <button data-viewport="tablet" class="viewport-button" type="button">Tablet</button>
              <button data-viewport="desktop" class="viewport-button active" type="button">Desktop</button>
            </div>
          </div>
          <div id="previewMeta" class="preview-meta">Preview updates after each analysis pass.</div>
          <div class="preview-frame">
            <div id="previewCanvas" class="preview-canvas empty-preview">
              <p>Preview unavailable until an image is analyzed.</p>
            </div>
          </div>
        </section>

        <section class="card code-card">
          <div class="section-heading">
            <h2>Generated Component</h2>
            <div class="code-actions">
              <button id="copyButton" class="ghost-button" type="button">Copy</button>
              <button id="saveButton" class="primary-button" type="button">Save</button>
            </div>
          </div>
          <textarea id="codeOutput" spellcheck="false" class="code-output" readonly></textarea>
        </section>
      </main>
    </div>
    <script nonce="${nonce}" src="${scriptUri}"></script>
  </body>
</html>`;
  }
}

function getNonce(): string {
  const characters = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789";
  let value = "";

  for (let index = 0; index < 32; index += 1) {
    value += characters.charAt(Math.floor(Math.random() * characters.length));
  }

  return value;
}
