<?php
function renderIcon(string $name): string
{
    $icons = [
        'dashboard' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h7v7H4zM13 4h7v11h-7zM4 13h7v7H4zM13 17h7v3h-7z"/></svg>',
        'users' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 11a4 4 0 1 0-4-4 4 4 0 0 0 4 4ZM8 13a4 4 0 1 0-4-4 4 4 0 0 0 4 4Zm0 2c-3.31 0-6 1.79-6 4v1h12v-1c0-2.21-2.69-4-6-4Zm8 0c-.29 0-.56.02-.83.05A5.93 5.93 0 0 1 18 20h4v-1c0-2.21-2.69-4-6-4Z"/></svg>',
        'bolt' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M13 2 4 14h6l-1 8 9-12h-6z"/></svg>',
        'mail' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 5h18v14H3zm2 2v.5l7 5 7-5V7zm14 10V9.93l-7 5-7-5V17z"/></svg>',
        'shield' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2 4 5v6c0 5.25 3.4 10.74 8 12 4.6-1.26 8-6.75 8-12V5zm-1 14-4-4 1.41-1.41L11 13.17l4.59-4.58L17 10z"/></svg>',
        'plug' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 7V3h2v4h6V3h2v4h2v6a7 7 0 0 1-6 6.92V23h-2v-3.08A7 7 0 0 1 5 13V7Zm0 2v4a5 5 0 0 0 10 0V9Z"/></svg>',
        'gear' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m19.14 12.94.04-.94-.04-.94 2.03-1.58a.5.5 0 0 0 .12-.64l-1.92-3.32a.5.5 0 0 0-.6-.22l-2.39.96a7.38 7.38 0 0 0-1.63-.94L14.4 2.8a.5.5 0 0 0-.49-.4h-3.82a.5.5 0 0 0-.49.4L9.24 5.3a7.38 7.38 0 0 0-1.63.94l-2.39-.96a.5.5 0 0 0-.6.22L2.7 8.82a.5.5 0 0 0 .12.64l2.03 1.58-.04.94.04.94-2.03 1.58a.5.5 0 0 0-.12.64l1.92 3.32a.5.5 0 0 0 .6.22l2.39-.96c.5.38 1.04.69 1.63.94l.36 2.5a.5.5 0 0 0 .49.4h3.82a.5.5 0 0 0 .49-.4l.36-2.5c.59-.25 1.13-.56 1.63-.94l2.39.96a.5.5 0 0 0 .6-.22l1.92-3.32a.5.5 0 0 0-.12-.64ZM12 15.5A3.5 3.5 0 1 1 15.5 12 3.5 3.5 0 0 1 12 15.5Z"/></svg>',
    ];

    return $icons[$name] ?? $icons['dashboard'];
}

$navHrefMap = [
    'Dashboard' => '#dashboard',
    'Leads' => '#leads',
    'Campaigns' => '#campaigns',
    'Inbox Rotation' => '#inbox-rotation',
    'Spam Guard' => '#spam-guard',
    'Integrations' => '#integrations',
    'Settings' => '#dashboard',
];
?>
<aside class="sidebar" id="sidebar">
    <div class="brand-row">
        <button class="menu-toggle" id="menuToggle" type="button" aria-label="Toggle navigation">
            <span></span>
            <span></span>
            <span></span>
        </button>
        <div class="brand-mark">
            <span></span>
        </div>
        <div class="brand-copy">
            <strong>LeadWave AI</strong>
            <small>Outbound OS</small>
        </div>
    </div>

    <nav class="nav-list" aria-label="Primary">
        <?php foreach ($navItems as $item): ?>
            <?php $isActive = $item['label'] === $activePage; ?>
            <a class="nav-link<?= $isActive ? ' is-active' : '' ?>" href="<?= htmlspecialchars($navHrefMap[$item['label']] ?? '#dashboard') ?>">
                <span class="nav-icon"><?= renderIcon($item['icon']) ?></span>
                <span><?= htmlspecialchars($item['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-card">
        <p>AI agent status</p>
        <strong>Scraping, scoring, routing, and CRM sync are active</strong>
        <span>This build stores lead drafts locally and updates inbox and campaign records every time you generate outreach.</span>
    </div>
</aside>
