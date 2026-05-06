<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

handleRequest();

$pageTitle = 'LeadWave AI';
$activePage = 'Dashboard';
$navItems = [
    ['label' => 'Dashboard', 'icon' => 'dashboard'],
    ['label' => 'Leads', 'icon' => 'users'],
    ['label' => 'Campaigns', 'icon' => 'bolt'],
    ['label' => 'Inbox Rotation', 'icon' => 'mail'],
    ['label' => 'Spam Guard', 'icon' => 'shield'],
    ['label' => 'Integrations', 'icon' => 'plug'],
    ['label' => 'Settings', 'icon' => 'gear'],
];

$dashboard = dashboardData(db());
$flash = getFlash();
$latestLead = $dashboard['latestLead'];
$campaigns = $dashboard['campaigns'];
$primaryCampaign = $campaigns[0] ?? null;
$connectedIntegrations = count(array_filter($dashboard['integrations'], static fn(array $item): bool => $item['status'] === 'Connected'));
$generatedLeads = count($dashboard['leads']);
$latestSpamValue = $latestLead !== null ? (float) $latestLead['spam_score'] : 0.0;
$deliverabilityGauge = max(12, 100 - (int) round($latestSpamValue * 10));
$latestSignals = $latestLead !== null ? explode('|', (string) $latestLead['signal_summary']) : ['No enriched signal yet'];
$heroSubject = $latestLead['email_subject'] ?? 'Add a lead to generate a live outreach preview';
$heroBody = $latestLead['email_body'] ?? 'Submit a company website and contact name. LeadWave will enrich the lead, create an icebreaker, score the copy, and assign an inbox.';
$heroScore = $latestLead['personalization_score'] ?? 0;
$heroInbox = $latestLead['inbox_name'] ?? 'No inbox assigned';
$heroCompany = $latestLead['company_name'] ?? 'your target company';

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<main class="main-content">
    <header class="topbar">
        <div>
            <p class="eyebrow">AI-powered outbound operating system</p>
            <h1>Cold email personalization at scale</h1>
        </div>
        <div class="topbar-actions">
            <form method="post" class="inline-form">
                <input type="hidden" name="action" value="rotate_inboxes">
                <button class="ghost-button" type="submit">Rotate inboxes</button>
            </form>
            <form method="post" class="inline-form">
                <input type="hidden" name="action" value="sync_crm">
                <button class="primary-button" type="submit">Sync CRM</button>
            </form>
        </div>
    </header>

    <?php if ($flash !== null): ?>
        <div class="flash-banner flash-<?= h($flash['type']) ?>">
            <?= h($flash['message']) ?>
        </div>
    <?php endif; ?>

    <section class="hero-panel" id="dashboard">
        <div class="hero-copy">
            <span class="hero-badge">Website and LinkedIn enrichment workflow</span>
            <h2>Scrape public company context and turn it into usable outbound copy.</h2>
            <p>
                This workspace app stores leads in SQLite, fetches company website context, keeps public LinkedIn references,
                generates a personalized outreach draft, scores spam risk, assigns an inbox, and tracks campaign activity.
            </p>
            <div class="hero-metrics">
                <div>
                    <strong><?= h((string) $generatedLeads) ?></strong>
                    <span>Generated lead drafts saved locally</span>
                </div>
                <div>
                    <strong><?= h((string) $connectedIntegrations) ?></strong>
                    <span>Connected integrations available for sync</span>
                </div>
                <div>
                    <strong><?= h((string) count($dashboard['inboxes'])) ?></strong>
                    <span>Inbox pools managed by rotation logic</span>
                </div>
            </div>
        </div>
        <div class="hero-card">
            <div class="hero-card-top">
                <p>Live Email Preview</p>
                <span>Personalization score <?= h((string) $heroScore) ?>/100</span>
            </div>
            <div class="email-card">
                <p class="email-label">Subject</p>
                <p class="email-subject"><?= h($heroSubject) ?></p>
                <p class="email-label">Opening</p>
                <p class="email-body"><?= nl2br(h($heroBody)) ?></p>
            </div>
            <div class="hero-card-footer">
                <div>
                    <span>Spam risk</span>
                    <strong><?= h(number_format($latestSpamValue, 1)) ?> / 10</strong>
                </div>
                <div>
                    <span>Inbox route</span>
                    <strong><?= h($heroInbox) ?></strong>
                </div>
            </div>
        </div>
    </section>

    <section class="stats-grid">
        <?php foreach ($dashboard['stats'] as $stat): ?>
            <article class="stat-card">
                <p class="eyebrow"><?= h($stat['eyebrow']) ?></p>
                <div class="stat-row">
                    <h3><?= h($stat['value']) ?></h3>
                    <span class="delta delta-<?= h($stat['tone']) ?>"><?= h($stat['delta']) ?></span>
                </div>
                <p><?= h($stat['description']) ?></p>
            </article>
        <?php endforeach; ?>
    </section>

    <section class="content-grid">
        <div class="stack">
            <article class="panel" id="leads">
                <div class="panel-header">
                    <div>
                        <p class="eyebrow">Lead capture</p>
                        <h3>Scrape and personalize a new lead</h3>
                    </div>
                    <span class="chip">Writes to SQLite</span>
                </div>
                <form method="post" class="lead-form">
                    <input type="hidden" name="action" value="create_lead">
                    <div class="form-grid">
                        <label class="form-field">
                            <span>Company name</span>
                            <input class="text-input" type="text" name="company_name" placeholder="VertexCloud" required>
                        </label>
                        <label class="form-field">
                            <span>Website URL</span>
                            <input class="text-input" type="text" name="website_url" placeholder="https://example.com" required>
                        </label>
                        <label class="form-field">
                            <span>LinkedIn URL</span>
                            <input class="text-input" type="text" name="linkedin_url" placeholder="https://www.linkedin.com/in/name">
                        </label>
                        <label class="form-field">
                            <span>Contact name</span>
                            <input class="text-input" type="text" name="contact_name" placeholder="Maya Chen" required>
                        </label>
                        <label class="form-field">
                            <span>Contact role</span>
                            <input class="text-input" type="text" name="contact_role" placeholder="VP Growth">
                        </label>
                        <label class="form-field">
                            <span>Campaign</span>
                            <input class="text-input" type="text" name="campaign_name" placeholder="Enterprise AI Outbound">
                        </label>
                    </div>
                    <button class="primary-button form-submit" type="submit">Generate outreach draft</button>
                </form>
            </article>

            <article class="panel">
                <div class="panel-header">
                    <div>
                        <p class="eyebrow">Enrichment output</p>
                        <h3>Latest lead intelligence</h3>
                    </div>
                    <span class="chip"><?= h($heroCompany) ?></span>
                </div>
                <?php if ($latestLead !== null): ?>
                    <div class="detail-grid">
                        <div class="detail-card">
                            <span class="detail-label">Website summary</span>
                            <p><?= h($latestLead['company_summary']) ?></p>
                        </div>
                        <div class="detail-card">
                            <span class="detail-label">LinkedIn notes</span>
                            <p><?= h($latestLead['linkedin_summary']) ?></p>
                        </div>
                        <div class="detail-card">
                            <span class="detail-label">AI icebreaker</span>
                            <p><?= h($latestLead['icebreaker']) ?></p>
                        </div>
                        <div class="detail-card">
                            <span class="detail-label">Detected signals</span>
                            <p><?= h($latestLead['signal_summary']) ?></p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <strong>No leads generated yet</strong>
                        <p>Add a company website and contact to populate this panel with enrichment output.</p>
                    </div>
                <?php endif; ?>
            </article>

            <article class="panel" id="campaigns">
                <div class="panel-header">
                    <div>
                        <p class="eyebrow">Campaign analytics</p>
                        <h3>Stored campaign performance</h3>
                    </div>
                    <span class="chip"><?= h((string) count($campaigns)) ?> campaigns</span>
                </div>
                <div class="chart-card">
                    <div class="chart-summary">
                        <div>
                            <span>Sent</span>
                            <strong><?= h((string) ($primaryCampaign['total_sent'] ?? 0)) ?></strong>
                        </div>
                        <div>
                            <span>Opened</span>
                            <strong><?= h((string) ($primaryCampaign['total_opened'] ?? 0)) ?></strong>
                        </div>
                        <div>
                            <span>Replied</span>
                            <strong><?= h((string) ($primaryCampaign['total_replied'] ?? 0)) ?></strong>
                        </div>
                        <div>
                            <span>Booked</span>
                            <strong><?= h((string) ($primaryCampaign['total_booked'] ?? 0)) ?></strong>
                        </div>
                    </div>
                    <div class="bar-chart" aria-label="Lead creation by weekday">
                        <?php foreach ($dashboard['campaignBars'] as $bar): ?>
                            <div class="bar-group">
                                <div class="bar-track">
                                    <span class="bar-fill" style="height: <?= (int) $bar['height'] ?>%"></span>
                                </div>
                                <span class="bar-label"><?= h($bar['label']) ?> · <?= h((string) $bar['count']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="campaign-layout">
                    <div class="campaign-list">
                        <?php foreach ($campaigns as $campaign): ?>
                            <div class="campaign-card">
                                <strong><?= h($campaign['name']) ?></strong>
                                <span>Status: <?= h($campaign['status']) ?></span>
                                <span>Sent <?= h((string) $campaign['total_sent']) ?> · Replies <?= h((string) $campaign['total_replied']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <form method="post" class="campaign-form">
                        <input type="hidden" name="action" value="create_campaign">
                        <label class="form-field">
                            <span>New campaign name</span>
                            <input class="text-input" type="text" name="campaign_title" placeholder="Q3 RevOps Outreach" required>
                        </label>
                        <button class="ghost-button form-submit" type="submit">Create campaign</button>
                    </form>
                </div>
            </article>

            <article class="panel">
                <div class="panel-header">
                    <div>
                        <p class="eyebrow">Generated leads</p>
                        <h3>Recent personalized drafts</h3>
                    </div>
                    <span class="chip">Most recent first</span>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Company</th>
                                <th>Decision maker</th>
                                <th>Signals</th>
                                <th>Inbox</th>
                                <th>Score</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($dashboard['leads'] === []): ?>
                                <tr>
                                    <td colspan="5" class="empty-row">No lead drafts stored yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($dashboard['leads'] as $lead): ?>
                                    <tr>
                                        <td><?= h($lead['company_name']) ?></td>
                                        <td><?= h($lead['contact_name'] . ($lead['contact_role'] !== '' ? ', ' . $lead['contact_role'] : '')) ?></td>
                                        <td><?= h(truncateText((string) $lead['signal_summary'], 58)) ?></td>
                                        <td><?= h($lead['inbox_name']) ?></td>
                                        <td><span class="score-pill"><?= h((string) $lead['personalization_score']) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </article>
        </div>

        <aside class="stack">
            <article class="panel" id="spam-guard">
                <div class="panel-header">
                    <div>
                        <p class="eyebrow">Spam score checker</p>
                        <h3>Latest deliverability check</h3>
                    </div>
                </div>
                <div class="gauge-wrap">
                    <div class="gauge" style="--gauge-value: <?= (int) $deliverabilityGauge ?>;">
                        <div class="gauge-inner">
                            <strong><?= h((string) $deliverabilityGauge) ?></strong>
                            <span>Deliverability</span>
                        </div>
                    </div>
                    <ul class="signal-list">
                        <li>Spam score on latest draft: <?= h(number_format($latestSpamValue, 1)) ?> / 10</li>
                        <li>Subject length is checked before the draft is stored.</li>
                        <li>Latest signals: <?= h(implode(', ', array_map('trim', array_slice($latestSignals, 0, 3)))) ?></li>
                    </ul>
                </div>
            </article>

            <article class="panel" id="inbox-rotation">
                <div class="panel-header">
                    <div>
                        <p class="eyebrow">Inbox rotation</p>
                        <h3>Multi-sender health</h3>
                    </div>
                </div>
                <div class="rotation-list">
                    <?php foreach ($dashboard['inboxes'] as $inbox): ?>
                        <div class="rotation-item">
                            <div>
                                <strong><?= h($inbox['name']) ?></strong>
                                <span><?= h($inbox['email_address']) ?> · <?= h($inbox['warmup_status']) ?></span>
                            </div>
                            <b><?= h((string) $inbox['reputation_score']) ?>%</b>
                        </div>
                    <?php endforeach; ?>
                </div>
            </article>

            <article class="panel" id="integrations">
                <div class="panel-header">
                    <div>
                        <p class="eyebrow">CRM integrations</p>
                        <h3>Connected revenue stack</h3>
                    </div>
                </div>
                <div class="integration-grid">
                    <?php foreach ($dashboard['integrations'] as $integration): ?>
                        <div class="integration-card">
                            <strong><?= h($integration['name']) ?></strong>
                            <span><?= h($integration['type']) ?> · <?= h($integration['status']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </article>

            <article class="panel">
                <div class="panel-header">
                    <div>
                        <p class="eyebrow">Automation feed</p>
                        <h3>Recent system events</h3>
                    </div>
                </div>
                <div class="activity-list">
                    <?php foreach ($dashboard['activities'] as $activity): ?>
                        <div class="activity-item">
                            <span class="activity-dot"></span>
                            <p><?= h($activity['message']) ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </article>
        </aside>
    </section>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
