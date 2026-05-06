#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import re
import ssl
import sys
from collections import Counter
from dataclasses import asdict, dataclass
from datetime import datetime, timezone
from html.parser import HTMLParser
from pathlib import Path
from typing import Dict, Iterable, List, Optional, Sequence, Tuple
from urllib.error import HTTPError, URLError
from urllib.parse import urljoin, urlparse
from urllib.request import Request, urlopen


USER_AGENT = "WebVulnAuditor/0.1 (+https://github.com/ashiqevm0496/LeadWave-AI)"
SCRIPT_VERSION_RE = re.compile(r"(?P<name>jquery|bootstrap|react|vue|angular|next|nuxt)[-/_.]?(?P<version>\d+(?:\.\d+){0,2})", re.I)
INLINE_RISK_PATTERNS = {
    "eval() usage": re.compile(r"\beval\s*\(", re.I),
    "document.write() usage": re.compile(r"\bdocument\.write\s*\(", re.I),
    "innerHTML assignment": re.compile(r"\.innerHTML\s*=", re.I),
    "Function constructor": re.compile(r"new\s+Function\s*\(", re.I),
}
SUSPICIOUS_SEO_TERMS = [
    "viagra",
    "cialis",
    "casino",
    "betting",
    "loan approval",
    "crypto giveaway",
    "essay writing service",
]
TRACKER_HOSTS = {
    "www.google-analytics.com": "Google Analytics",
    "www.googletagmanager.com": "Google Tag Manager",
    "connect.facebook.net": "Meta Pixel",
    "snap.licdn.com": "LinkedIn Insight Tag",
    "cdn.segment.com": "Segment",
    "js.hs-scripts.com": "HubSpot Tracking",
    "bat.bing.com": "Microsoft Ads",
    "static.hotjar.com": "Hotjar",
}
COMMON_DISCOVERY_PATHS = [
    "/swagger",
    "/swagger-ui",
    "/swagger-ui.html",
    "/openapi.json",
    "/openapi.yaml",
    "/api",
    "/api/docs",
    "/graphql",
    "/graphiql",
    "/robots.txt",
    "/sitemap.xml",
    "/.well-known/security.txt",
]
OUTDATED_FLOORS = {
    "jquery": (3, 5, 0),
    "bootstrap": (5, 2, 0),
    "react": (17, 0, 0),
    "vue": (3, 2, 0),
    "angular": (14, 0, 0),
    "next": (12, 0, 0),
    "nuxt": (3, 0, 0),
}


@dataclass
class Finding:
    severity: str
    title: str
    category: str
    evidence: str
    remediation: str


@dataclass
class AuditReport:
    target: str
    scanned_at: str
    status_code: Optional[int]
    final_url: Optional[str]
    headers: Dict[str, str]
    findings: List[Finding]
    discovered_endpoints: List[Dict[str, str]]
    framework_versions: List[Dict[str, str]]
    trackers: List[Dict[str, str]]
    notes: List[str]


class AssetParser(HTMLParser):
    def __init__(self) -> None:
        super().__init__()
        self.scripts: List[Dict[str, str]] = []
        self.links: List[Dict[str, str]] = []
        self.meta: List[Dict[str, str]] = []
        self.inline_scripts: List[str] = []
        self.event_handlers: List[str] = []
        self._in_script = False
        self._current_script_attrs: Dict[str, str] | None = None
        self.title = ""
        self._in_title = False

    def handle_starttag(self, tag: str, attrs: Sequence[Tuple[str, Optional[str]]]) -> None:
        attr_map = {k.lower(): v or "" for k, v in attrs}
        for attr_name, attr_value in attr_map.items():
            if attr_name.startswith("on") and attr_value:
                self.event_handlers.append(f"{tag}[{attr_name}]={attr_value[:140]}")

        if tag.lower() == "script":
            self._in_script = True
            self._current_script_attrs = attr_map
            self.scripts.append(attr_map)
        elif tag.lower() == "link":
            self.links.append(attr_map)
        elif tag.lower() == "meta":
            self.meta.append(attr_map)
        elif tag.lower() == "title":
            self._in_title = True

    def handle_endtag(self, tag: str) -> None:
        if tag.lower() == "script":
            self._in_script = False
            self._current_script_attrs = None
        elif tag.lower() == "title":
            self._in_title = False

    def handle_data(self, data: str) -> None:
        if self._in_script and self._current_script_attrs is not None and not self._current_script_attrs.get("src"):
            snippet = data.strip()
            if snippet:
                self.inline_scripts.append(snippet[:5000])
        if self._in_title:
            self.title += data


