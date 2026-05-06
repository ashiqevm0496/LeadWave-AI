import * as vscode from "vscode";
import { UiGeneratorPanel } from "./panel";

export function activate(context: vscode.ExtensionContext): void {
  const disposable = vscode.commands.registerCommand("uiScreenshotReact.openGenerator", () => {
    UiGeneratorPanel.render(context.extensionUri);
  });

  context.subscriptions.push(disposable);
}

export function deactivate(): void {
  // No-op.
}
