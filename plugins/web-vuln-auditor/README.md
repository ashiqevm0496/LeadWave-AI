# Web Vuln Auditor

Repo-local Codex plugin for browser-facing website security audits.

## What it checks

- Exposed API and documentation endpoints
- Missing or weak security headers
- Unsafe script patterns and insecure script sourcing
- Outdated frontend frameworks and libraries
- Tracking and privacy risks
- SEO spam injection indicators

## Run directly

```bash
python plugins/web-vuln-auditor/scripts/audit_site.py https://example.com
```

## Output

- Markdown report in `plugins/web-vuln-auditor/reports/`
- Optional JSON report with `--format json`