def fetch_url(url: str, timeout: int) -> Tuple[Optional[str], Dict[str, str], Optional[int], Optional[str], Optional[str]]:
    request = Request(url, headers={"User-Agent": USER_AGENT})
    context = ssl.create_default_context()
    context.check_hostname = False
    context.verify_mode = ssl.CERT_NONE

    try:
        with urlopen(request, timeout=timeout, context=context) as response:
            body = response.read().decode("utf-8", errors="replace")
            headers = {k: v for k, v in response.headers.items()}
            return body, headers, response.getcode(), response.geturl(), None
    except HTTPError as exc:
        try:
            body = exc.read().decode("utf-8", errors="replace")
        except Exception:
            body = None
        headers = {k: v for k, v in exc.headers.items()} if exc.headers else {}
        return body, headers, exc.code, exc.geturl(), f"HTTPError: {exc.reason}"
    except URLError as exc:
        return None, {}, None, None, f"URLError: {exc.reason}"


def normalize_target(target: str) -> str:
    if not re.match(r"^https?://", target, re.I):
        return "https://" + target
    return target


def compare_versions(found: Tuple[int, ...], floor: Tuple[int, ...]) -> bool:
    length = max(len(found), len(floor))
    found_full = found + (0,) * (length - len(found))
    floor_full = floor + (0,) * (length - len(floor))
    return found_full < floor_full


def parse_version(value: str) -> Tuple[int, ...]:
    return tuple(int(part) for part in value.split("."))


def discover_frameworks(parser: AssetParser) -> List[Dict[str, str]]:
    found: List[Dict[str, str]] = []
    assets = [item.get("src", "") for item in parser.scripts] + [item.get("href", "") for item in parser.links]
    for asset in assets:
        match = SCRIPT_VERSION_RE.search(asset)
        if not match:
            continue
        found.append({
            "name": match.group("name").lower(),
            "version": match.group("version"),
            "source": asset,
        })
    return found


def analyze_headers(headers: Dict[str, str], findings: List[Finding], target: str) -> None:
    lower = {k.lower(): v for k, v in headers.items()}

    expected = {
        "content-security-policy": ("High", "Set a strict Content-Security-Policy that avoids unsafe-inline and unsafe-eval."),
        "strict-transport-security": ("Medium", "Enable HSTS for HTTPS responses with a long max-age and includeSubDomains if appropriate."),
        "x-frame-options": ("Medium", "Set X-Frame-Options to DENY or SAMEORIGIN unless framing is required."),
        "x-content-type-options": ("Medium", "Set X-Content-Type-Options to nosniff."),
        "referrer-policy": ("Low", "Set Referrer-Policy to strict-origin-when-cross-origin or stricter."),
        "permissions-policy": ("Low", "Set Permissions-Policy to restrict powerful browser features.")
    }

    for header, (severity, remediation) in expected.items():
        if header not in lower:
            findings.append(Finding(
                severity=severity,
                title=f"Missing {header}",
                category="Headers",
                evidence=f"{target} does not return the {header} response header.",
                remediation=remediation,
            ))

    csp = lower.get("content-security-policy", "")
    if csp:
        if "unsafe-inline" in csp or "unsafe-eval" in csp:
            findings.append(Finding(
                severity="High",
                title="Weak Content-Security-Policy directives",
                category="Headers",
                evidence=f"CSP contains risky directives: {csp}",
                remediation="Remove unsafe-inline and unsafe-eval where possible and move to nonce- or hash-based execution."
            ))

    server = lower.get("server")
    if server:
        findings.append(Finding(
            severity="Info",
            title="Server banner exposed",
            category="Headers",
            evidence=f"Server header discloses: {server}",
            remediation="Reduce unnecessary version disclosure in public headers where possible."
        ))


