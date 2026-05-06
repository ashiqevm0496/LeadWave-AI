---
name: web-vuln-auditor
description: "Scan public websites for browser-visible security weaknesses, privacy risks, exposed API surfaces, and SEO spam injections, then generate a professional audit report."
---

# Web Vuln Auditor

Use this plugin when the user wants a website security audit focused on browser-visible and HTTP-layer risks.

## Scope

This plugin is designed for:

- public target URLs
- passive analysis of headers, HTML, scripts, and cookies
- light-touch discovery of common API and documentation endpoints
- professional reports with severity, evidence, and remediation

This plugin is not designed for:

- authenticated penetration testing
- brute force scanning
- exploitation
- destructive security testing

## Run

From the repo root:

```bash
python plugins/web-vuln-auditor/scripts/audit_site.py https://target.example
```

Optional flags:

```bash
python plugins/web-vuln-auditor/scripts/audit_site.py https://target.example --format json
python plugins/web-vuln-auditor/scripts/audit_site.py https://target.example --output custom-report.md
python plugins/web-vuln-auditor/scripts/audit_site.py https://target.example --timeout 12
```

## What to report back

- overall risk summary
- key findings by severity
- notable exposed endpoints
- header weaknesses
- outdated framework evidence
- tracking and SEO spam indicators
- path to the generated report file
