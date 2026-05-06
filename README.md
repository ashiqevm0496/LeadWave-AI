# LeadWave AI

LeadWave AI is a PHP dashboard for cold email personalization workflows.

## What it does

- Stores leads locally in SQLite
- Scrapes public company website content
- Stores public LinkedIn profile references and attempts basic public metadata capture
- Generates an outreach draft and icebreaker
- Scores spam risk heuristically
- Assigns the best available inbox from a rotation pool
- Tracks campaign records, activities, inbox health, and CRM sync state

## Project structure

- `index.php` renders the dashboard
- `app/bootstrap.php` contains the database, scraping, scoring, and action handlers
- `includes/` contains reusable layout parts
- `assets/css/styles.css` contains the dashboard styling
- `assets/js/app.js` contains the sidebar behavior

## Run locally

```bash
php -S 127.0.0.1:8081
```

Then open `http://127.0.0.1:8081`.

## Push to GitHub

```bash
git config user.name "Your Name"
git config user.email "you@example.com"
git add .
git commit -m "Build LeadWave AI MVP"
git remote add origin YOUR_REPO_URL
git push -u origin main
```