def analyze_scripts(target: str, parser: AssetParser, findings: List[Finding], trackers: List[Dict[str, str]]) -> None:
    page_scheme = urlparse(target).scheme
    for script in parser.scripts:
        src = script.get("src", "").strip()
        if src:
            parsed = urlparse(src)
            host = parsed.netloc.lower()
            if host in TRACKER_HOSTS:
                trackers.append({
                    "host": host,
                    "name": TRACKER_HOSTS[host],
                    "source": src,
                })
            if page_scheme == "https" and src.startswith("http://"):
                findings.append(Finding(
                    severity="High",
                    title="Insecure third-party script source",
                    category="Scripts",
                    evidence=f"Script loaded over HTTP on an HTTPS page: {src}",
                    remediation="Serve all third-party assets over HTTPS or remove them."
                ))

    for snippet in parser.inline_scripts:
        for title, pattern in INLINE_RISK_PATTERNS.items():
            if pattern.search(snippet):
                findings.append(Finding(
                    severity="Medium",
                    title=f"Inline script contains {title}",
                    category="Scripts",
                    evidence=f"Inline script snippet matched {title}.",
                    remediation="Refactor risky DOM and script patterns and move logic to reviewed static assets."
                ))
                break

    if parser.event_handlers:
        findings.append(Finding(
            severity="Low",
            title="Inline event handlers detected",
            category="Scripts",
            evidence=f"Found {len(parser.event_handlers)} inline event handler attributes, e.g. {parser.event_handlers[0]}",
            remediation="Move inline event handlers into external scripts to improve CSP hardening."
        ))


def analyze_frameworks(frameworks: List[Dict[str, str]], findings: List[Finding]) -> None:
    for framework in frameworks:
        floor = OUTDATED_FLOORS.get(framework["name"])
        if not floor:
            continue
        found_version = parse_version(framework["version"])
        if compare_versions(found_version, floor):
            findings.append(Finding(
                severity="Medium",
                title=f"Potentially outdated {framework['name']} version",
                category="Frameworks",
                evidence=f"Detected {framework['name']} {framework['version']} from asset {framework['source']}",
                remediation=f"Review the deployed {framework['name']} version and upgrade to at least {'.'.join(map(str, floor))} if still in use."
            ))


def analyze_tracking(trackers: List[Dict[str, str]], findings: List[Finding]) -> None:
    if not trackers:
        return
    unique_names = ", ".join(sorted({item["name"] for item in trackers}))
    findings.append(Finding(
        severity="Low",
        title="Third-party tracking stack detected",
        category="Privacy",
        evidence=f"Detected browser-facing tracking services: {unique_names}",
        remediation="Review consent gating, data sharing, and script necessity for each third-party tracker."
    ))


def analyze_seo_spam(html: str, parser: AssetParser, findings: List[Finding]) -> None:
    lowered = html.lower()
    spam_hits = [term for term in SUSPICIOUS_SEO_TERMS if term in lowered]
    hidden_indicators = len(re.findall(r"display\s*:\s*none|visibility\s*:\s*hidden|opacity\s*:\s*0", lowered))
    cloaked_links = len(re.findall(r"<a[^>]+style=[\"'][^\"']*(display\s*:\s*none|visibility\s*:\s*hidden)", lowered))

    if spam_hits:
        findings.append(Finding(
            severity="High",
            title="Suspicious SEO spam terms detected",
            category="SEO Spam",
            evidence=f"Found suspicious terms in page source: {', '.join(spam_hits)}",
            remediation="Inspect templates and CMS content for unauthorized injected text or compromised plugin output."
        ))

    if hidden_indicators >= 4 or cloaked_links >= 2:
        findings.append(Finding(
            severity="Medium",
            title="Hidden content patterns may indicate cloaking or spam injection",
            category="SEO Spam",
            evidence=f"Detected {hidden_indicators} hidden-style indicators and {cloaked_links} hidden links in page markup.",
            remediation="Review hidden blocks and links for unauthorized content injection and remove non-essential cloaked elements."
        ))


