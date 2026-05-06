(function () {
  const vscode = acquireVsCodeApi();

  const state = {
    analysis: null,
    viewport: "desktop",
    imageUrl: null
  };

  const elements = {
    dropzone: document.getElementById("dropzone"),
    fileInput: document.getElementById("fileInput"),
    browseButton: document.getElementById("browseButton"),
    status: document.getElementById("status"),
    analysisBadge: document.getElementById("analysisBadge"),
    analysisSummary: document.getElementById("analysisSummary"),
    componentList: document.getElementById("componentList"),
    previewMeta: document.getElementById("previewMeta"),
    previewCanvas: document.getElementById("previewCanvas"),
    codeOutput: document.getElementById("codeOutput"),
    copyButton: document.getElementById("copyButton"),
    saveButton: document.getElementById("saveButton"),
    viewportButtons: Array.from(document.querySelectorAll("[data-viewport]"))
  };

  initialize();

  function initialize() {
    elements.browseButton.addEventListener("click", () => elements.fileInput.click());
    elements.fileInput.addEventListener("change", async (event) => {
      const file = event.target.files?.[0];
      if (file) {
        await handleFile(file);
      }
    });

    elements.dropzone.addEventListener("dragover", (event) => {
      event.preventDefault();
      elements.dropzone.classList.add("dragover");
    });

    elements.dropzone.addEventListener("dragleave", () => {
      elements.dropzone.classList.remove("dragover");
    });

    elements.dropzone.addEventListener("drop", async (event) => {
      event.preventDefault();
      elements.dropzone.classList.remove("dragover");
      const file = event.dataTransfer?.files?.[0];
      if (file) {
        await handleFile(file);
      }
    });

    elements.dropzone.addEventListener("keydown", (event) => {
      if (event.key === "Enter" || event.key === " ") {
        event.preventDefault();
        elements.fileInput.click();
      }
    });

    document.addEventListener("paste", async (event) => {
      const items = Array.from(event.clipboardData?.items ?? []);
      const imageItem = items.find((item) => item.type.startsWith("image/"));
      if (!imageItem) {
        return;
      }

      const file = imageItem.getAsFile();
      if (file) {
        await handleFile(file);
      }
    });

    elements.copyButton.addEventListener("click", () => {
      if (elements.codeOutput.value.trim()) {
        vscode.postMessage({ type: "copyCode", payload: elements.codeOutput.value });
      }
    });

    elements.saveButton.addEventListener("click", () => {
      if (elements.codeOutput.value.trim()) {
        vscode.postMessage({ type: "saveCode", payload: elements.codeOutput.value });
      }
    });

    elements.viewportButtons.forEach((button) => {
      button.addEventListener("click", () => {
        state.viewport = button.dataset.viewport || "desktop";
        syncViewportButtons();
        renderPreview();
      });
    });
  }

  async function handleFile(file) {
    updateStatus(`Analyzing ${file.name}...`, "working");
    const dataUrl = await fileToDataUrl(file);
    state.imageUrl = dataUrl;

    try {
      state.analysis = await analyzeScreenshot(dataUrl);
      elements.codeOutput.value = state.analysis.code;
      renderAnalysis();
      renderComponentList();
      renderPreview();
      updateStatus(`Analyzed ${file.name} successfully.`, "ready");
    } catch (error) {
      console.error(error);
      state.analysis = null;
      elements.codeOutput.value = "";
      renderAnalysis();
      renderComponentList();
      renderPreview();
      updateStatus("Analysis failed. Try a cleaner screenshot with stronger visual separation.", "error");
    }
  }

  function updateStatus(message, stateName) {
    elements.status.textContent = message;
    elements.analysisBadge.textContent =
      stateName === "working" ? "Processing" : stateName === "ready" ? "Ready" : stateName === "error" ? "Error" : "Idle";
  }

  function syncViewportButtons() {
    elements.viewportButtons.forEach((button) => {
      button.classList.toggle("active", button.dataset.viewport === state.viewport);
    });
  }

  async function analyzeScreenshot(dataUrl) {
    const image = await loadImage(dataUrl);
    const normalized = drawNormalizedImage(image);
    const { canvas, context, width, height } = normalized;
    const imageData = context.getImageData(0, 0, width, height);
    const background = estimateBackgroundColor(imageData);
    const palette = extractPalette(imageData, background);
    const rawElements = detectElements(imageData, background);
    const filteredElements = pruneElements(rawElements, width, height);
    const tree = buildLayoutTree(filteredElements, { width, height, background });
    const components = detectReusableComponents(tree);
    annotateReusableNodes(tree, components);
    const spacing = analyzeSpacing(filteredElements);
    const responsive = inferResponsiveStrategy(width, tree);
    const code = generateReactComponent(tree, {
      width,
      height,
      background,
      palette,
      components,
      spacing,
      responsive
    });

    return {
      width,
      height,
      background,
      palette,
      elementCount: filteredElements.length,
      elements: filteredElements,
      tree,
      components,
      spacing,
      responsive,
      code
    };
  }

  function drawNormalizedImage(image) {
    const maxDimension = 1200;
    const scale = Math.min(1, maxDimension / Math.max(image.width, image.height));
    const width = Math.max(1, Math.round(image.width * scale));
    const height = Math.max(1, Math.round(image.height * scale));
    const canvas = document.createElement("canvas");
    const context = canvas.getContext("2d", { willReadFrequently: true });
    canvas.width = width;
    canvas.height = height;
    context.drawImage(image, 0, 0, width, height);
    return { canvas, context, width, height, scale };
  }

  function estimateBackgroundColor(imageData) {
    const { data, width, height } = imageData;
    const counts = new Map();
    const sampleStep = Math.max(2, Math.floor(Math.min(width, height) / 80));

    const registerPixel = (x, y) => {
      const index = (y * width + x) * 4;
      const alpha = data[index + 3];
      if (alpha < 16) {
        return;
      }
      const color = quantizeColor(data[index], data[index + 1], data[index + 2], 16);
      counts.set(color, (counts.get(color) || 0) + 1);
    };

    for (let x = 0; x < width; x += sampleStep) {
      registerPixel(x, 0);
      registerPixel(x, Math.max(0, height - 1));
    }

    for (let y = 0; y < height; y += sampleStep) {
      registerPixel(0, y);
      registerPixel(Math.max(0, width - 1), y);
    }

    let best = "#f0f0f0";
    let bestCount = -1;

    counts.forEach((count, color) => {
      if (count > bestCount) {
        bestCount = count;
        best = color;
      }
    });

    return hexToRgb(best);
  }

  function extractPalette(imageData, background) {
    const { data, width, height } = imageData;
    const counts = new Map();
    const sampleStep = Math.max(2, Math.floor(Math.max(width, height) / 120));

    for (let y = 0; y < height; y += sampleStep) {
      for (let x = 0; x < width; x += sampleStep) {
        const index = (y * width + x) * 4;
        if (data[index + 3] < 32) {
          continue;
        }
        const current = { r: data[index], g: data[index + 1], b: data[index + 2] };
        if (colorDistance(current, background) < 16 && counts.size > 0) {
          continue;
        }
        const quantized = quantizeColor(current.r, current.g, current.b, 24);
        counts.set(quantized, (counts.get(quantized) || 0) + 1);
      }
    }

    return Array.from(counts.entries())
      .sort((a, b) => b[1] - a[1])
      .slice(0, 6)
      .map(([hex]) => hex);
  }

  function detectElements(imageData, background) {
    const { data, width, height } = imageData;
    const active = new Uint8Array(width * height);
    const visited = new Uint8Array(width * height);
    const contrastThreshold = 24;

    for (let y = 1; y < height - 1; y += 1) {
      for (let x = 1; x < width - 1; x += 1) {
        const pixelIndex = (y * width + x) * 4;
        const alpha = data[pixelIndex + 3];
        if (alpha < 24) {
          continue;
        }

        const current = { r: data[pixelIndex], g: data[pixelIndex + 1], b: data[pixelIndex + 2] };
        const bgDelta = colorDistance(current, background);
        const rightIndex = pixelIndex + 4;
        const bottomIndex = pixelIndex + width * 4;
        const localContrast = Math.max(
          colorDistance(current, { r: data[rightIndex], g: data[rightIndex + 1], b: data[rightIndex + 2] }),
          colorDistance(current, { r: data[bottomIndex], g: data[bottomIndex + 1], b: data[bottomIndex + 2] })
        );

        if (bgDelta > contrastThreshold || localContrast > contrastThreshold + 12) {
          active[y * width + x] = 1;
        }
      }
    }

    const elements = [];
    const stack = [];
    const offsets = [-1, 1, -width, width];
    const minPixels = Math.max(36, Math.floor((width * height) * 0.00008));

    for (let index = 0; index < active.length; index += 1) {
      if (!active[index] || visited[index]) {
        continue;
      }

      visited[index] = 1;
      stack.length = 0;
      stack.push(index);

      let count = 0;
      let sumR = 0;
      let sumG = 0;
      let sumB = 0;
      let minX = width;
      let minY = height;
      let maxX = 0;
      let maxY = 0;

      for (let pointer = 0; pointer < stack.length; pointer += 1) {
        const currentIndex = stack[pointer];
        const x = currentIndex % width;
        const y = Math.floor(currentIndex / width);
        const pixelIndex = currentIndex * 4;
        count += 1;
        sumR += data[pixelIndex];
        sumG += data[pixelIndex + 1];
        sumB += data[pixelIndex + 2];
        minX = Math.min(minX, x);
        minY = Math.min(minY, y);
        maxX = Math.max(maxX, x);
        maxY = Math.max(maxY, y);

        for (const offset of offsets) {
          const nextIndex = currentIndex + offset;
          if (nextIndex < 0 || nextIndex >= active.length || visited[nextIndex] || !active[nextIndex]) {
            continue;
          }

          const nextX = nextIndex % width;
          if (Math.abs(nextX - x) > 1) {
            continue;
          }

          visited[nextIndex] = 1;
          stack.push(nextIndex);
        }
      }

      const boxWidth = maxX - minX + 1;
      const boxHeight = maxY - minY + 1;
      const area = boxWidth * boxHeight;
      const density = count / area;

      if (count < minPixels || boxWidth < 4 || boxHeight < 4) {
        continue;
      }

      if (density < 0.08 && area < width * height * 0.08) {
        continue;
      }

      const averageColor = {
        r: Math.round(sumR / count),
        g: Math.round(sumG / count),
        b: Math.round(sumB / count)
      };

      elements.push({
        id: `node-${elements.length + 1}`,
        x: minX,
        y: minY,
        width: boxWidth,
        height: boxHeight,
        area,
        density,
        color: rgbToHex(averageColor),
        radius: estimateRadius(imageData, { x: minX, y: minY, width: boxWidth, height: boxHeight }, averageColor, background),
        kind: classifyElement(boxWidth, boxHeight, density, averageColor, background)
      });
    }

    return mergeOverlappingElements(elements);
  }

  function pruneElements(elements, width, height) {
    const screenArea = width * height;
    return elements
      .filter((element) => element.area < screenArea * 0.9)
      .filter((element) => element.width > 6 && element.height > 6)
      .filter((element, index, list) => {
        const duplicate = list.find((candidate, candidateIndex) => {
          if (candidateIndex === index) {
            return false;
          }
          return overlapRatio(element, candidate) > 0.92 && candidate.area >= element.area;
        });
        return !duplicate;
      })
      .sort((a, b) => a.area - b.area);
  }

  function mergeOverlappingElements(elements) {
    const merged = [...elements];
    let changed = true;

    while (changed) {
      changed = false;

      for (let i = 0; i < merged.length; i += 1) {
        for (let j = i + 1; j < merged.length; j += 1) {
          const first = merged[i];
          const second = merged[j];
          const overlap = overlapRatio(first, second);
          const horizontalGap = gapBetween(first.x, first.x + first.width, second.x, second.x + second.width);
          const verticalGap = gapBetween(first.y, first.y + first.height, second.y, second.y + second.height);
          const shouldMerge =
            overlap > 0.28 ||
            (horizontalGap <= 8 && overlapSpan(first.y, first.height, second.y, second.height) > 0.72) ||
            (verticalGap <= 8 && overlapSpan(first.x, first.width, second.x, second.width) > 0.72);

          if (!shouldMerge) {
            continue;
          }

          const union = {
            id: `${first.id}-${second.id}`,
            x: Math.min(first.x, second.x),
            y: Math.min(first.y, second.y),
            width: Math.max(first.x + first.width, second.x + second.width) - Math.min(first.x, second.x),
            height: Math.max(first.y + first.height, second.y + second.height) - Math.min(first.y, second.y),
            area: 0,
            density: Math.max(first.density, second.density),
            color: first.area >= second.area ? first.color : second.color,
            radius: Math.max(first.radius, second.radius),
            kind: pickMergedKind(first.kind, second.kind)
          };
          union.area = union.width * union.height;
          merged.splice(j, 1);
          merged.splice(i, 1, union);
          changed = true;
          break;
        }

        if (changed) {
          break;
        }
      }
    }

    return merged;
  }

  function classifyElement(width, height, density, color, background) {
    const aspectRatio = width / Math.max(height, 1);
    const bgContrast = colorDistance(color, background);

    if (height <= 20 && aspectRatio >= 3) {
      return "text";
    }

    if (aspectRatio >= 1.8 && aspectRatio <= 5 && height >= 28 && height <= 80 && density > 0.48) {
      return "button";
    }

    if (aspectRatio <= 1.2 && width >= 36 && height >= 36 && density > 0.7 && bgContrast > 28) {
      return "icon";
    }

    if (width >= 120 && height >= 72 && density > 0.28) {
      return "card";
    }

    if (bgContrast > 32 && density > 0.65) {
      return "surface";
    }

    return "shape";
  }

  function estimateRadius(imageData, bounds, foreground, background) {
    const { data, width } = imageData;
    const samples = [
      [bounds.x, bounds.y],
      [bounds.x + bounds.width - 1, bounds.y],
      [bounds.x, bounds.y + bounds.height - 1],
      [bounds.x + bounds.width - 1, bounds.y + bounds.height - 1]
    ];

    const cornerMatches = samples.filter(([x, y]) => {
      const index = (y * width + x) * 4;
      if (data[index + 3] < 16) {
        return true;
      }
      const cornerColor = { r: data[index], g: data[index + 1], b: data[index + 2] };
      return colorDistance(cornerColor, background) < colorDistance(cornerColor, foreground);
    }).length;

    if (cornerMatches >= 3) {
      return Math.round(Math.min(bounds.width, bounds.height) * 0.2);
    }

    if (bounds.height <= 48 && bounds.width / Math.max(bounds.height, 1) > 1.5) {
      return Math.round(bounds.height * 0.3);
    }

    return Math.min(16, Math.round(Math.min(bounds.width, bounds.height) * 0.08));
  }

  function buildLayoutTree(elements, screen) {
    const nodes = elements.map((element) => ({
      ...element,
      children: [],
      synthetic: false
    }));

    const root = {
      id: "root-screen",
      kind: "screen",
      synthetic: true,
      x: 0,
      y: 0,
      width: screen.width,
      height: screen.height,
      radius: 0,
      color: rgbToHex(screen.background),
      children: []
    };

    const byAreaAscending = [...nodes].sort((a, b) => a.area - b.area);

    for (const child of [...nodes].sort((a, b) => a.area - b.area)) {
      const parent = byAreaAscending.find((candidate) => {
        if (candidate.id === child.id) {
          return false;
        }
        if (candidate.area <= child.area) {
          return false;
        }
        return containsRect(candidate, child, 10);
      });

      if (parent) {
        parent.children.push(child);
        child.parentId = parent.id;
      } else {
        root.children.push(child);
      }
    }

    for (const node of nodes) {
      if (node.children.length > 0) {
        node.children = orderChildren(node.children);
      }
    }

    root.children = orderChildren(root.children);
    return finalizeNode(root, true);
  }

  function finalizeNode(node, isRoot = false) {
    const directChildren = (node.children || []).map((child) => finalizeNode(child));
    if (directChildren.length === 0) {
      return {
        ...node,
        layout: "leaf",
        children: []
      };
    }

    const groups = groupChildren(directChildren);
    const layout = groups.every((group) => group.groupType === "row") ? "column" : "row";
    const normalizedChildren = groups.map((group, index) => {
      if (group.items.length === 1) {
        return group.items[0];
      }

      const bounds = getBounds(group.items);
      return {
        id: `${node.id}-group-${index + 1}`,
        synthetic: true,
        kind: "group",
        layout: group.groupType,
        x: bounds.x,
        y: bounds.y,
        width: bounds.width,
        height: bounds.height,
        color: "transparent",
        radius: 0,
        children: group.items
      };
    });

    return {
      ...node,
      synthetic: node.synthetic || isRoot,
      layout,
      children: normalizedChildren
    };
  }

  function groupChildren(children) {
    const rowGroups = clusterByAxis(children, "row");
    if (rowGroups.length > 1) {
      return rowGroups.map((items) => ({ groupType: "row", items }));
    }

    const columnGroups = clusterByAxis(children, "column");
    if (columnGroups.length > 1) {
      return columnGroups.map((items) => ({ groupType: "column", items }));
    }

    return [{ groupType: "row", items: [...children].sort((a, b) => a.x - b.x) }];
  }

  function clusterByAxis(children, mode) {
    const sorted = [...children].sort((a, b) => (mode === "row" ? a.y - b.y || a.x - b.x : a.x - b.x || a.y - b.y));
    const groups = [];

    for (const child of sorted) {
      const currentGroup = groups[groups.length - 1];
      if (!currentGroup) {
        groups.push([child]);
        continue;
      }

      const last = currentGroup[currentGroup.length - 1];
      const aligned =
        mode === "row"
          ? overlapSpan(last.y, last.height, child.y, child.height) > 0.3 || Math.abs(child.y - last.y) < Math.max(18, child.height * 0.35)
          : overlapSpan(last.x, last.width, child.x, child.width) > 0.3 || Math.abs(child.x - last.x) < Math.max(18, child.width * 0.35);

      if (aligned) {
        currentGroup.push(child);
      } else {
        groups.push([child]);
      }
    }

    return groups;
  }

  function detectReusableComponents(root) {
    const seen = new Map();

    walkTree(root, (node, depth) => {
      if (depth === 0 || node.children.length === 0) {
        return;
      }
      const signature = componentSignature(node);
      const bucket = seen.get(signature) || [];
      bucket.push(node);
      seen.set(signature, bucket);
    });

    const reusable = [];
    let index = 1;

    seen.forEach((nodes, signature) => {
      if (nodes.length < 2) {
        return;
      }
      const template = nodes[0];
      reusable.push({
        signature,
        name: suggestComponentName(template, index),
        count: nodes.length,
        template,
        kind: template.kind,
        averageSize: `${Math.round(template.width)}x${Math.round(template.height)}`
      });
      index += 1;
    });

    return reusable.sort((a, b) => b.count - a.count);
  }

  function annotateReusableNodes(root, components) {
    const bySignature = new Map(components.map((component) => [component.signature, component.name]));
    walkTree(root, (node, depth) => {
      if (depth === 0 || node.children.length === 0) {
        return;
      }
      node.reusableName = bySignature.get(componentSignature(node));
    });
  }

  function analyzeSpacing(elements) {
    const horizontal = [];
    const vertical = [];
    const rowGroups = clusterByAxis(elements, "row");

    for (const row of rowGroups) {
      const sorted = [...row].sort((a, b) => a.x - b.x);
      for (let index = 1; index < sorted.length; index += 1) {
        const previous = sorted[index - 1];
        const current = sorted[index];
        const gap = current.x - (previous.x + previous.width);
        if (gap > 0) {
          horizontal.push(Math.round(gap));
        }
      }
    }

    const sortedByY = [...elements].sort((a, b) => a.y - b.y);
    for (let index = 1; index < sortedByY.length; index += 1) {
      const previous = sortedByY[index - 1];
      const current = sortedByY[index];
      const gap = current.y - (previous.y + previous.height);
      if (gap > 0) {
        vertical.push(Math.round(gap));
      }
    }

    return {
      horizontal: summarizeDistances(horizontal),
      vertical: summarizeDistances(vertical)
    };
  }

  function inferResponsiveStrategy(width, tree) {
    const breakpoint = width >= 1280 ? "xl" : width >= 1024 ? "lg" : width >= 768 ? "md" : "sm";
    const rowGroups = [];
    walkTree(tree, (node) => {
      if (node.layout === "row" && node.children.length > 1) {
        rowGroups.push(node);
      }
    });

    return {
      sourceWidth: width,
      breakpoint,
      stackOnSmallScreens: rowGroups.length,
      description:
        rowGroups.length > 0
          ? `Stacks ${rowGroups.length} horizontal groups by default, then restores them at the ${breakpoint} breakpoint.`
          : `Keeps the inferred structure mostly columnar; only the root container expands at the ${breakpoint} breakpoint.`
    };
  }

  function generateReactComponent(root, context) {
    const reusableDefinitions = [];
    const renderedReusable = new Set();
    const componentMap = new Map(context.components.map((component) => [component.name, component]));

    function renderNode(node, depth, insideDefinition = false) {
      if (!insideDefinition && node.reusableName && componentMap.has(node.reusableName)) {
        if (!renderedReusable.has(node.reusableName)) {
          renderedReusable.add(node.reusableName);
          const definition = renderReusableDefinition(componentMap.get(node.reusableName));
          reusableDefinitions.push(definition);
        }
        return `${indent(depth)}<${node.reusableName} />`;
      }

      if (node.children.length === 0) {
        return renderLeaf(node, depth);
      }

      const className = buildClassName(node, context.responsive, true);
      const childLines = node.children.map((child) => renderNode(child, depth + 1, insideDefinition)).join("\n");
      return `${indent(depth)}<div className="${className}">
${childLines}
${indent(depth)}</div>`;
    }

    function renderReusableDefinition(component) {
      const body = renderNode(component.template, 2, true);
      return `function ${component.name}() {
  return (
${body}
  );
}`;
    }

    const rootClass = [
      "min-h-screen",
      `bg-[${rgbToHex(context.background)}]`,
      "px-4",
      "py-6",
      "md:px-6",
      "md:py-8"
    ].join(" ");

    const frameClass = `mx-auto flex w-full max-w-[${Math.round(context.width)}px] flex-col gap-[${context.spacing?.vertical?.primary || 16}px]`;
    const screenTree = renderNode(root, 3, false);

    const definitionsBlock = reusableDefinitions.length > 0 ? `${reusableDefinitions.join("\n\n")}\n\n` : "";

    return `import React from "react";

${definitionsBlock}export default function GeneratedScreen() {
  return (
    <section className="${rootClass}">
      <div className="${frameClass}">
${screenTree}
      </div>
    </section>
  );
}
`;
  }

  function buildClassName(node, responsive, includeBackground) {
    const classes = [];

    if (node.layout === "row") {
      classes.push("flex", "flex-col", `${responsive.breakpoint}:flex-row`);
      classes.push(`gap-[${estimateGap(node.children, "horizontal")}px]`);
    } else if (node.layout === "column" || node.layout === "screen") {
      classes.push("flex", "flex-col");
      classes.push(`gap-[${estimateGap(node.children, "vertical")}px]`);
    } else {
      classes.push("relative");
    }

    if (includeBackground && node.kind !== "screen" && node.color !== "transparent") {
      classes.push(`bg-[${node.color}]`);
    }

    if (node.radius > 0) {
      classes.push(`rounded-[${node.radius}px]`);
    }

    if (node.kind === "card" || node.kind === "surface" || node.children.length > 0) {
      classes.push(`p-[${estimatePadding(node)}px]`);
    }

    if (node.kind !== "screen" && node.kind !== "group") {
      classes.push(`w-full`);
      if (node.width < 960) {
        classes.push(`${responsive.breakpoint}:w-[${Math.round(node.width)}px]`);
      }
      classes.push(`min-h-[${Math.round(node.height)}px]`);
    }

    if (node.kind === "button") {
      classes.push("items-center", "justify-center");
    }

    return classes.join(" ").replace(/\s+/g, " ").trim();
  }

  function renderLeaf(node, depth) {
    const className = buildLeafClassName(node);
    const label =
      node.kind === "button"
        ? "Button"
        : node.kind === "text"
          ? ""
          : node.kind === "icon"
            ? ""
            : " ";

    if (node.kind === "button") {
      return `${indent(depth)}<button className="${className}">${label}</button>`;
    }

    return `${indent(depth)}<div className="${className}">${label}</div>`;
  }

  function buildLeafClassName(node) {
    const classes = ["shrink-0"];
    if (node.kind === "text") {
      classes.push(`h-[${Math.max(10, Math.round(node.height))}px]`, `w-[${Math.round(node.width)}px]`, "rounded-full");
    } else {
      classes.push(`min-h-[${Math.round(node.height)}px]`, `w-full`, `md:w-[${Math.round(node.width)}px]`);
      if (node.kind === "icon") {
        classes.push("aspect-square");
      }
    }

    classes.push(`bg-[${node.color}]`);
    if (node.radius > 0) {
      classes.push(`rounded-[${node.radius}px]`);
    }

    if (node.kind === "button") {
      classes.push("inline-flex", "items-center", "justify-center", "text-sm", "font-semibold", "text-white");
    }

    return classes.join(" ");
  }

  function renderAnalysis() {
    if (!state.analysis) {
      elements.analysisSummary.innerHTML = "Paste a screenshot to populate component detection, spacing metrics, and the extracted palette.";
      elements.previewMeta.textContent = "Preview updates after each analysis pass.";
      return;
    }

    const { width, height, background, palette, elementCount, spacing, responsive } = state.analysis;
    elements.analysisSummary.innerHTML = `
      <div class="metric-grid">
        <div class="metric">
          <span class="metric-label">Canvas</span>
          <div class="metric-value">${width} × ${height}</div>
        </div>
        <div class="metric">
          <span class="metric-label">Elements</span>
          <div class="metric-value">${elementCount}</div>
        </div>
        <div class="metric">
          <span class="metric-label">Primary H Gap</span>
          <div class="metric-value">${spacing.horizontal.primary || 0}px</div>
        </div>
        <div class="metric">
          <span class="metric-label">Primary V Gap</span>
          <div class="metric-value">${spacing.vertical.primary || 0}px</div>
        </div>
      </div>
      <div>
        <span class="metric-label">Palette</span>
        <div class="swatch-row">
          ${[rgbToHex(background), ...palette]
            .slice(0, 6)
            .map(
              (color) => `
                <div class="swatch">
                  <span class="swatch-dot" style="background:${color}"></span>
                  <span>${color}</span>
                </div>
              `
            )
            .join("")}
        </div>
      </div>
      <div>
        <span class="metric-label">Spacing Clusters</span>
        <div class="chip-row">
          ${spacing.horizontal.values
            .slice(0, 4)
            .map((value) => `<span class="chip">H ${value}px</span>`)
            .join("")}
          ${spacing.vertical.values
            .slice(0, 4)
            .map((value) => `<span class="chip">V ${value}px</span>`)
            .join("")}
        </div>
      </div>
      <div>
        <span class="metric-label">Responsive Strategy</span>
        <div class="chip-row">
          <span class="chip">${responsive.breakpoint} breakpoint</span>
          <span class="chip">${responsive.stackOnSmallScreens} stacked groups</span>
        </div>
        <p class="component-meta">${responsive.description}</p>
      </div>
    `;

    elements.previewMeta.textContent = `Heuristic preview using ${elementCount} detected regions. Breakpoint target: ${responsive.breakpoint}.`;
  }

  function renderComponentList() {
    if (!state.analysis || state.analysis.components.length === 0) {
      elements.componentList.innerHTML = "No repeated component patterns were detected in this screenshot.";
      return;
    }

    elements.componentList.innerHTML = state.analysis.components
      .map(
        (component) => `
          <div class="component-item">
            <div class="component-title">
              <strong>${component.name}</strong>
              <span class="badge">${component.count}x</span>
            </div>
            <div class="component-meta">
              Kind: ${component.kind} · Avg size: ${component.averageSize}
            </div>
          </div>
        `
      )
      .join("");
  }

  function renderPreview() {
    if (!state.analysis) {
      elements.previewCanvas.innerHTML = "<p>Preview unavailable until an image is analyzed.</p>";
      elements.previewCanvas.classList.add("empty-preview");
      return;
    }

    elements.previewCanvas.classList.remove("empty-preview");
    const viewportWidth =
      state.viewport === "mobile" ? 375 : state.viewport === "tablet" ? 768 : Math.min(1400, state.analysis.width);
    const stageWidth = Math.min(viewportWidth, state.analysis.width);
    const treeMarkup = renderPreviewNode(state.analysis.tree, 0, stageWidth);

    elements.previewCanvas.innerHTML = `
      <div class="preview-device" style="width:${viewportWidth + 40}px; max-width:100%;">
        <div class="preview-stage" style="width:${stageWidth}px;">
          ${treeMarkup}
        </div>
      </div>
    `;
  }

  function renderPreviewNode(node, depth, stageWidth) {
    if (node.children.length === 0) {
      const width = stageWidth > 420 && depth > 0 ? Math.min(stageWidth, node.width) : stageWidth;
      return `
        <div
          class="preview-node preview-leaf"
          style="
            width:${width}px;
            min-height:${Math.max(10, node.height)}px;
            background:${node.color};
            border-radius:${node.radius}px;
            margin-bottom:${depth === 0 ? 0 : 8}px;
          "
        >
          ${node.kind === "button" ? `<span class="preview-node-label">button</span>` : ""}
        </div>
      `;
    }

    const direction = node.layout === "row" ? "preview-row" : "preview-stack";
    const gap = estimateGap(node.children, node.layout === "row" ? "horizontal" : "vertical");
    const label = node.kind === "screen" ? "" : `<span class="preview-node-label">${node.reusableName || node.kind}</span>`;
    const background = node.color && node.color !== "transparent" ? node.color : "rgba(255,255,255,0.02)";

    return `
      <div
        class="preview-node preview-container ${direction}"
        style="
          width:${Math.min(stageWidth, node.width || stageWidth)}px;
          min-height:${node.kind === "screen" ? "auto" : `${node.height}px`};
          background:${background};
          border-radius:${node.radius}px;
          gap:${gap}px;
          padding:${node.kind === "screen" ? 0 : estimatePadding(node)}px;
        "
      >
        ${label}
        ${node.children.map((child) => renderPreviewNode(child, depth + 1, Math.min(stageWidth, child.width || stageWidth))).join("")}
      </div>
    `;
  }

  function renderPreviewNodeLabel(node) {
    return node.reusableName || node.kind;
  }

  function componentSignature(node) {
    return [
      node.kind,
      node.layout,
      node.children.length,
      bucket(node.width, 24),
      bucket(node.height, 24),
      bucket(node.radius, 6)
    ].join(":");
  }

  function suggestComponentName(node, index) {
    if (node.kind === "card") {
      return `DetectedCard${index}`;
    }
    if (node.layout === "row") {
      return `DetectedRow${index}`;
    }
    if (node.kind === "surface") {
      return `DetectedPanel${index}`;
    }
    return `DetectedBlock${index}`;
  }

  function walkTree(node, callback, depth = 0) {
    callback(node, depth);
    for (const child of node.children) {
      walkTree(child, callback, depth + 1);
    }
  }

  function orderChildren(children) {
    return [...children].sort((a, b) => a.y - b.y || a.x - b.x);
  }

  function getBounds(nodes) {
    const x = Math.min(...nodes.map((node) => node.x));
    const y = Math.min(...nodes.map((node) => node.y));
    const maxX = Math.max(...nodes.map((node) => node.x + node.width));
    const maxY = Math.max(...nodes.map((node) => node.y + node.height));
    return {
      x,
      y,
      width: maxX - x,
      height: maxY - y
    };
  }

  function containsRect(parent, child, inset) {
    return (
      child.x >= parent.x + inset &&
      child.y >= parent.y + inset &&
      child.x + child.width <= parent.x + parent.width - inset &&
      child.y + child.height <= parent.y + parent.height - inset
    );
  }

  function overlapRatio(first, second) {
    const intersectionWidth = Math.max(0, Math.min(first.x + first.width, second.x + second.width) - Math.max(first.x, second.x));
    const intersectionHeight = Math.max(0, Math.min(first.y + first.height, second.y + second.height) - Math.max(first.y, second.y));
    if (intersectionWidth === 0 || intersectionHeight === 0) {
      return 0;
    }

    const intersectionArea = intersectionWidth * intersectionHeight;
    return intersectionArea / Math.min(first.area, second.area);
  }

  function overlapSpan(startA, sizeA, startB, sizeB) {
    const overlap = Math.max(0, Math.min(startA + sizeA, startB + sizeB) - Math.max(startA, startB));
    return overlap / Math.min(sizeA, sizeB);
  }

  function gapBetween(startA, endA, startB, endB) {
    if (endA < startB) {
      return startB - endA;
    }
    if (endB < startA) {
      return startA - endB;
    }
    return 0;
  }

  function pickMergedKind(first, second) {
    if (first === second) {
      return first;
    }
    if (first === "card" || second === "card") {
      return "card";
    }
    if (first === "surface" || second === "surface") {
      return "surface";
    }
    if (first === "button" || second === "button") {
      return "button";
    }
    return "shape";
  }

  function summarizeDistances(values) {
    const rounded = values.filter((value) => Number.isFinite(value) && value >= 0).map((value) => roundToNearest(value, 2));
    const counts = new Map();

    for (const value of rounded) {
      counts.set(value, (counts.get(value) || 0) + 1);
    }

    const ordered = Array.from(counts.entries())
      .sort((a, b) => b[1] - a[1])
      .map(([value]) => value);

    return {
      primary: ordered[0] || 0,
      values: ordered
    };
  }

  function estimateGap(children, axis) {
    if (children.length <= 1) {
      return 12;
    }

    const distances = [];
    const sorted = [...children].sort((a, b) => (axis === "horizontal" ? a.x - b.x : a.y - b.y));
    for (let index = 1; index < sorted.length; index += 1) {
      const previous = sorted[index - 1];
      const current = sorted[index];
      const gap = axis === "horizontal" ? current.x - (previous.x + previous.width) : current.y - (previous.y + previous.height);
      if (gap > 0) {
        distances.push(gap);
      }
    }

    if (distances.length === 0) {
      return 12;
    }

    return roundToNearest(distances.sort((a, b) => a - b)[Math.floor(distances.length / 2)], 2);
  }

  function estimatePadding(node) {
    if (node.children.length === 0) {
      return 0;
    }

    const childBounds = getBounds(node.children);
    const left = Math.max(0, childBounds.x - node.x);
    const top = Math.max(0, childBounds.y - node.y);
    const right = Math.max(0, node.x + node.width - (childBounds.x + childBounds.width));
    const bottom = Math.max(0, node.y + node.height - (childBounds.y + childBounds.height));
    return roundToNearest(Math.max(12, Math.min(left, top, right, bottom) || 16), 2);
  }

  function bucket(value, size) {
    return Math.round(value / size) * size;
  }

  function roundToNearest(value, step) {
    return Math.round(value / step) * step;
  }

  function quantizeColor(r, g, b, step) {
    const red = Math.round(r / step) * step;
    const green = Math.round(g / step) * step;
    const blue = Math.round(b / step) * step;
    return rgbToHex({ r: red, g: green, b: blue });
  }

  function colorDistance(first, second) {
    return Math.sqrt(
      Math.pow(first.r - second.r, 2) + Math.pow(first.g - second.g, 2) + Math.pow(first.b - second.b, 2)
    );
  }

  function rgbToHex(color) {
    const toHex = (value) => value.toString(16).padStart(2, "0");
    return `#${toHex(clamp(color.r))}${toHex(clamp(color.g))}${toHex(clamp(color.b))}`;
  }

  function hexToRgb(hex) {
    const normalized = hex.replace("#", "");
    return {
      r: parseInt(normalized.slice(0, 2), 16),
      g: parseInt(normalized.slice(2, 4), 16),
      b: parseInt(normalized.slice(4, 6), 16)
    };
  }

  function clamp(value) {
    return Math.max(0, Math.min(255, value));
  }

  function fileToDataUrl(file) {
    return new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = () => resolve(String(reader.result));
      reader.onerror = reject;
      reader.readAsDataURL(file);
    });
  }

  function loadImage(dataUrl) {
    return new Promise((resolve, reject) => {
      const image = new Image();
      image.onload = () => resolve(image);
      image.onerror = reject;
      image.src = dataUrl;
    });
  }

  function indent(depth) {
    return "  ".repeat(depth);
  }
})();