def discover_endpoints(base_url: str, timeout: int) -> List[Dict[str, str]]:
    discovered = []
    for path in COMMON_DISCOVERY_PATHS:
        target = urljoin(base_url, path)
        body, headers, status_code, final_url, error = fetch_url(target, timeout)
        if status_code and status_code < 400:
            discovered.append({
                "path": path,
                "status": str(status_code),
                "url": final_url or target,
                "note": "Accessible",
            })
        elif status_code in {401, 403}:
            discovered.append({
                "path": path,
                "status": str(status_code),
                "url": final_url or target,
                "note": "Exists but access-restricted",
            })
        elif status_code in {400, 405} and path in {"/graphql", "/graphiql", "/api"}:
            discovered.append({
                "path": path,
                "status": str(status_code) if status_code else "error",
                "url": final_url or target,
                "note": "Endpoint responded but rejected the request method or payload",
            })
    return discovered


def analyze_exposed_endpoints(discovered: List[Dict[str, str]], findings: List[Finding]) -> None:
    for endpoint in discovered:
        path = endpoint["path"]
        if path in {"/swagger", "/swagger-ui", "/swagger-ui.html", "/openapi.json", "/openapi.yaml", "/api/docs"} and endpoint["status"] == "200":
            findings.append(Finding(
                severity="Medium",
                title="Public API documentation exposed",
                category="APIs",
                evidence=f"Endpoint {path} is publicly accessible at {endpoint['url']}",
                remediation="Confirm this documentation is intentionally public and sanitize any sensitive examples or schemas."
            ))
        if path == "/graphql" and endpoint["status"] in {"200", "400"}:
            findings.append(Finding(
                severity="Medium",
                title="GraphQL endpoint exposed",
                category="APIs",
                evidence=f"GraphQL-like endpoint discovered at {endpoint['url']} with status {endpoint['status']}",
                remediation="Confirm authorization, introspection policy, and query complexity controls for the GraphQL endpoint."
            ))


def summarize(findings: Sequence[Finding]) -> Dict[str, int]:
    counter = Counter(f.severity for f in findings)
    return {level: counter.get(level, 0) for level in ["Critical", "High", "Medium", "Low", "Info"]}


def render_markdown(report: AuditReport) -> str:
    summary = summarize(report.findings)
    lines = [
        f"# Web Security Audit Report",
        "",
        f"- Target: `{report.target}`",
        f"- Final URL: `{report.final_url or 'N/A'}`",
        f"- Scan time: `{report.scanned_at}`",
        f"- Status code: `{report.status_code if report.status_code is not None else 'N/A'}`",
        "",
        "## Executive Summary",
        "",
        f"- High risk findings: **{summary['High']}**",
        f"- Medium risk findings: **{summary['Medium']}**",
        f"- Low risk findings: **{summary['Low']}**",
        f"- Informational findings: **{summary['Info']}**",
        "",
        "## Findings",
        "",
    ]

    if not report.findings:
        lines.extend([
            "No reportable issues were detected by the passive and light-touch checks in this plugin.",
            "",
        ])
    else:
        for index, finding in enumerate(report.findings, start=1):
            lines.extend([
                f"### {index}. [{finding.severity}] {finding.title}",
                "",
                f"- Category: {finding.category}",
                f"- Evidence: {finding.evidence}",
                f"- Remediation: {finding.remediation}",
                "",
            ])

    lines.extend([
        "## Exposed Endpoints",
        "",
    ])
    if report.discovered_endpoints:
        for endpoint in report.discovered_endpoints:
            lines.append(f"- `{endpoint['path']}` -> `{endpoint['status']}` ({endpoint['note']})")
    else:
        lines.append("- No common API or documentation endpoints were discovered by the light-touch probes.")
    lines.append("")

    lines.extend([
        "## Framework Fingerprints",
        "",
    ])
    if report.framework_versions:
        for item in report.framework_versions:
            lines.append(f"- `{item['name']}` `{item['version']}` from `{item['source']}`")
    else:
        lines.append("- No framework versions were fingerprinted from static asset names.")
    lines.append("")

    lines.extend([
        "## Tracking and Privacy Observations",
        "",
    ])
    if report.trackers:
        for item in report.trackers:
            lines.append(f"- `{item['name']}` via `{item['host']}`")
    else:
        lines.append("- No known tracker hosts were detected in the parsed page assets.")
    lines.append("")

    lines.extend([
        "## Response Headers",
        "",
    ])
    for key, value in sorted(report.headers.items()):
        lines.append(f"- `{key}`: `{value}`")
    lines.append("")

    if report.notes:
        lines.extend([
            "## Notes",
            "",
        ])
        for note in report.notes:
            lines.append(f"- {note}")
        lines.append("")

    return "\n".join(lines)


def render_json(report: AuditReport) -> str:
    payload = asdict(report)
    payload["findings"] = [asdict(item) for item in report.findings]
    return json.dumps(payload, indent=2)


def build_report(target: str, timeout: int) -> AuditReport:
    normalized = normalize_target(target)
    html, headers, status_code, final_url, fetch_error = fetch_url(normalized, timeout)
    findings: List[Finding] = []
    notes: List[str] = []
    trackers: List[Dict[str, str]] = []

    if fetch_error:
        notes.append(fetch_error)

    if html is None:
        findings.append(Finding(
            severity="High",
            title="Target could not be fetched",
            category="Availability",
            evidence=fetch_error or f"Failed to fetch {normalized}",
            remediation="Verify the target URL, TLS configuration, and external reachability before running the audit again."
        ))
        return AuditReport(
            target=normalized,
            scanned_at=datetime.now(timezone.utc).isoformat(),
            status_code=status_code,
            final_url=final_url,
            headers=headers,
            findings=findings,
            discovered_endpoints=[],
            framework_versions=[],
            trackers=[],
            notes=notes,
        )

    parser = AssetParser()
    parser.feed(html)

    analyze_headers(headers, findings, normalized)
    analyze_scripts(final_url or normalized, parser, findings, trackers)
    frameworks = discover_frameworks(parser)
    analyze_frameworks(frameworks, findings)
    analyze_tracking(trackers, findings)
    analyze_seo_spam(html, parser, findings)
    discovered = discover_endpoints(final_url or normalized, timeout)
    analyze_exposed_endpoints(discovered, findings)

    if "set-cookie" in {k.lower() for k in headers}:
        notes.append("Set-Cookie header is present. Review HttpOnly, Secure, and SameSite attributes separately if cookie governance matters.")

    return AuditReport(
        target=normalized,
        scanned_at=datetime.now(timezone.utc).isoformat(),
        status_code=status_code,
        final_url=final_url,
        headers=headers,
        findings=findings,
        discovered_endpoints=discovered,
        framework_versions=frameworks,
        trackers=trackers,
        notes=notes,
    )


def make_output_path(target: str, output_arg: Optional[str], fmt: str) -> Path:
    if output_arg:
        return Path(output_arg)
    host = urlparse(normalize_target(target)).netloc.replace(":", "-")
    timestamp = datetime.now().strftime("%Y%m%d-%H%M%S")
    suffix = "json" if fmt == "json" else "md"
    return Path(__file__).resolve().parent.parent / "reports" / f"{host}-{timestamp}.{suffix}"


def main(argv: Optional[Sequence[str]] = None) -> int:
    parser = argparse.ArgumentParser(description="Audit a public website for browser-visible security weaknesses.")
    parser.add_argument("target", help="Target URL or hostname")
    parser.add_argument("--format", choices=["markdown", "json"], default="markdown")
    parser.add_argument("--output", help="Optional output file path")
    parser.add_argument("--timeout", type=int, default=10, help="HTTP timeout in seconds")
    args = parser.parse_args(argv)

    report = build_report(args.target, args.timeout)
    output_path = make_output_path(args.target, args.output, args.format)
    output_path.parent.mkdir(parents=True, exist_ok=True)

    if args.format == "json":
        rendered = render_json(report)
    else:
        rendered = render_markdown(report)

    output_path.write_text(rendered, encoding="utf-8")

    summary = summarize(report.findings)
    print(f"Report written to: {output_path}")
    print(
        "Findings summary: "
        f"high={summary['High']} medium={summary['Medium']} low={summary['Low']} info={summary['Info']}"
    )

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
